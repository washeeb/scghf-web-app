<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\CauseStatus;
use App\Models\Concerns\BelongsToDivision;
use App\Models\Concerns\HasSeo;
use App\Models\Concerns\RecordsAuthor;
use App\Support\TaxDeductibility;
use App\ValueObjects\Money;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A fundraising appeal — the destination of a donation.
 *
 * Separate from `Project`, which is the work. A cause can fund several
 * projects; a project can run with no appeal behind it.
 *
 * Exactly one cause is the GENERAL FUND: seeded, locked, and the fallback so
 * every gift has a destination even when the donor chose no appeal. Module 4's
 * `donations.cause_id` is NOT NULL, and this is what makes that safe.
 *
 * @property CauseStatus $status
 */
class Cause extends Model
{
    use BelongsToDivision;
    use HasFactory;
    use HasSeo;
    use HasUlids;
    use LogsActivity;
    use RecordsAuthor;
    use SoftDeletes;

    protected $fillable = [
        'division_id', 'project_id', 'title', 'slug', 'summary', 'description',
        'goal', 'currency', 'starts_on', 'ends_on', 'status',
        'giving_levels', 'min_donation_minor', 'is_urgent',
        'goal_reached_behaviour', 'redirect_cause_id', 'fund_code',
        'is_tax_deductible', 'tax_approval_id', 'allow_recurring', 'allow_fee_cover',
        'featured_image_id', 'is_featured', 'is_published', 'published_at', 'sort_order',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'currency' => 'GHS',
        'raised_minor' => 0,
        'donation_count' => 0,
        'is_general_fund' => false,
        'is_locked' => false,
        'is_tax_deductible' => false,
        'allow_recurring' => true,
        'allow_fee_cover' => true,
        'is_featured' => false,
        'is_published' => false,
        'sort_order' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CauseStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'published_at' => 'datetime',
            'is_general_fund' => 'boolean',
            'is_locked' => 'boolean',
            'is_tax_deductible' => 'boolean',
            'allow_recurring' => 'boolean',
            'allow_fee_cover' => 'boolean',
            'is_featured' => 'boolean',
            'is_published' => 'boolean',
            'giving_levels' => 'array',
            'is_urgent' => 'boolean',
            'goal' => MoneyCast::class.':goal_minor,currency',
            'raised' => MoneyCast::class.':raised_minor,currency',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $cause): void {
            if (blank($cause->slug)) {
                $cause->slug = Str::slug($cause->title);
            }

            $cause->slug = Str::slug($cause->slug);
        });

        static::deleting(function (self $cause): void {
            if ($cause->is_locked) {
                throw new RuntimeException(
                    "The [{$cause->title}] cause cannot be deleted. It is the fallback "
                    .'destination for donations made without a chosen appeal, and every gift '
                    .'must have somewhere to go.'
                );
            }
        });
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

    // ── Relationships ────────────────────────────────────────────────────────

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<CauseUpdate, $this> */
    public function updates(): HasMany
    {
        return $this->hasMany(CauseUpdate::class);
    }

    /** The s.100 approval covering this cause specifically, if there is one. */
    public function taxApproval(): BelongsTo
    {
        return $this->belongsTo(TaxApproval::class, 'tax_approval_id');
    }

    /** @return BelongsTo<Media, $this> */
    public function featuredImage(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'featured_image_id');
    }

    // ── The General Fund ─────────────────────────────────────────────────────

    /**
     * The fallback destination for a gift with no chosen appeal.
     *
     * Throws rather than returning null. A donation reaching the point of
     * needing a destination and finding none is a payment the foundation has
     * taken and cannot account for, which is the one failure mode worth
     * stopping the request over.
     */
    public static function generalFund(): self
    {
        return static::query()->where('is_general_fund', true)->first()
            ?? throw new RuntimeException(
                'No General Fund cause exists. Run CauseSeeder — every donation needs a '
                .'destination, and this is the one that catches gifts with no appeal chosen.'
            );
    }

    // ── Progress ─────────────────────────────────────────────────────────────

    public function raisedAmount(): Money
    {
        return $this->raised ?? Money::zero($this->currency);
    }

    /**
     * Fold a completed gift into the appeal total.
     *
     * Incremented atomically in SQL — `UPDATE ... SET raised_minor =
     * raised_minor + ?` — never read-then-written. A read-modify-write loses
     * money under concurrency, and two gifts landing in the same second is
     * exactly when it matters.
     *
     * Safe to increment rather than recompute because donations are
     * append-only: nothing is ever edited or removed, so the running total
     * cannot drift away from the rows behind it.
     */
    public function recordDonation(Donation $donation): void
    {
        static::whereKey($this->getKey())->update([
            'raised_minor' => DB::raw('raised_minor + '.(int) $donation->amount->toMinor()),
            'donation_count' => DB::raw('donation_count + 1'),
            'updated_at' => now(),
        ]);
    }

    /**
     * Recompute the total from the donations themselves.
     *
     * For reconciliation and for repairing a total after an offline gift is
     * corrected — not for the hot path, which increments. Counts only completed
     * gifts, so a refunded donation drops out and the appeal stops overstating
     * what it raised.
     */
    public function recalculateRaised(): Money
    {
        $minor = (int) Donation::query()
            ->where('cause_id', $this->getKey())
            ->completed()
            ->sum('amount_minor');

        $count = Donation::query()->where('cause_id', $this->getKey())->completed()->count();

        static::whereKey($this->getKey())->update([
            'raised_minor' => $minor,
            'donation_count' => $count,
        ]);

        return Money::ofMinor($minor, $this->currency);
    }

    /**
     * Percentage of the target raised, or null where there is no target.
     *
     * Not capped at 100. An appeal that raised 140% of its goal should say so —
     * clamping it hides the best news the page has.
     */
    public function progressPercent(): ?int
    {
        $goal = $this->goal;

        if ($goal === null || $goal->isZero()) {
            return null;
        }

        return (int) round($this->raisedAmount()->toMinor() / $goal->toMinor() * 100);
    }

    public function remaining(): ?Money
    {
        $goal = $this->goal;

        if ($goal === null) {
            return null;
        }

        $raised = $this->raisedAmount();

        return $raised->greaterThanOrEqual($goal) ? Money::zero($this->currency) : $goal->minus($raised);
    }

    /**
     * Whether this appeal may take a donation right now.
     *
     * Status, publication and the closing date all have to agree. An appeal
     * past its end date still accepting money is how a foundation ends up
     * holding funds it announced it had stopped raising.
     */
    public function acceptsDonations(): bool
    {
        if (! $this->status->acceptsDonations() || ! $this->is_published) {
            return false;
        }

        if ($this->starts_on !== null && $this->starts_on->isFuture()) {
            return false;
        }

        if ($this->ends_on !== null && ! $this->ends_on->endOfDay()->isFuture()) {
            return false;
        }

        return ! $this->isClosedByReachingItsGoal();
    }

    // ── Reaching the goal ────────────────────────────────────────────────────

    /** Keep taking money past the target. The default, and see the note below. */
    public const ON_GOAL_CONTINUE = 'continue';

    /** Stop taking money the moment the target is met. */
    public const ON_GOAL_CLOSE = 'close';

    /** Send further giving to another appeal. */
    public const ON_GOAL_REDIRECT = 'redirect';

    public function hasReachedItsGoal(): bool
    {
        $goal = $this->goal;

        return $goal !== null && ! $goal->isZero() && $this->raisedAmount()->greaterThanOrEqual($goal);
    }

    /**
     * Whether the goal being met has actually stopped this appeal.
     *
     * ── The default is to KEEP ACCEPTING, and that is a decision ────────────
     *
     * A foundation that hits its target and then refuses money is leaving gifts
     * on the table, and a donor who has already decided to give is not somebody
     * to turn away at the last step. What must not happen is taking money
     * SILENTLY against a goal that is met — so the appeal page says the target
     * has been reached whatever this returns.
     *
     * Closing is for the appeals where continuing would be wrong: a specific,
     * funded, finite thing where more money cannot buy more of it. Redirecting
     * is for when there is somewhere better for it to go.
     */
    public function isClosedByReachingItsGoal(): bool
    {
        if (! $this->hasReachedItsGoal()) {
            return false;
        }

        return in_array(
            $this->goal_reached_behaviour,
            [self::ON_GOAL_CLOSE, self::ON_GOAL_REDIRECT],
            true,
        );
    }

    /**
     * Where a donor should be sent instead, if anywhere.
     *
     * Null when this appeal is still taking money, when it is simply closed, or
     * when the appeal it points at has itself stopped accepting. That last
     * check matters: a redirect chain into a second full appeal would bounce a
     * donor between two pages that both decline their gift.
     */
    public function redirectTarget(): ?self
    {
        if ($this->goal_reached_behaviour !== self::ON_GOAL_REDIRECT || ! $this->hasReachedItsGoal()) {
            return null;
        }

        $target = $this->redirect_cause_id === null
            ? null
            : static::query()->find($this->redirect_cause_id);

        return $target?->acceptsDonations() === true ? $target : null;
    }

    // ── Giving levels ────────────────────────────────────────────────────────

    /**
     * The suggested amounts for THIS appeal, with what each one buys.
     *
     * "GH₵ 50 provides a school kit for one child" raises materially more than
     * a blank amount box, because it answers the question a hesitant donor is
     * actually asking — not "how much should I give?" but "what does my money
     * do?".
     *
     * A malformed level is dropped rather than thrown on: this is JSON edited
     * through a form, and one bad row must not take down the page the
     * foundation raises money on.
     *
     * @return Collection<int, array{amount: Money, label: string, description: ?string}>
     */
    public function givingLevels(): Collection
    {
        return collect($this->giving_levels ?? [])
            ->filter(fn (mixed $level): bool => is_array($level)
                && is_numeric($level['amount_minor'] ?? null)
                && (int) $level['amount_minor'] > 0)
            ->map(fn (array $level): array => [
                'amount' => Money::ofMinor((int) $level['amount_minor'], $this->currency),
                'label' => (string) ($level['label'] ?? ''),
                'description' => ($level['description'] ?? null) ?: null,
            ])
            ->sortBy(fn (array $level): int => $level['amount']->toMinor())
            ->values();
    }

    /**
     * The smallest gift this appeal accepts.
     *
     * The appeal's own floor where it has one, and the site floor otherwise.
     * The site floor exists for a commercial reason — a gift smaller than the
     * transaction fee costs money to accept — and an appeal's own is editorial:
     * the smallest gift that buys anything in that appeal's terms.
     */
    public function minimumDonation(): ?Money
    {
        if ($this->min_donation_minor !== null) {
            return Money::ofMinor((int) $this->min_donation_minor, $this->currency);
        }

        $siteFloor = setting('donations.min_amount');

        return $siteFloor instanceof Money ? $siteFloor : null;
    }

    // ── Tax ──────────────────────────────────────────────────────────────────

    /**
     * Whether deductibility wording may be shown for this cause.
     *
     * Delegates to the single gate rather than reading `is_tax_deductible`
     * directly. The flag on this row says the trustees consider this a
     * qualifying worthwhile cause; it does NOT say the foundation holds the GRA
     * approval that makes the claim sayable. Reading the column here would be
     * exactly the shortcut that puts an unsupported claim in front of a donor.
     */
    public function qualifiesForTaxRelief(?DateTimeInterface $on = null): bool
    {
        return app(TaxDeductibility::class)->qualifies($this, $on);
    }

    public function isLive(): bool
    {
        return $this->is_published
            && $this->status->isPubliclyListable()
            && ($this->published_at === null || $this->published_at->isPast());
    }

    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where('is_published', true)
            ->whereIn('status', [
                CauseStatus::Active->value,
                CauseStatus::Paused->value,
                CauseStatus::Completed->value,
            ])
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    #[Scope]
    protected function accepting(Builder $query): void
    {
        $query->where('is_published', true)
            ->where('status', CauseStatus::Active->value)
            ->where(fn (Builder $q) => $q->whereNull('starts_on')->orWhereDate('starts_on', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', now()));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'title', 'slug', 'status', 'division_id', 'goal_minor',
                'is_published', 'is_tax_deductible', 'tax_approval_id',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('cause');
    }
}
