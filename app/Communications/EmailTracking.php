<?php

declare(strict_types=1);

namespace App\Communications;

use App\Models\EmailLog;
use App\Models\EmailTemplate;
use Illuminate\Support\Facades\URL;

/**
 * Open and click tracking, off unless the trustees switch it on.
 *
 * ── Marketing only, and only when enabled ───────────────────────────────────
 *
 * `COMMS_TRACK_OPENS` and `COMMS_TRACK_CLICKS` have been in `.env.example`
 * since Phase 3 and read by nothing. Knowing who read a message, and when,
 * is Act 843 processing that needs its own lawful basis and its own line in
 * the privacy notice — so it is a decision the trustees take, recorded in
 * `.env`, and it never applies to a receipt or a password reset: those are
 * not things to watch somebody read.
 *
 * ── How ────────────────────────────────────────────────────────────────────
 *
 * An open is a one-pixel image whose address names the log row. A click is
 * every link rewritten through a signed redirect that names the log row and
 * the destination; the signature stops the redirect being used to bounce
 * people to addresses of somebody else's choosing. Both count on the log,
 * nowhere else.
 */
final class EmailTracking
{
    public function opensEnabled(EmailTemplate $template): bool
    {
        return (bool) config('communications.tracking.opens', false) && $template->category === EmailTemplate::CATEGORY_MARKETING;
    }

    public function clicksEnabled(EmailTemplate $template): bool
    {
        return (bool) config('communications.tracking.clicks', false) && $template->category === EmailTemplate::CATEGORY_MARKETING;
    }

    /** Rewrite the rendered HTML for this log row. */
    public function instrument(string $html, EmailLog $log, EmailTemplate $template): string
    {
        if ($this->clicksEnabled($template)) {
            $html = (string) preg_replace_callback(
                '/href="(https?:\/\/[^"]+)"/i',
                fn (array $m): string => 'href="'.e($this->clickUrl($log, html_entity_decode($m[1]))).'"',
                $html,
            );
        }

        if ($this->opensEnabled($template)) {
            $html .= '<img src="'.e($this->openUrl($log)).'" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;">';
        }

        return $html;
    }

    public function openUrl(EmailLog $log): string
    {
        return route('track.open', ['log' => $log->ulid]);
    }

    public function clickUrl(EmailLog $log, string $destination): string
    {
        // An unsubscribe link is never rewritten: a one-click unsubscribe that
        // goes through a redirect is not one click, and a mail client's
        // List-Unsubscribe check may refuse it.
        if (str_contains($destination, '/newsletter/unsubscribe/') || str_contains($destination, '/newsletter/preferences/')) {
            return $destination;
        }

        return URL::signedRoute('track.click', ['log' => $log->ulid, 'to' => $destination]);
    }
}
