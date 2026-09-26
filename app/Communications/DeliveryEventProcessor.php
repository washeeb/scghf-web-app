<?php

declare(strict_types=1);

namespace App\Communications;

use App\Chat\WhatsappInbox;
use App\Models\EmailLog;
use App\Models\InboundWebhookEvent;
use App\Models\SmsLog;
use App\Models\Suppression;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns a provider's delivery event into a suppression, or a log update.
 *
 * The receiving end of Module 7. `EmailLog::markBounced()` and
 * `markComplained()` were built and then had nothing to call them; this is what
 * calls them.
 *
 * ── Everything here refuses to act on an unauthenticated event ──────────────
 *
 * A bounce suppresses an address. If an unsigned request could produce one,
 * anybody who could guess a donor's email could stop their receipts arriving —
 * a denial of service dressed up as a bounce, and completely silent.
 *
 * ── Idempotent, because providers redeliver ─────────────────────────────────
 *
 * The unique `event_id` stops the same event being stored twice, and
 * `Suppression::record()` upserts rather than inserting, so even a duplicate
 * that got past that produces one row with a higher occurrence count.
 */
class DeliveryEventProcessor
{
    /**
     * Record an event exactly as it arrived, before understanding it.
     *
     * In this order, and the order is the point: store, verify, return. The
     * controller can then answer 200 immediately and let the queue do the work
     * — providers time out and retry if an endpoint is slow, and doing the work
     * inline is how one slow suppression becomes four duplicate deliveries.
     */
    public function record(
        string $provider,
        string $channel,
        string $rawBody,
        bool $signatureValid,
        ?string $signature = null,
        ?string $sourceIp = null,
    ): InboundWebhookEvent {
        $parsed = json_decode($rawBody, true);
        $parsed = is_array($parsed) ? $parsed : [];

        $eventId = $this->eventId($provider, $parsed, $rawBody);

        $existing = InboundWebhookEvent::where('event_id', $eventId)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return InboundWebhookEvent::create([
                'provider' => $provider,
                'channel' => $channel,
                'event_id' => $eventId,
                'event_type' => $this->normaliseType($provider, $parsed),
                'subject_address' => $this->subjectAddress($channel, $parsed),
                'raw_payload' => $rawBody,
                'signature' => $signature,
                'signature_valid' => $signatureValid,
                'source_ip' => $sourceIp,
                'received_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Lost the race with a simultaneous redelivery. That row is the one.
            $existing = InboundWebhookEvent::where('event_id', $eventId)->first();

            if ($existing !== null) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * Act on a stored event. Safe to call repeatedly.
     */
    public function process(InboundWebhookEvent $event): void
    {
        if (! $event->signature_valid) {
            /*
             * Kept as evidence, never acted on. A run of these is somebody
             * probing the endpoint, and the log line is how anybody finds out.
             */
            Log::warning('An unauthenticated delivery webhook was not processed.', [
                'provider' => $event->provider,
                'ip' => $event->source_ip,
                'event' => $event->ulid,
            ]);

            return;
        }

        if ($event->isProcessed()) {
            return;
        }

        if (! $event->isActionable()) {
            // Stored and acknowledged, not acted on. Providers add event types
            // without warning, and failing on them would fill the queue with
            // noise over something that is not a problem.
            $event->markProcessed();

            return;
        }

        try {
            match ($event->channel) {
                InboundWebhookEvent::CHANNEL_EMAIL => $this->applyToEmail($event),
                InboundWebhookEvent::CHANNEL_WHATSAPP => $this->applyToWhatsapp($event),
                default => $this->applyToSms($event),
            };

            $event->markProcessed();
        } catch (Throwable $e) {
            $event->markFailed($e->getMessage());

            throw $e;
        }
    }

    private function applyToEmail(InboundWebhookEvent $event): void
    {
        $address = (string) $event->subject_address;

        if ($address === '') {
            return;
        }

        /*
         * The most recent message we sent to this address, so the outcome lands
         * on the row somebody would look at. Null is normal and not an error —
         * a bounce can arrive for a message sent before the log was kept, or
         * from a different system on the same domain.
         */
        $log = EmailLog::query()
            ->forAddress($address)
            ->whereIn('status', [EmailLog::STATUS_SENT, EmailLog::STATUS_DELIVERED])
            ->latest('sent_at')
            ->first();

        $detail = $this->detail($event);

        match ($event->event_type) {
            InboundWebhookEvent::TYPE_BOUNCE => $log?->markBounced(hard: true, detail: $detail)
                ?? $this->suppressWithoutLog($address, Suppression::REASON_HARD_BOUNCE, $detail),

            InboundWebhookEvent::TYPE_SOFT_BOUNCE => $log?->markBounced(hard: false, detail: $detail),

            InboundWebhookEvent::TYPE_COMPLAINT => $log?->markComplained($detail)
                ?? $this->suppressWithoutLog($address, Suppression::REASON_COMPLAINT, $detail),

            /*
             * An unsubscribe arriving through the provider — usually the
             * one-click List-Unsubscribe header being honoured by the mailbox
             * rather than by us. Marketing scope only: they asked to stop
             * receiving appeals, not to stop receiving receipts.
             */
            InboundWebhookEvent::TYPE_UNSUBSCRIBE => $this->suppressWithoutLog(
                $address, Suppression::REASON_UNSUBSCRIBE, $detail,
            ),

            InboundWebhookEvent::TYPE_DELIVERED => $log?->markDelivered(),

            default => null,
        };
    }

    private function applyToSms(InboundWebhookEvent $event): void
    {
        $number = Suppression::tryNormaliseAddress(
            Suppression::CHANNEL_SMS,
            (string) $event->subject_address,
        );

        if ($number === null) {
            return;
        }

        $log = SmsLog::query()
            ->where('to_number', $number)
            ->whereIn('status', [SmsLog::STATUS_SENT, SmsLog::STATUS_DELIVERED])
            ->latest('sent_at')
            ->first();

        $detail = $this->detail($event);

        match ($event->event_type) {
            InboundWebhookEvent::TYPE_DELIVERED => $log?->markDelivered($detail),

            InboundWebhookEvent::TYPE_FAILED,
            InboundWebhookEvent::TYPE_BOUNCE => $log?->markUndelivered(
                $event->event_type, $detail,
            ),

            /*
             * A STOP reply. In Ghana this is the only way many people will ever
             * opt out of SMS, and honouring it immediately is both courtesy and
             * the thing that keeps a sender ID in good standing.
             */
            InboundWebhookEvent::TYPE_UNSUBSCRIBE => Suppression::record(
                Suppression::CHANNEL_SMS, $number, Suppression::REASON_UNSUBSCRIBE,
                $detail, 'webhook',
            ),

            default => null,
        };
    }

    /**
     * A WhatsApp status from Meta (Wave 2): sent → delivered → read, or
     * failed. The row is found by Meta's message id, which the send stored;
     * "read" counts as delivered. A failure with Meta's code 131047 (the
     * 24-hour window) or 131026 (not on WhatsApp) marks the row
     * undelivered with the reason; nothing is suppressed on a failure —
     * a number that is not on WhatsApp today may be tomorrow.
     */
    private function applyToWhatsapp(InboundWebhookEvent $event): void
    {
        $parsed = json_decode((string) $event->raw_payload, true);

        /*
         * A message from somebody is a live chat, not a delivery report. It
         * goes to WhatsappInbox, which is idempotent on Meta's message id by
         * way of this table's own unique event id — a retried webhook must
         * not become a second copy of the visitor's question.
         */
        if ($event->event_type === InboundWebhookEvent::TYPE_INBOUND) {
            if (is_array($parsed)) {
                app(WhatsappInbox::class)->receive($parsed);
            }

            return;
        }

        $statuses = is_array($parsed) ? (array) data_get($parsed, 'entry.0.changes.0.value.statuses', []) : [];

        foreach ($statuses as $status) {
            $id = (string) ($status['id'] ?? '');
            $state = Str::lower((string) ($status['status'] ?? ''));

            if ($id === '') {
                continue;
            }

            $log = SmsLog::query()
                ->where('channel', SmsLog::CHANNEL_WHATSAPP)
                ->where('provider_message_id', $id)
                ->first();

            if ($log === null) {
                continue;
            }

            $reason = (string) (data_get($status, 'errors.0.title') ?? data_get($status, 'errors.0.message') ?? '');

            match ($state) {
                'delivered', 'read' => $log->status === SmsLog::STATUS_DELIVERED ? null : $log->markDelivered($state),
                'failed' => $log->markUndelivered('failed', $reason !== '' ? $reason : null),
                default => null,
            };
        }
    }

    /**
     * Suppress an address we have no log row for.
     *
     * A bounce for a message we did not send is still a bounce: it may be a
     * receipt sent before logging existed, or mail from another system on the
     * same domain. Ignoring it would leave a known-dead address on the list.
     */
    private function suppressWithoutLog(string $address, string $reason, ?string $detail): void
    {
        Suppression::record(Suppression::CHANNEL_EMAIL, $address, $reason, $detail, 'webhook');
    }

    /**
     * A stable id for an event that may not carry one.
     *
     * Where the provider sends no id, a hash of the body stands in — so a
     * replayed identical body still collides on the unique index. Without the
     * fallback, an event with no id would insert a fresh row on every delivery
     * and be processed every time.
     *
     * @param  array<string, mixed>  $parsed
     */
    private function eventId(string $provider, array $parsed, string $rawBody): string
    {
        if ($provider === 'meta') {
            $inbound = data_get($parsed, 'entry.0.changes.0.value.messages.0.id');

            if (is_string($inbound) && $inbound !== '') {
                return 'meta:in:'.$inbound;
            }

            $status = data_get($parsed, 'entry.0.changes.0.value.statuses.0');

            if (is_array($status) && isset($status['id'])) {
                return 'meta:'.$status['id'].':'.($status['status'] ?? '');
            }
        }

        $id = $parsed['id']
            ?? $parsed['_id']
            ?? $parsed['MessageID']
            ?? data_get($parsed, 'event-data.id')
            ?? data_get($parsed, 'data.email_id')
            ?? data_get($parsed, 'data.id');

        return is_scalar($id) && (string) $id !== ''
            ? $provider.':'.$id
            : $provider.':sha256:'.hash('sha256', $rawBody);
    }

    /**
     * Map a provider's vocabulary onto ours.
     *
     * Every provider has its own words for the same four things. Normalising
     * here means nothing downstream has to know which provider is in use — the
     * same reasoning that keeps Paystack's payload shape inside PaystackService.
     *
     * @param  array<string, mixed>  $parsed
     */
    private function normaliseType(string $provider, array $parsed): ?string
    {
        if ($provider === 'meta') {
            // A message from somebody, rather than a report about one of ours.
            if (data_get($parsed, 'entry.0.changes.0.value.messages.0') !== null) {
                return InboundWebhookEvent::TYPE_INBOUND;
            }

            return match (Str::lower((string) data_get($parsed, 'entry.0.changes.0.value.statuses.0.status', ''))) {
                'delivered', 'read' => InboundWebhookEvent::TYPE_DELIVERED,
                'failed' => InboundWebhookEvent::TYPE_FAILED,
                default => null, // `sent`: stored, not acted on
            };
        }

        $raw = Str::lower((string) (
            $parsed['event']
            ?? $parsed['type']
            ?? $parsed['RecordType']
            ?? $parsed['status']
            ?? data_get($parsed, 'event-data.event')
            ?? data_get($parsed, 'data.status')
            ?? ''
        ));

        if ($raw === '') {
            return null;
        }

        /*
         * A subscription change is NOT necessarily an unsubscribe.
         *
         * Postmark sends `SubscriptionChange` for both directions and puts the
         * direction in `SuppressSending`. Mapping the record type straight to
         * "unsubscribe" would suppress somebody who had just opted back in —
         * a silent removal of a person who explicitly asked to hear from us.
         *
         * A re-subscribe is not acted on here at all: the double opt-in on
         * `subscribers` owns that decision, and a provider event is not consent.
         */
        if (str_contains($raw, 'subscriptionchange')) {
            $suppressing = $parsed['SuppressSending'] ?? $parsed['suppress_sending'] ?? null;

            return filter_var($suppressing, FILTER_VALIDATE_BOOLEAN)
                ? InboundWebhookEvent::TYPE_UNSUBSCRIBE
                : null;
        }

        /*
         * Resend says `email.bounced` for both kinds and puts the kind in
         * `data.bounce.type`: Permanent is a dead address, Transient is a
         * full mailbox or a greylist. Only the first earns a suppression.
         */
        if ($raw === 'email.bounced') {
            return Str::lower((string) data_get($parsed, 'data.bounce.type', 'permanent')) === 'transient'
                ? InboundWebhookEvent::TYPE_SOFT_BOUNCE
                : InboundWebhookEvent::TYPE_BOUNCE;
        }

        return match (true) {
            str_contains($raw, 'complain'), str_contains($raw, 'spam') => InboundWebhookEvent::TYPE_COMPLAINT,
            str_contains($raw, 'unsubscrib'), $raw === 'stop' => InboundWebhookEvent::TYPE_UNSUBSCRIBE,

            // Order matters: "soft" must be tested before the general bounce.
            str_contains($raw, 'soft'), str_contains($raw, 'deferred'), str_contains($raw, 'delay') => InboundWebhookEvent::TYPE_SOFT_BOUNCE,
            str_contains($raw, 'bounce'), str_contains($raw, 'dropped') => InboundWebhookEvent::TYPE_BOUNCE,

            str_contains($raw, 'deliver') => InboundWebhookEvent::TYPE_DELIVERED,
            str_contains($raw, 'fail'), str_contains($raw, 'reject'),
            str_contains($raw, 'expire'), str_contains($raw, 'undeliver') => InboundWebhookEvent::TYPE_FAILED,

            default => null,
        };
    }

    /**
     * Which address or number the event is about.
     *
     * @param  array<string, mixed>  $parsed
     */
    private function subjectAddress(string $channel, array $parsed): ?string
    {
        $value = data_get($parsed, 'entry.0.changes.0.value.statuses.0.recipient_id')
            ?? $parsed['recipient']
            ?? $parsed['email']
            ?? $parsed['Email']
            ?? $parsed['msisdn']
            ?? $parsed['to']
            ?? data_get($parsed, 'event-data.recipient')
            ?? data_get($parsed, 'data.recipient')
            ?? data_get($parsed, 'data.to');

        // Resend gives `to` as a list; we send to one person at a time.
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        if (! is_scalar($value) || (string) $value === '') {
            return null;
        }

        return $channel === InboundWebhookEvent::CHANNEL_EMAIL
            ? mb_strtolower(trim((string) $value))
            : (Suppression::tryNormaliseAddress(Suppression::CHANNEL_SMS, '+'.ltrim((string) $value, '+'))
                ?? (string) $value);
    }

    /** The provider's own words for what happened, kept verbatim. */
    private function detail(InboundWebhookEvent $event): ?string
    {
        $payload = $event->payload();

        $reason = $payload['reason']
            ?? $payload['Details']
            ?? $payload['description']
            ?? data_get($payload, 'event-data.delivery-status.message')
            ?? data_get($payload, 'data.bounce.message')
            ?? data_get($payload, 'data.failed.reason')
            ?? null;

        return is_scalar($reason) ? Str::limit((string) $reason, 500) : null;
    }
}
