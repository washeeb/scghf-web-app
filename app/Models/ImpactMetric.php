<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToDivision;
use App\Support\DisclosureControl;
use App\Support\Slug;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Something the foundation counts.
 *
 * Students supported, boreholes dug, screenings held. The figures themselves
 * live in `impact_metric_values` as a time series — a metric with a single
 * total column cannot answer "how did this year compare with last", which is
 * the only question anyone asks of an impact number.
 *
 * **`counts_people` is the compliance-critical flag.** A metric counting people
 * is subject to the minimum-group rule when published: "1 beneficiary supported
 * in Widower Support, Tamale, March 2026" identifies that person as surely as
 * printing their name would. A metric counting boreholes is not.
 *
 * @see DisclosureControl
 */
class ImpactMetric extends Model
{
    use BelongsToDivision;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    public const TYPE_INTEGER = 'integer';

    public const TYPE_DECIMAL = 'decimal';

    public const TYPE_MONEY = 'money';

    public const TYPE_PERCENTAGE = 'percentage';

    public const AGGREGATION_SUM = 'sum';

    public const AGGREGATION_AVERAGE = 'average';

    public const AGGREGATION_LATEST = 'latest';

    public const AGGREGATION_MAX = 'max';

    protected $fillable = [
        'division_id', 'project_id', 'name', 'slug', 'description', 'unit',
        'value_type', 'aggregation', 'counts_people', 'baseline_value', 'target_value',
        'icon', 'sort_order', 'is_public', 'is_featured',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'value_type' => self::TYPE_INTEGER,
        'aggregation' => self::AGGREGATION_SUM,
        'counts_people' => false,
        'sort_order' => 0,
        'is_public' => true,
        'is_featured' => false,
    ];

    protected function casts(): array
    {
        return [
            'counts_people' => 'boolean',
            'is_public' => 'boolean',
            'is_featured' => 'boolean',
            'baseline_value' => 'decimal:4',
            'target_value' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $metric): void {
            $metric->slug = Slug::for($metric->slug, $metric->name);
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

    /** @return HasMany<ImpactMetricValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(ImpactMetricValue::class)->orderBy('period_start');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    // ── Figures ──────────────────────────────────────────────────────────────

    /**
     * The headline figure across a period, combined per `aggregation`.
     *
     * Returns a raw float — `publishedTotal()` is what a public page should
     * call, because it applies the disclosure control this does not.
     */
    public function total(
        string|\DateTimeInterface|null $from = null,
        string|\DateTimeInterface|null $to = null,
    ): float {
        /*
         * Built from the model rather than from `values()`, which carries an
         * ascending `orderBy('period_start')` for display. Appending
         * `orderByDesc` to that would not override it — the ascending clause
         * comes first and wins — so `latest` would quietly return the EARLIEST
         * figure. Starting clean makes the ordering here the only ordering.
         */
        $query = ImpactMetricValue::query()->where('impact_metric_id', $this->getKey());

        if ($from !== null) {
            $query->whereDate('period_start', '>=', $from);
        }

        if ($to !== null) {
            $query->whereDate('period_start', '<=', $to);
        }

        return (float) match ($this->aggregation) {
            self::AGGREGATION_AVERAGE => $query->avg('value') ?? 0,
            self::AGGREGATION_MAX => $query->max('value') ?? 0,
            self::AGGREGATION_LATEST => $query->orderByDesc('period_start')->value('value') ?? 0,
            default => $query->sum('value'),
        };
    }

    /**
     * The figure as it may be PUBLISHED, or null where it must be suppressed.
     *
     * The only method a public page should call. A metric counting people whose
     * figure falls below the minimum group size returns null, because a count
     * of one or two in a sensitive category singles those people out — and the
     * foundation's categories include health, orphan status and widowhood.
     *
     * A metric counting things is never suppressed: one borehole is one
     * borehole.
     */
    public function publishedTotal(
        string|\DateTimeInterface|null $from = null,
        string|\DateTimeInterface|null $to = null,
    ): ?float {
        $total = $this->total($from, $to);

        if (! $this->counts_people) {
            return $total;
        }

        return app(DisclosureControl::class)->permits((int) $total) ? $total : null;
    }

    /** Formatted for display, honouring the value type. */
    public function format(?float $value): string
    {
        if ($value === null) {
            return DisclosureControl::NOTICE;
        }

        return match ($this->value_type) {
            self::TYPE_MONEY => Money::ofMinor((int) $value)->format(),
            self::TYPE_PERCENTAGE => rtrim(rtrim(number_format($value, 1), '0'), '.').'%',
            self::TYPE_DECIMAL => number_format($value, 2),
            default => number_format($value).($this->unit ? ' '.$this->unit : ''),
        };
    }

    public function progressToTarget(): ?int
    {
        if ($this->target_value === null || (float) $this->target_value === 0.0) {
            return null;
        }

        return (int) round($this->total() / (float) $this->target_value * 100);
    }

    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('is_public', true)->orderBy('sort_order')->orderBy('name');
    }
}
