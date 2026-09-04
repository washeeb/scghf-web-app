<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Security
|--------------------------------------------------------------------------
|
| Rate limits and transport hardening. Like config/admin.php, every key here
| has been documented in .env.example since Phase 2 and read by nothing.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    |
    | Attempts per minute. Each is tuned to what a real person does, not to a
    | round number — a limit set above what an attacker needs is decoration.
    |
    | ⚠ THE KEY MATTERS AS MUCH AS THE NUMBER.
    |
    | Login is limited per email AND per IP, together. Limiting by email alone
    | lets anybody lock a named administrator out of their own account by
    | failing five logins against their address — a denial of service that costs
    | the attacker nothing and looks like the security control working. Limiting
    | by IP alone lets a botnet spray one password across every account.
    */
    'rate_limits' => [

        // Five is roughly "I have mistyped it twice and tried my other
        // password". A sixth attempt in a minute is not a person remembering.
        'login' => (int) env('RATE_LIMIT_LOGIN', 5),

        'password_reset' => (int) env('RATE_LIMIT_PASSWORD_RESET', 3),
        'registration' => (int) env('RATE_LIMIT_REGISTRATION', 3),

        // Contact forms are a spam target. Three a minute is generous for
        // somebody writing a genuine enquiry.
        'contact' => (int) env('RATE_LIMIT_CONTACT', 3),

        /*
         * Donation initiation. Deliberately the loosest of these.
         *
         * A donor whose mobile-money prompt times out will legitimately try
         * again several times in a row, and a rate limit that stops somebody
         * giving money is a rate limit that has cost more than it saved.
         */
        'donation' => (int) env('RATE_LIMIT_DONATION', 10),

        'api' => (int) env('RATE_LIMIT_API', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Lockout
    |--------------------------------------------------------------------------
    |
    | How long a throttled login stays throttled. Long enough to make spraying
    | pointless, short enough that a locked-out member of staff can make a cup
    | of tea rather than telephone somebody.
    */
    'lockout_seconds' => (int) env('LOGIN_LOCKOUT_SECONDS', 900),

    /*
    |--------------------------------------------------------------------------
    | HTTPS
    |--------------------------------------------------------------------------
    |
    | Forces every generated URL to https. Off locally, on everywhere else.
    |
    | It matters here beyond the usual reasons: this application sets a session
    | cookie that authenticates an administrator who can read beneficiary case
    | files, and a single plaintext request on a shared café network is enough
    | to hand that cookie over.
    */
    'force_https' => (bool) env('FORCE_HTTPS', false),
];
