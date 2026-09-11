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

        return $message;
    }
}
