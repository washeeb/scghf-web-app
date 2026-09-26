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
    | Response headers
    |--------------------------------------------------------------------------
    |
    | Set by `SecurityHeaders` on every response the application produces.
    | `public/.htaccess` carries the same headers with `setifempty`, so a file
    | Apache serves without PHP (an image, a built asset) still gets them and
    | a header PHP set is never doubled.
    |
    | ── The Content Security Policy ─────────────────────────────────────────
    |
    | The public site runs no Alpine and no Livewire — plain Blade with a few
    | kilobytes of script — so it can have the strict form: scripts only from
    | this origin or carrying this request's nonce, nothing inline without
    | one, no eval. The inline theme script and the Vite tags carry the nonce.
    |
    | The admin panel is Filament, which injects inline scripts and styles of
    | its own and needs `unsafe-eval` for Alpine. It gets the looser policy,
    | REPORT-ONLY, so a breach of it is logged rather than the panel broken.
    | Tightening it is a Filament upgrade away, not a setting.
    |
    | Origins are listed once, here, because the .htaccess copy and this one
    | must say the same thing.
    */
    'headers' => [
        /*
         * HSTS: 0 = not sent. Send it only once https works everywhere on the
         * domain, INCLUDING every subdomain (staging, mail, cpanel…), because
         * `includeSubDomains` commits them all for max-age. 31536000 = a year.
         */
        'hsts_max_age' => (int) env('HSTS_MAX_AGE', 0),

        'csp' => [
            'script_origins' => ['https://js.paystack.co', 'https://challenges.cloudflare.com'],
            'connect_origins' => ['https://api.paystack.co', 'https://challenges.cloudflare.com'],
            'frame_origins' => ['https://checkout.paystack.com', 'https://challenges.cloudflare.com'],
            'form_action_origins' => ['https://checkout.paystack.com'],
            // Media may live on S3-compatible storage; a wider img-src is the
            // price of the escape hatch. Everything else is this origin.
            'img_origins' => ['https:', 'data:', 'blob:'],
            'font_origins' => ['data:'],
        ],

        'permissions_policy' => 'accelerometer=(), autoplay=(), camera=(), display-capture=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(self), usb=()',
    ],

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
