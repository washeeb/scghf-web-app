<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Community\EnquiryKinds;
use App\Community\EnquiryRecorder;
use App\Models\ContactDepartment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The structured enquiry forms: partner with us, corporate giving, an in-kind
 * offer, fundraising for us.
 *
 * Each is rendered by the `enquiry-form` block on whichever CMS page the
 * editor places it, posts here with its kind, and lands in the contact inbox
 * routed to the right department with its answers written under headings —
 * so what arrives is actionable rather than "I have some things".
 *
 * The rules are built from the kind's field list, so the form and the
 * validation cannot disagree.
 */
class EnquiryController extends Controller
{
    public function store(Request $request, string $kind): RedirectResponse
    {
        if (! EnquiryKinds::has($kind)) {
            throw new NotFoundHttpException;
        }

        $spec = EnquiryKinds::get($kind);

        $validated = $request->validate($this->rules($spec), [
            'phone.regex' => __('That does not look like a Ghanaian number. Try 024 123 4567.'),
            'consent.accepted' => __('We need your agreement to hold these details in order to reply.'),
        ]);

        $department = ContactDepartment::query()
            ->where('key', $spec['department'])
            ->where('is_active', true)
            ->where('is_confidential', false)
            ->first();

        $message = app(EnquiryRecorder::class)->record($request, $department, [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'subject' => $spec['subject'],
            'message' => $this->body($spec, $validated),
        ], $this->consentText());

        return back()->with('status', __(
            'Thank you. Your reference is :reference — quote it if you write to us again.',
            ['reference' => $message->reference],
        ));
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function rules(array $spec): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'string', 'email:rfc', 'max:191'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^(\+?233|0)[2345][0-9]{8}$/'],
            'message' => ['nullable', 'string', 'max:3000'],
            'consent' => ['accepted'],
        ];

        foreach ($spec['fields'] as $name => $field) {
            $rule = [($field['required'] ?? false) ? 'required' : 'nullable'];

            $rule[] = match ($field['type']) {
                'select' => Rule::in(array_keys($field['options'])),
                'textarea' => 'string',
                default => 'string',
            };

            $rule[] = $field['type'] === 'textarea' ? 'max:3000' : 'max:191';

            $rules[$name] = $rule;
        }

        return $rules;
    }

    /**
     * The answers, under their headings, as the message body.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $answers
     */
    private function body(array $spec, array $answers): string
    {
        $lines = [];

        foreach ($spec['fields'] as $name => $field) {
            $value = $answers[$name] ?? null;

            if (blank($value)) {
                continue;
            }

            if ($field['type'] === 'select') {
                $value = $field['options'][$value] ?? $value;
            }

            $lines[] = $field['label'].":\n".trim((string) $value);
        }

        if (filled($answers['message'] ?? null)) {
            $lines[] = __('Anything else').":\n".trim((string) $answers['message']);
        }

        return implode("\n\n", $lines);
    }

    private function consentText(): string
    {
        return (string) setting(
            'compliance.contact_consent_text',
            __('I agree that :name may store these details in order to reply to me.', [
                'name' => setting('general.legal_name', setting('general.short_name', config('app.name'))),
            ]),
        );
    }
}
