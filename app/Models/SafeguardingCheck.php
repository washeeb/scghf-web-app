<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * One safeguarding check on one application, with its evidence.
 *
 * A row per check rather than a set of booleans on the application, because the
 * question an inquiry asks is not "was this person cleared" but "what did you
 * check, when, on what evidence, and who signed it off". Booleans answer the
 * first; only rows answer the second.
 *
 * Two rules enforced here:
 *
 *   - a PASS needs a reference and a verifier. A check with neither is a claim.
 *   - a WAIVER needs a reason and an authoriser. Waiving a police check for
 *     somebody who will work unsupervised with children is a decision somebody
 *     has to own by name.
 */
class SafeguardingCheck extends Model
{
    use HasFactory;

    public const OUTCOME_PENDING = 'pending';

    public const OUTCOME_PASSED = 'passed';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_WAIVED = 'waived';

    protected $fillable = [
        'volunteer_application_id', 'check_type', 'outcome', 'reference',
        'notes', 'completed_on', 'expires_on', 'evidence_media_id',
        'verified_by', 'waiver_reason', 'waived_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['outcome' => self::OUTCOME_PENDING];

    protected function casts(): array
    {
        return [
            // The certificate number, encrypted at rest (Phase 12).
            'reference' => 'encrypted',
            'completed_on' => 'date',
            'expires_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $check): void {
            if ($check->outcome === self::OUTCOME_PASSED) {
                if (blank($check->reference)) {
                    throw new RuntimeException(sprintf(
                        'Cannot record %s as passed without a reference — the certificate '
                        .'number, the referee spoken to, the date of the interview. A check '
                        .'with no evidence is a claim.',
                        $check->check_type,
                    ));
                }

                if ($check->verified_by === null) {
                    throw new RuntimeException(sprintf(
                        'Cannot record %s as passed without naming who verified it. A check '
                        .'nobody put their name to is not a check.',
                        $check->check_type,
                    ));
                }

                $check->completed_on ??= now()->toDateString();
            }

            if ($check->outcome === self::OUTCOME_WAIVED) {
                if (blank($check->waiver_reason) || $check->waived_by === null) {
                    throw new RuntimeException(sprintf(
                        'Waiving %s needs a stated reason and an authoriser. Waiving a check '
                        .'on somebody who will work unsupervised with vulnerable people is a '
                        .'decision that has to be owned.',
                        $check->check_type,
                    ));
                }
            }
        });
    }

    /** @return BelongsTo<VolunteerApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(VolunteerApplication::class, 'volunteer_application_id');
    }

    /** @return BelongsTo<User, $this> */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** @return BelongsTo<Media, $this> */
    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'evidence_media_id');
    }

    /** The human label for this check, from the policy. */
    public function label(): string
    {
        return (string) config(
            "compliance.safeguarding.required_checks.{$this->check_type}.label",
            $this->check_type,
        );
    }

    /**
     * Whether this check currently satisfies the requirement.
     *
     * Evaluated from the dates on every call rather than from a stored flag —
     * the same rule as a GRA approval and a consent record. A clearance that
     * expired last month must stop satisfying anything the day it expires, not
     * the next time somebody runs a report.
     */
    public function isSatisfied(?\DateTimeInterface $on = null): bool
    {
        $on = $on ? Carbon::instance($on) : now();

        if (! in_array($this->outcome, [self::OUTCOME_PASSED, self::OUTCOME_WAIVED], true)) {
            return false;
        }

        return $this->expires_on === null || $this->expires_on->endOfDay()->gte($on);
    }

    public function hasExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->endOfDay()->isPast();
    }

    public function daysUntilExpiry(): ?int
    {
        return $this->expires_on === null
            ? null
            : (int) now()->startOfDay()->diffInDays($this->expires_on->startOfDay(), false);
    }

    /**
     * Record a pass, with its evidence and its expiry.
     *
     * A police clearance is given a default expiry from the policy, because a
     * certificate is a statement about a point in time. Other checks — an
     * interview, a reference taken up — do not expire in the same way.
     */
    public function pass(User $verifier, string $reference, ?\DateTimeInterface $expiresOn = null): void
    {
        $expiry = $expiresOn;

        if ($expiry === null && $this->check_type === 'police_clearance') {
            $months = (int) config('compliance.safeguarding.clearance_valid_months', 24);
            $expiry = now()->addMonths($months);
        }

        $this->forceFill([
            'outcome' => self::OUTCOME_PASSED,
            'reference' => $reference,
            'verified_by' => $verifier->getKey(),
            'completed_on' => now()->toDateString(),
            'expires_on' => $expiry?->format('Y-m-d'),
        ])->save();
    }

    public function fail(User $verifier, string $notes): void
    {
        $this->forceFill([
            'outcome' => self::OUTCOME_FAILED,
            'verified_by' => $verifier->getKey(),
            'notes' => $notes,
            'completed_on' => now()->toDateString(),
        ])->save();
    }

    public function waive(User $authoriser, string $reason): void
    {
        $this->forceFill([
            'outcome' => self::OUTCOME_WAIVED,
            'waived_by' => $authoriser->getKey(),
            'waiver_reason' => $reason,
            'completed_on' => now()->toDateString(),
        ])->save();
    }

    #[Scope]
    protected function satisfied(Builder $query): void
    {
        $query->whereIn('outcome', [self::OUTCOME_PASSED, self::OUTCOME_WAIVED])
            ->where(fn (Builder $q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', now()));
    }

    /** Checks about to go stale, so a volunteer is re-checked before they lapse. */
    #[Scope]
    protected function expiringWithin(Builder $query, int $days): void
    {
        $query->where('outcome', self::OUTCOME_PASSED)
            ->whereNotNull('expires_on')
            ->whereBetween('expires_on', [now()->toDateString(), now()->addDays($days)->toDateString()]);
    }
}
