<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The one slug rule, applied by every model that has a slug.
 *
 * Twenty-six models derive a public address from a title. They all did it
 * the same way in their own `saving` hook — take the editor's slug if there
 * is one, else the title, then `Str::slug()` it — which was fine until the
 * question "what if the result is empty?" had twenty-six places to be
 * answered and was answered in none. `Str::slug('!!!')` is `''`, and a
 * product with an empty slug is the shop index, a page with one is its
 * parent, and a redirect to either is a loop.
 *
 * So the rule lives here: the editor's choice wins over the title, both are
 * cleaned, and an address that comes out empty is an exception at save
 * time — the save fails loudly, an import stops on it, and nothing is
 * stored at an address nothing can reach. Every form already requires the
 * title, so a person reaches this only with a title made of punctuation.
 */
final class Slug
{
    public static function for(?string $chosen, ?string $fallback): string
    {
        $slug = Str::slug((string) (filled($chosen) ? $chosen : $fallback));

        if ($slug === '') {
            throw new InvalidArgumentException(
                'A slug could not be made from "'.trim((string) ($chosen ?: $fallback)).'". '
                .'The title needs at least one letter or number, or give the record a slug by hand.'
            );
        }

        return $slug;
    }
}
