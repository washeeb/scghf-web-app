<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The state of a gift.
 *
 * Mirrors PaymentStatus but is not the same thing, and conflating them would be
 * a mistake: a payment can settle while the donation still needs review, and a
 * donation can be `refunded` long after its payment succeeded. One is about the
 * gateway; the other is about the foundation's books.
 */
enum DonationStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Abandoned = 'abandoned';
    case NeedsReview = 'needs_review';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Abandoned => 'Abandoned',
            self::NeedsReview => 'Needs review',
            self::Refunded => 'Refunded',
        };
    }

    /**
     * Whether this gift counts towards a cause total or an impact figure.
     *
     * Only `completed`. A refunded gift is deliberately excluded — the money
     * went back, and leaving it in the total would overstate what the
     * foundation raised.
     */
    public function countsTowardsTotals(): bool
    {
        return $this === self::Completed;
    }

    /** Whether an acknowledgement may be issued. */
    public function isReceiptable(): bool
    {
        return $this === self::Completed;
    }

    /**
     * Whether a human has to look at it.
     *
     * `needs_review` means the gateway settled something we did not expect. The
     * money may well have been taken, so it is neither completed nor failed
     * until somebody decides.
     */
    public function needsReview(): bool
    {
        return $this === self::NeedsReview;
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
