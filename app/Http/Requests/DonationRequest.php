<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Cause;
use App\ValueObjects\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * What somebody may put on the donation form.
 *
 * ── The amount is typed in cedis and validated in pesewas ───────────────────
 *
 * A donor types 50. Everything downstream works in minor units, and
 * `Money::ofMajor()` is the only conversion allowed — a stray `(int) $amount`
 * anywhere between here and the gateway turns a fifty-cedi gift into fifty
 * pesewas, and the donor sees the right number on the form and the wrong one
 * on their bank statement.
 *
 * The limits come from the settings layer rather than a constant, because the
 * floor exists for a commercial reason — a gift smaller than the transaction
 * fee costs the foundation money to accept — and that figure moves when the
 * gateway's pricing does.
 *
 * ── The cause is validated as LIVE, not merely as existing ──────────────────
 *
 * A slug pointing at a draft or a closed appeal must not be accepted. Otherwise
 * anybody can take a payment against an appeal the foundation has announced it
 * closed, and the foundation is holding money it said it had stopped raising.
 *
 * ── Consent is required to hold their details, not to give ──────────────────
 *
 * The tick covers storing a name and an email in order to issue a receipt —
 * which Act 843 requires a basis for. The MARKETING opt-ins are separate and
 * optional, and neither blocks the gift: a donation form that refuses money
 * unless somebody joins a mailing list is a donation form that loses money.
 */
class DonationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /*
             * In cedis, because that is what the donor is typing. `decimal:0,2`
             * rather than `numeric` so "50.005" is refused rather than silently
             * rounded into half a pesewa nobody can pay.
             */
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],

            'cause' => [
                'nullable',
                'string',
                Rule::exists(Cause::class, 'slug')->where('is_published', true),
            ],

            'donor_name' => ['required', 'string', 'max:191'],
            'donor_email' => ['required', 'string', 'email:rfc', 'max:191'],
            'donor_phone' => ['nullable', 'string', 'max:20', 'regex:/^(\+?233|0)[2345][0-9]{8}$/'],

            'cover_fee' => ['nullable', 'boolean'],
            'is_anonymous' => ['nullable', 'boolean'],

            /*
             * How often. `once` is a single gift; anything else is a standing
             * commitment established once the first payment is confirmed.
             * Weekly is offered because it is how a market trader budgets.
             */
            'frequency' => ['nullable', Rule::in(['once', 'weekly', 'monthly', 'quarterly', 'annually'])],

            /*
             * The hosted page, or a direct charge to a mobile-money wallet. The
             * direct charge needs the network and the number the prompt goes
             * to — which may not be the number they gave as their contact.
             */
            'pay_with' => ['nullable', Rule::in(['gateway', 'momo'])],
            'momo_provider' => ['required_if:pay_with,momo', 'nullable', Rule::in(['mtn', 'vod', 'atl'])],
            'momo_phone' => ['required_if:pay_with,momo', 'nullable', 'string', 'max:20', 'regex:/^(\+?233|0)[2345][0-9]{8}$/'],

            'public_message' => ['nullable', 'string', 'max:300'],
            'donor_address' => ['nullable', 'string', 'max:255'],
            'donor_city' => ['nullable', 'string', 'max:191'],

            // Attribution, carried as hidden fields from the link that brought
            // the donor here. Never shown, never trusted for anything but a
            // report.
            'source' => ['nullable', 'string', 'max:64'],
            'utm_source' => ['nullable', 'string', 'max:100'],
            'utm_medium' => ['nullable', 'string', 'max:100'],
            'utm_campaign' => ['nullable', 'string', 'max:100'],
            'utm_term' => ['nullable', 'string', 'max:100'],
            'utm_content' => ['nullable', 'string', 'max:100'],

            // Marketing, and optional. See the note at the top.
            'consent_email' => ['nullable', 'boolean'],
            'consent_sms' => ['nullable', 'boolean'],

            // Holding their details at all. Not optional.
            'consent' => ['accepted'],

            'tribute_type' => ['nullable', Rule::in(['memory', 'honour'])],
            'tribute_name' => ['nullable', 'string', 'max:191', 'required_with:tribute_type'],
            'tribute_message' => ['nullable', 'string', 'max:500'],
            'tribute_notify_email' => ['nullable', 'string', 'email:rfc', 'max:191'],
        ];
    }

    /**
     * The checks that need the settings layer or another field.
     *
     * ⚠ The amount limits are deliberately here rather than in `rules()`. They
     * are read from the CMS, and a `min:` baked into a rule string would go
     * stale the moment the foundation changed the figure — which is precisely
     * the sort of drift nobody notices until a donor is refused for no visible
     * reason.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('amount')) {
                    return;
                }

                $amount = Money::ofMajor((float) $this->input('amount'));

                /*
                 * The APPEAL's floor where it has one, and the site's
                 * otherwise. They mean different things: the site floor is
                 * commercial — a gift smaller than the transaction fee costs
                 * money to accept — and an appeal's own is editorial, the
                 * smallest gift that buys anything in that appeal's terms.
                 */
                $minimum = $this->causeModel()?->minimumDonation() ?? setting('donations.min_amount');
                $maximum = setting('donations.max_amount');

                if ($minimum instanceof Money && $amount->lessThan($minimum)) {
                    $validator->errors()->add('amount', __(
                        'The smallest gift we can accept is :amount — anything less costs more to '
                        .'process than it raises.',
                        ['amount' => $minimum->format()],
                    ));
                }

                if ($maximum instanceof Money && $amount->greaterThan($maximum)) {
                    /*
                     * A ceiling on a public form is a fraud control, not a
                     * limit on generosity — a very large gift typed into a
                     * website is more often a mistyped one. The message says
                     * how to make it properly rather than just refusing.
                     */
                    $validator->errors()->add('amount', __(
                        'For a gift above :amount, please get in touch so we can arrange it directly.',
                        ['amount' => $maximum->format()],
                    ));
                }
            },

            function (Validator $validator): void {
                $cause = $this->causeModel();

                if ($cause !== null && ! $cause->acceptsDonations()) {
                    $validator->errors()->add('cause', __(
                        'That appeal has closed. Your gift can still go to our general work.'
                    ));
                }
            },
        ];
    }

    /** The chosen appeal, if one was chosen and it exists. */
    public function causeModel(): ?Cause
    {
        $slug = $this->string('cause')->toString();

        return $slug === '' ? null : Cause::query()->where('slug', $slug)->first();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'donor_email.required' => __('We need an email address to send your receipt to.'),
            'donor_phone.regex' => __('That does not look like a Ghanaian number. Try 024 123 4567.'),
            'consent.accepted' => __('We need your permission to hold your details in order to issue a receipt.'),
            'tribute_name.required_with' => __('Please tell us who this gift is for.'),
            'momo_provider.required_if' => __('Which network is the wallet on?'),
            'momo_phone.required_if' => __('Which number should we send the prompt to?'),
            'momo_phone.regex' => __('That does not look like a Ghanaian number. Try 024 123 4567.'),
        ];
    }

    public function frequency(): string
    {
        return $this->string('frequency')->toString() ?: 'once';
    }

    public function wantsRecurring(): bool
    {
        return $this->frequency() !== 'once';
    }

    public function paysByMobileMoney(): bool
    {
        return $this->input('pay_with') === 'momo';
    }

    /**
     * The UTM parameters that were filled in, or null.
     *
     * @return array<string, string>|null
     */
    public function utm(): ?array
    {
        $utm = collect(['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'])
            ->mapWithKeys(fn (string $key): array => [substr($key, 4) => trim((string) $this->input($key))])
            ->filter()
            ->all();

        return $utm === [] ? null : $utm;
    }
}
