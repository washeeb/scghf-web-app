<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Support\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Changing the password from inside a signed-in session.
 *
 * `current_password` is required and is the whole point. Without it, a session
 * left open on a shared computer — an internet café, a phone lent to somebody —
 * is enough to change the password and lock the owner out of their own giving
 * history. The password reset flow does not need it, because there the person
 * has proved control of the inbox instead.
 */
class PasswordUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', PasswordPolicy::rule()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.current_password' => __('That is not your current password.'),
            'password.different' => __('The new password must be different from the current one.'),
        ];
    }
}
