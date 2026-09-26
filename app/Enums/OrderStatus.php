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
    case Packed = 'packed';
    case Shipped = 'shipped';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Collected = 'collected';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case NeedsReview = 'needs_review';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting payment',
            self::Paid => 'Paid',
            self::Processing => 'Being prepared',
            self::Packed => 'Packed',
            self::Shipped => 'Shipped',
            self::OutForDelivery => 'Out for delivery',
            self::Delivered => 'Delivered',
            self::Collected => 'Collected',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Refunded => 'Refunded',
            self::NeedsReview => 'Needs review',
        };
    }

    /**
     * What the customer is told when the order reaches this state.
     *
     * The sentence under the status line in `order.status`; the template
     * carries the frame and this carries the fact, so the foundation can
     * reword the email without a deploy and the software can add a status
     * without a template.
     */
    public function customerMessage(): string
    {
        return match ($this) {
            self::Processing => 'We are getting it ready.',
            self::Packed => 'It is packed and waiting for the courier.',
            self::Shipped => 'It has been handed to the courier.',
            self::OutForDelivery => 'It is with the courier today. Please keep your phone nearby.',
            self::Delivered => 'It has been delivered. Thank you for supporting our work.',
            self::Collected => 'It has been collected. Thank you for supporting our work.',
            self::Completed => 'Everything on this order is done. Thank you for supporting our work.',
            self::Cancelled => 'This order has been cancelled. If you paid, the refund follows separately.',
            self::Refunded => 'The payment for this order has been returned to you.',
            default => '',
        };
    }

    /** Whether the money is in. */
    public function isPaid(): bool
    {
        return in_array($this, [
            self::Paid, self::Processing, self::Packed, self::Shipped, self::OutForDelivery,
            self::Delivered, self::Collected, self::Completed, self::Refunded,
        ], true);
    }

    /** Whether the foundation still owes the customer something. */
    public function isOpen(): bool
    {
        return in_array($this, [
            self::Pending, self::Paid, self::Processing, self::Packed, self::Shipped, self::OutForDelivery,
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
            self::Delivered, self::Collected, self::Completed, self::Cancelled, self::Refunded,
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
