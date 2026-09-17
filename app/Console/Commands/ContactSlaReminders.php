<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Communications\MessageDispatcher;
use App\Filament\Resources\ContactMessages\ContactMessageResource;
use App\Models\ContactMessage;
use Illuminate\Console\Command;
use Throwable;

/**
 * An enquiry that has waited past its department's reply target tells
 * somebody — once.
 *
 * `isOverdue()` has driven a badge in the inbox since Phase 5, which is
 * useful to somebody looking at the inbox and useless to somebody who is
 * not. The reminder goes to whoever the enquiry is assigned to; failing
 * that the department's mailbox; failing that the general address. Once
 * per message: a nag every hour is a filter rule waiting to be written.
 */
class ContactSlaReminders extends Command
{
    protected $signature = 'scghf:contact-sla {--execute : Send the reminders rather than only listing them}';

    protected $description = 'Email whoever owns an enquiry that has waited past its department’s reply target';

    public function handle(MessageDispatcher $dispatcher): int
    {
        $overdue = ContactMessage::query()
            ->with(['department', 'assignee'])
            ->needingSlaReminder()
            ->orderBy('created_at')
            ->get();

        if ($overdue->isEmpty()) {
            $this->info('Nothing is past its reply target.');

            return self::SUCCESS;
        }

        foreach ($overdue as $message) {
            $this->line(sprintf('%s — %s (%s), waiting %s', $message->reference, $message->name, $message->department?->name, $message->created_at->diffForHumans(null, true)));
        }

        if (! $this->option('execute')) {
            $this->warn('DRY RUN — nothing sent. Add --execute to send.');

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($overdue as $message) {
            $to = $message->assignee?->email
                ?: $message->department?->email
                ?: (string) setting('contact.email_general', '');

            if (blank($to) || str_contains($to, '{{')) {
                $this->warn(sprintf('%s: nobody to tell (no owner, no department address, no general address).', $message->reference));

                continue;
            }

            try {
                $dispatcher->queueEmail('contact.sla_reminder', $to, [
                    'reference' => $message->reference,
                    'from_name' => $message->name,
                    'subject' => $message->subject ?: __('(no subject)'),
                    'department' => $message->department?->name ?? __('General'),
                    'waiting' => $message->created_at->diffForHumans(null, true),
                    'target_hours' => (string) $message->department?->sla_hours,
                    'admin_url' => ContactMessageResource::getUrl('edit', ['record' => $message]),
                ], [
                    'to_name' => $message->assignee?->name,
                    'related' => $message,
                    'user_id' => $message->assignee?->getKey(),
                    'idempotency_key' => 'contact.sla_reminder:'.$message->reference,
                ]);

                $message->forceFill(['sla_reminded_at' => now()])->save();
                $sent++;
            } catch (Throwable $e) {
                report($e);
                $this->error(sprintf('%s: %s', $message->reference, $e->getMessage()));
            }
        }

        $this->info(sprintf('%d reminder(s) queued.', $sent));

        return self::SUCCESS;
    }
}
