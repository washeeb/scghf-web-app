<?php

declare(strict_types=1);

namespace App\Communications;

use App\Models\CampaignRecipient;
use App\Models\EmailLog;
use App\Models\NewsletterCampaign;
use App\Models\Subscriber;
use App\Models\Suppression;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * Builds a campaign's recipient list, then sends it a batch at a time.
 *
 * ── Building and sending are separate, and hours apart ──────────────────────
 *
 * The host sends a couple of hundred messages an hour, so a campaign to two
 * thousand subscribers takes most of a day. That single fact shapes everything
 * here:
 *
 *   - the list is built once and stored, so progress survives a killed worker
 *   - suppression is re-read at the moment each message goes, because somebody
 *     who unsubscribes in hour three has unsubscribed
 *   - a campaign can be PAUSED mid-flight, which is only meaningful because
 *     there are still 1,600 messages left to stop
 *
 * ── One door out ────────────────────────────────────────────────────────────
 *
 * Campaign mail goes through MessageDispatcher like everything else. The
 * campaign's own body is passed into the seeded `newsletter.campaign` template
 * as `{{content}}`, so the branding, the footer, the unsubscribe link and the
 * suppression check are the same code that handles a donation receipt. A
 * separate sending path for bulk would be a path with no suppression check on
 * it — and bulk is exactly where that matters most.
 */
class CampaignSender
{
    public const TEMPLATE_KEY = 'newsletter.campaign';

    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    /**
     * Materialise the recipient list.
     *
     * Rebuildable: running it again adds anybody who subscribed since, and
     * leaves existing rows (and their sent/skipped state) alone. That matters
     * on a send measured in hours — a campaign half-way through should be able
     * to pick up new subscribers without re-sending to the first thousand.
     *
     * @return int how many recipients the list now holds
     */
    public function build(NewsletterCampaign $campaign): int
    {
        $newsletter = $campaign->newsletter;

        $campaign->forceFill(['status' => NewsletterCampaign::STATUS_BUILDING])->save();

        /*
         * Targeting is by TOPIC, not by division.
         *
         * `subscribers` records what somebody asked to hear about; it does not
         * record which division they belong to, because they do not belong to
         * one — a supporter is a supporter of the Foundation. A campaign's
         * `division_id` is therefore attribution for reporting, not a filter,
         * and the narrowing that does happen is the topic the newsletter
         * declares.
         */
        $newsletter->subscriberQuery()
            ->chunkById(500, function ($subscribers) use ($campaign): void {
                $rows = [];

                foreach ($subscribers as $subscriber) {
                    /** @var Subscriber $subscriber */
                    $rows[] = [
                        'newsletter_campaign_id' => $campaign->getKey(),
                        'subscriber_id' => $subscriber->getKey(),
                        'email' => mb_strtolower(trim((string) $subscriber->email)),
                        'name' => $subscriber->name,
                        'status' => CampaignRecipient::STATUS_PENDING,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if ($rows !== []) {
                    /*
                     * insertOrIgnore against the unique (campaign, email) index.
                     * Somebody subscribed twice under two names gets one copy,
                     * and a rebuild does not duplicate or reset anybody.
                     */
                    DB::table('campaign_recipients')->insertOrIgnore($rows);
                }
            });

        $campaign->refreshCounters();

        $campaign->forceFill([
            'status' => $campaign->status === NewsletterCampaign::STATUS_BUILDING
                ? NewsletterCampaign::STATUS_DRAFT
                : $campaign->status,
        ])->save();

        return (int) $campaign->recipient_count;
    }

    /**
     * Send the next batch.
     *
     * Returns a summary rather than throwing on individual failures: one bad
     * address must not stop the other 1,999.
     *
     * @return array{sent: int, skipped: int, failed: int, remaining: int}
     */
    public function sendBatch(NewsletterCampaign $campaign, int $limit): array
    {
        if ($campaign->status === NewsletterCampaign::STATUS_PAUSED) {
            return ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'remaining' => 0];
        }

        /*
         * The gate is checked on the FIRST batch only. A send takes hours and
         * arrives here dozens of times; re-asserting would fail on the second
         * batch, because "already sending" is itself one of the reasons a
         * campaign may not be started.
         */
        if ($campaign->status !== NewsletterCampaign::STATUS_SENDING) {
            $campaign->assertSendable();
        }

        $campaign->markSending();

        $batch = $this->claim($campaign, $limit);
        $summary = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'remaining' => 0];

        foreach ($batch as $recipient) {
            $outcome = $this->sendOne($campaign, $recipient);
            $summary[$outcome]++;
        }

        $campaign->refreshCounters();

        $summary['remaining'] = $campaign->recipients()
            ->where('status', CampaignRecipient::STATUS_PENDING)
            ->count();

        if ($summary['remaining'] === 0 && $campaign->recipient_count > 0) {
            $campaign->markComplete();
        }

        return $summary;
    }

    /**
     * Claim a batch under a lock.
     *
     * Two overlapping cron workers can otherwise both pick up the same
     * recipient, and the second copy of a newsletter is the one that gets
     * reported as spam.
     *
     * @return Collection<int, CampaignRecipient>
     */
    private function claim(NewsletterCampaign $campaign, int $limit): Collection
    {
        return DB::transaction(function () use ($campaign, $limit) {
            $batch = $campaign->recipients()
                // Eager-loaded: every recipient's consent is re-read before its
                // message goes, and doing that lazily would be one query per
                // person on a two-thousand-person send.
                ->with('subscriber')
                ->where('status', CampaignRecipient::STATUS_PENDING)
                ->whereNull('claimed_at')
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            if ($batch->isNotEmpty()) {
                CampaignRecipient::query()
                    ->whereIn('id', $batch->modelKeys())
                    ->update(['claimed_at' => now(), 'updated_at' => now()]);
            }

            return $batch;
        });
    }

    /** @return 'sent'|'skipped'|'failed' */
    private function sendOne(NewsletterCampaign $campaign, CampaignRecipient $recipient): string
    {
        $subscriber = $recipient->subscriber;

        /*
         * Re-read the subscriber, not the row we built hours ago. Somebody who
         * unsubscribed since the list was built has unsubscribed, and the whole
         * reason this check is here rather than at build time is that hours
         * pass in between.
         */
        if ($subscriber === null || $subscriber->unsubscribed_at !== null) {
            $recipient->skip(CampaignRecipient::SKIP_UNSUBSCRIBED);

            return 'skipped';
        }

        if ($subscriber->status !== 'confirmed') {
            $recipient->skip(CampaignRecipient::SKIP_UNCONFIRMED);

            return 'skipped';
        }

        if (Suppression::blocks(Suppression::CHANNEL_EMAIL, $recipient->email, 'marketing')) {
            $recipient->skip(CampaignRecipient::SKIP_SUPPRESSED);

            return 'skipped';
        }

        /*
         * No unsubscribe token means no way off the list, and a bulk message
         * with no way off the list is what generates the complaints that stop
         * receipts being delivered. Skipped rather than sent without one.
         */
        $unsubscribeUrl = $this->unsubscribeUrl($subscriber);

        if ($unsubscribeUrl === null) {
            $recipient->skip(CampaignRecipient::SKIP_INVALID);

            return 'skipped';
        }

        $log = $this->dispatcher->sendEmailNow(
            self::TEMPLATE_KEY,
            $recipient->email,
            [
                'subject' => $campaign->subject,
                'content' => new HtmlString((string) $campaign->body_html),
                'content_text' => $campaign->body_text ?? strip_tags((string) $campaign->body_html),
                'preheader' => $campaign->preheader,
                'subscriber_name' => $subscriber->name ?? '',
                'unsubscribe_url' => $unsubscribeUrl,
            ],
            [
                'to_name' => $recipient->name,
                'related' => $campaign,
                'unsubscribe_url' => $unsubscribeUrl,
            ],
        );

        if ($log->status === EmailLog::STATUS_SENT) {
            $recipient->markSent($log);

            return 'sent';
        }

        if ($log->status === EmailLog::STATUS_SUPPRESSED) {
            // The dispatcher checked again, at the last possible moment, and
            // found something this method's earlier check did not.
            $recipient->skip(CampaignRecipient::SKIP_SUPPRESSED, $log);

            return 'skipped';
        }

        $recipient->fail($log);

        return 'failed';
    }

    /**
     * The one-click unsubscribe link for this subscriber.
     *
     * Built from the token that already exists on the subscriber row — no
     * login, no confirmation page. An unsubscribe that takes three clicks is an
     * unsubscribe that becomes a spam complaint.
     */
    private function unsubscribeUrl(Subscriber $subscriber): ?string
    {
        $token = $subscriber->unsubscribe_token;

        if (blank($token)) {
            return null;
        }

        $path = trim((string) config('communications.newsletter.unsubscribe_path', '/newsletter/unsubscribe'), '/');

        return url($path.'/'.$token);
    }

    /**
     * Send the campaign to one address as a test.
     *
     * Required before a real send. It goes through exactly the same path as the
     * real thing — same template, same rendering, same unsubscribe handling —
     * because a test that takes a different route tests the wrong thing.
     */
    public function sendTest(NewsletterCampaign $campaign, string $address): EmailLog
    {
        $log = $this->dispatcher->sendEmailNow(
            self::TEMPLATE_KEY,
            $address,
            [
                'subject' => '[TEST] '.$campaign->subject,
                'content' => new HtmlString((string) $campaign->body_html),
                'content_text' => $campaign->body_text ?? strip_tags((string) $campaign->body_html),
                'preheader' => $campaign->preheader,
                'subscriber_name' => 'Test recipient',
                'unsubscribe_url' => url('/newsletter/unsubscribe/test'),
            ],
            [
                'related' => $campaign,
                'unsubscribe_url' => url('/newsletter/unsubscribe/test'),
            ],
        );

        if ($log->status === EmailLog::STATUS_SENT) {
            $campaign->recordTestSend($address);
        }

        return $log;
    }
}
