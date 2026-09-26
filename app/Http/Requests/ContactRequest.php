<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ContactDepartment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What somebody may send through the contact form.
 *
 * ── Consent is required, not assumed ────────────────────────────────────────
 *
 * Act 843 requires a lawful basis for holding somebody's name, email and
 * message, and "they typed it into a box" is not one on its own. The tick is
 * `accepted`, so an unticked box fails validation rather than quietly storing a
 * `false` — and the wording they agreed to is snapshotted onto the record,
 * because consent to a privacy notice that has since been rewritten is not
 * evidence of anything.
 *
 * ── The department must be one that accepts enquiries ───────────────────────
 *
 * Validated against the active list rather than trusted from the form. Without
 * that, a crafted request could file an enquiry under a confidential
 * department — which is the one place in this application where a message is
 * hidden from most staff, and therefore the one place worth aiming at.
 */
class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],

            // `rfc` and not `dns`. A lookup inside a form submission makes
            // sending a message depend on the web server's resolver, and shared
            // hosting is exactly where that times out.
            'email' => ['required', 'string', 'email:rfc', 'max:191'],

            'phone' => ['nullable', 'string', 'max:20', 'regex:/^(\+?233|0)[2345][0-9]{8}$/'],

            'subject' => ['nullable', 'string', 'max:191'],

            'message' => ['required', 'string', 'min:10', 'max:5000'],

            'contact_department_id' => [
                'nullable',
                Rule::exists(ContactDepartment::class, 'id')->where('is_active', true),
            ],

            'consent' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone.regex' => __('That does not look like a Ghanaian number. Try 024 123 4567.'),
            'message.min' => __('Please tell us a little more so we can help.'),
            'consent.accepted' => __('We need your permission to hold your details before we can reply.'),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'contact_department_id' => __('department'),
            'consent' => __('permission'),
        ];
    }
}
