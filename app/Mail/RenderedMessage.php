<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * A message whose body has already been rendered from a CMS template.
 *
 * Deliberately dumb. All the decisions — which template, which variables,
 * whether the address is suppressed — were made by MessageDispatcher before
 * this object existed. This class only knows how to hand Laravel a subject, a
 * body and the right headers.
 *
 * The one thing it does add is the List-Unsubscribe pair, and that is not
 * cosmetic: without it Gmail and Yahoo now penalise bulk senders, and a
 * penalised sending domain stops delivering donation receipts, not just
 * appeals.
 */
class RenderedMessage extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $bodyHtml,
        public ?string $bodyText = null,
        public ?string $preheader = null,
        public ?string $unsubscribeUrl = null,
        public ?string $preferencesUrl = null,
        public ?string $fromAddressOverride = null,
        public ?string $fromNameOverride = null,
        public ?string $replyToOverride = null,
    ) {}

    public function envelope(): Envelope
    {
        $envelope = new Envelope(subject: $this->subjectLine);

        if ($this->fromAddressOverride !== null) {
            $envelope = $envelope->from($this->fromAddressOverride, $this->fromNameOverride);
        }

        $replyTo = $this->replyToOverride ?? config('mail.reply_to.address');

        if (filled($replyTo)) {
            $envelope = $envelope->replyTo($replyTo, $this->replyToOverride === null ? config('mail.reply_to.name') : null);
        }

        return $envelope;
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.layouts.default',
            text: $this->bodyText === null ? null : 'mail.layouts.plain',
        );
    }

    /**
     * RFC 8058 one-click unsubscribe.
     *
     * Both headers or neither: `List-Unsubscribe-Post` without a URL is
     * meaningless, and the URL without the Post header gives the reader a link
     * their mail client will not surface as a button — which sends them to the
     * spam button instead, which is the outcome the whole suppression list
     * exists to avoid.
     */
    public function headers(): Headers
    {
        if ($this->unsubscribeUrl === null) {
            return new Headers;
        }

        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }
}
