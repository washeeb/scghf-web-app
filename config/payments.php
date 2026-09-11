<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Payments
|--------------------------------------------------------------------------
|
| One gateway, one code path, for donations AND shop orders. Two payment paths
| is how a ledger diverges from the gateway, and reconciling a divergence after
| the fact is guesswork.
|
| Every amount in this file is INTEGER PESEWAS. Nothing here is a float.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    |
    | `fake` returns deterministic fixtures so the entire payments module can be
    | built and tested before the merchant account exists — which is the
    | situation this project is actually in.
    |
    | Production REFUSES TO BOOT with `fake`. A live site quietly accepting
    | pretend payments is worse than a live site that will not start, because
    | the donor believes they have given and the foundation believes it has
    | received.
    */
    'driver' => env('PAYMENT_DRIVER', 'fake'),

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | GHS, everywhere, on every call. Paystack rejects a transaction with no
    | currency, and an amount without one is meaningless data.
    */
    'currency' => env('PAYSTACK_CURRENCY', 'GHS'),

    /*
    |--------------------------------------------------------------------------
    | Paystack
    |--------------------------------------------------------------------------
    */
    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),

        /*
         * Paystack signs webhooks with the SECRET KEY unless a separate webhook
         * secret is configured. Blank means "use the secret key", which is the
         * documented default — but it is expressed here rather than assumed at
         * the call site, so rotating to a distinct secret is a config change.
         */
        'webhook_secret' => env('PAYSTACK_WEBHOOK_SECRET') ?: env('PAYSTACK_SECRET_KEY'),

        'base_url' => rtrim((string) env('PAYSTACK_BASE_URL', 'https://api.paystack.co'), '/'),
        'callback_url' => env('PAYSTACK_CALLBACK_URL'),
        'webhook_path' => env('PAYSTACK_WEBHOOK_PATH', '/webhooks/paystack'),
        'timeout' => (int) env('PAYSTACK_TIMEOUT', 20),

        // Mobile money first: it is how Ghana pays.
        'channels' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('PAYSTACK_CHANNELS', 'mobile_money,card')),
        ))),

        /*
         * Paystack's own IP ranges, published for webhook allow-listing.
         *
         * A SECOND line of defence, never the first — the HMAC signature is
         * what actually authenticates a webhook. An IP check alone is trivially
         * spoofed upstream of the application, and behind a shared-hosting
         * proxy the remote address may not even be the true origin.
         *
         * Empty disables the check, which is the right default until the
         * hosting arrangement is known.
         */
        'webhook_ips' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('PAYSTACK_WEBHOOK_IPS', '')),
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fees
    |--------------------------------------------------------------------------
    |
    | Paystack Ghana's published pricing. VERIFY AGAINST THE SIGNED MERCHANT
    | AGREEMENT before go-live — negotiated rates differ, and a wrong rate here
    | means the "cover the fee" option under- or over-charges every donor who
    | ticks it.
    |
    | This model is for DISPLAY and for grossing up a fee-covered gift. What
    | Paystack actually charged comes back on the webhook and is what gets
    | stored: reconciliation compares the two, and the gateway wins.
    */
    'fees' => [
        // Basis points, so the rate is an integer and never a float in
        // arithmetic. 195 = 1.95%.
        'percent_bps' => (int) round(((float) env('PAYSTACK_FEE_PERCENT', 1.95)) * 100),

        // GH₵ 100.00. Above this the fee stops growing.
        'cap_minor' => (int) env('PAYSTACK_FEE_CAP_PESEWAS', 10000),

        // Some gateways add a flat component on top. Zero for Paystack Ghana
        // today; present so adding one is a config change, not a code change.
        'flat_minor' => (int) env('PAYSTACK_FEE_FLAT_PESEWAS', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Donation limits
    |--------------------------------------------------------------------------
    |
    | The floor exists because a gift smaller than the fee costs the foundation
    | money to accept. The ceiling is an anti-fraud and anti-typo measure — a
    | genuine large gift is welcome, but it should go through Finance rather
    | than a public form, where an extra zero is one keystroke away.
    */
    'donations' => [
        'min_minor' => (int) env('DONATION_MIN_PESEWAS', 500),
        'max_minor' => (int) env('DONATION_MAX_PESEWAS', 10000000),
        'allow_fee_cover' => (bool) env('DONATION_ALLOW_FEE_COVER', true),

        'presets_minor' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('DONATION_PRESETS_PESEWAS', '5000,10000,25000,50000,100000')),
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    */
    'webhooks' => [
        /*
         * How many times a failed event is retried before it is left for a
         * human. Paystack retries on its side too, and the unique index on
         * `event_id` makes a duplicate delivery a no-op — so this is about
         * transient failures on OUR side, not about chasing the gateway.
         */
        'max_attempts' => (int) env('PAYMENT_WEBHOOK_MAX_ATTEMPTS', 5),

        /*
         * Keys scrubbed from any payload before it is stored.
         *
         * PCI DSS SAQ-A depends on this application never touching card data.
         * Paystack does not send a PAN, but a payload is stored verbatim and
         * this is the one place to be paranoid rather than trusting that the
         * gateway will never change what it sends.
         */
        'scrub_keys' => [
            'card', 'cvv', 'cvc', 'pin', 'number', 'card_number', 'pan',
            'expiry_month', 'expiry_year', 'authorization_code_full',
            'account_number', 'password', 'otp',
        ],

        /*
         * Event types the handler acts on. Anything else is stored and
         * acknowledged, but not processed — an unrecognised event is evidence,
         * not an error, and Paystack adds new ones without warning.
         */
        'handled_events' => [
            'charge.success',
            'charge.failed',
            'transfer.success',
            'transfer.failed',
            'transfer.reversed',
            'refund.processed',
            'refund.failed',
            'subscription.create',
            'subscription.disable',
            'subscription.not_renew',
            'invoice.create',
            'invoice.payment_failed',
            'invoice.update',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    |
    | A daily comparison of what the ledger says against what Paystack settled.
    | Backups that are never restored and ledgers that are never reconciled fail
    | the same way: silently, and only discovered when it matters.
    */
    'recurring' => [
        /*
         * Consecutive failed charges before a standing gift is paused rather
         * than retried. Retrying an expired card every month is how a charity
         * ends up on a card network's watch list, and by the third failure the
         * donor has usually moved on anyway.
         */
        'max_failures' => (int) env('RECURRING_MAX_FAILURES', 3),
    ],

    'reconciliation' => [
        'enabled' => (bool) env('PAYMENT_RECONCILIATION_ENABLED', true),

        // How far back a run looks. Settlement can lag, so a single day's
        // window would miss anything that landed late.
        'lookback_days' => (int) env('PAYMENT_RECONCILIATION_LOOKBACK_DAYS', 7),

        // A transaction still `pending` after this long is stale: the donor
        // almost certainly abandoned the payment page.
        'abandon_after_minutes' => (int) env('PAYMENT_ABANDON_AFTER_MINUTES', 60),
    ],
];
