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

    /*
    |--------------------------------------------------------------------------
    | Passwords
    |--------------------------------------------------------------------------
    |
    | The rules a public donor account must meet. Staff accounts are created in
    | the admin panel and meet the same bar — the users table carries one
    | password policy, not two.
    */
    'passwords' => [

        /*
         * Twelve, not eight.
         *
         * Length is the property of a password that resists an offline attack;
         * the usual complexity theatre — one capital, one digit, one symbol —
         * mostly produces Password1! on every site the person uses. NIST
         * SP 800-63B has preferred length over composition since 2017, and this
         * follows it: long, checked against known breaches, no character-class
         * rules at all.
         */
        'min_length' => (int) env('PASSWORD_MIN_LENGTH', 12),

        /*
         * Check the password against Have I Been Pwned's breach corpus.
         *
         * k-anonymity: only the first five characters of the SHA-1 hash leave
         * this server, so neither the password nor anything identifying it is
         * transmitted.
         *
         * Off under test, because a suite that makes a network call per
         * registration is a suite that fails when the wifi does. Laravel fails
         * OPEN when the API is unreachable, so a donor is never stopped from
         * registering by somebody else's outage.
         */
        'check_compromised' => (bool) env('PASSWORD_CHECK_COMPROMISED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Public accounts
    |--------------------------------------------------------------------------
    */
    'accounts' => [

        /*
         * Whether the public may create an account at all.
         *
         * A switch rather than a route comment, because the realistic reason to
         * need it is a registration-spam wave at 2am — and taking the form down
         * must not require a deploy.
         */
        'registration_open' => (bool) env('ACCOUNT_REGISTRATION_OPEN', true),

        /*
         * Email somebody when their account is signed into from a device it has
         * not been used on before.
         *
         * Never on the first sign-in of a new account: every device is new
         * then, and an alert that fires when it cannot mean anything is an
         * alert people learn to dismiss.
         */
        'alert_on_new_device' => (bool) env('ACCOUNT_ALERT_NEW_DEVICE', true),

        /*
         * How long a verification or password-reset link stays usable, in
         * minutes.
         *
         * Sixty rather than Laravel's default, because the outbox drains on a
         * cron tick and a Ghanaian mobile inbox is not read the second it
         * arrives. Short enough that a forwarded email is not a standing key to
         * the account.
         */
        'link_lifetime_minutes' => (int) env('ACCOUNT_LINK_LIFETIME', 60),
    ],
];
