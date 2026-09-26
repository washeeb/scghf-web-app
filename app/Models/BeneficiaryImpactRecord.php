<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToDivision;
use App\Support\DisclosureControl;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * The anonymous statistical record of an assisted beneficiary.
 *
 * A separate dataset from `beneficiaries`, on purpose. It holds a division, a
 * programme, a coarse geography, a gender, an age band, an assistance band, a
 * period and a broad outcome — and nothing else. No name, no contact details,
 * no identifiers, no exact address, no source documents, no case notes, no
 * payment references, no exact amount and no exact date.
 *
 * `source_beneficiary_id` is `ON DELETE SET NULL`. While the case record
 * lawfully exists this row is pseudonymous, which is fine; when the retention
 * period expires and that record is destroyed, the linkage goes with it in the
 * same statement. After that there is no practical means of reconnecting this
 * row to a person — not a hash, not an encrypted id, nothing — which is what
 * separates genuine de-identification from pseudonymisation.
 *
 * Falls under the `anonymised_statistics` retention class and is kept
 * indefinitely: Act 843's retention limits do not bite on data identifying
 * nobody, and the Act expressly permits statistical and historical retention.
 *
 * **Every published breakdown goes through DisclosureControl.** The rows here
 * are anonymous individually; a filter narrow enough to leave two of them is
 * not.
 */
class BeneficiaryImpactRecord extends Model
{
    use BelongsToDivision;
    use HasFactory;

    protected $fillable = [
        'source_beneficiary_id', 'division_id', 'project_id', 'focus_area_id',
        'region', 'district', 'gender', 'age_band',
        'assistance_band', 'assistance_period', 'outcome',
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<FocusArea, $this> */
    public function focusArea(): BelongsTo
    {
        return $this->belongsTo(FocusArea::class);
    }

    /** Whether the identifiable case record behind this row still exists. */
    public function isStillLinked(): bool
    {
        return $this->source_beneficiary_id !== null;
    }

    /**
     * A breakdown, with small groups suppressed.
     *
     * The ONLY method that should feed a public impact page or an export.
     * Grouping by district and period on a small population routinely produces
     * cells of one or two, and in categories like widower support or health
     * that cell is a person.
     *
     * @param  array<int, string>  $dimensions  columns to group by
     * @return Collection<int, array<string, mixed>>
     */
    public static function breakdown(array $dimensions, ?callable $filter = null): Collection
    {
        $query = static::query()
            ->selectRaw(implode(', ', $dimensions).', COUNT(*) as count')
            ->groupBy($dimensions);

        if ($filter !== null) {
            $filter($query);
        }

        $rows = $query->get()->map(fn (Model $row): array => $row->getAttributes());

        return app(DisclosureControl::class)->apply($rows);
    }

    /** Records for a period label such as "2026-03". */
    #[Scope]
    protected function inPeriod(Builder $query, string $period): void
    {
        $query->where('assistance_period', $period);
    }
}
