<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\DonationRequest;
use App\Models\Cause;
use App\Models\Donation;
use App\Models\PaymentTransaction;
use App\Payments\DonationService;
use App\Payments\PaymentManager;
use App\Support\PageMeta;
use App\ValueObjects\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * Giving by card or mobile money.
 *
 * ── The browser redirect is not proof of payment ────────────────────────────
 *
 * ⚠ The single most important thing in this file. A donor coming back from
 * Paystack proves only that a browser followed a link. The money is confirmed
 * by the webhook, signed with HMAC-SHA512 and verified before anything is
 * marked complete — so the return page reports what the DATABASE says, never
 * what the query string says.
 *
 * Nothing here reads `?status=success`. A page that did could be made to
 * display a completed gift by anybody who typed the URL, and the foundation
 * would thank somebody who had paid nothing.
 *
 * ── A pending gift gets an honest page, not a fake one ──────────────────────
 *
 * Webhooks on shared hosting arrive through a cron-driven queue, so there is
 * routinely a minute between paying and the record catching up. The page says
 * the payment is being confirmed rather than pretending either way — and it
 * says the reference, so a donor who never sees a receipt has something to
 * quote.
 *
 * ── The form is a plain POST ────────────────────────────────────────────────
 *
 * No JavaScript is needed to give. On a low-end Android phone over a metered
 * connection — the real usage context here — a donation form that waits for a
 * script is one that loses the gift.
 */
class DonateController extends Controller
{
    /**
     * Paystack's codes for the Ghanaian networks, and what a donor calls them.
     * Telecel was Vodafone, and the gateway still says `vod`.
     */
    public const MOMO_PROVIDERS = [
        'mtn' => 'MTN Mobile Money',
        'vod' => 'Telecel Cash',
        'atl' => 'AirtelTigo Money',
    ];

    public function show(Request $request): View
    {
        $cause = $this->causeFromRequest($request);

        return view('donate.form', [
            'cause' => $cause,
            'causes' => $this->openCauses(),
            'presets' => $this->presets(),
            'attribution' => $this->attribution($request),
            'momoProviders' => self::MOMO_PROVIDERS,
            'meta' => PageMeta::site(
                $cause !== null
                    ? __('Give to :appeal', ['appeal' => $cause->title])
                    : __('Donate'),
                __('Support the work of :name.', ['name' => setting('general.short_name', config('app.name'))]),
            ),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Donate'), 'url' => null],
            ],
        ]);
    }

    /**
     * Build the gift and send the donor to the gateway.
     *
     * `DonationService::start()` does the work: it grosses up a fee-covered
     * gift rather than adding the fee (adding it always leaves the foundation
     * short), allocates the amount across designations so the parts sum exactly
     * to the whole, and refuses to charge for a gift whose items do not
     * reconcile.
     */
    public function store(DonationRequest $request): RedirectResponse
    {
        $input = [
            // `ofMajor`, because the donor typed cedis. This is the only place
            // the conversion happens.
            'amount' => Money::ofMajor((float) $request->input('amount')),
            'cause' => $request->causeModel(),

            'donor_name' => $request->string('donor_name')->toString(),
            'donor_email' => $request->string('donor_email')->toString(),
            'donor_phone' => $request->string('donor_phone')->toString() ?: null,
            'donor_address' => $request->string('donor_address')->toString() ?: null,
            'donor_city' => $request->string('donor_city')->toString() ?: null,

            'cover_fee' => $request->boolean('cover_fee'),
            'is_anonymous' => $request->boolean('is_anonymous'),
            'wants_recurring' => $request->wantsRecurring(),
            'recurring_interval' => $request->wantsRecurring() ? $request->frequency() : null,
            'public_message' => $request->string('public_message')->toString() ?: null,

            'source' => $request->string('source')->toString() ?: null,
            'utm' => $request->utm(),
            'callback_url' => route('donate.callback'),

            'consent_email' => $request->boolean('consent_email'),
            'consent_sms' => $request->boolean('consent_sms'),
            'consent_text' => $this->consentText(),
            'consent_ip' => $request->ip(),

            'user_id' => $request->user()?->getKey(),

            'tribute' => $request->filled('tribute_type') ? [
                'type' => $request->string('tribute_type')->toString(),
                'name' => $request->string('tribute_name')->toString(),
                'message' => $request->string('tribute_message')->toString() ?: null,
                'notify_email' => $request->string('tribute_notify_email')->toString() ?: null,
            ] : [],
        ];

        if ($request->paysByMobileMoney()) {
            /*
             * A direct charge. The donor stays here and approves a prompt on
             * their phone; the waiting page — the same thank-you page, in its
             * pending state — says what to do and watches for the webhook.
             */
            $result = app(DonationService::class)->startMobileMoney($input + [
                'momo_provider' => $request->string('momo_provider')->toString(),
                'momo_phone' => $request->string('momo_phone')->toString(),
            ]);

            return redirect()->route('donate.thanks', $result['donation']);
        }

        $result = app(DonationService::class)->start($input);

        /** @var PaymentTransaction $transaction */
        $transaction = $result['transaction'];

        if ($transaction->authorization_url === null) {
            /*
             * The gateway refused to start the payment. Nothing has been
             * charged, so the donor is returned to the form with their input
             * intact and an honest message — not sent onward to a page that
             * would have to explain a failure it cannot describe.
             */
            return back()
                ->withInput()
                ->withErrors(['amount' => __(
                    'We could not start the payment just now. Nothing has been charged — please try '
                    .'again, or use bank transfer or Mobile Money instead.'
                )]);
        }

        return redirect()->away($transaction->authorization_url);
    }

    /**
     * Where Paystack sends the donor back to.
     *
     * `PAYSTACK_CALLBACK_URL` has pointed at `/donate/callback` in
     * `.env.example` since Phase 2, and the route did not exist — so a real
     * payment would have returned the donor to a 404 immediately after taking
     * their money.
     *
     * The reference in the query string is looked UP; it is not trusted to say
     * anything about the outcome.
     */
    public function callback(Request $request): RedirectResponse
    {
        $reference = $request->string('reference')->toString()
            ?: $request->string('trxref')->toString();

        $transaction = $reference === ''
            ? null
            : PaymentTransaction::query()->where('gateway_reference', $reference)->first();

        $donation = $transaction?->payable instanceof Donation ? $transaction->payable : null;

        if ($donation === null) {
            /*
             * No reference, or one that matches nothing. Not an error page: a
             * donor who reached this URL some other way is better served by the
             * donation form than by a stack trace of an explanation.
             */
            return redirect()->route('donate');
        }

        /*
         * Asked of the gateway, not assumed from the redirect. The webhook is
         * the source of truth and it arrives through a cron-driven queue, so a
         * donor would otherwise see "confirming" for a minute after paying;
         * verifying here settles the gift through exactly the same path the
         * webhook uses, and a second settlement is a no-op.
         */
        app(PaymentManager::class)->verifyAndSettle($transaction);

        return redirect()->route('donate.thanks', $donation);
    }

    /**
     * Where the gift stands, for the waiting page to poll.
     *
     * A pending transaction is verified with the gateway each time, so a
     * mobile-money approval shows here within seconds rather than when the
     * queue next drains. Throttled on the route; nothing here is expensive
     * but a verify is a call to Paystack.
     */
    public function status(Donation $donation): JsonResponse
    {
        $transaction = $donation->transaction;

        if ($transaction !== null && ! $transaction->status->isFinal()) {
            app(PaymentManager::class)->verifyAndSettle($transaction);
            $transaction->refresh();
        }

        $donation->refresh();

        return response()->json([
            'status' => $donation->status->value,
            'awaiting' => $transaction?->awaiting_action,
            'message' => $transaction?->display_text,
            'settled' => $donation->status->value !== 'pending',
        ]);
    }

    /**
     * The code the network texted, during a direct mobile-money charge.
     */
    public function otp(Request $request, Donation $donation): RedirectResponse
    {
        $request->validate(['otp' => ['required', 'string', 'regex:/^[0-9]{4,8}$/']]);

        $transaction = $donation->transaction;

        if ($transaction === null || $transaction->awaiting_action !== 'send_otp') {
            return redirect()->route('donate.thanks', $donation);
        }

        $transaction = app(PaymentManager::class)->submitOtp($transaction, $request->string('otp')->toString());

        if ($transaction->status->value === 'failed') {
            return redirect()->route('donate.thanks', $donation)
                ->withErrors(['otp' => __('That code was not accepted. Nothing has been charged.')]);
        }

        return redirect()->route('donate.thanks', $donation);
    }

    /**
     * What happened, according to the database.
     *
     * Reached by ULID rather than by reference or id: it is what §1.1 requires
     * of anything in a URL, and it does not let somebody walk the donation
     * table by incrementing a number.
     */
    public function thanks(Donation $donation): View
    {
        $donation->load(['cause', 'transaction', 'receipt']);

        return view('donate.thanks', [
            'donation' => $donation,
            'transaction' => $donation->transaction,
            /*
             * A signed link, valid for a month: the receipt is a document the
             * donor is entitled to and the page they are on proves nothing
             * about who they are. Signing it means the link in the email and
             * the link here are the same thing, and neither can be guessed.
             */
            'receiptUrl' => $donation->receipt !== null
                ? URL::temporarySignedRoute('receipts.download', now()->addMonth(), ['receipt' => $donation->receipt->ulid])
                : null,
            'shareUrl' => $donation->cause !== null && ! $donation->cause->is_general_fund
                ? route('causes.show', $donation->cause)
                : route('donate'),
            'meta' => PageMeta::site(__('Thank you'), noindex: true),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Thank you'), 'url' => null],
            ],
        ]);
    }

    /**
     * Where the donor came from, for the hidden fields on the form.
     *
     * Read from the query string of the page that loaded the form — a link in
     * a WhatsApp broadcast, a radio advert's short URL — and carried through
     * the POST. Truncated and whitelisted here; the request validates them
     * again on the way back.
     *
     * @return array<string, string>
     */
    private function attribution(Request $request): array
    {
        return collect(['source', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'])
            ->mapWithKeys(fn (string $key): array => [$key => mb_substr(trim($request->string($key)->toString()), 0, 100)])
            ->filter()
            ->all();
    }

    /**
     * The suggested amounts.
     *
     * From `donations.presets`, in pesewas. A malformed setting yields an empty
     * list rather than an exception — a broken preset must not take down the
     * page the foundation raises money on.
     *
     * @return Collection<int, Money>
     */
    private function presets(): Collection
    {
        $presets = setting('donations.presets', []);

        if (! is_array($presets)) {
            return collect();
        }

        return collect($presets)
            ->filter(fn (mixed $minor): bool => is_int($minor) && $minor > 0)
            ->map(fn (int $minor): Money => Money::ofMinor($minor))
            ->values();
    }

    /** @return Collection<int, Cause> */
    private function openCauses(): Collection
    {
        return Cause::query()
            ->live()
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (Cause $cause): bool => $cause->acceptsDonations())
            ->values();
    }

    private function causeFromRequest(Request $request): ?Cause
    {
        $slug = $request->string('cause')->toString();

        if ($slug === '') {
            return null;
        }

        $cause = Cause::query()->where('slug', $slug)->first();

        return $cause?->acceptsDonations() === true ? $cause : null;
    }

    private function consentText(): string
    {
        return (string) setting(
            'compliance.donation_consent_text',
            __('I agree that :name may store my details in order to process this gift and issue a receipt.', [
                'name' => setting('general.legal_name', setting('general.short_name', config('app.name'))),
            ]),
        );
    }
}
