<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Community\VolunteerNotifier;
use App\Models\VolunteerShift;
use Illuminate\Console\Command;

/**
 * The evening before, tell each volunteer about tomorrow's shift.
 *
 * Deferred from Phase 10, where the template would have had nothing to
 * remind about. One message per shift, marked on the shift when it goes so
 * a second run the same evening sends nothing. Goes into the ordinary
 * outbox, which respects quiet hours — the cron line runs at 17:00 so it
 * is out before they start.
 */
class ShiftReminders extends Command
{
    protected $signature = 'scghf:shift-reminders
                            {--execute : Queue the reminders rather than only listing them}
                            {--for= : The day to remind about (YYYY-MM-DD); default tomorrow}';

    protected $description = 'Remind volunteers about tomorrow’s shifts by email and SMS';

    public function handle(VolunteerNotifier $notifier): int
    {
        $day = $this->option('for') ? now()->parse((string) $this->option('for')) : now()->addDay();

        $shifts = VolunteerShift::query()
            ->with(['volunteer', 'opportunity'])
            ->needingReminderOn($day)
            ->orderBy('starts_at')
            ->get();

        if ($shifts->isEmpty()) {
            $this->info(sprintf('No shifts on %s waiting for a reminder.', $day->toDateString()));

            return self::SUCCESS;
        }

        foreach ($shifts as $shift) {
            $this->line(sprintf(
                '%s — %s, %s%s',
                $shift->volunteer?->full_name ?? '(no volunteer)',
                $shift->starts_at->format('H:i'),
                $shift->activity ?: ($shift->opportunity?->title ?? 'volunteering'),
                $shift->volunteer?->isAvailable() ? '' : '  [skipped: not currently available]',
            ));
        }

        if (! $this->option('execute')) {
            $this->warn('DRY RUN — nothing queued. Add --execute to send.');

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($shifts as $shift) {
            $notifier->shiftReminder($shift);
            $shift->forceFill(['reminder_sent_at' => now()])->save();
            $sent++;
        }

        $this->info(sprintf('%d reminder(s) queued.', $sent));

        return self::SUCCESS;
    }
}
