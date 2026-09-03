<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Division;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Content scoped to one division, or to the foundation as a whole.
 *
 * `division_id` is nullable everywhere it appears, and **null means
 * foundation-wide, not unknown**. A testimonial about the foundation in general
 * is not missing its division; it genuinely belongs to all of them. That is why
 * `forDivision()` includes the null rows by default — a division page that
 * hides foundation-wide content shows less than it should.
 */
trait BelongsToDivision
{
    /** @return BelongsTo<Division, $this> */
    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    /** Whether this record belongs to the whole foundation rather than one division. */
    public function isFoundationWide(): bool
    {
        return $this->division_id === null;
    }

    /**
     * Rows for a division, plus the foundation-wide ones.
     *
     * Pass `$includeShared: false` for an administrative listing where "which
     * rows are actually tagged to this division" is the question being asked.
     */
    #[Scope]
    protected function forDivision(Builder $query, Division|int|null $division, bool $includeShared = true): void
    {
        $id = $division instanceof Division ? $division->getKey() : $division;

        if ($id === null) {
            $query->whereNull('division_id');

            return;
        }

        $query->where(function (Builder $q) use ($id, $includeShared): void {
            $q->where('division_id', $id);

            if ($includeShared) {
                $q->orWhereNull('division_id');
            }
        });
    }
}
