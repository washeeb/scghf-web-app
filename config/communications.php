<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Communications
|--------------------------------------------------------------------------
|
| Email, SMS and in-app notifications. One dispatcher, one suppression list,
| one log per channel.
|
| Two things in this file are worth understanding before changing anything.
|
| 1. SUPPRESSION HAS A SCOPE.
|    "Stop emailing me" and "this mailbox does not exist" are different facts
|    with different consequences. Somebody who unsubscribes from appeals still
|    needs the receipt for the gift they just made — silently withholding it
|    loses them their record of a donation and loses the Foundation its
|    evidence of having acknowledged one. So an unsubscribe suppresses
|    marketing; a bounce suppresses everything.
|
| 2. SENDING IS SLOW HERE, AND THAT CHANGES THE DESIGN.
|    Shared cPanel hosting caps outbound mail per hour. A newsletter to two
|    thousand people therefore takes most of a day, drained a batch at a time
|    by cron. Everything downstream follows from that: consent is re-checked
|    per message rather than per campaign, and every scheduled message carries
|    an expiry so a backlog cannot deliver yesterday's reminder tomorrow.
|
| Retention of the logs themselves is policy, so it lives in
| config/compliance.php with every other retention period — not here.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Channels
    |--------------------------------------------------------------------------
    |
    | A disabled channel does not throw. The message is still rendered and
    | logged with status `disabled`, because "we never sent this" is a fact
    | somebody will need, and a silent no-op is how it gets lost.
    */
    'channels' => [
        'mail' => env('MAIL_ENABLED', true),
        'sms' => env('SMS_ENABLED', true),
        'database' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Message categories
    |--------------------------------------------------------------------------
    |
    | Every template declares one. The category decides which suppressions
    | block it, whether the message needs a List-Unsubscribe header, and
    | whether the rendered body is kept.
    |
    |   transactional  a response to something the person did — a receipt, an
    |                  order update, a password reset. Blocked only by an
    |                  absolute suppression.
    |   marketing      appeals, newsletters, campaigns. Blocked by any
    |                  suppression, and always carries an unsubscribe link.
    |   system         internal alerts to staff. Never sent to a member of the
    |                  public, so it carries no unsubscribe link.
    */
    'categories' => [

        'transactional' => [
            'label' => 'Transactional',
            'blocked_by_scopes' => ['all'],
            'requires_unsubscribe' => false,
            'store_body' => true,
            'priority' => 1,
        ],

        'marketing' => [
            'label' => 'Marketing and appeals',
            'blocked_by_scopes' => ['all', 'marketing'],
            'requires_unsubscribe' => true,
            // The body lives once on the campaign, not once per recipient. Two
            // thousand copies of the same 40KB newsletter is a quarter of the
            // disk quota on a shared plan, to store nothing new.
            'store_body' => false,
            'priority' => 9,
        ],

        'system' => [
            'label' => 'Internal system alert',
            'blocked_by_scopes' => ['all'],
            'requires_unsubscribe' => false,
            'store_body' => true,
            'priority' => 3,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Suppression
    |--------------------------------------------------------------------------
    |
    | Blueprint risk DEL-4. Without this list, bounces accumulate, the sending
    | domain's reputation degrades, and the first thing to stop arriving is the
    | thing that matters most — donation receipts.
    |
    | Each reason maps to a scope, and the mapping is the policy:
    |
    |   all        no message of any kind, including receipts
    |   marketing  appeals and newsletters only
    |
    | ⚠ `complaint` is set to `all` deliberately, and it is the one entry here
    | that is a judgement call rather than a technical fact. Somebody who marks
    | a receipt as spam has told their mailbox provider we are a nuisance;
    | continuing to mail them is what gets a domain blocklisted, and it takes
    | the receipts down with it. The cost is that their next receipt is not
    | delivered — which is why a suppressed transactional message is still
    | LOGGED and still raises a task, so Finance can post or hand it over
    | instead. Suppression stops the send, never the record.
    |
    | This is flagged as an open decision in docs/PHASE-3-DATA-ARCHITECTURE.md.
    */
    'suppression' => [

        'reasons' => [
            'hard_bounce' => [
                'label' => 'Hard bounce',
                'scope' => 'all',
                'purpose' => 'The mailbox does not exist. Sending again is pointless and '
                    .'damages the sending domain.',
            ],
            'complaint' => [
                'label' => 'Spam complaint',
                'scope' => 'all',
                'purpose' => 'The recipient reported us to their provider. Continuing to send '
                    .'is what gets a domain blocklisted.',
            ],
            'unsubscribe' => [
                'label' => 'Unsubscribed',
                'scope' => 'marketing',
                'purpose' => 'They asked not to receive appeals. They did not ask to stop '
                    .'receiving receipts for gifts they make.',
            ],
            'invalid' => [
                'label' => 'Invalid address',
                'scope' => 'all',
                'purpose' => 'Malformed, or a role address that should never have been '
                    .'collected.',
            ],
            'erasure_request' => [
                'label' => 'Act 843 erasure request',
                'scope' => 'all',
                'purpose' => 'The data subject exercised their right to object. The address is '
                    .'retained ONLY to honour that objection.',
            ],
            'manual' => [
                'label' => 'Added by staff',
                'scope' => 'all',
                'purpose' => 'A decision taken by a person, recorded with who took it.',
            ],
            'soft_bounce_repeated' => [
                'label' => 'Repeated soft bounces',
                'scope' => 'all',
                'purpose' => 'A mailbox that has been full or unreachable for weeks is, in '
                    .'practice, gone.',
            ],
        ],

        /*
         * How many soft bounces before an address is suppressed outright. A
         * single full mailbox is not a dead address; five in a row is.
         */
        'soft_bounce_threshold' => 5,

        /*
         * Suppression only ever STRENGTHENS automatically. An address suppressed
         * for marketing that later hard-bounces is upgraded to `all`; an address
         * suppressed for `all` is never downgraded by an automatic process.
         * Releasing one is a decision by a named person, with a reason.
         */
        'allow_automatic_release' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sending rate
    |--------------------------------------------------------------------------
    |
    | InMotion shared hosting caps outbound mail per hour, and exceeding the cap
    | does not queue — it rejects, and repeated rejections get the account
    | flagged. So the dispatcher throttles itself.
    |
    | The counter is not a counter. It is a COUNT of rows in `email_logs` with
    | `sent_at` inside the window, which means it is exactly what was actually
    | sent, is atomic without a lock, cannot drift, and survives a cron process
    | being killed mid-batch. A separate counter table would have none of those
    | properties on a host where every minute is a fresh PHP process.
    */
    'throttle' => [
        'mail' => [
            'per_minute' => (int) env('MAIL_BULK_PER_MINUTE', 20),
            'per_hour' => (int) env('MAIL_BULK_PER_HOUR', 200),
        ],
        'sms' => [
            'per_minute' => (int) env('SMS_PER_MINUTE', 60),
            'per_hour' => (int) env('SMS_PER_HOUR', 1000),
        ],

        /*
         * Transactional messages are exempt from the bulk ceiling but not from
         * the per-minute one. A receipt waiting behind four hundred newsletter
         * sends is a support call; a receipt that blows the hourly cap is an
         * account suspension. Neither is acceptable, so transactional mail
         * jumps the queue by priority instead of by bypassing the limit.
         */
        'exempt_categories' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduled messages
    |--------------------------------------------------------------------------
    */
    'scheduling' => [

        // How many messages one cron invocation may drain. The queue worker
        // runs with --max-time=55, so this must be comfortably completable
        // inside a minute or the batch is cut off mid-flight every time.
        'batch_size' => (int) env('COMMS_BATCH_SIZE', 25),

        /*
         * A claimed message whose worker died is released after this long. Set
         * shorter than the retry window and a message sends twice; set longer
         * and a killed worker strands it. Five minutes against a 55-second
         * worker is deliberately generous.
         */
        'claim_ttl_minutes' => 5,

        'max_attempts' => 3,

        /*
         * Default shelf life, by category. A backed-up queue must not deliver
         * "the event is tomorrow" three days after the event — the message is
         * expired and logged as expired, which is information, rather than sent
         * and embarrassing.
         *
         * Null means it never expires: a receipt is worth delivering late.
         */
        'default_expiry_hours' => [
            'transactional' => null,
            'marketing' => 72,
            'system' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS
    |--------------------------------------------------------------------------
    |
    | Ghana-specific, and the details below are the ones that cost money or
    | silently lose messages.
    |
    | SENDER ID. An alphanumeric sender ID must be REGISTERED with each network
    | or the message is accepted by the provider and dropped by the network,
    | with no error anywhere. That failure is invisible from our side, which is
    | why `provider_status` is recorded separately from our own status and why a
    | message with no delivery report is `unknown`, never `delivered`.
    |
    | ENCODING. GSM-7 fits 160 characters in one segment; anything outside that
    | alphabet forces UCS-2, which fits 70. The Ghana cedi sign ₵ is NOT in
    | GSM-7. Writing "GH₵ 50.00" in a template therefore turns a one-segment
    | message into a three-segment one for every recipient, for one character.
    | The segmenter detects this and the template editor refuses to let it
    | through unnoticed.
    */
    'sms' => [

        'driver' => env('SMS_DRIVER', 'log'),

        /*
         * The registered alphanumeric sender ID.
         *
         * Confirmed with the Foundation 2026-09-04: mNotify, sender ID
         * "GreaterHope". Eleven characters, which is exactly the GSM limit —
         * one more and the networks would reject it, silently.
         */
        'sender_id' => env('SMS_SENDER_ID', 'GreaterHope'),

        // Alphanumeric sender IDs are capped at 11 characters by GSM.
        'sender_id_max_length' => 11,

        'segments' => [
            'gsm7' => ['single' => 160, 'multipart' => 153],
            'ucs2' => ['single' => 70, 'multipart' => 67],
        ],

        /*
         * Indicative cost per segment in INTEGER PESEWAS, used to cost messages
         * before a provider account exists. Replace with the contracted rate
         * once one does; it is an estimate and the column that stores it says so.
         */
        'cost_per_segment_minor' => (int) env('SMS_COST_PER_SEGMENT_MINOR', 4),

        /*
         * A template longer than this is refused. Three segments is three times
         * the cost and, on a feature phone, three separate arrivals that may
         * reorder. If a message will not fit, it wants to be an email with an
         * SMS pointing at it.
         */
        'max_segments' => (int) env('SMS_MAX_SEGMENTS', 2),

        /*
         * Monthly spend alert threshold, in integer pesewas. An ALERT, not a
         * stop: cutting off SMS mid-month silences exactly the messages that
         * matter most, and the figure is an estimate against a rate that may be
         * wrong — not a good enough reason to stop talking to people.
         */
        'monthly_budget_minor' => (int) env('SMS_MONTHLY_BUDGET_GHS', 200) * 100,

        /*
         * Ghana mobile prefixes, national format, for network attribution and
         * cost reporting. Not used for routing — the provider does that — but a
         * bill is much easier to check when the log knows which network each
         * message went to.
         */
        'networks' => [
            'mtn' => ['024', '025', '053', '054', '055', '059'],
            'telecel' => ['020', '050'],
            'at' => ['026', '027', '056', '057'],
            'glo' => ['023'],
        ],

        'country_code' => '233',

        /*
         * mNotify — the chosen provider.
         *
         * Credentials live in .env and nowhere else. `base_url` is
         * configurable because mNotify has moved its API host before, and a
         * hardcoded hostname is a deployment nobody can do quickly.
         */
        'mnotify' => [
            'api_key' => env('MNOTIFY_API_KEY'),
            'base_url' => env('MNOTIFY_BASE_URL', 'https://api.mnotify.com/api'),

            /*
             * Short, and deliberately so. This runs inside a cron-launched
             * worker with --max-time=55 draining a batch; a provider that
             * hangs for sixty seconds on one message must not take the whole
             * batch down with it. A timeout is a failed message that retries,
             * which is recoverable.
             */
            'timeout' => (int) env('MNOTIFY_TIMEOUT', 15),

            /*
             * SMS credits are pre-paid. Running out is silent from our side —
             * messages are simply rejected — so the balance is checked and
             * recorded, and a low balance raises a warning while there is still
             * time to top up.
             */
            'low_balance_credits' => (int) env('SMS_LOW_BALANCE_THRESHOLD', 50),
        ],

        /*
         * The other three gateways behind the same `SmsGateway` contract.
         * Every one of these keys is read by its gateway class and nothing
         * else; the driver is chosen in Settings → Communications, with the
         * `.env` value as the default.
         */
        'arkesel' => [
            'api_key' => env('ARKESEL_API_KEY'),
            'base_url' => env('ARKESEL_BASE_URL', 'https://sms.arkesel.com/api/v2'),
            'timeout' => (int) env('ARKESEL_TIMEOUT', 15),
        ],

        'hubtel' => [
            'client_id' => env('HUBTEL_CLIENT_ID'),
            'client_secret' => env('HUBTEL_CLIENT_SECRET'),
            'base_url' => env('HUBTEL_BASE_URL', 'https://smsc.hubtel.com/v1'),
            'timeout' => (int) env('HUBTEL_TIMEOUT', 15),
        ],

        'twilio' => [
            'account_sid' => env('TWILIO_ACCOUNT_SID'),
            'auth_token' => env('TWILIO_AUTH_TOKEN'),
            'from' => env('TWILIO_FROM'),
            'messaging_service_sid' => env('TWILIO_MESSAGING_SERVICE_SID'),
            'base_url' => env('TWILIO_BASE_URL', 'https://api.twilio.com/2010-04-01'),
            'timeout' => (int) env('TWILIO_TIMEOUT', 15),
        ],

        // In whichever unit the chosen driver reports: credits for mNotify
        // and Arkesel, money for Twilio. Hubtel reports none.
        'low_balance' => (float) env('SMS_LOW_BALANCE_THRESHOLD', 50),

        /*
         * How long to keep asking mNotify whether a message arrived.
         *
         * A report that has not appeared within a day is not going to. The
         * message stays `sent` — never `delivered` — because handing a message
         * to a provider is not evidence that a network accepted it.
         */
        'delivery_report_window_hours' => 24,

        /*
         * SMS is intrusive and, in Ghana, often paid for by the recipient's
         * attention rather than their money — but it is still the channel that
         * reaches a beneficiary with no smartphone. Quiet hours are respected
         * for marketing only; a payment confirmation goes when it goes.
         */
        'quiet_hours' => ['from' => '21:00', 'to' => '07:00'],
        'quiet_hours_apply_to' => ['marketing'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound delivery webhooks
    |--------------------------------------------------------------------------
    |
    | Where bounces, complaints and delivery reports come back in. Without this
    | the suppression list never fills, the bounce rate climbs, and the first
    | thing to stop being delivered is donation receipts — which is the failure
    | the suppression list exists to prevent.
    |
    | ⚠ A provider with NO SECRET verifies as FALSE, never as true.
    |
    | The tempting shortcut is "no secret configured, so skip the check", and it
    | turns an unconfigured endpoint into an open one that anybody can use to
    | suppress any address they can guess. Failing closed means an unconfigured
    | provider records events and acts on none of them: visible, and harmless.
    |
    | Every path lives under /webhooks/ because that prefix is what
    | bootstrap/app.php exempts from CSRF — a webhook path outside it would be
    | rejected on every delivery.
    */
    'webhooks' => [

        'providers' => [

            'mnotify' => [
                'channel' => 'sms',
                'secret' => env('MNOTIFY_WEBHOOK_SECRET'),
                'signature_header' => 'x-mnotify-signature',
                'algorithm' => 'sha256',
            ],

            /*
             * Whichever transactional mail provider is chosen. Blueprint risk
             * DEL-3 says receipts should not go through the shared cPanel IP,
             * so one of these will be in use — and each signs differently,
             * which is why the signed string is assembled from config rather
             * than hardcoded.
             */
            'postmark' => [
                'channel' => 'email',
                'secret' => env('POSTMARK_WEBHOOK_SECRET'),
                'signature_header' => 'x-postmark-signature',
                'algorithm' => 'sha256',
            ],

            'mailgun' => [
                'channel' => 'email',
                'secret' => env('MAILGUN_WEBHOOK_SECRET'),
                'signature_header' => 'x-mailgun-signature',
                // Mailgun signs timestamp + body, so a captured signature
                // cannot be replayed against a different payload.
                'timestamp_header' => 'x-mailgun-timestamp',
                'algorithm' => 'sha256',
            ],

            'resend' => [
                'channel' => 'email',
                'secret' => env('RESEND_WEBHOOK_SECRET'),
                'signature_header' => 'svix-signature',
                'timestamp_header' => 'svix-timestamp',
                'algorithm' => 'sha256',
            ],
        ],

        'path_prefix' => 'webhooks/delivery',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracking
    |--------------------------------------------------------------------------
    |
    | OFF, and off on purpose.
    |
    | An open-tracking pixel records that a named person read a message, when,
    | and from roughly where. Under Act 843 that is processing of personal data
    | needing its own lawful basis and its own disclosure in the privacy notice
    | — neither of which the Foundation has yet decided on. Turning it on is a
    | decision for the trustees, not a default someone inherits.
    |
    | The columns exist so enabling it later is a config change rather than a
    | migration, and the dispatcher never writes them while this is false.
    */
    'tracking' => [
        'opens' => env('COMMS_TRACK_OPENS', false),
        'clicks' => env('COMMS_TRACK_CLICKS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Templates
    |--------------------------------------------------------------------------
    */
    'templates' => [

        /*
         * Rendering REFUSES when a required variable is missing, rather than
         * emitting an empty string or a raw {{token}}.
         *
         * "Dear ," and "Dear {{donor_name}}," are both worse than a failed job
         * that retries and alerts. This is the same stance the acknowledgement
         * builder takes, for the same reason: a document that goes out wrong
         * cannot be recalled, and a job that fails loudly can be fixed.
         */
        'strict_variables' => true,

        /*
         * Placeholder syntax. Deliberately not Blade: these strings are edited
         * by non-technical staff in a browser, and Blade in a database column
         * is arbitrary PHP execution one SQL injection away from being someone
         * else's PHP.
         */
        'delimiters' => ['{{', '}}'],

        /*
         * Variables available to EVERY template without being declared, drawn
         * from the CMS settings layer so none of it is hardcoded.
         *
         * ⚠ Each value on the right must be a key the settings seeder actually
         * creates, or a null meaning "computed below". Three of these pointed
         * at keys that do not exist — `general.site_name`, `general.site_url`
         * and `organisation.legal_name` — which is why every seeded template
         * signed off "With gratitude," and every receipt subject read "Your
         * donation to  — SCGHF-R…". An unresolved global collapses to an empty
         * string rather than leaving a visible {{token}}, so the failure was
         * silent by design and invisible in review.
         */
        'global_variables' => [
            'site_name' => 'general.short_name',
            'site_url' => null,
            'organisation_legal_name' => 'general.legal_name',
            'organisation_address' => 'contact.address',
            'contact_email' => 'contact.email_general',
            'contact_phone' => 'contact.phone_primary',
            'current_year' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Newsletters and campaigns
    |--------------------------------------------------------------------------
    */
    'newsletter' => [

        /*
         * A campaign must be sent to a test address, and looked at, before it
         * can go to the list. Not bureaucracy — the failure it prevents is a
         * broken merge tag or a dead link reaching two thousand people at once,
         * which cannot be recalled and which costs more trust than the campaign
         * was going to earn.
         */
        'require_test_send' => true,

        /*
         * Drafting and sending are separate permissions already
         * (newsletter.draft / newsletter.send). This makes the model enforce
         * what the permissions imply: an unapproved campaign will not send,
         * whatever route is used to try.
         */
        'require_approval' => true,

        /*
         * Only confirmed subscribers. The double opt-in on `subscribers` is
         * worth nothing if a campaign can be built from pending rows.
         */
        'confirmed_only' => true,

        /*
         * Recipients are re-checked against the suppression list at SEND time,
         * not at build time. On a host that sends two hundred messages an hour,
         * hours pass between the two — and somebody who unsubscribes during
         * that window has unsubscribed.
         */
        'recheck_suppression_at_send' => true,

        /*
         * Where the one-click unsubscribe link points. No login, no
         * confirmation page: an unsubscribe that takes three clicks becomes a
         * spam complaint, and a spam complaint costs the sending domain far
         * more than a lost subscriber does.
         */
        'unsubscribe_path' => '/newsletter/unsubscribe',
    ],
];
