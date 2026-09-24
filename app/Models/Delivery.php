<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shop order in a courier's hands.
 *
 * The delivery is the courier's record — assigned, picked up, out for
 * delivery, delivered (to whom, with what proof) or failed (why). The
 * order's own status moves in step through `Order::transitionTo()`, so the
 * customer's tracking page, emails and texts are the ones the office has
 * always sent. Nothing here is deleted: a failed delivery is reassigned or
 * retried on the same row, and the count of attempts is part of the story.
 */
class Delivery extends Model
{
    use HasFactory;
    use HasUlids;

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_PICKED_UP = 'picked_up';

    public const STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_ASSIGNED => 'Assigned',
        self::STATUS_PICKED_UP => 'Picked up',
        self::STATUS_OUT_FOR_DELIVERY => 'Out for delivery',
        self::STATUS_DELIVERED => 'Delivered',
        self::STATUS_FAILED => 'Could not deliver',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    protected $fillable = [
        'order_id', 'courier_id', 'assigned_by', 'status', 'attempts', 'assigned_at', 'picked_up_at',
        'out_for_delivery_at', 'delivered_at', 'failed_at', 'recipient_name', 'proof_note',
        'proof_photo_path', 'proof_lat', 'proof_lng', 'failure_reason', 'office_notes',
    ];

    protected $attributes = ['status' => self::STATUS_ASSIGNED, 'attempts' => 0];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'assigned_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'out_for_delivery_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'proof_lat' => 'decimal:7',
            'proof_lng' => 'decimal:7',
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function courier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'courier_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function label(): string
    {
        return __(self::STATUS_LABELS[$this->status] ?? ucfirst($this->status));
    }

    /** Still the courier's to act on. */
    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_ASSIGNED, self::STATUS_PICKED_UP, self::STATUS_OUT_FOR_DELIVERY, self::STATUS_FAILED], true);
    }

    public function isDone(): bool
    {
        return in_array($this->status, [self::STATUS_DELIVERED, self::STATUS_CANCELLED], true);
    }

    public function hasProofPhoto(): bool
    {
        return filled($this->proof_photo_path);
    }

    /** A map link for the recorded position, when there is one. */
    public function proofMapUrl(): ?string
    {
        if ($this->proof_lat === null || $this->proof_lng === null) {
            return null;
        }

        return 'https://www.google.com/maps?q='.$this->proof_lat.','.$this->proof_lng;
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereIn('status', [self::STATUS_ASSIGNED, self::STATUS_PICKED_UP, self::STATUS_OUT_FOR_DELIVERY, self::STATUS_FAILED]);
    }

    #[Scope]
    protected function forCourier(Builder $query, User $courier): void
    {
        $query->where('courier_id', $courier->getKey());
    }
}
