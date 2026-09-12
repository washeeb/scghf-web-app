<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Turnstile;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The Turnstile token on a form, checked with Cloudflare.
 *
 * Applied only when Turnstile is configured — the form request wraps it in
 * `Rule::when(Turnstile::enabled(), …)` — so the rule itself never has to
 * decide whether it is switched on.
 */
final class TurnstileToken implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! Turnstile::verify(is_string($value) ? $value : null, request()->ip())) {
            $fail(__('We could not confirm you are a person. Please try the check again — nothing has been charged.'));
        }
    }
}
