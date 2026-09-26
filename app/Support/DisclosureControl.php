<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Suppresses statistical cells too small to be safe to publish.
 *
 * The risk this exists for: the Foundation works with small populations in
 * sensitive categories — health, orphan status, widow and widower support,
 * evangelism. A public impact breakdown that filters down to "Ashanti /
 * Widower Support / 2026-03: 1 beneficiary" has just told the reader something
 * about one identifiable person, even though no name was published and every
 * identifier was stripped.
 *
 * So any published or exported breakdown passes through here first, and a cell
 * below the minimum group size is reported as suppressed rather than shown.
 *
 * The threshold is not a figure mandated by Act 843. It is a disclosure control
 * that reduces singling-out risk, set in config so it can be raised for a
 * particularly sensitive report without touching code.
 */
final class DisclosureControl
{
    public const SUPPRESSED = null;

    /** The label shown in place of a suppressed figure. */
    public const NOTICE = 'Suppressed (group too small to publish)';

    public function minimumGroupSize(): int
    {
        return (int) config('compliance.privacy.minimum_group_size', 5);
    }

    /** Whether a group of this size may be published at all. */
    public function permits(int $count): bool
    {
        // Zero is publishable: "no beneficiaries in this category" identifies
        // nobody. It is the small non-zero counts that single people out.
        return $count === 0 || $count >= $this->minimumGroupSize();
    }

    /** A count, or null where the group is too small to disclose. */
    public function count(int $count): ?int
    {
        return $this->permits($count) ? $count : self::SUPPRESSED;
    }

    /**
     * Suppress small cells across a set of aggregate rows.
     *
     * Every measure on a suppressed row is nulled, not just the count — a row
     * showing "beneficiaries: suppressed, total assistance: GHS 4,735" has
     * disclosed the individual amount anyway.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  string  $countKey  the column holding the group size
     * @param  array<int, string>  $measures  other columns to null when suppressed
     * @return Collection<int, array<string, mixed>>
     */
    public function apply(Collection $rows, string $countKey = 'count', array $measures = []): Collection
    {
        return $rows->map(function (array $row) use ($countKey, $measures): array {
            $count = (int) ($row[$countKey] ?? 0);

            if ($this->permits($count)) {
                return $row + ['suppressed' => false];
            }

            $row[$countKey] = self::SUPPRESSED;

            foreach ($measures as $measure) {
                $row[$measure] = self::SUPPRESSED;
            }

            return $row + ['suppressed' => true];
        });
    }

    /**
     * Whether a breakdown is safe to publish as a whole.
     *
     * Useful as a guard before exporting: if most cells would be suppressed the
     * breakdown is too fine-grained to be worth publishing at all, and a
     * coarser one should be produced instead.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    public function isPublishable(Collection $rows, string $countKey = 'count'): bool
    {
        if ($rows->isEmpty()) {
            return true;
        }

        return $rows->every(fn (array $row): bool => $this->permits((int) ($row[$countKey] ?? 0)));
    }
}
