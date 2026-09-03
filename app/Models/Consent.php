<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasConsents;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A recorded permission to use somebody's photograph, story, video or name.
 *
 * The table that keeps the foundation out of trouble. Photographs of
 * beneficiaries — especially children — are the highest-risk content it
 * publishes, and "we asked them" is not a defence anyone can produce two years
 * later.
 *
 * Validity is evaluated FROM THE DATES on every call, never from a stored
 * status, for the same reason a GRA approval is: nothing about what may be
 * published should depend on a cron job having run last night.
 *
 * @see HasConsents
 */
class Consent extends Model
{
    use HasFactory;
    use HasUlids;

    public const TYPE_PHOTO = 'photo';

    public const TYPE_STORY = 'story';

    public const TYPE_VIDEO = 'video';

    public const TYPE_NAME_USE = 'name_use';

    public const TYPE_DATA_PROCESSING = 'data_processing';

    public const SCOPE_WEBSITE = 'website';

    public const SCOPE_PRINT = 'print';

    public const SCOPE_SOCIAL = 'social';

    public const SCOPE_ALL = 'all';

    public const BY_SELF = 'self';

    public const BY_PARENT = 'parent';

    public const BY_GUARDIAN = 'guardian';

    public const BY_NEXT_OF_KIN = 'next_of_kin';

    protected $fillable = [
        'consentable_type', 'consentable_id', 'consent_type', 'scope',
        'granted_by_name', 'granted_by_relationship', 'is_minor', 'guardian_name',
        'granted_at', 'expires_at', 'revoked_at', 'revoked_reason',
        'evidence_media_id', 'notes', 'captured_ip', 'recorded_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'scope' => self::SCOPE_WEBSITE,
        'granted_by_relationship' => self::BY_SELF,
        'is_minor' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_minor' => 'boolean',
            'granted_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $consent): void {
            $consent->granted_at ??= now();

            /*
             * A child cannot consent for themselves, and an unnamed guardian is
             * not evidence of anything. Refusing here rather than validating in
             * a form means the rule holds however the row was created — an
             * import, a console command, a seeder.
             */
            if ($consent->is_minor && blank($consent->guardian_name)) {
                throw new RuntimeException(
                    'Consent for a minor must name the parent or guardian who gave it. '
                    .'Publishing a photograph of a child on an unattributed consent is the '
                    .'single riskiest thing this system can do.'
                );
            }

            if ($consent->is_minor && $consent->granted_by_relationship === self::BY_SELF) {
                throw new RuntimeException(
                    'A minor cannot give consent on their own behalf. Record the parent or '
                    .'guardian as the person who gave it.'
                );
            }

            if ($consent->expires_at !== null && $consent->expires_at->lte($consent->granted_at)) {
                throw new RuntimeException('Consent cannot expire on or before it was granted.');
            }
        });
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /** @return MorphTo<Model, $this> */
    public function consentable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Media, $this> */
    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'evidence_media_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Whether this consent authorises publication right now.
     *
     * Revoked beats everything, including a future expiry date: somebody
     * withdrawing consent is an instruction, not a suggestion.
     */
    public function isValid(?\DateTimeInterface $on = null): bool
    {
        $on = $on ? Carbon::instance($on) : now();

        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->granted_at === null || $this->granted_at->gt($on)) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->gt($on);
    }

    /** Whether this consent covers a given publication channel. */
    public function coversScope(string $scope): bool
    {
        return $this->scope === self::SCOPE_ALL || $this->scope === $scope;
    }

    public function revoke(string $reason = '', ?User $by = null): void
    {
        $this->forceFill([
            'revoked_at' => now(),
            'revoked_reason' => $reason !== '' ? $reason : null,
        ])->save();
    }

    /** Consents that authorise publication as of now. */
    #[Scope]
    protected function valid(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where('granted_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** Consents nearing expiry, so they can be renewed before content goes dark. */
    #[Scope]
    protected function expiringWithin(Builder $query, int $days): void
    {
        $query->whereNull('revoked_at')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays($days)]);
    }
}
