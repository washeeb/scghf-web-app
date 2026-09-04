<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * A donor editing their own details.
 *
 * ── The email address is deliberately not here ──────────────────────────────
 *
 * Changing the address on an account is a security operation, not a profile
 * edit: it is how an attacker with a stolen session makes the takeover
 * permanent, by pointing password resets at an inbox they control. It needs the
 * current password, a confirmation link to the NEW address, and a warning to
 * the old one — and it belongs on the security page with those three things
 * around it, not on a form that also edits a phone number.
 *
 * Until that exists, the address is changed by staff on request. That is a
 * smaller feature than an unsafe self-service one.
 */
class ProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:191'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9 +()-]{9,32}$/'],
            'accepts_email_marketing' => ['sometimes', 'boolean'],
            'accepts_sms_marketing' => ['sometimes', 'boolean'],

            /*
             * Both are real options for a Ghanaian donor abroad, and neither is
             * free text: a locale reaches the translator and a timezone reaches
             * date arithmetic on receipts.
             */
            'locale' => ['sometimes', 'string', Rule::in(['en'])],
            'timezone' => ['sometimes', 'string', Rule::in(timezone_identifiers_list())],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }

    /**
     * Only what a donor is allowed to change about themselves.
     *
     * Built from a fixed list rather than from `validated()`, because
     * `validated()` returns whatever passed — and a rule added carelessly later
     * would silently become a field the public can set. `type`, `is_active` and
     * `suspended_at` are the ones that must never appear here.
     *
     * @return array<string, mixed>
     */
    public function profileAttributes(): array
    {
        $attributes = [
            'name' => (string) $this->validated('name'),
            'accepts_email_marketing' => $this->boolean('accepts_email_marketing'),
            'accepts_sms_marketing' => $this->boolean('accepts_sms_marketing'),
        ];

        /*
         * A field that was not submitted is not a field that was cleared.
         *
         * `phone` absent has to leave the stored number alone, and `phone`
         * present but empty has to clear it. Collapsing the two would mean any
         * future partial save of this form silently wiped the donor's mobile
         * number — which for a foundation whose donors mostly pay by Mobile
         * Money is the contact detail that matters most.
         */
        if ($this->has('phone')) {
            $attributes['phone'] = $this->filled('phone') ? (string) $this->input('phone') : null;
        }

        foreach (['locale', 'timezone'] as $optional) {
            if ($this->has($optional)) {
                $attributes[$optional] = (string) $this->validated($optional);
            }
        }

        return $attributes;
    }

    /** Whether this request turns a marketing channel on that was off. */
    public function grantsMarketingConsent(): bool
    {
        $user = $this->user();

        return ($this->boolean('accepts_email_marketing') && ! $user?->accepts_email_marketing)
            || ($this->boolean('accepts_sms_marketing') && ! $user?->accepts_sms_marketing);
    }

    public function normalisedEmail(): string
    {
        return Str::lower(trim((string) $this->user()?->email));
    }
}
