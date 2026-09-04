<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Features;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * A supporter's own fundraising page, routing donations to a cause.
 *
 * Behind `features.p2p_fundraising`, which is OFF. The table and model exist
 * because `donations.fundraiser_id` was promised in the Module 4 migration and
 * never delivered — a foreign key documented in a comment and absent from the
 * schema is worse than one nobody mentioned.
 *
 * ── Why it starts as pending_review ─────────────────────────────────────────
 *
 * A page carrying the foundation's name, telling a story about its work, and
 * taking money in its name is published BY the foundation — whatever the
 * supporter's intentions. The story might name a beneficiary. It might promise
 * something the foundation cannot deliver. It might be a person the foundation
 * would rather not be associated with.
 *
 * That exposure is the reason the whole feature is flagged off, and the review
 * gate is what would make turning it on defensible.
 */
class Fundraiser extends Model
{
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    public const STATUS_PENDING = 'pending_review';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'title', 'slug', 'story', 'user_id', 'cause_id',
        'goal', 'currency', 'ends_on', 'image_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'raised_minor' => 0,
        'donation_count' => 0,
        'currency' => 'GHS',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'goal' => MoneyCast::class.':goal_minor,currency',
            'raised' => MoneyCast::class.':raised_minor,currency',
            'ends_on' => 'date',
            'approved_at' => 'datetime',
            'donation_count' => 'integer',
        ];
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsTo<Cause, $this> */
    public function cause(): BelongsTo
    {
        return $this->belongsTo(Cause::class);
    }

    /** @return HasMany<Donation, $this> */
    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class);
    }

    /**
     * Whether this page may be shown at all.
     *
     * Reads the feature flag as well as the status, so a page approved while
     * the feature was on does not stay live after somebody turns it off. A
     * flag that only gates new records is a flag that does not gate anything.
     */
    public function isVisible(): bool
    {
        return app(Features::class)->enabled('p2p_fundraising')
            && $this->status === self::STATUS_ACTIVE;
    }

    public function approve(User $reviewer): void
    {
        if (! $reviewer->can('fundraisers.moderate')) {
            throw new RuntimeException(
                'Approving a supporter fundraising page needs the `fundraisers.moderate` '
                .'permission. The page carries the foundation\'s name, so the foundation '
                .'publishes it.'
            );
        }

        $this->forceFill([
            'status' => self::STATUS_ACTIVE,
            'approved_by' => $reviewer->getKey(),
            'approved_at' => now(),
        ])->save();
    }

    /**
     * Take a page down.
     *
     * Immediate and without a finding, the same way a safeguarding concern
     * suspends a volunteer: whatever the eventual conclusion, a page the
     * foundation is uneasy about should stop collecting money in its name now.
     */
    public function suspend(string $reason): void
    {
        $this->forceFill([
            'status' => self::STATUS_SUSPENDED,
            'suspension_reason' => $reason,
        ])->save();
    }

    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    #[Scope]
    protected function awaitingReview(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDING);
    }
}
