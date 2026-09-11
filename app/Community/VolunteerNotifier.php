<?php

declare(strict_types=1);

namespace App\Community;

use App\Communications\MessageDispatcher;
use App\Models\VolunteerApplication;
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
}
