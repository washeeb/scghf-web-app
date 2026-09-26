<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A delivery event from an email or SMS provider, stored exactly as it arrived.
 *
 * ── Why this exists at all ──────────────────────────────────────────────────
 *
 * Module 7 built a suppression list, gave `EmailLog` a `markBounced()` and a
 * `markComplained()`, and made the entire channel depend on bounces reaching
 * it — then nothing was built to receive them. The list would have stayed
 * empty, the bounce rate would have climbed, and the first thing to stop being
 * delivered would have been donation receipts.
 *
 * ── Built on the same terms as payment_webhook_events ───────────────────────
 *
 * Because the same things go wrong. The raw body is stored BEFORE anything
 * tries to understand it, the signature verdict is recorded rather than acted
 * on by throwing, and the unique `event_id` means a redelivery is a no-op
 * rather than a second suppression.
 *
 * An unauthenticated event is kept and never processed. Acting on one would let
 * anybody who could guess a donor's email address suppress their receipts —
 * a trivial denial of service dressed as a bounce.
 */
class InboundWebhookEvent extends Model
{
    use HasFactory;
    use HasUlids;

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    /** Normalised event types. A provider's own vocabulary maps onto these. */
    public const TYPE_BOUNCE = 'bounce';

    public const TYPE_SOFT_BOUNCE = 'soft_bounce';

    public const TYPE_COMPLAINT = 'complaint';

    public const TYPE_DELIVERED = 'delivered';

    public const TYPE_FAILED = 'failed';

    public const TYPE_UNSUBSCRIBE = 'unsubscribe';

    /**
     * A message FROM somebody, not a report about one we sent.
     *
     * Meta delivers inbound WhatsApp messages to the same endpoint as the
     * delivery statuses, so the difference is in the payload rather than the
     * URL. These become live-chat conversations — see WhatsappInbox.
     */
    public const TYPE_INBOUND = 'inbound_message';

    protected $fillable = [
        'provider', 'channel', 'event_id', 'event_type', 'subject_address',
        'raw_payload', 'signature', 'signature_valid', 'source_ip', 'received_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'signature_valid' => false,
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'payload_archived_at' => 'datetime',
            'attempts' => 'integer',
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

    /**
     * The parsed body, or an empty array.
     *
     * Parsed on demand rather than stored parsed, so the stored copy stays the
     * bytes the provider actually sent — which is what makes it evidence.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $decoded = json_decode((string) $this->raw_payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    /**
     * Whether this event is one we know how to act on.
     *
     * An unrecognised type is stored, acknowledged and left alone. Providers add
     * event types without warning, and failing on them would fill the queue
     * with noise over something that is not a problem.
     */
    public function isActionable(): bool
    {
        return $this->signature_valid && in_array($this->event_type, [
            self::TYPE_BOUNCE, self::TYPE_SOFT_BOUNCE, self::TYPE_COMPLAINT,
            self::TYPE_DELIVERED, self::TYPE_FAILED, self::TYPE_UNSUBSCRIBE,
            self::TYPE_INBOUND,
        ], true);
    }

    public function markProcessed(): void
    {
        $this->forceFill([
            'processed_at' => now(),
            'attempts' => $this->attempts + 1,
            'error' => null,
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'attempts' => $this->attempts + 1,
            'error' => $error,
        ])->save();
    }

    #[Scope]
    protected function unprocessed(Builder $query): void
    {
        $query->whereNull('processed_at')->where('signature_valid', true);
    }

    /**
     * Events that failed authentication.
     *
     * Kept as evidence and never acted on. A run of them is somebody probing
     * the endpoint, which is worth seeing.
     */
    #[Scope]
    protected function unauthenticated(Builder $query): void
    {
        $query->where('signature_valid', false);
    }
}
