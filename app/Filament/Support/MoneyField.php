<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\ValueObjects\Money;
use Filament\Forms\Components\TextInput;

/**
 * A price typed in cedis, stored in pesewas.
 *
 * ── Why the shop differs from the rest of the admin ─────────────────────────
 *
 * Elsewhere money is entered in pesewas with a helper showing the cedis, and
 * that is right for a donation goal set once a quarter. A shop is different:
 * somebody pricing thirty items types "45.00" thirty times, and asking for
 * "4500" thirty times is asking for one of them to be "450".
 *
 * ── Two conversions, both explicit ──────────────────────────────────────────
 *
 * `MoneyCast` hands the form a Money and accepts back a Money or an INTEGER of
 * minor units, throwing on anything else — the strictness that stops a stray
 * "45.00" being written as forty-five pesewas. So the Money is unwrapped to a
 * decimal string on the way in, and `Money::ofMajor()` is the ONLY thing that
 * turns the typed decimal back into pesewas on the way out. `decimal:0,2`
 * refuses "45.005" rather than rounding half a pesewa nobody can pay.
 */
class MoneyField
{
    public static function make(string $name): TextInput
    {
        return TextInput::make($name)
            ->prefix('GH₵')
            ->numeric()
            ->minValue(0)
            ->rule('decimal:0,2')
            ->formatStateUsing(fn (mixed $state): ?string => match (true) {
                $state instanceof Money => $state->toMajorString(),
                is_numeric($state) => (string) $state,
                default => null,
            })
            ->dehydrateStateUsing(fn (mixed $state): ?int => blank($state)
                ? null
                : Money::ofMajor((float) $state)->toMinor());
    }
}
