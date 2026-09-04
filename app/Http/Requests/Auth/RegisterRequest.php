<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Support\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Creating a public donor account.
 *
 * ── Marketing consent is a checkbox that starts empty ────────────────────────
 *
 * Act 843 requires consent to be specific, informed and freely given. A
 * pre-ticked box is none of those, and neither is a single "I agree to the
 * terms" that silently includes marketing — so the opt-ins are separate,
 * unticked, and worded as what they actually are.
 *
 * Receipts are not affected by any of this. A receipt is transactional: it is
 * the record of a transaction the donor initiated, it carries no appeal, and it
 * goes whether or not they ever tick anything.
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:191'],

            /*
             * `max:191` matches the column, which is 191 rather than 255
             * because it is indexed and a utf8mb4 character can be four bytes.
             * Without this the database errors on an address the validator was
             * happy with.
             *
             * `email:rfc` and NOT `email:rfc,dns`. The DNS check means a live
             * lookup inside the registration request: latency on every sign-up,
             * and a hard failure whenever the host's resolver is having a bad
             * morning — which on shared hosting it periodically is. The check it
             * would perform is one this flow already performs better, because
             * an address that does not exist never receives its verification
             * link and the account never becomes usable.
             *
             * Unique WITHOUT excluding soft-deleted rows, because the column's
             * unique index does not exclude them either. Skipping them here
             * would turn a validation message into a database error on insert.
             */
            'email' => [
                'required', 'string', 'email:rfc', 'max:191',
                Rule::unique('users', 'email'),
            ],

            /*
             * Optional, because a donor giving by Mobile Money already gives us
             * their number at the point of payment and asking twice is friction
             * on the way in. Validated loosely on purpose: the model normalises
             * to E.164 and keeps the raw input, so a number typed with spaces,
             * with +233, or starting 0 all land in the same place.
             */
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9 +()-]{9,32}$/'],

            'password' => ['required', 'string', 'confirmed', PasswordPolicy::rule()],

            /*
             * Not `accepted` — that would make it required, which is the
             * pre-ticked box in a different costume. `boolean` and absent means
             * no.
             */
            'accepts_email_marketing' => ['sometimes', 'boolean'],
            'accepts_sms_marketing' => ['sometimes', 'boolean'],

            // This one IS required: it is the privacy notice, not a marketing
            // opt-in, and there is no lawful basis for processing without it.
            'accepts_privacy_policy' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'accepts_privacy_policy.accepted' => __('Please confirm you have read how we handle your data.'),
            'email.unique' => __('An account already exists for this address. Try signing in, or reset your password.'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => Str::lower(trim((string) $this->input('email'))),
            'name' => trim((string) $this->input('name')),
        ]);
    }
}
