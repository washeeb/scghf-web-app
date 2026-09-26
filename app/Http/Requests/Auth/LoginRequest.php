<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A sign-in attempt at the public form.
 *
 * ── Why the throttling is here and not on the route ─────────────────────────
 *
 * `throttle:login` middleware would work and would be shorter. It cannot do two
 * things this needs.
 *
 * First, it cannot fire Illuminate\Auth\Events\Lockout, which is what
 * RecordAuthenticationEvent listens for — and a lockout is the single most
 * interesting row in the whole login history, because it is either an attack or
 * a member of staff who needs help in the next five minutes. Middleware would
 * return a 429 and nothing anywhere would record why.
 *
 * Second, the decay it applies is a minute. config/security.php sets
 * `lockout_seconds` to fifteen, on the reasoning that a lockout should make
 * spraying pointless while still letting a locked-out person make a cup of tea.
 *
 * ── Two keys, deliberately ──────────────────────────────────────────────────
 *
 * The narrow key is address + IP. The wide one is the IP alone, at a higher
 * ceiling.
 *
 * Keyed on the address alone, anybody could lock a named administrator out of
 * their own account by failing five sign-ins against it — a denial of service
 * that costs the attacker nothing and looks exactly like the control working.
 * Keyed on the IP alone, one shared mobile-network gateway — which in Ghana is
 * most of the country — is one bucket for thousands of people.
 */
class LoginRequest extends FormRequest
{
    private ?User $account = null;

    private bool $accountLoaded = false;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>|string> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:191'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The account for the address typed, or null.
     *
     * Memoised because three different questions are asked of it in one request
     * — is it staff, is it suspended, is the password right — and each of them
     * reaching for the database separately turns one query into three.
     */
    public function account(): ?User
    {
        if (! $this->accountLoaded) {
            $this->account = User::where('email', Str::lower(trim((string) $this->input('email'))))->first();
            $this->accountLoaded = true;
        }

        return $this->account;
    }

    /**
     * Whether the password given matches the account found.
     *
     * Used to decide things BEFORE authenticating — whether this is a staff
     * account that belongs at the panel, whether it is suspended. Both of those
     * answers reveal something about the account, so neither may be given to
     * somebody who has not proved they hold the password.
     */
    public function passwordIsCorrect(): bool
    {
        $account = $this->account();

        return $account !== null
            && Hash::check((string) $this->input('password'), (string) $account->password);
    }

    /**
     * Sign in, or fail with the same message in every case.
     *
     * "These credentials do not match our records" whether the address is
     * unknown, the password is wrong, or the account is inactive — because a
     * different message for each is a way to ask this form which addresses have
     * accounts.
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $credentials = [
            'email' => Str::lower(trim((string) $this->input('email'))),
            'password' => (string) $this->input('password'),
            /*
             * Belt and braces. The controller has already sent staff to the
             * panel by this point, and this makes it impossible for a change
             * there to quietly open a staff sign-in that skips the second
             * factor. A staff account is not a public account.
             */
            'type' => UserType::Donor->value,
            'is_active' => true,
        ];

        if (! Auth::attempt($credentials, $this->boolean('remember'))) {
            $this->recordFailure();

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        RateLimiter::clear($this->addressThrottleKey());
    }

    /**
     * Refuse before checking the password, once the limit is reached.
     *
     * Fires the Lockout event on the way, which is what puts a row in the login
     * history and an entry in the audit trail.
     */
    public function ensureIsNotRateLimited(): void
    {
        $narrow = ! RateLimiter::tooManyAttempts($this->throttleKey(), $this->maxAttempts());
        $wide = ! RateLimiter::tooManyAttempts($this->addressThrottleKey(), $this->maxAttempts() * 5);

        if ($narrow && $wide) {
            return;
        }

        Event::dispatch(new Lockout($this));

        $seconds = max(
            RateLimiter::availableIn($this->throttleKey()),
            RateLimiter::availableIn($this->addressThrottleKey()),
        );

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
            ]),
        ]);
    }

    /** Address + IP: the narrow bucket. */
    public function throttleKey(): string
    {
        return 'login|'.Str::transliterate(Str::lower((string) $this->input('email'))).'|'.$this->ip();
    }

    /** IP alone: the wide bucket, five times looser. */
    public function addressThrottleKey(): string
    {
        return 'login-ip|'.$this->ip();
    }

    /**
     * Count a failure that happened outside `authenticate()`.
     *
     * A suspended account whose password was correct is still a successful
     * guess, and it must cost the guesser an attempt — otherwise the suspended
     * accounts become an unthrottled oracle for testing passwords.
     */
    public function recordFailure(): void
    {
        RateLimiter::hit($this->throttleKey(), $this->lockoutSeconds());
        RateLimiter::hit($this->addressThrottleKey(), $this->lockoutSeconds());
    }

    private function maxAttempts(): int
    {
        return (int) config('security.rate_limits.login', 5);
    }

    private function lockoutSeconds(): int
    {
        return (int) config('security.lockout_seconds', 900);
    }
}
