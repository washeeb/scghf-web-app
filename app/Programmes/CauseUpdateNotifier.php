<?php

declare(strict_types=1);

namespace App\Programmes;

use App\Communications\MessageDispatcher;
use App\Enums\DonationStatus;
use App\Models\CauseUpdate;
use App\Models\Donation;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Telling the people who gave what their gift did.
 *
 * ── The single most valuable message a foundation sends ─────────────────────
 *
 * The commonest reason a donor does not give a second time is that they never
 * heard what the first gift did. This is the message that answers it.
 *
 * ── Only the donors of THIS appeal ──────────────────────────────────────────
 *
 * Not the mailing list. A foundation that mails everybody about one project
 * teaches its whole list to ignore it, and the people who actually funded the
 * thing get the same generic broadcast as somebody who signed up at an event
 * two years ago.
 *
 * ── Marketing consent is honoured, and that is not optional ─────────────────
 *
 * The template is categorised as marketing, so `MessageDispatcher` applies the
 * marketing suppressions and the unsubscribe header. Slipping campaign mail
 * through the transactional channel — where receipts live — is the fastest way
 * to have the receipts themselves stop arriving.
 *
 * ── One address per person, whatever they gave ──────────────────────────────
 *
 * A donor who gave to the same appeal five times gets one email. The obvious
 * implementation sends five, and the donor concludes the foundation cannot
 * count.
 *
 * ── Queued, one at a time, and a failure never stops the run ───────────────
 *
 * The host caps outbound mail per hour, so these go through the outbox rather
 * than being sent inline; the scheduler drains it a few at a time. One bad
 * address must not abandon the rest of the list.
 */
class CauseUpdateNotifier
{
    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    /**
     * Queue the update to everybody who gave to this appeal.
     *
     * @return int how many people it was queued for
     */
    public function notify(CauseUpdate $update): int
    {
        $cause = $update->cause;

        if ($cause === null) {
            return 0;
        }

        $recipients = $this->recipients($update);
        $queued = 0;

        foreach ($recipients as $email => $name) {
            try {
                $this->dispatcher->queueEmail('cause.update', (string) $email, [
                    'name' => $name,
                    'cause' => $cause->title,
                    'title' => $update->title,
                    'body' => (string) $update->body,
                    'cause_url' => route('causes.show', $cause),
                ]);

                $queued++;
            } catch (Throwable) {
                /*
                 * Suppressed, invalid, or over a rate limit. Skipped rather
                 * than abandoning the run: one bad address must not cost the
                 * other four hundred people their update.
                 */
            }
        }

        return $queued;
    }

    /**
     * Who should hear about this.
     *
     * ⚠ Completed gifts only, with an email address, who consented to updates,
     * and who did not give anonymously.
     *
     * The anonymity check is the one worth pausing on. `is_anonymous` is about
     * how a gift is DISPLAYED, and a donor who ticked it has told the
     * foundation something about how visible they want to be. Mailing them
     * about the appeal is not a breach of that on its own — but combined with a
     * gift they asked to keep quiet, it is the sort of thing that reads as the
     * foundation not having noticed. `consent_email` is the real gate; this is
     * the cautious reading of the other signal.
     *
     * @return Collection<string, string> email => name
     */
    private function recipients(CauseUpdate $update): Collection
    {
        return Donation::query()
            ->where('cause_id', $update->cause_id)
            ->where('status', DonationStatus::Completed->value)
            ->whereNotNull('donor_email')
            ->where('consent_email', true)
            ->where('is_anonymous', false)
            ->get(['donor_email', 'donor_name'])
            // Keyed by address, so a donor who gave five times gets one email.
            ->mapWithKeys(fn (Donation $donation): array => [
                mb_strtolower(trim((string) $donation->donor_email)) => (string) ($donation->donor_name ?? ''),
            ]);
    }
}
