<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Contracts\Payable;
use App\Enums\OrderStatus;
use App\Shop\OrderFulfilment;
use App\Shop\OrderNotifier;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A shop purchase.
 *
 * **A purchase is not a gift, and this class is where that stops being a
 * slogan.** An Order is a `Payable` — it shares the gateway boundary with
 * donations, because two payment paths is how a ledger diverges — but it is on
 * `config('compliance.tax.never_acknowledge_payable_types')`, so
 * `TaxDeductibility::mayAcknowledge()` refuses it and `Acknowledgement::for()`
 * throws if one is ever passed. What it gets instead is an INVOICE, from its
 * own numbering series.
 *
 * Append-only once paid: a completed order's figures are what an invoice in a
 * customer's hands already states.
 *
 * @property OrderStatus $status
 */
class Order extends Model implements Payable
{
    use HasFactory;
    use HasUlids;
    use LogsActivity;

    protected $fillable = [
        'reference', 'user_id', 'customer_name', 'customer_email', 'customer_phone',
        'status', 'subtotal', 'shipping', 'discount', 'total', 'currency',
        'coupon_id', 'coupon_code', 'shipping_zone_id', 'shipping_rate_id', 'shipping_method',
        'delivery_name', 'delivery_phone', 'delivery_address', 'delivery_area',
        'delivery_region', 'delivery_notes', 'is_pickup', 'channel', 'notes', 'recorded_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'currency' => 'GHS',
        'shipping_minor' => 0,
        'discount_minor' => 0,
        'fee_minor' => 0,
        'is_pickup' => false,
        'stock_held' => false,
        'stock_committed' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'is_pickup' => 'boolean',
            'stock_held' => 'boolean',
            'stock_committed' => 'boolean',
            'paid_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'subtotal' => MoneyCast::class.':subtotal_minor,currency',
            'shipping' => MoneyCast::class.':shipping_minor,currency',
            'discount' => MoneyCast::class.':discount_minor,currency',
            'total' => MoneyCast::class.':total_minor,currency',
            'fee' => MoneyCast::class.':fee_minor,currency',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $order): void {
            $order->reference ??= self::generateReference();
        });

        static::updating(function (self $order): void {
            /*
             * Append-only once paid. These figures are what an invoice already
             * in a customer's hands states, and changing them would make the
             * ledger disagree with a document the foundation has issued.
             */
            if (OrderStatus::tryFrom((string) $order->getRawOriginal('status'))?->isPaid()) {
                $frozen = array_intersect(
                    array_keys($order->getDirty()),
                    ['subtotal_minor', 'shipping_minor', 'discount_minor', 'total_minor',
                        'reference', 'currency'],
                );

                if ($frozen !== []) {
                    throw new RuntimeException(
                        'A paid order is append-only. Cannot change: '.implode(', ', $frozen)
                        .'. Refund it or raise a credit note instead.'
                    );
                }
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException(
                'Orders are never deleted. Cancel or refund it — the ledger is append-only.'
            );
        });

        /*
         * History is written EXPLICITLY at each transition rather than from an
         * `updated` hook. A hook would fire for every save and could not know
         * who made the change or why, so every row would read "system" with no
         * note — which is exactly the information a customer service enquiry
         * needs and the hook cannot supply.
         */
    }

    /** `SCGHF-O-…` — a different prefix from a donation, deliberately. */
    public static function generateReference(): string
    {
        return 'SCGHF-O-'.Str::upper(substr(Str::ulid()->toBase32(), -10));
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    /** @return HasOne<Invoice, $this> */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    /** @return MorphOne<PaymentTransaction, $this> */
    public function transaction(): MorphOne
    {
        return $this->morphOne(PaymentTransaction::class, 'payable');
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return HasMany<DigitalDownloadToken, $this> */
    /** @return HasMany<IssuedTicket, $this> */
    public function issuedTickets(): HasMany
    {
        return $this->hasMany(IssuedTicket::class);
    }

    /** The gifts a "donation product" line became. @return HasMany<Donation, $this> */
    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class);
    }

    public function downloadTokens(): HasMany
    {
        return $this->hasMany(DigitalDownloadToken::class);
    }

    // ── The Payable contract ─────────────────────────────────────────────────

    public function chargeableAmount(): Money
    {
        return $this->total;
    }

    public function payerEmail(): ?string
    {
        return $this->customer_email;
    }

    /**
     * The money arrived. Convert the stock hold into a sale.
     *
     * Idempotent: `stock_committed` is the guard, checked under a row lock, so
     * a retried webhook cannot decrement the shelf twice.
     */
    public function onPaymentSettled(PaymentTransaction $transaction): void
    {
        if ($this->status->isPaid()) {
            return;
        }

        DB::transaction(function () use ($transaction): void {
            $order = static::query()->lockForUpdate()->find($this->getKey());

            if ($order === null || $order->status->isPaid()) {
                return;
            }

            $fee = $transaction->fee ?? Money::zero($order->currency);

            $order->forceFill([
                'status' => OrderStatus::Paid,
                'fee_minor' => $fee->toMinor(),
                'paid_at' => $transaction->paid_at ?? now(),
                'channel' => $transaction->channel ?? $order->channel,
                'paystack_reference' => $transaction->gateway_reference,
            ])->save();

            $order->commitStock();
            $order->recordStatusChange(OrderStatus::Pending, null, 'Payment received.');

            $this->setRawAttributes($order->getAttributes(), true);
        });

        /*
         * Outside the transaction. The invoice and the confirmation email are
         * consequences of the order being paid, not conditions of it — and
         * `OrderNotifier` reports rather than throws, so a broken template
         * cannot fail the webhook and have the gateway redeliver a payment
         * that has already been counted.
         */
        // Downloads, donations and tickets first, so the confirmation can
        // carry the links and the receipt can be issued for the meal.
        app(OrderFulfilment::class)->fulfil($this);
        app(OrderNotifier::class)->confirm($this);
    }

    /**
     * The payment failed. Put the stock back on the shelf.
     *
     * Releasing rather than holding it indefinitely matters more than it looks:
     * a foundation with twelve mugs and eleven abandoned checkouts would show
     * as sold out.
     */
    public function onPaymentFailed(PaymentTransaction $transaction): void
    {
        if ($this->status->isPaid()) {
            return;
        }

        $from = $this->status;

        $this->releaseStock();

        $this->forceFill([
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
            'cancel_reason' => 'Payment failed or was abandoned.',
            'paystack_reference' => $transaction->gateway_reference,
        ])->save();

        $this->recordStatusChange($from, null, 'Payment failed or was abandoned.');
    }

    /**
     * The customer opened the payment page and never came back.
     *
     * **The stock goes back on the shelf.** This is the case that matters most
     * for a small shop: without it, twelve mugs and eleven abandoned checkouts
     * reads as sold out, and the goods sit reserved for people who left.
     */
    public function onPaymentAbandoned(PaymentTransaction $transaction): void
    {
        if ($this->status->isPaid()) {
            return;
        }

        $from = $this->status;

        $this->releaseStock();

        $this->forceFill([
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
            'cancel_reason' => 'Checkout abandoned.',
            'paystack_reference' => $transaction->gateway_reference,
        ])->save();

        $this->recordStatusChange($from, null, 'Checkout abandoned; stock released.');
    }

    /**
     * The gateway settled something unexpected.
     *
     * Held, and the stock stays held with it. Releasing would let somebody else
     * buy goods this customer may well have paid for; committing would ship
     * goods against a payment nobody has verified.
     */
    public function onPaymentMismatch(PaymentTransaction $transaction): void
    {
        $from = $this->status;

        $this->forceFill([
            'status' => OrderStatus::NeedsReview,
            'paystack_reference' => $transaction->gateway_reference,
            'notes' => trim((string) $this->notes."\n".$transaction->mismatch_reason),
        ])->save();

        $this->recordStatusChange($from, null, (string) $transaction->mismatch_reason);

        Log::critical('Order held for review after a payment mismatch.', [
            'order' => $this->reference,
            'transaction' => $transaction->ulid,
        ]);
    }

    /**
     * The money went back. A full refund closes the order; a partial one is a
     * note on it. Putting the goods back on the shelf is a decision for the
     * person handling the return, not a side effect of the gateway's webhook.
     */
    public function onRefunded(Refund $refund): void
    {
        $full = $refund->transaction !== null
            && $refund->transaction->refundedAmount()->greaterThanOrEqual($this->total);

        if ($full && $this->status !== OrderStatus::Refunded) {
            $this->transitionTo(OrderStatus::Refunded, null, 'Refunded in full via the gateway.');
        }

        if (! $full) {
            $this->forceFill(['notes' => trim((string) $this->notes."\nPartial refund of ".$refund->amount->format().' processed.')])->save();
        }
    }

    // ── Stock ────────────────────────────────────────────────────────────────

    /** Reserve the shelf while the customer is on the payment page. */
    public function holdStock(): void
    {
        if ($this->stock_held) {
            return;
        }

        DB::transaction(function (): void {
            foreach ($this->items as $item) {
                $item->variant?->hold($item->quantity, $this->reference);
            }

            static::whereKey($this->getKey())->update(['stock_held' => true]);
        });

        $this->refresh();
    }

    public function releaseStock(): void
    {
        if (! $this->stock_held || $this->stock_committed) {
            return;
        }

        DB::transaction(function (): void {
            foreach ($this->items as $item) {
                $item->variant?->releaseHold($item->quantity, $this->reference);
            }

            static::whereKey($this->getKey())->update(['stock_held' => false]);
        });

        $this->refresh();
    }

    public function commitStock(): void
    {
        if ($this->stock_committed) {
            return;
        }

        DB::transaction(function (): void {
            foreach ($this->items as $item) {
                $item->variant?->commitSale($item->quantity, $this->reference);
            }

            static::whereKey($this->getKey())->update([
                'stock_committed' => true,
                'stock_held' => false,
            ]);
        });

        $this->refresh();
    }

    // ── Money ────────────────────────────────────────────────────────────────

    /**
     * Assert the arithmetic holds.
     *
     * subtotal + shipping − discount = total, and the lines sum to the
     * subtotal. Checked before the gateway is called, for the same reason a
     * donation's designations are: a total that does not add up is a bug worth
     * refusing to charge for.
     */
    public function assertTotalsReconcile(): void
    {
        $lines = (int) $this->items()->sum('line_total_minor');

        if ($lines !== $this->subtotal_minor) {
            throw new RuntimeException(sprintf(
                'Order %s does not reconcile: lines sum to %s but the subtotal is %s.',
                $this->reference,
                Money::ofMinor($lines, $this->currency)->format(),
                $this->subtotal->format(),
            ));
        }

        $expected = $this->subtotal_minor + $this->shipping_minor - $this->discount_minor;

        if ($expected !== $this->total_minor) {
            throw new RuntimeException(sprintf(
                'Order %s does not reconcile: %s + %s − %s should be %s, but the total is %s.',
                $this->reference,
                $this->subtotal->format(),
                $this->shipping->format(),
                $this->discount->format(),
                Money::ofMinor($expected, $this->currency)->format(),
                $this->total->format(),
            ));
        }
    }

    public function totalWeightGrams(): int
    {
        return (int) $this->items->sum(fn (OrderItem $item): int => (int) $item->weight_grams * $item->quantity);
    }

    /** Whether anything in the order has to be carried somewhere. */
    public function requiresDelivery(): bool
    {
        return $this->items->contains(fn (OrderItem $item): bool => $item->product?->requiresDelivery() ?? true);
    }

    public function hasDigitalItems(): bool
    {
        return $this->items->contains(fn (OrderItem $item): bool => $item->product?->isDigital() ?? false);
    }

    // ── Status ───────────────────────────────────────────────────────────────

    public function transitionTo(OrderStatus $status, ?User $by = null, string $note = ''): void
    {
        $from = $this->status;

        $this->forceFill(array_filter([
            'status' => $status,
            'shipped_at' => $status === OrderStatus::Shipped ? now() : $this->shipped_at,
            'delivered_at' => in_array($status, [OrderStatus::Delivered, OrderStatus::Collected], true)
                ? now()
                : $this->delivered_at,
            'cancelled_at' => $status === OrderStatus::Cancelled ? now() : $this->cancelled_at,
        ], fn ($value): bool => $value !== null))->save();

        $this->recordStatusChange($from, $by, $note);

        /*
         * The customer is told on every change after payment. Dispatch has
         * its own message, sent by the action that knows the courier; a
         * transition that changes nothing sends nothing.
         */
        if ($from !== $status && $status !== OrderStatus::Shipped) {
            app(OrderNotifier::class)->status($this, $status);
        }
    }

    /**
     * Cancel, putting the stock back.
     *
     * A paid order cannot simply be cancelled — the money has to go back first,
     * and a refund is its own record with its own approval.
     */
    public function cancel(string $reason, ?User $by = null): void
    {
        if ($this->status->isPaid()) {
            throw new RuntimeException(
                'A paid order cannot be cancelled outright. Refund it — the money has to go back, '
                .'and that is a separate record with its own approval.'
            );
        }

        $from = $this->status;

        $this->releaseStock();

        $this->forceFill([
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ])->save();

        $this->recordStatusChange($from, $by, $reason);

        // An unpaid checkout that was abandoned is swept, not written to; a
        // customer who was still deciding is told nothing they did not ask.
        if ($from !== OrderStatus::Pending) {
            app(OrderNotifier::class)->status($this, OrderStatus::Cancelled);
        }
    }

    public function recordStatusChange(?OrderStatus $from, ?User $by = null, string $note = ''): void
    {
        OrderStatusHistory::create([
            'order_id' => $this->getKey(),
            'from_status' => $from?->value,
            'to_status' => $this->status->value,
            'note' => $note !== '' ? $note : null,
            'changed_by' => $by?->getKey(),
            'created_at' => now(),
        ]);
    }

    #[Scope]
    protected function paid(Builder $query): void
    {
        $query->whereIn('status', array_map(
            fn (OrderStatus $status): string => $status->value,
            array_filter(OrderStatus::cases(), fn (OrderStatus $s): bool => $s->isPaid()),
        ));
    }

    /** Unpaid orders old enough that the customer has clearly gone. */
    #[Scope]
    protected function stale(Builder $query): void
    {
        $minutes = (int) config('payments.reconciliation.abandon_after_minutes', 60);

        $query->where('status', OrderStatus::Pending->value)
            ->where('stock_held', true)
            ->where('created_at', '<', now()->subMinutes($minutes));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'total_minor', 'paid_at', 'paystack_reference'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('order');
    }
}
