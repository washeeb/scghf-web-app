<?php

declare(strict_types=1);

namespace App\Community;

use App\Communications\MessageDispatcher;
use App\Models\Volunteer;
use App\Models\VolunteerApplication;
use App\Models\VolunteerShift;
use Throwable;

/**
 * Telling an applicant where they stand.
 *
 * `volunteer.application_received` has been seeded since Phase 3 with no
 * caller; so has the `volunteer.approved` SMS. Both transactional — they are
 * about the application the person made — and all reported rather than
 * thrown, because a broken template must not lose an application.
 */
final class VolunteerNotifier
{
    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    public function received(VolunteerApplication $application): void
    {
        try {
            $this->dispatcher->queueEmail('volunteer.application_received', $application->email, [
                'name' => $application->full_name,
                'opportunity_title' => $application->opportunity ? ' '.__('as :role', ['role' => $application->opportunity->title]) : '',
                'reference' => $application->reference,
            ], [
                'to_name' => $application->full_name,
                'related' => $application,
                'user_id' => $application->user_id,
                'idempotency_key' => 'volunteer.application_received:'.$application->reference,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function approved(VolunteerApplication $application): void
    {
        try {
            $this->dispatcher->queueEmail('volunteer.approved', $application->email, [
                'name' => $application->full_name,
                'role' => $application->opportunity?->title ?? __('volunteer'),
                'reference' => $application->reference,
            ], [
                'to_name' => $application->full_name,
                'related' => $application,
                'user_id' => $application->user_id,
                'idempotency_key' => 'volunteer.approved:'.$application->reference,
            ]);
        } catch (Throwable $e) {
            report($e);
        }

        if (blank($application->phone)) {
            return;
        }

        try {
            $this->dispatcher->queueSms('volunteer.approved', (string) $application->phone, [
                'name' => $application->full_name,
            ], [
                'related' => $application,
                'user_id' => $application->user_id,
                'idempotency_key' => 'volunteer.approved.sms:'.$application->reference,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Declined.
     *
     * The reason recorded on the application is for the foundation; the email
     * carries the applicant-facing wording written in the action, because "a
     * reference could not be taken up" and "the police check came back" are
     * not sentences to send automatically.
     */
    public function declined(VolunteerApplication $application, string $message): void
    {
        try {
            $this->dispatcher->queueEmail('volunteer.declined', $application->email, [
                'name' => $application->full_name,
                'message' => $message,
                'reference' => $application->reference,
            ], [
                'to_name' => $application->full_name,
                'related' => $application,
                'user_id' => $application->user_id,
                'idempotency_key' => 'volunteer.declined:'.$application->reference,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Through to the next stage. Email, and a text where there is a number. */
    public function shortlisted(VolunteerApplication $application): void
    {
        $this->send('volunteer.shortlisted', $application, [
            'name' => $application->full_name,
            'role' => $application->opportunity?->title ?? __('a volunteer'),
            'reference' => $application->reference,
        ], sms: ['name' => $application->full_name]);
    }

    /** An interview set: when and where. */
    public function interview(VolunteerApplication $application): void
    {
        $at = $application->interview_at;

        if ($at === null) {
            return;
        }

        $variables = [
            'name' => $application->full_name,
            'role' => $application->opportunity?->title ?? __('a volunteer'),
            'interview_date' => $at->translatedFormat('l j F Y'),
            'interview_time' => $at->format('g:i a'),
            'location' => (string) $application->interview_location,
            'reference' => $application->reference,
        ];

        $this->send('volunteer.interview', $application, $variables, sms: [
            'name' => $application->full_name,
            'interview_date' => $at->format('D j M'),
            'interview_time' => $at->format('g:i a'),
            'location' => (string) $application->interview_location,
        ], suffix: ':'.$at->timestamp);
    }

    /** The evening before a shift. */
    public function shiftReminder(VolunteerShift $shift): void
    {
        $volunteer = $shift->volunteer;

        if ($volunteer === null || ! $volunteer->isAvailable()) {
            return;
        }

        $variables = [
            'name' => $volunteer->full_name,
            'activity' => $shift->activity ?: ($shift->opportunity?->title ?? __('Volunteering')),
            'shift_date' => $shift->starts_at->translatedFormat('l j F'),
            'shift_time' => $shift->starts_at->format('g:i a').'–'.$shift->ends_at->format('g:i a'),
            'location' => (string) ($shift->location ?: $shift->opportunity?->location ?: ''),
        ];

        if (filled($volunteer->email)) {
            try {
                $this->dispatcher->queueEmail('volunteer.shift_reminder', (string) $volunteer->email, $variables, [
                    'to_name' => $volunteer->full_name,
                    'related' => $shift,
                    'user_id' => $volunteer->user_id,
                    'idempotency_key' => 'volunteer.shift_reminder:'.$shift->getKey(),
                ]);
            } catch (Throwable $e) {
                report($e);
            }
        }

        if (filled($volunteer->phone)) {
            try {
                $this->dispatcher->queueSms('volunteer.shift_reminder', (string) $volunteer->phone, [
                    'name' => $volunteer->full_name,
                    'activity' => $variables['activity'],
                    'shift_time' => $shift->starts_at->format('g:i a'),
                    'location' => $variables['location'],
                ], [
                    'related' => $shift,
                    'user_id' => $volunteer->user_id,
                    'idempotency_key' => 'volunteer.shift_reminder.sms:'.$shift->getKey(),
                ]);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** On leaving: the hours they gave, said back to them. */
    public function thankYou(Volunteer $volunteer): void
    {
        if (blank($volunteer->email)) {
            return;
        }

        try {
            $this->dispatcher->queueEmail('volunteer.thank_you', (string) $volunteer->email, [
                'name' => $volunteer->full_name,
                'role' => $volunteer->role ?: __('a volunteer'),
                'hours' => number_format($volunteer->total_hours),
                'started' => $volunteer->started_on?->translatedFormat('F Y') ?? '',
                'ended' => ($volunteer->ended_on ?? now())->translatedFormat('F Y'),
            ], [
                'to_name' => $volunteer->full_name,
                'related' => $volunteer,
                'user_id' => $volunteer->user_id,
                'idempotency_key' => 'volunteer.thank_you:'.$volunteer->getKey().':'.($volunteer->ended_on?->toDateString() ?? 'now'),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Email always; SMS when the template exists and there is a number. The
     * idempotency key is the template and the reference, so a stage message
     * goes once per stage however many times the button is pressed.
     *
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>|null  $sms
     */
    private function send(string $template, VolunteerApplication $application, array $variables, ?array $sms = null, string $suffix = ''): void
    {
        try {
            $this->dispatcher->queueEmail($template, $application->email, $variables, [
                'to_name' => $application->full_name,
                'related' => $application,
                'user_id' => $application->user_id,
                'idempotency_key' => $template.':'.$application->reference.$suffix,
            ]);
        } catch (Throwable $e) {
            report($e);
        }

        if ($sms === null || blank($application->phone)) {
            return;
        }

        try {
            $this->dispatcher->queueSms($template, (string) $application->phone, $sms, [
                'related' => $application,
                'user_id' => $application->user_id,
                'idempotency_key' => $template.'.sms:'.$application->reference.$suffix,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
