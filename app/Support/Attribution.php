<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Which campaign brought this person.
 *
 * A donor rarely lands on the donate page. They land on an appeal from a
 * WhatsApp link with `?utm_source=whatsapp&utm_campaign=harvest`, read,
 * and give three pages later. So the parameters are captured on the FIRST
 * page that carries them and kept in the session; a donation or an order
 * made later in the visit is stamped with them. First touch wins: a later
 * link in the same session does not overwrite the one that brought them.
 *
 * Only the six standard keys, each capped at 100 characters, nothing else
 * from the query string — this is attribution, not tracking.
 */
final class Attribution
{
    public const KEYS = ['source', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    private const SESSION_KEY = 'attribution';

    /** Remember the campaign parameters on this request, if any and not already remembered. */
    public static function capture(Request $request): void
    {
        if (! $request->hasSession() || $request->session()->has(self::SESSION_KEY)) {
            return;
        }

        $found = self::fromInput($request->query());

        if ($found !== []) {
            $request->session()->put(self::SESSION_KEY, $found + ['landed_on' => $request->path(), 'at' => now()->toIso8601String()]);
        }
    }

    /**
     * What to stamp on a donation or order: the form's own fields first
     * (the donate page carries them as hidden inputs), then the session.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function current(Request $request, array $input = []): array
    {
        $explicit = self::fromInput($input);

        if ($explicit !== []) {
            return $explicit;
        }

        $remembered = $request->hasSession() ? (array) $request->session()->get(self::SESSION_KEY, []) : [];

        return self::fromInput($remembered);
    }

    /**
     * The `utm` column's shape, as Phase 8 defined it: the five UTM keys
     * without their prefix, or null when there are none.
     *
     * @param  array<string, string>  $stamp
     * @return array<string, string>|null
     */
    public static function utm(array $stamp): ?array
    {
        $utm = [];

        foreach ($stamp as $key => $value) {
            if (str_starts_with($key, 'utm_')) {
                $utm[substr($key, 4)] = $value;
            }
        }

        return $utm === [] ? null : $utm;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function fromInput(array $input): array
    {
        $out = [];

        foreach (self::KEYS as $key) {
            $value = trim((string) ($input[$key] ?? ''));

            if ($value !== '') {
                $out[$key] = mb_substr($value, 0, 100);
            }
        }

        return $out;
    }
}
