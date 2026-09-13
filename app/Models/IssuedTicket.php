<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One admission to one event, held by one person.
 *
 * The registration is who; the ticket is the thing shown at the door. The
 * code is short, unambiguous (no O/0, I/1) and typed by a volunteer with a
 * phone, so it is not a ULID.
 */
class IssuedTicket extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id', 'event_ticket_id', 'event_registration_id', 'order_id', 'order_item_id',
        'seq', 'code', 'holder_name',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $ticket): void {
            $ticket->code ??= self::newCode();
        });
    }

    public static function newCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';

        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return 'T-'.substr($code, 0, 4).'-'.substr($code, 4);
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<EventTicket, $this> */
    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(EventTicket::class, 'event_ticket_id');
    }

    /** @return BelongsTo<EventRegistration, $this> */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'event_registration_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isValid(): bool
    {
        return $this->cancelled_at === null && $this->checked_in_at === null;
    }

    public function checkIn(?User $by = null): void
    {
        if ($this->cancelled_at !== null) {
            throw new \RuntimeException('This ticket was cancelled.');
        }

        if ($this->checked_in_at !== null) {
            throw new \RuntimeException('This ticket was already used at '.$this->checked_in_at->format('H:i').'.');
        }

        $this->forceFill(['checked_in_at' => now(), 'checked_in_by' => $by?->getKey()])->save();
    }
}
