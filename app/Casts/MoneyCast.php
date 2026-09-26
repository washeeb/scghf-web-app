<?php

declare(strict_types=1);

namespace App\Casts;

use App\ValueObjects\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts a pair of columns — an integer minor-unit amount and a currency code —
 * into a Money value object, and back.
 *
 * Usage on a model:
 *
 *     protected function casts(): array
 *     {
 *         return [
 *             // reads `amount_minor` + `currency`
 *             'amount' => MoneyCast::class.':amount_minor,currency',
 *
 *             // or, where the currency column is shared across several amounts
 *             'fee' => MoneyCast::class.':fee_minor,currency',
 *         ];
 *     }
 *
 * The currency column is optional; without it the amount is assumed GHS, which
 * is the only currency this application transacts in.
 *
 * @implements CastsAttributes<Money|null, Money|int|string|null>
 */
class MoneyCast implements CastsAttributes
{
    public function __construct(
        protected ?string $amountColumn = null,
        protected string $currencyColumn = 'currency',
        protected string $defaultCurrency = 'GHS',
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        $column = $this->amountColumn ?? $key.'_minor';

        $minor = $attributes[$column] ?? null;

        if ($minor === null) {
            return null;
        }

        $currency = $attributes[$this->currencyColumn] ?? $this->defaultCurrency;

        return Money::ofMinor((int) $minor, (string) $currency);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $column = $this->amountColumn ?? $key.'_minor';

        if ($value === null) {
            return [$column => null];
        }

        // An integer is taken as minor units — the storage format. Anything
        // else must be a Money, so a stray float or '50.00' cannot be written
        // as if it were 50 pesewas.
        $money = match (true) {
            $value instanceof Money => $value,
            is_int($value) => Money::ofMinor($value, $attributes[$this->currencyColumn] ?? $this->defaultCurrency),
            default => throw new InvalidArgumentException(
                sprintf(
                    'Attribute [%s] must be a %s or an integer of minor units, %s given. '
                    .'If you have a decimal amount, build it with Money::ofMajor() first.',
                    $key,
                    Money::class,
                    get_debug_type($value),
                ),
            ),
        };

        $out = [$column => $money->minor];

        // Only write the currency column if the model actually has one.
        if (array_key_exists($this->currencyColumn, $attributes)) {
            $out[$this->currencyColumn] = $money->currency;
        }

        return $out;
    }
}
