<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The state of a recurring gift.
 *
 * `Failing` is deliberately distinct from `Cancelled`. A card that expired is
 * not a donor who stopped giving, and treating the two the same loses the
 * distinction that decides whether anyone should get in touch.
 */
enum SubscriptionStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Failing = 'failing';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Failing => 'Payment failing',
            self::Cancelled => 'Cancelled',
            self::Completed => 'Completed',
        };
    }

    /** Whether the charging run should look at it. */
    public function isChargeable(): bool
    {
        // Failing is still chargeable: the retry schedule is what recovers a
        // temporarily declined card, and giving up on the first failure would
        // lose gifts the donor fully intended to make.
        return in_array($this, [self::Active, self::Failing], true);
    }

    /** Whether the donor stopped it, as opposed to it breaking. */
    public function wasEndedByDonor(): bool
    {
        return $this === self::Cancelled;
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Cancelled, self::Completed], true);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            static fn (array $carry, self $case): array => $carry + [$case->value => $case->label()],
            [],
        );
    }
}
