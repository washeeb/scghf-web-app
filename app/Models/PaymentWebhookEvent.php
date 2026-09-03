<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One webhook delivery, stored before it is understood.
 *
 * The raw payload is written FIRST, before any parsing. A malformed webhook is
 * still evidence — of an integration change, of a bug, of somebody probing the
 * endpoint — and parsing first means the one payload worth having is the one
 * that gets thrown away.
 *
 * The unique index on `event_id` is the idempotency guarantee, and it lives in
 * the database rather than in application logic on purpose. Paystack retries
 * aggressively; a replayed `charge.success` must be a no-op even if every line
 * of PHP that handles it is wrong.
 *
 * Append-only, and enforced: an event row is a record of what arrived, and a
 * record that can be edited is not a record.
 */
class PaymentWebhookEvent extends Model
{
    use HasFactory;

    /** Columns the processor is allowed to write after the fact. */
    private const MUTABLE = [
        'processed_at', 'processing_error', 'attempts', 'updated_at',
    ];

    protected $fillable = [
        'gateway', 'event_id', 'event_type', 'gateway_reference',
        'raw_payload', 'signature', 'signature_valid', 'source_ip', 'received_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'gateway' => 'paystack',
        'signature_valid' => false,
        'attempts' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $event): void {
            /*
             * The arrival record is immutable. Only the processing outcome may
             * be written afterwards — rewriting the payload or the signature
             * verdict would destroy the evidence the table exists to keep.
             */
            $changed = array_keys($event->getDirty());
            $illegal = array_diff($changed, self::MUTABLE);

            if ($illegal !== []) {
                throw new RuntimeException(
                    'Webhook events are append-only. Cannot modify: '.implode(', ', $illegal).'. '
                    .'The stored payload and signature verdict are evidence of what arrived.'
                );
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException(
                'Webhook events are never deleted. They are the audit trail for every '
                .'payment the foundation has taken.'
            );
        });
    }

    /** The parsed payload, or an empty array if it never was valid JSON. */
    public function payload(): array
    {
        $decoded = json_decode((string) $this->raw_payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function data(): array
    {
        return $this->payload()['data'] ?? [];
    }

    /** @return BelongsTo<PaymentTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'gateway_reference', 'gateway_reference');
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    /** Whether this event type is one the handler acts on. */
    public function isHandled(): bool
    {
        return in_array($this->event_type, (array) config('payments.webhooks.handled_events', []), true);
    }

    public function markProcessed(): void
    {
        $this->forceFill([
            'processed_at' => now(),
            'processing_error' => null,
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'processing_error' => $error,
            'attempts' => $this->attempts + 1,
        ])->save();
    }

    public function hasExhaustedAttempts(): bool
    {
        return $this->attempts >= (int) config('payments.webhooks.max_attempts', 5);
    }

    /** Valid events still waiting to be acted on. */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('signature_valid', true)->whereNull('processed_at');
    }

    /**
     * Events that failed the signature check.
     *
     * Worth watching as a set rather than one at a time: a run of them is the
     * signal that somebody is probing the endpoint.
     */
    #[Scope]
    protected function rejected(Builder $query): void
    {
        $query->where('signature_valid', false);
    }
}
