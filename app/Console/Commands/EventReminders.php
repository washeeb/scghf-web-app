<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Community\EventNotifier;
use App\Models\Event;
use Illuminate\Console\Command;

/**
 * The day before an event, remind the people who said they were coming.
 *
 * `event.reminder` was seeded in Phase 3 and nothing sent it. One pass per
 * event: `reminders_sent_at` is set when it goes, and every message carries
 * a per-person idempotency key and expires at the event start.
 */
class EventReminders extends Command
{
    protected $signature = 'scghf:event-reminders
                            {--execute : Queue the reminders rather than only listing them}
                            {--for= : The day to remind about (YYYY-MM-DD); default tomorrow}';

    protected $description = 'Remind registered people about tomorrow’s events by email and SMS';

    public function handle(EventNotifier $notifier): int
    {
        $day = $this->option('for') ? now()->parse((string) $this->option('for')) : now()->addDay();

        $events = Event::query()
            ->where('is_published', true)
            ->where('status', Event::STATUS_SCHEDULED)
            ->whereNull('reminders_sent_at')
            ->whereBetween('starts_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->withCount('registrations')
            ->orderBy('starts_at')
            ->get();

        if ($events->isEmpty()) {
            $this->info(sprintf('No events on %s waiting for reminders.', $day->toDateString()));

            return self::SUCCESS;
        }

        foreach ($events as $event) {
            $this->line(sprintf('%s — %s, %d registration(s)', $event->title, $event->starts_at->format('H:i'), $event->registrations_count));
        }

        if (! $this->option('execute')) {
            $this->warn('DRY RUN — nothing queued. Add --execute to send.');

            return self::SUCCESS;
        }

        foreach ($events as $event) {
            $queued = $notifier->remind($event);
            $event->forceFill(['reminders_sent_at' => now()])->save();
            $this->info(sprintf('%s: %d reminder(s) queued.', $event->title, $queued));
        }

        return self::SUCCESS;
    }
}
