<?php

declare(strict_types=1);

namespace App\Community;

use App\Communications\MessageDispatcher;
use App\Models\ContactDepartment;
use App\Models\ContactMessage;
use Illuminate\Http\Request;
use Throwable;

/**
 * Writing an enquiry into the inbox and acknowledging it.
 *
 * Shared by the contact form and the structured enquiry forms (partner,
 * corporate, in-kind, fundraise), so there is one place that decides what is
 * stored with a message — the consent wording, the IP, the page it came from
 * — and one place that queues the acknowledgement.
 *
 * The acknowledgement goes through `MessageDispatcher` like every other email:
 * logged, suppression-checked, rate-limited. A failure to queue it is
 * swallowed on purpose — the enquiry is already saved, and a missing courtesy
 * is not a lost message.
 */
final class EnquiryRecorder
{
    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    /**
     * @param  array{name: string, email: string, phone?: ?string, subject?: ?string, message: string}  $fields
     */
    public function record(Request $request, ?ContactDepartment $department, array $fields, string $consentText): ContactMessage
    {
        $message = ContactMessage::create([
            'contact_department_id' => $department?->getKey(),
            'name' => $fields['name'],
            'email' => mb_strtolower(trim($fields['email'])),
            'phone' => $fields['phone'] ?? null,
            'subject' => $fields['subject'] ?? null,
            'message' => $fields['message'],
            'consent_given' => true,
            'consent_text' => $consentText,
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'source_url' => $request->headers->get('referer'),
        ]);

        try {
            $this->dispatcher->queueEmail('contact.acknowledgement', $message->email, [
                'name' => $message->name,
                'reference' => $message->reference,
                'department' => $department?->name ?? '',
                'sla_hours' => (string) ($department?->sla_hours ?? ''),
            ], [
                'to_name' => $message->name,
                'related' => $message,
                'idempotency_key' => 'contact.acknowledgement:'.$message->reference,
            ]);
        } catch (Throwable) {
            // See the class note.
        }

        /*
         * The staff side. To the department's own address, or the general
         * contact address: the inbox screen exists, and nobody sits watching
         * it. Its failure is swallowed for the same reason the acknowledgement's
         * is — the message is already saved, and that is the part that matters.
         */
        $to = (string) ($department?->email ?: setting('contact.email_general', ''));

        if ($to !== '' && ! str_contains($to, '{{')) {
            try {
                $this->dispatcher->queueEmail('contact.admin_alert', $to, [
                    'name' => $message->name,
                    'email' => $message->email,
                    'department' => $department?->name ?? __('General enquiries'),
                    'subject' => $message->subject ?: __('(no subject)'),
                    'message' => $message->message,
                    'admin_url' => route('filament.admin.resources.contact-messages.edit', $message),
                ], [
                    'related' => $message,
                    'idempotency_key' => 'contact.admin_alert:'.$message->reference,
                ]);
            } catch (Throwable) {
                // The message is saved; the inbox shows it.
            }
        }

        return $message;
    }
}
