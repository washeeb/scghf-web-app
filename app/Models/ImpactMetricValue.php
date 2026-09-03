<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Anonymiser;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One figure, for one metric, over one period.
 *
 * Carries no personal data by construction — a count and a period, nothing
 * else — which is why it falls under the `anonymised_statistics` retention
 * class and is kept indefinitely. Act 843's retention limits do not bite on
 * data that identifies nobody.
 *
 * That property is not automatic; it holds only as long as the periods stay
 * coarse enough. `period_start` is a date, and the reporting layer generalises
 * it further through Anonymiser before publication.
 */
class ImpactMetricValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'impact_metric_id', 'period_start', 'period_end', 'value',
        'notes', 'source', 'recorded_by', 'verified_at', 'verified_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'value' => 'decimal:4',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ImpactMetric, $this> */
    public function metric(): BelongsTo
    {
        return $this->belongsTo(ImpactMetric::class, 'impact_metric_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<User, $this> */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function verify(User $by): void
    {
        $this->forceFill([
            'verified_at' => now(),
            'verified_by' => $by->getKey(),
        ])->save();
    }

    /**
     * The period as a coarse label — "2026-03", "2026-Q1", "2026".
     *
     * Goes through Anonymiser rather than formatting the date here, so the
     * granularity used in impact reporting is the same one the privacy policy
     * sets, and changing it changes both together.
     */
    public function periodLabel(): ?string
    {
        return app(Anonymiser::class)->period($this->period_start);
    }

    /** Values that have been checked by someone. */
    #[Scope]
    protected function verified(Builder $query): void
    {
        $query->whereNotNull('verified_at');
    }
}
