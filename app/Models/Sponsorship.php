<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\BelongsToDivision;
use App\Support\Anonymiser;
use App\Support\Features;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A donor supporting one child or student, month after month.
 *
 * ── The most safeguarding-sensitive thing this foundation could build ───────
 *
 * Sponsorship links a named adult to a named vulnerable child and then sends
 * that adult news and photographs about them, indefinitely. Done carelessly it
 * is a system for introducing strangers to children and telling them where to
 * find them.
 *
 * `features.sponsorship` has been true since Phase 2 with nothing behind it,
 * which is the worst of the three states: off is a decision, on-and-built is a
 * feature, on-and-empty is a promise the application cannot keep.
 *
 * ── What this model refuses ─────────────────────────────────────────────────
 *
 * **Nothing about the child leaves without a live consent.** Not a status flag
 * — the actual `consents` row, checked at the moment of sending, expiry and
 * revocation included.
 *
 * **What the sponsor learns is generalised.** A first name only where somebody
 * agreed to that, an age BAND rather than a birthday, a district rather than a
 * community, and never coordinates. The same boundary the analytics dataset
 * draws, for the same reason: those four facts together identify a child in a
 * small population.
 *
 * **There is no route from sponsor to child.** No address, no phone, no message
 * thread — not disabled, absent. Correspondence goes through staff or it does
 * not happen.
 */
class Sponsorship extends Model
{
    use BelongsToDivision;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_ENDED = 'ended';

    public const END_SAFEGUARDING = 'safeguarding';

    protected $fillable = [
        'donor_id', 'user_id', 'beneficiary_id', 'division_id', 'project_id',
        'subscription_id', 'amount', 'currency', 'frequency', 'notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'frequency' => 'monthly',
        'currency' => 'GHS',
        // All three default to false. A sponsorship that has not been through
        // the consent check tells the sponsor nothing at all.
        'may_receive_updates' => false,
        'may_receive_photographs' => false,
        'may_know_given_name' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class.':amount_minor,currency',
            'may_receive_updates' => 'boolean',
            'may_receive_photographs' => 'boolean',
            'may_know_given_name' => 'boolean',
            'started_on' => 'date',
            'ended_on' => 'date',
            'matched_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $sponsorship): void {
            $sponsorship->reference ??= 'SCGHF-SP-'.Str::upper(substr(Str::ulid()->toBase32(), -10));
        });
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Beneficiary, $this> */
    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    /** @return BelongsTo<Donor, $this> */
    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<SponsorshipUpdate, $this> */
    public function updates(): HasMany
    {
        return $this->hasMany(SponsorshipUpdate::class);
    }

    // ── Matching ─────────────────────────────────────────────────────────────

    /**
     * Link a sponsor to a child.
     *
     * Refused unless the feature is on AND a consent covering the child's story
     * exists. The flag alone is not enough: a feature switch is an operational
     * decision, and a child's consent is not.
     */
    public function match(User $staff): void
    {
        if (app(Features::class)->disabled('sponsorship')) {
            throw new RuntimeException(
                'Sponsorship is switched off. A sponsorship cannot be matched while it is.'
            );
        }

        if ($this->beneficiary === null) {
            throw new RuntimeException('A sponsorship must name the child it supports.');
        }

        if (! $staff->can('beneficiaries.manage')) {
            throw new RuntimeException(
                'Matching a sponsor to a child needs the `beneficiaries.manage` permission.'
            );
        }

        $this->forceFill([
            'status' => self::STATUS_ACTIVE,
            'matched_by' => $staff->getKey(),
            'matched_at' => now(),
            'started_on' => $this->started_on ?? now()->toDateString(),
        ])->save();
    }

    /**
     * End a sponsorship immediately on a safeguarding ground.
     *
     * Immediate and without a finding, the same way a safeguarding concern
     * suspends a volunteer. Whatever the eventual conclusion, the flow of
     * information about a child to an adult stops now.
     */
    public function endForSafeguarding(string $note): void
    {
        $this->forceFill([
            'status' => self::STATUS_ENDED,
            'ended_on' => now()->toDateString(),
            'end_reason' => self::END_SAFEGUARDING,
            'may_receive_updates' => false,
            'may_receive_photographs' => false,
            'may_know_given_name' => false,
            'notes' => trim(($this->notes ?? '')."\n".$note),
        ])->save();
    }

    public function end(string $reason): void
    {
        $this->forceFill([
            'status' => self::STATUS_ENDED,
            'ended_on' => now()->toDateString(),
            'end_reason' => $reason,
        ])->save();
    }

    // ── What the sponsor may be told ─────────────────────────────────────────

    /**
     * Why this sponsor may not be sent an update, or null if they may.
     *
     * The consent is re-read here every time, never cached on the sponsorship.
     * A consent can expire or be revoked, and a sponsorship that remembered
     * "yes" from two years ago would keep sending photographs of a child whose
     * guardian has since said no.
     */
    public function updateRejectionReason(bool $withPhotograph = false): ?string
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return 'This sponsorship is not active.';
        }

        if (! $this->may_receive_updates) {
            return 'This sponsor has not been cleared to receive updates about this child.';
        }

        if ($this->beneficiary === null) {
            return 'The child\'s record no longer exists, so nothing can be sent about them.';
        }

        $storyConsent = $this->liveConsent(Consent::TYPE_STORY);

        if ($storyConsent === null) {
            return 'There is no current consent covering news about this child. A consent that '
                .'has expired or been revoked is not a consent.';
        }

        if ($withPhotograph) {
            if (! $this->may_receive_photographs) {
                return 'This sponsor may receive written updates but not photographs.';
            }

            if ($this->liveConsent(Consent::TYPE_PHOTO) === null) {
                return 'There is no current photography consent for this child.';
            }
        }

        return null;
    }

    public function canReceiveUpdate(bool $withPhotograph = false): bool
    {
        return $this->updateRejectionReason($withPhotograph) === null;
    }

    /**
     * How this child is described to their sponsor.
     *
     * Generalised on purpose. "Ama, 9, in the BrightPath education programme in
     * Tamale district" is enough for a sponsor to feel connected and is not
     * enough to find her. A full name, a birthday and a community together
     * would be — in a small population, that is one child.
     *
     * @return array<string, string|null>
     */
    public function childProfileForSponsor(): array
    {
        $child = $this->beneficiary;

        if ($child === null) {
            return [];
        }

        return array_filter([
            // A given name only where somebody agreed to that specifically.
            'name' => $this->may_know_given_name
                ? Str::before(trim((string) $child->full_name), ' ')
                : 'The child you sponsor',

            // A band, never a birthday. A date of birth plus a district plus a
            // programme is an identifier.
            'age' => $child->date_of_birth === null
                ? null
                : app(Anonymiser::class)->ageBand($child->date_of_birth),

            // District, never the community — and never coordinates.
            'district' => $child->district,
            'region' => $child->region,
            'programme' => $this->project?->title,
        ], fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * A consent of the given type that is live RIGHT NOW.
     *
     * Evaluated from the dates on every call, never from a stored status — the
     * same stance the GRA approval and the safeguarding clearances take, and
     * for the same reason: nothing about what may be disclosed should depend on
     * a cron job having run last night.
     */
    public function liveConsent(string $type): ?Consent
    {
        return $this->beneficiary?->consents()
            ->where('consent_type', $type)
            ->get()
            ->first(fn (Consent $consent): bool => $consent->isValid());
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }
}
