<?php

declare(strict_types=1);

namespace App\Communications;

use App\Communications\Contracts\SmsGateway;
use App\Mail\RenderedMessage;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\NotificationLog;
use App\Models\ScheduledMessage;
use App\Models\SmsLog;
use App\Models\SmsTemplate;
use App\Models\Suppression;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use RuntimeException;
use Throwable;

/**
 * The only door out.
 *
 * Nothing in this application sends a message any other way. That is not tidiness
 * — it is what makes the suppression list, the logging and the rate limiting
 * true rather than merely available. A second send path would be a path with no
 * suppression check on it, and the first thing to escape through it would be an
 * appeal to somebody who had asked not to receive one.
 *
 * ── The order of operations, and why ────────────────────────────────────────
 *
 *   1. resolve the template          — missing or deactivated fails loudly here
 *   2. normalise the address         — an unnormalised address misses the list
 *   3. WRITE THE LOG                 — before anything can go wrong
 *   4. check the channel is enabled  — logged as `disabled`, not skipped
 *   5. check the suppression list    — logged as `suppressed`, with the reason
 *   6. render                        — a missing required variable throws
 *   7. hand to the transport
 *
 * Step 3 comes before steps 4 to 7 deliberately. Every refusal below produces a
 * row explaining itself, because the failure that costs most in this module is
 * the silent one: a donor who never received a receipt, and a foundation that
 * believes it sent one.
 *
 * ── Everything goes through the outbox ──────────────────────────────────────
 *
 * `queue()` is the normal path even for a receipt. One path means the throttle
 * is enforced in one place, the outbox is a real, visible queue somebody can
 * inspect, and a message can be cancelled before it goes. Transactional
 * messages carry priority 1, so in practice they leave on the next cron tick.
 */
class MessageDispatcher
{
    public function __construct(
        private readonly SmsGateway $sms,
        private readonly SmsSegmenter $segmenter,
    ) {}

    // ── Queueing ─────────────────────────────────────────────────────────────

    /**
     * Put an email in the outbox.
     *
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $options  related, user, send_after, expires_at,
     *                                         idempotency_key, priority, to_name
     */
    public function queueEmail(string $templateKey, string $to, array $variables = [], array $options = []): ScheduledMessage
    {
        $template = EmailTemplate::forKey($templateKey);

        return $this->queue(ScheduledMessage::CHANNEL_EMAIL, $templateKey, $template->category, $to, $variables, $options);
    }

    /**
     * Put an SMS in the outbox.
     *
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $options
     */
    public function queueSms(string $templateKey, string $to, array $variables = [], array $options = []): ScheduledMessage
    {
        $template = SmsTemplate::forKey($templateKey);

        return $this->queue(ScheduledMessage::CHANNEL_SMS, $templateKey, $template->category, $to, $variables, $options);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $options
     */
    private function queue(
        string $channel,
        string $templateKey,
        string $category,
        string $to,
        array $variables,
        array $options,
    ): ScheduledMessage {
        /** @var Model|null $related */
        $related = $options['related'] ?? null;

        return ScheduledMessage::create([
            'channel' => $channel,
            'template_key' => $templateKey,
            'category' => $category,
            'to_address' => $this->normalise($channel, $to),
            'to_name' => $options['to_name'] ?? null,
            'payload' => $this->freeze($variables),
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'user_id' => $options['user_id'] ?? null,
            'send_after' => $options['send_after'] ?? now(),
            'expires_at' => $options['expires_at'] ?? null,
            'priority' => $options['priority'] ?? null,
            // Unique. A webhook replayed, or a retry after a timeout that had
            // actually succeeded, collides here instead of producing a second
            // receipt.
            'idempotency_key' => $options['idempotency_key'] ?? null,
            'created_by' => $options['created_by'] ?? null,
        ]);
    }

    // ── Delivery ─────────────────────────────────────────────────────────────

    /**
     * Send one queued message.
     *
     * Called by the drain command. Returns the log row, whatever the outcome —
     * including a refusal, which is an outcome and not an absence.
     */
    public function deliver(ScheduledMessage $message): EmailLog|SmsLog
    {
        try {
            $log = $message->channel === ScheduledMessage::CHANNEL_EMAIL
                ? $this->sendEmailNow(
                    (string) $message->template_key,
                    (string) $message->to_address,
                    $this->thaw($message->payload ?? []),
                    $this->optionsFrom($message),
                )
                : $this->sendSmsNow(
                    (string) $message->template_key,
                    (string) $message->to_address,
                    $this->thaw($message->payload ?? []),
                    $this->optionsFrom($message),
                );
        } catch (Throwable $e) {
            $message->markAttemptFailed($e->getMessage());

            throw $e;
        }

        /*
         * The two log models share these status strings deliberately, so one
         * mapping covers both channels. A blocked message is NOT retried — the
         * suppression list will say the same thing in ten minutes, and retrying
         * it three times just writes the refusal into the log three times.
         */
        match ($log->status) {
            'sent' => $message->markSent(),
            'suppressed', 'disabled' => $message->markSuppressed((string) $log->blocked_reason),
            default => $message->markAttemptFailed((string) ($log->error ?? 'Not sent.')),
        };

        return $log;
    }

    /**
     * Variables on their way into the outbox.
     *
     * An `Htmlable` — the paragraphs of a receipt, the list of lines on an
     * order — cannot survive `json_encode`, which turns it into `{}`. It is
     * stored as a tagged array instead, and `thaw()` turns it back into an
     * HtmlString at delivery so the renderer still knows to print it unescaped.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function freeze(array $variables): array
    {
        foreach ($variables as $name => $value) {
            if ($value instanceof Htmlable) {
                $variables[$name] = ['__html' => $value->toHtml()];
            }
        }

        return $variables;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function thaw(array $payload): array
    {
        foreach ($payload as $name => $value) {
            if (is_array($value) && array_keys($value) === ['__html'] && is_string($value['__html'])) {
                $payload[$name] = new HtmlString($value['__html']);
            }
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function optionsFrom(ScheduledMessage $message): array
    {
        return [
            'to_name' => $message->to_name,
            'related_type' => $message->related_type,
            'related_id' => $message->related_id,
            'user_id' => $message->user_id,
            'unsubscribe_url' => data_get($message->payload, 'unsubscribe_url'),
        ];
    }

    // ── Email ────────────────────────────────────────────────────────────────

    /**
     * Render and send an email immediately.
     *
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $options
     */
    public function sendEmailNow(string $templateKey, string $to, array $variables = [], array $options = []): EmailLog
    {
        $template = EmailTemplate::forKey($templateKey);
        $address = mb_strtolower(trim($to));

        // The log exists before anything can go wrong with the message.
        $log = EmailLog::create([
            'email_template_id' => $template->getKey(),
            'template_key' => $template->key,
            'category' => $template->category,
            'to_address' => $address,
            'to_name' => $options['to_name'] ?? null,
            'from_address' => $template->from_address ?? config('mail.from.address'),
            'reply_to' => $template->reply_to,
            'subject' => $template->subject,
            'related_type' => $this->relatedType($options),
            'related_id' => $this->relatedId($options),
            'user_id' => $options['user_id'] ?? null,
            'status' => EmailLog::STATUS_QUEUED,
            'queued_at' => now(),
        ]);

        if (! config('communications.channels.mail', true)) {
            $log->markBlocked(
                EmailLog::STATUS_DISABLED,
                'Email is switched off for this environment, so nothing was sent.',
            );

            return $log;
        }

        $suppression = Suppression::blocking(Suppression::CHANNEL_EMAIL, $address, $template->category);

        if ($suppression !== null) {
            /*
             * The message stops here, the record does not. For a transactional
             * message this row is what tells Finance to post the receipt or
             * hand it over — silent non-delivery of a receipt is the failure
             * this whole module is built to make impossible.
             */
            $log->markBlocked(EmailLog::STATUS_SUPPRESSED, $suppression->explanation());

            if ($template->category === EmailTemplate::CATEGORY_TRANSACTIONAL) {
                Log::warning('A transactional email was suppressed and will need another route.', [
                    'template' => $template->key,
                    'log' => $log->ulid,
                    'reason' => $suppression->reason,
                ]);
            }

            return $log;
        }

        $unsubscribeUrl = $options['unsubscribe_url'] ?? null;

        /*
         * Bulk mail with no way off the list is what generates spam complaints,
         * and spam complaints are what stop receipts being delivered. So a
         * marketing message with no unsubscribe link is refused rather than
         * sent without one.
         */
        if ($template->requiresUnsubscribe() && blank($unsubscribeUrl)) {
            throw new RuntimeException(
                "Refusing to send [{$template->key}]: a marketing email must carry a working "
                .'unsubscribe link. Sending without one generates the complaints that stop '
                .'receipts being delivered.'
            );
        }

        $rendered = $template->render($variables);

        $log->forceFill([
            'subject' => $rendered['subject'],
            'body_html' => $template->storesBody() ? $rendered['html'] : null,
            'body_text' => $template->storesBody() ? $rendered['text'] : null,
            'body_stored' => $template->storesBody(),
            'status' => EmailLog::STATUS_SENDING,
        ])->save();

        // Only marketing mail, and only when the trustees switched it on.
        $rendered['html'] = app(EmailTracking::class)->instrument($rendered['html'], $log, $template);

        try {
            Mail::to($address, $options['to_name'] ?? null)
                ->send(new RenderedMessage(
                    subjectLine: $rendered['subject'],
                    bodyHtml: $rendered['html'],
                    bodyText: $rendered['text'],
                    preheader: $rendered['preheader'],
                    unsubscribeUrl: $unsubscribeUrl,
                    preferencesUrl: $options['preferences_url'] ?? null,
                    fromAddressOverride: $template->from_address,
                    fromNameOverride: $template->from_name,
                    replyToOverride: $template->reply_to,
                ));

            $log->markSent(mailer: (string) config('mail.default'));
        } catch (Throwable $e) {
            $log->markFailed($e->getMessage());
        }

        return $log;
    }

    // ── SMS ──────────────────────────────────────────────────────────────────

    /**
     * Render, cost and send an SMS immediately.
     *
     * The rendered body is measured again here, not trusted from the template.
     * `{{donor_name}}` is thirteen characters in the template and might be
     * twenty-five in the message — which is the difference between one segment
     * and two, on every message sent.
     *
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $options
     */
    public function sendSmsNow(string $templateKey, string $to, array $variables = [], array $options = []): SmsLog
    {
        $template = SmsTemplate::forKey($templateKey);
        $number = PhoneNumber::normalise($to);
        $body = $template->render($variables);
        $measurement = $this->segmenter->measure($body);

        $log = SmsLog::create([
            'sms_template_id' => $template->getKey(),
            'template_key' => $template->key,
            'category' => $template->category,
            'to_number' => $number,
            'network' => PhoneNumber::network($number),
            'sender_id' => $template->senderId(),
            'body' => $body,
            'encoding' => $measurement['encoding'],
            'character_count' => $measurement['characters'],
            'segments' => $measurement['segments'],
            'estimated_cost_minor' => $measurement['segments']
                * (int) config('communications.sms.cost_per_segment_minor', 4),
            'currency' => (string) config('payments.currency', 'GHS'),
            'driver' => $this->sms->name(),
            'related_type' => $this->relatedType($options),
            'related_id' => $this->relatedId($options),
            'user_id' => $options['user_id'] ?? null,
            'status' => SmsLog::STATUS_QUEUED,
            'queued_at' => now(),
        ]);

        if (! config('communications.channels.sms', true)) {
            $log->markBlocked(
                SmsLog::STATUS_DISABLED,
                'SMS is switched off for this environment, so nothing was sent.',
            );

            return $log;
        }

        $suppression = Suppression::blocking(Suppression::CHANNEL_SMS, $number, $template->category);

        if ($suppression !== null) {
            $log->markBlocked(SmsLog::STATUS_SUPPRESSED, $suppression->explanation());

            return $log;
        }

        if ($this->inQuietHours($template->category)) {
            $log->markBlocked(
                SmsLog::STATUS_SUPPRESSED,
                'Held back: marketing SMS is not sent during quiet hours.',
            );

            return $log;
        }

        try {
            $result = $this->sms->send($log);

            if ($result->successful) {
                $log->markSent($result->providerMessageId, $result->providerStatus);
            } else {
                $log->markFailed((string) $result->message);
            }
        } catch (Throwable $e) {
            $log->markFailed($e->getMessage());
        }

        return $log;
    }

    /**
     * Whether a marketing SMS would arrive in the middle of the night.
     *
     * Applies to marketing only. A payment confirmation goes when the payment
     * goes — somebody who has just given at eleven at night should be told it
     * worked, not told in the morning.
     */
    private function inQuietHours(string $category): bool
    {
        /** @var array<int, string> $applies */
        $applies = config('communications.sms.quiet_hours_apply_to', []);

        if (! in_array($category, $applies, true)) {
            return false;
        }

        $from = (string) config('communications.sms.quiet_hours.from', '21:00');
        $to = (string) config('communications.sms.quiet_hours.to', '07:00');
        $now = now()->format('H:i');

        // The window crosses midnight, so it is "after 21:00 OR before 07:00",
        // not "between" — which would be an empty range.
        return $from > $to
            ? ($now >= $from || $now < $to)
            : ($now >= $from && $now < $to);
    }

    // ── In-app notifications ─────────────────────────────────────────────────

    /**
     * Record an in-app notification and fan it out to the channels asked for.
     *
     * The record is written whatever happens to the channels, so the gap
     * between "we tried to reach them three ways" and "one of them worked" is
     * visible. That gap is usually what somebody is asking about.
     *
     * @param  array<int, string>  $channels
     * @param  array<string, mixed>  $options
     */
    public function notify(
        Model $notifiable,
        string $key,
        string $title,
        ?string $body = null,
        array $channels = ['database'],
        array $options = [],
    ): NotificationLog {
        /** @var Model|null $related */
        $related = $options['related'] ?? null;

        $notification = NotificationLog::create([
            'notifiable_type' => $notifiable->getMorphClass(),
            'notifiable_id' => $notifiable->getKey(),
            'key' => $key,
            'title' => $title,
            'body' => $body,
            'action_url' => $options['action_url'] ?? null,
            'action_label' => $options['action_label'] ?? null,
            'level' => $options['level'] ?? NotificationLog::LEVEL_INFO,
            'channels' => $channels,
            'delivered_channels' => [],
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
        ]);

        if (in_array('database', $channels, true)) {
            $notification->recordDelivery('database');
        }

        foreach (['mail' => 'email_template', 'sms' => 'sms_template'] as $channel => $templateOption) {
            if (! in_array($channel, $channels, true) || blank($options[$templateOption] ?? null)) {
                continue;
            }

            $address = $channel === 'mail'
                ? ($options['email'] ?? null)
                : ($options['phone'] ?? null);

            if (blank($address)) {
                continue;
            }

            $queued = $channel === 'mail'
                ? $this->queueEmail((string) $options[$templateOption], (string) $address, $options['variables'] ?? [], ['related' => $related])
                : $this->queueSms((string) $options[$templateOption], (string) $address, $options['variables'] ?? [], ['related' => $related]);

            // Queued, not delivered. The channel is only recorded as delivered
            // once something actually went — which is the drain's job, not this
            // method's.
            unset($queued);
        }

        return $notification;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function normalise(string $channel, string $address): string
    {
        return $channel === ScheduledMessage::CHANNEL_SMS
            ? PhoneNumber::normalise($address)
            : mb_strtolower(trim($address));
    }

    /** @param array<string, mixed> $options */
    private function relatedType(array $options): ?string
    {
        if (isset($options['related']) && $options['related'] instanceof Model) {
            return $options['related']->getMorphClass();
        }

        return $options['related_type'] ?? null;
    }

    /** @param array<string, mixed> $options */
    private function relatedId(array $options): int|string|null
    {
        if (isset($options['related']) && $options['related'] instanceof Model) {
            return $options['related']->getKey();
        }

        return $options['related_id'] ?? null;
    }
}
