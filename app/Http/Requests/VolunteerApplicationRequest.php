<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ShippingZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Applying to volunteer.
 *
 * ── The declaration is the one field that is not optional ───────────────────
 *
 * It is what the applicant is later held to: that they have read the
 * safeguarding policy, disclosed anything relevant, and answered truthfully.
 * `VolunteerApplication::submit()` refuses without it, and so does this.
 *
 * ── Date of birth is required for a role with vulnerable contact ────────────
 *
 * A police clearance is applied for against a date of birth, and a volunteer
 * who will work with children must be an adult. For a role with no such
 * contact it is not asked for at all — data that is not needed is data that
 * should not be collected.
 *
 * ── Disclosed convictions are free text ─────────────────────────────────────
 *
 * A yes/no invites a no. The useful information is the circumstances, and a
 * disclosure the foundation then weighed is exactly what an inquiry would ask
 * to see.
 */
class VolunteerApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $contact = (bool) $this->route('opportunity')?->involves_vulnerable_contact ?? true;

        return [
            'full_name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'string', 'email:rfc', 'max:191'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^(\+?233|0)[2345][0-9]{8}$/'],
            'region' => ['nullable', 'string', Rule::in(ShippingZone::REGIONS)],
            'occupation' => ['nullable', 'string', 'max:191'],

            'date_of_birth' => [
                $contact ? 'required' : 'nullable',
                'date',
                'before:'.now()->subYears(18)->toDateString(),
                'after:'.now()->subYears(100)->toDateString(),
            ],

            'motivation' => ['required', 'string', 'max:3000'],
            'experience' => ['nullable', 'string', 'max:3000'],
            'availability' => ['nullable', 'string', 'max:191'],

            'next_of_kin_name' => ['nullable', 'string', 'max:191'],
            'next_of_kin_phone' => ['nullable', 'string', 'max:20', 'regex:/^(\+?233|0)[2345][0-9]{8}$/'],

            'disclosed_convictions' => ['nullable', 'string', 'max:3000'],

            'declaration' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone.regex' => __('That does not look like a Ghanaian number. Try 024 123 4567.'),
            'next_of_kin_phone.regex' => __('That does not look like a Ghanaian number.'),
            'date_of_birth.before' => __('Volunteers must be 18 or over.'),
            'date_of_birth.required' => __('We need your date of birth because this role needs a police clearance, which is applied for against it.'),
            'declaration.accepted' => __('The declaration has to be agreed before an application can be made.'),
        ];
    }
}
