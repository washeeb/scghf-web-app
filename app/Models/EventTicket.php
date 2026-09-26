<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Features;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A class of ticket for an event.
 *
 * Named in §2.6 and referenced by `events.is_ticketed`, which the Event model
 * already refuses to set while `features.event_ticketing` is down. This is the
 * other end of that reference.
 *
 * A price of zero is a real answer, not a missing one: a free ticket that still
 * reserves a place is the common case at an outreach event, and modelling free
 * places as "no ticket" would lose the count.
 *
 * @property Money|null $price
 */
class EventTicket extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'event_id', 'name', 'description', 'price', 'currency',
        'quantity', 'max_per_order', 'sales_open_at', 'sales_close_at',
        'is_active', 'sort_order',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'price_minor' => 0,
        'currency' => 'GHS',
        'sold' => 0,
        'max_per_order' => 10,
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class.':price_minor,currency',
            'quantity' => 'integer',
            'sold' => 'integer',
            'max_per_order' => 'integer',
            'is_active' => 'boolean',
            'sales_open_at' => 'datetime',
            'sales_close_at' => 'datetime',
        ];
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

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function isFree(): bool
    {
        return (int) $this->price_minor === 0;
    }

    public function remaining(): ?int
    {
        return $this->quantity === null ? null : max(0, $this->quantity - $this->sold);
    }

    public function isSoldOut(): bool
    {
        return $this->remaining() === 0;
    }

    /**
     * Why this ticket cannot be bought right now, or null if it can.
     *
     * The feature flag is checked here as well as on the event, so a ticket
     * created while ticketing was on does not stay purchasable after somebody
     * turns it off. A flag that only gates creation gates nothing.
     */
    public function purchaseRejectionReason(int $quantity = 1): ?string
    {
        if (app(Features::class)->disabled('event_ticketing')) {
            return 'Ticketing is not switched on.';
        }

        if (! $this->is_active) {
            return 'This ticket is not on sale.';
        }

        if ($this->sales_open_at !== null && $this->sales_open_at->isFuture()) {
            return 'Sales for this ticket open on '.$this->sales_open_at->format('j M Y').'.';
        }

        if ($this->sales_close_at !== null && $this->sales_close_at->isPast()) {
            return 'Sales for this ticket have closed.';
        }

        if ($quantity > $this->max_per_order) {
            /*
             * Without a per-order cap, one supporter books forty places in a
             * hall that seats a hundred and the event looks full while the room
             * is empty.
             */
            return "A maximum of {$this->max_per_order} of these may be booked at once.";
        }

        $remaining = $this->remaining();

        if ($remaining !== null && $quantity > $remaining) {
            return $remaining === 0
                ? 'This ticket is sold out.'
                : "Only {$remaining} of these are left.";
        }

        return null;
    }

    public function canBePurchased(int $quantity = 1): bool
    {
        return $this->purchaseRejectionReason($quantity) === null;
    }

    #[Scope]
    protected function onSale(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort_order');
    }
}
