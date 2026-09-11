<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registering for an event.
 *
 * ── Photography consent is asked, not assumed ───────────────────────────────
 *
 * The column is nullable with no default, because "we never asked" must be
 * distinguishable from "they said no". The form asks with a yes/no pair, and
 * the answer is required — this foundation photographs its events, and a
 * person who was not asked is a person whose face is on a page they did not
 * agree to.
 *
 * ── Three consents, three questions ─────────────────────────────────────────
 *
 * Holding their details to run the event (required — it is the registration).
 * Being contacted about THIS event (optional). Joining the newsletter
 * (optional, and never pre-ticked). One box cannot express all three, and a
 * registration treated as a mailing-list signup is how a list becomes
 * something nobody actually agreed to.
 */
class EventRegistrationRequest extends FormRequest
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
            'email' => ['required', 'string', 'email:rfc', 'max:191'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^(\+?233|0)[2345][0-9]{8}$/'],
            'guests' => ['nullable', 'integer', 'min:0', 'max:10'],

            'accessibility_needs' => ['nullable', 'string', 'max:500'],
            'dietary_needs' => ['nullable', 'string', 'max:500'],

            'photography_consent' => ['required', Rule::in(['1', '0'])],
            'contact_consent' => ['nullable', 'boolean'],
            'newsletter_consent' => ['nullable', 'boolean'],

            'consent' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone.regex' => __('That does not look like a Ghanaian number. Try 024 123 4567.'),
            'photography_consent.required' => __('Please tell us whether you are happy to be photographed.'),
            'consent.accepted' => __('We need your agreement to hold these details in order to register you.'),
        ];
    }
}
