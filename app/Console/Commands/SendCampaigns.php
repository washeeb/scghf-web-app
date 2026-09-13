<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Communications\CampaignSender;
use App\Communications\SendThrottle;
use App\Models\NewsletterCampaign;
use Illuminate\Console\Command;
use Throwable;

/**
 * Move every scheduled or sending campaign along by one batch.
 *
 * ── The gap this closes ─────────────────────────────────────────────────────
 *
 * `CampaignSender::sendBatch()` was written and tested in Phase 3 and
 * called by nothing on a schedule: a campaign approved and scheduled for
 * Tuesday morning stayed "scheduled" forever. This runs every five minutes
 * and sends as much as the hourly mail allowance leaves — the same
 * allowance the transactional outbox draws on, so a newsletter can never
 * crowd a receipt out of the hour.
 *
 * A campaign whose list was never built is built here first; one whose
 * gate fails (no test, no approval) is marked failed with the reason, so
 * the admin shows why rather than a status that never changes.
 */
class SendCampaigns extends Command
{
    protected $signature = 'scghf:send-campaigns
                            {--limit= : Send at most this many in this run, regardless of the allowance}';

    protected $description = 'Send the next batch of every newsletter campaign that is due';

    public function handle(CampaignSender $sender, SendThrottle $throttle): int
    {
        $due = NewsletterCampaign::query()
            ->whereIn('status', [NewsletterCampaign::STATUS_SCHEDULED, NewsletterCampaign::STATUS_SENDING])
            ->where(fn ($q) => $q->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', now()))
            ->orderBy('scheduled_for')
            ->get();

        if ($due->isEmpty()) {
            $this->info('No campaign is due.');

            return self::SUCCESS;
        }

        $allowance = $this->option('limit') !== null
            ? (int) $this->option('limit')
            : $throttle->batchSize('mail');

        if ($allowance < 1) {
            $this->warn($throttle->explain('mail'));

            return self::SUCCESS;
        }

        foreach ($due as $campaign) {
            if ($allowance < 1) {
                break;
            }

            try {
                if ($campaign->recipient_count < 1 && $campaign->status === NewsletterCampaign::STATUS_SCHEDULED) {
                    $sender->build($campaign);
                    // build() leaves a draft as a draft; a scheduled one stays scheduled.
                    $campaign->forceFill(['status' => NewsletterCampaign::STATUS_SCHEDULED])->save();
                }

                $summary = $sender->sendBatch($campaign->fresh(), $allowance);
            } catch (Throwable $e) {
                $campaign->forceFill(['status' => NewsletterCampaign::STATUS_FAILED, 'failure_reason' => $e->getMessage()])->save();
                $this->error(sprintf('%s: %s', $campaign->title, $e->getMessage()));

                continue;
            }

            $allowance -= $summary['sent'];

            $this->line(sprintf(
                '%s — sent %d, skipped %d, failed %d, %d remaining',
                $campaign->title,
                $summary['sent'],
                $summary['skipped'],
                $summary['failed'],
                $summary['remaining'],
            ));
        }

        return self::SUCCESS;
    }
}
