<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Communications\MessageDispatcher;
use App\Filament\Resources\Grants\GrantResource;
use App\Models\GrantObligation;
use Illuminate\Console\Command;
use Throwable;

/**
 * A funder's report falls due in a fortnight: tell the grant's owner, and
 * tell them again a week later if it is still not done. Deadlines are
 * the whole point of the grants screen (ROADMAP §1.6); a deadline that
 * only shows on a page nobody is looking at is missed.
 */
class GrantReminders extends Command
{
    protected $signature = 'scghf:grant-reminders {--execute : Send the reminders rather than only listing them} {--days=14 : How far ahead to look}';

    protected $description = 'Email grant owners about funder obligations falling due or overdue';

    public function handle(MessageDispatcher $dispatcher): int
    {
        $days = max(1, (int) $this->option('days'));

        $due = GrantObligation::query()
            ->with(['grant.owner', 'grant.funder'])
            ->needingReminder($days)
            ->orderBy('due_on')
            ->get();

        if ($due->isEmpty()) {
            $this->info('No grant obligations are due within '.$days.' days.');

            return self::SUCCESS;
        }

        foreach ($due as $obligation) {
            $this->line(sprintf('%s — %s (%s), due %s%s', $obligation->grant?->title, $obligation->title, $obligation->grant?->funder?->name, $obligation->due_on->toDateString(), $obligation->isOverdue() ? ' — OVERDUE' : ''));
        }

        if (! $this->option('execute')) {
            $this->warn('DRY RUN — nothing sent. Add --execute to send.');

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($due as $obligation) {
            $grant = $obligation->grant;
            $to = $grant?->owner?->email ?: (string) setting('contact.email_donations', setting('contact.email_general', ''));

            if ($grant === null || blank($to) || str_contains($to, '{{')) {
                $this->warn(sprintf('%s: nobody to tell (no owner and no finance address).', $obligation->title));

                continue;
            }

            try {
                $dispatcher->queueEmail('grants.obligation_due', $to, [
                    'grant' => $grant->title,
                    'funder' => (string) $grant->funder?->name,
                    'obligation' => $obligation->title,
                    'kind' => GrantObligation::KINDS[$obligation->kind] ?? $obligation->kind,
                    'due_on' => $obligation->due_on->format('j F Y'),
                    'when' => $obligation->isOverdue() ? __('overdue by :days days', ['days' => (int) $obligation->due_on->diffInDays(now())]) : __('due in :days days', ['days' => (int) now()->startOfDay()->diffInDays($obligation->due_on)]),
                    'admin_url' => GrantResource::getUrl('view', ['record' => $grant]),
                ], [
                    'to_name' => $grant->owner?->name,
                    'related' => $grant,
                    'user_id' => $grant->owner?->getKey(),
                    'idempotency_key' => 'grants.obligation_due:'.$obligation->getKey().':'.now()->format('Y-m-d'),
                ]);

                $obligation->forceFill(['reminded_at' => now()])->save();
                $sent++;
            } catch (Throwable $e) {
                $this->error(sprintf('%s: %s', $obligation->title, $e->getMessage()));
            }
        }

        $this->info(sprintf('%d reminder(s) queued.', $sent));

        return self::SUCCESS;
    }
}
