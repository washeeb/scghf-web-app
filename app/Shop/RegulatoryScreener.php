<?php

declare(strict_types=1);

namespace App\Shop;

/**
 * Screens a product against the goods the foundation may not simply list.
 *
 * Medicines, regulated medical products, supplements, food and cosmetics are
 * FDA Ghana's business. The foundation sells branded merchandise; the risk is
 * not that somebody deliberately lists a drug, it is that a well-meaning
 * volunteer lists "herbal tea" or "hand sanitiser for the outreach" and nobody
 * notices until a regulator does.
 *
 * So this errs heavily towards flagging. A false positive costs an editor
 * thirty seconds to record a review; a false negative costs the foundation its
 * standing with a regulator, and possibly its charitable status.
 *
 * It FLAGS, it does not block. A flagged product simply cannot be published
 * until somebody records that the review happened — which is a different thing
 * from the software deciding what may be sold.
 */
final class RegulatoryScreener
{
    /**
     * Keywords found in the text, or an empty array.
     *
     * Word-boundary matched, so "creamery" does not trip "cream" and "drugstore"
     * does trip "drug". Multi-word phrases are matched as phrases.
     *
     * @return array<int, string>
     */
    public function screen(string ...$text): array
    {
        $haystack = mb_strtolower(implode(' ', array_map(
            static fn (string $part): string => strip_tags($part),
            $text,
        )));

        if (trim($haystack) === '') {
            return [];
        }

        $found = [];

        foreach ((array) config('compliance.shop.prohibited_keywords', []) as $keyword) {
            $pattern = '/\b'.preg_quote(mb_strtolower((string) $keyword), '/').'\b/u';

            if (preg_match($pattern, $haystack) === 1) {
                $found[] = (string) $keyword;
            }
        }

        return array_values(array_unique($found));
    }

    public function isFlagged(string ...$text): bool
    {
        return $this->screen(...$text) !== [];
    }

    /** The message shown to an editor beside the flag. */
    public function notice(): string
    {
        return (string) config('compliance.shop.prohibited_notice');
    }

    /**
     * The approved category taxonomy, for seeding and for the admin.
     *
     * @return array<string, array{label: string, items: array<int, string>}>
     */
    public function approvedCategories(): array
    {
        return (array) config('compliance.shop.approved_categories', []);
    }

    /**
     * Whether a category key is part of the agreed taxonomy.
     *
     * A category outside it is not forbidden — the trustees can add one — but it
     * is visibly outside what was agreed, which is what a policy key is for.
     */
    public function isApprovedCategory(?string $policyKey): bool
    {
        return $policyKey !== null && array_key_exists($policyKey, $this->approvedCategories());
    }
}
