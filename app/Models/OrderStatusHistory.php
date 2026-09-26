<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One step in an order's life.
 *
 * "When did this ship?" and "who cancelled it?" are the two questions a
 * customer service enquiry always asks, and a single status column answers
 * neither. Append-only, because a history that can be edited is not one.
 *
 * A null `changed_by` means the system did it — a webhook, the abandonment
 * sweep — which is a meaningful answer rather than missing data.
 */
class OrderStatusHistory extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'order_id', 'from_status', 'to_status', 'note', 'changed_by', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('Order history is append-only.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Order history is never deleted.');
        });
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function wasAutomatic(): bool
    {
        return $this->changed_by === null;
    }
}
