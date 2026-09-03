<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The life of an order.
 *
 * Deliberately not the same enum as PaymentStatus or DonationStatus. An order
 * can be paid and still unfulfilled, or delivered and later refunded — the
 * gateway's state and the foundation's obligation to the customer are different
 * questions, and one column cannot answer both.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Collected = 'collected';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case NeedsReview = 'needs_review';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting payment',
            self::Paid => 'Paid',
            self::Processing => 'Being prepared',
            self::Shipped => 'Shipped',
            self::Delivered => 'Delivered',
            self::Collected => 'Collected',
            self::Cancelled => 'Cancelled',
            self::Refunded => 'Refunded',
            self::NeedsReview => 'Needs review',
        };
    }

    /** Whether the money is in. */
    public function isPaid(): bool
    {
        return in_array($this, [
            self::Paid, self::Processing, self::Shipped,
            self::Delivered, self::Collected, self::Refunded,
        ], true);
    }

    /** Whether the foundation still owes the customer something. */
    public function isOpen(): bool
    {
        return in_array($this, [
            self::Pending, self::Paid, self::Processing, self::Shipped,
        ], true);
    }

    /** Whether stock should be held against it. */
    public function holdsStock(): bool
    {
        return $this === self::Pending;
    }

    public function isFinished(): bool
    {
        return in_array($this, [
            self::Delivered, self::Collected, self::Cancelled, self::Refunded,
        ], true);
    }

    /**
     * Whether an invoice may be issued.
     *
     * Paid, and nothing else. An unpaid order has no transaction to invoice,
     * and issuing one would put a document in a customer's hands for money
     * nobody has received.
     */
    public function isInvoiceable(): bool
    {
        return $this->isPaid();
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
