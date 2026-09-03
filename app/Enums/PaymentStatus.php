<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The state of one charge at the gateway boundary.
 *
 * `Mismatch` is the one that matters. It exists so that "the gateway told us
 * something we did not expect" is a state of its own rather than being forced
 * into `success` or `failed` — both of which would be a lie, and one of which
 * would credit a donation for the wrong amount.
 */
enum PaymentStatus: string
{
    case Initialised = 'initialised';
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
    case Abandoned = 'abandoned';
    case Mismatch = 'mismatch';

    public function label(): string
    {
        return match ($this) {
            self::Initialised => 'Initialised',
            self::Pending => 'Pending',
            self::Success => 'Successful',
            self::Failed => 'Failed',
            self::Abandoned => 'Abandoned',
            self::Mismatch => 'Needs review',
        };
    }

    /** Whether the money is in and the thing paid for may be fulfilled. */
    public function isSettled(): bool
    {
        return $this === self::Success;
    }

    /** Whether the gateway may still change its mind about this one. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Initialised, self::Pending], true);
    }

    /**
     * Whether a human has to look at it.
     *
     * A mismatch never resolves itself. Somebody in Finance compares what the
     * gateway settled against what the site expected and decides — the system's
     * job is to stop, not to guess.
     */
    public function needsReview(): bool
    {
        return $this === self::Mismatch;
    }

    /**
     * Whether this state can still move.
     *
     * A settled or failed transaction is final. Letting a `success` be
     * overwritten by a late `failed` webhook is how a completed donation
     * silently disappears from a donor's history.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Success, self::Failed, self::Abandoned], true);
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
