<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\BelongsToDivision;
use App\Models\Concerns\RecordsAuthor;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One grant: an idea, an application, an award, or a closed file.
 *
 * ── The pipeline ────────────────────────────────────────────────────────────
 *
 *   idea → drafting → submitted → awarded → closed
 *                               ↘ declined
 *
 * `submit()`, `award()`, `decline()` and `close()` move it and stamp the
 * dates; an award without an amount is refused, because "we got the
 * grant" with no figure is a story, not a record.
 *
 * ── Money ───────────────────────────────────────────────────────────────────
 *
 * Amounts are integer pesewas through MoneyCast. **Spend against the
 * grant is read from the ledger**, not typed: `spent()` sums the paid
 * payouts charged to this grant (`payouts.grant_id`), `committed()` the
 * approved ones not yet paid, `remaining()` what is left of the award. A
 * restricted grant (the default) is money the funder gave for one thing;
 * the flag is shown wherever the balance is.
 *
 * @property Money|null $amount_requested
 * @property Money|null $amount_awarded
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class Grant extends Model
{
    use BelongsToDivision;
    use HasFactory;
    use HasUlids;
    use LogsActivity;
    use RecordsAuthor;

    public const STATUS_IDEA = 'idea';

    public const STATUS_DRAFTING = 'drafting';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_AWARDED = 'awarded';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_IDEA => 'Idea',
        self::STATUS_DRAFTING => 'Drafting',
        self::STATUS_SUBMITTED => 'Submitted',
        self::STATUS_AWARDED => 'Awarded',
        self::STATUS_DECLINED => 'Declined',
        self::STATUS_CLOSED => 'Closed',
    ];

    protected $fillable = [
        'funder_id', 'project_id', 'division_id', 'title', 'funder_reference', 'status',
        'amount_requested', 'amount_awarded', 'currency', 'is_restricted',
        'deadline_on', 'submitted_on', 'decided_on', 'starts_on', 'ends_on',
        'purpose', 'notes', 'owner_id', 'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_IDEA,
        'currency' => 'GHS',
        'is_restricted' => true,
    ];

    protected function casts(): array
    {
        return [
            'amount_requested' => MoneyCast::class.':amount_requested_minor,currency',
            'amount_awarded' => MoneyCast::class.':amount_awarded_minor,currency',
            'is_restricted' => 'boolean',
            'deadline_on' => 'date',
            'submitted_on' => 'date',
            'decided_on' => 'date',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
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

    // ── Relationships ────────────────────────────────────────────────────────

    /** @return BelongsTo<Funder, $this> */
    public function funder(): BelongsTo
    {
        return $this->belongsTo(Funder::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<GrantObligation, $this> */
    public function obligations(): HasMany
    {
        return $this->hasMany(GrantObligation::class)->orderBy('due_on');
    }

    /** @return HasMany<GrantDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(GrantDocument::class)->orderByDesc('created_at');
    }

    /** @return HasMany<Payout, $this> */
    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    // ── The pipeline ─────────────────────────────────────────────────────────

    public function submit(): void
    {
        $this->forceFill(['status' => self::STATUS_SUBMITTED, 'submitted_on' => $this->submitted_on ?? now()->toDateString()])->save();
    }

    public function award(Money $amount, ?string $startsOn = null, ?string $endsOn = null): void
    {
        if (! $amount->isPositive()) {
            throw new RuntimeException('An award needs the amount awarded. "We got the grant" with no figure is a story, not a record.');
        }

        $this->forceFill([
            'status' => self::STATUS_AWARDED,
            'amount_awarded' => $amount,
            'decided_on' => now()->toDateString(),
            'starts_on' => $startsOn ?? $this->starts_on,
            'ends_on' => $endsOn ?? $this->ends_on,
        ])->save();
    }

    public function decline(): void
    {
        $this->forceFill(['status' => self::STATUS_DECLINED, 'decided_on' => now()->toDateString()])->save();
    }

    public function close(): void
    {
        $this->forceFill(['status' => self::STATUS_CLOSED])->save();
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_DECLINED, self::STATUS_CLOSED], true);
    }

    // ── Money against the award ──────────────────────────────────────────────

    /** Paid payouts charged to this grant. */
    public function spent(): Money
    {
        return Money::ofMinor((int) $this->payouts()->where('status', Payout::STATUS_PAID)->sum('amount_minor'), $this->currency);
    }

    /** Approved but not yet paid. */
    public function committed(): Money
    {
        return Money::ofMinor((int) $this->payouts()->where('status', Payout::STATUS_APPROVED)->sum('amount_minor'), $this->currency);
    }

    /** What is left of the award after what is paid and what is committed. Null with no award. */
    public function remaining(): ?Money
    {
        $awarded = $this->amount_awarded;

        if ($awarded === null) {
            return null;
        }

        return $awarded->minus($this->spent())->minus($this->committed());
    }

    /** Obligations not done and due within the window (or overdue). */
    public function obligationsDue(int $days = 14): HasMany
    {
        return $this->obligations()->whereNull('completed_on')->whereDate('due_on', '<=', now()->addDays($days));
    }

    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNotIn('status', [self::STATUS_DECLINED, self::STATUS_CLOSED]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'amount_requested_minor', 'amount_awarded_minor', 'deadline_on', 'project_id', 'owner_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
