<?php

declare(strict_types=1);

namespace App\Community;

use App\Communications\MessageDispatcher;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\IssuedTicket;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Telling people about an event they registered for.
 *
 * ── The confirmation existed; nothing sent it ───────────────────────────────
 *
 * `event.registration_confirmed` has been seeded since Phase 3 with no caller.
 * `Event::cancel()` has said "everybody registered will be told" in its own
 * docblock since Phase 3, and nothing told them.
 *
 * ── Transactional, so it needs no marketing consent ─────────────────────────
 *
 * A confirmation of something somebody just did, and a cancellation of it,
 * are messages about their own registration. `contact_consent` on the
 * registration is for anything ELSE about the event — a reminder, a change of
 * venue — and the cancellation honours it too, because a cancellation is the
 * one message about an event that nobody should miss.
 *
 * ── The join link goes in the email, not on the page ────────────────────────
 *
 * An online event's link is sent to the people who registered. Printing it on
 * the public page would make registration pointless.
 */
final class EventNotifier
{
    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    public function confirm(EventRegistration $registration): void
    {
        $event = $registration->event;

        if ($event === null || blank($registration->email)) {
            return;
        }

        try {
            $this->dispatcher->queueEmail('event.registration_confirmed', (string) $registration->email, [
                'name' => $registration->name,
                'event_title' => $event->title,
                'event_date' => $event->starts_at->format('l j F Y, H:i'),
                'venue' => $this->venue($event),
                'reference' => $registration->reference,
                'status' => $registration->status === EventRegistration::STATUS_WAITLISTED
                    ? __('You are on the waiting list — we will let you know if a place opens up.')
                    : __('Your place is confirmed.'),
                'online_url' => $event->is_online ? $event->online_url : null,
            ], [
                'to_name' => $registration->name,
                'related' => $registration,
                'user_id' => $registration->user_id,
                'idempotency_key' => 'event.registration_confirmed:'.$registration->reference,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The event is off. Tell everybody who was coming.
     *
     * @return int how many people were queued a message
     */
    public function cancelled(Event $event, string $reason): int
    {
        $queued = 0;

        $registrations = $event->registrations()
            ->whereIn('status', [EventRegistration::STATUS_REGISTERED, EventRegistration::STATUS_WAITLISTED])
            ->whereNotNull('email')
            ->get();

        foreach ($registrations as $registration) {
            try {
                $this->dispatcher->queueEmail('event.cancelled', (string) $registration->email, [
                    'name' => $registration->name,
                    'event_title' => $event->title,
                    'event_date' => $event->starts_at->format('l j F Y, H:i'),
                    'reason' => $reason,
                    'reference' => $registration->reference,
                ], [
                    'to_name' => $registration->name,
                    'related' => $registration,
                    'user_id' => $registration->user_id,
                    'priority' => 1,
                    'idempotency_key' => 'event.cancelled:'.$registration->reference,
                ]);

                $queued++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $queued;
    }

    /**
     * The day before. Email to everybody registered who agreed to be
     * contacted about the event; a text as well where there is a number.
     * Both expire at the event start — a reminder that arrives afterwards
     * is worse than none.
     *
     * @return int how many people were queued a message
     */
    public function remind(Event $event): int
    {
        $queued = 0;

        $registrations = $event->registrations()
            ->with('tickets')
            ->where('status', EventRegistration::STATUS_REGISTERED)
            ->where('contact_consent', true)
            ->get();

        $where = collect([$event->venue_name, $event->address, $event->area, $event->region])->filter();
        $directions = $event->is_online || $where->isEmpty()
            ? null
            : 'https://www.google.com/maps/search/?api=1&query='.urlencode($where->implode(', ').', Ghana');

        foreach ($registrations as $registration) {
            $tickets = $registration->tickets
                ->filter(fn (IssuedTicket $t): bool => $t->cancelled_at === null)
                ->map(fn (IssuedTicket $t): string => sprintf(
                    '<li><a href="%s">%s</a> — %s</li>',
                    e($t->url()),
                    e($t->code),
                    e($t->holder_name),
                ));

            if (filled($registration->email)) {
                try {
                    $this->dispatcher->queueEmail('event.reminder', (string) $registration->email, [
                        'name' => $registration->name,
                        'event_title' => $event->title,
                        'event_date' => $event->starts_at->translatedFormat('l j F'),
                        'event_time' => $event->starts_at->format('g:i a'),
                        'venue' => $this->venue($event),
                        'directions_url' => $directions,
                        'online_url' => $event->is_online ? $event->online_url : null,
                        'reference' => $registration->reference,
                        'tickets' => $tickets->isEmpty()
                            ? ''
                            : new HtmlString('<p>'.__('Your tickets — show one at the door:').'</p><ul>'.$tickets->implode("\n").'</ul>'),
                    ], [
                        'to_name' => $registration->name,
                        'related' => $registration,
                        'user_id' => $registration->user_id,
                        'expires_at' => $event->starts_at,
                        'idempotency_key' => 'event.reminder:'.$registration->reference,
                    ]);
                    $queued++;
                } catch (Throwable $e) {
                    report($e);
                }
            }

            if (filled($registration->phone)) {
                try {
                    $this->dispatcher->queueSms('event.reminder', (string) $registration->phone, [
                        'event_title' => $event->title,
                        'event_time' => $event->starts_at->format('g:i a'),
                        'venue' => trim($this->venue($event)) ?: ($event->is_online ? __('online') : ''),
                    ], [
                        'related' => $registration,
                        'user_id' => $registration->user_id,
                        'expires_at' => $event->starts_at,
                        'idempotency_key' => 'event.reminder.sms:'.$registration->reference,
                    ]);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        return $queued;
    }

    private function venue(Event $event): string
    {
        if ($event->is_online) {
            return ' '.__('(online)');
        }

        $where = collect([$event->venue_name, $event->area])->filter()->implode(', ');

        return $where === '' ? '' : ' '.__('at :venue', ['venue' => $where]);
    }
}
