<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ProfileUpdateRequest;
use App\Models\Suppression;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A donor's own details and what we are allowed to send them.
 *
 * ── Turning marketing off has to reach the suppression list ─────────────────
 *
 * `accepts_email_marketing` on the user is what the campaign builder reads.
 * `suppressions` is what MessageDispatcher reads, on every message. Updating
 * only the first would mean a donor unticks the box, the checkbox shows
 * unticked, and the next appeal goes out anyway — because the dispatcher never
 * looks at that column.
 *
 * So the checkbox writes to both: the column for the interface, a
 * `marketing`-scope suppression for the door. Ticking it again releases the
 * suppression, which `Suppression::release()` permits for an unsubscribe and
 * refuses for an erasure request.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('account.profile', ['user' => $request->user()]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        $user->fill($request->profileAttributes());

        /*
         * The consent timestamp moves only when permission is newly given, and
         * it is set with `forceFill` because it is deliberately NOT in
         * `$fillable`.
         *
         * That is the right shape twice over. `marketing_consent_at` is
         * evidence of WHEN somebody agreed, which Act 843 asks for — so it must
         * not be settable from a request payload, and it must not be touched by
         * saves that withdraw consent, or the evidence quietly becomes a
         * last-modified date that proves nothing.
         */
        if ($request->grantsMarketingConsent()) {
            $user->forceFill(['marketing_consent_at' => now()]);
        }

        $user->save();

        $this->syncMarketingSuppressions($request);

        /*
         * The donor record carries the same two preferences, because the
         * donation form writes to it for people who never create an account.
         * They must not disagree: `Donor::mayBeEmailed()` reads the donor row,
         * and a donor whose account says no and whose donor row says yes is a
         * donor who unsubscribed and kept receiving appeals.
         */
        $user->donor?->forceFill([
            'consent_email' => $request->boolean('accepts_email_marketing'),
            'consent_sms' => $request->boolean('accepts_sms_marketing'),
        ])->save();

        return back()->with('status', __('Your details have been saved.'));
    }

    /**
     * Keep the suppression list in step with the two checkboxes.
     *
     * ── Scope marketing, never all ──────────────────────────────────────────
     *
     * An unsubscribe stops appeals. It must not stop a receipt, an order update
     * or a password reset — those are the record of something the person did,
     * and withholding them is a worse failure than an unwanted newsletter.
     *
     * ── Ticking the box back on releases ONLY an unsubscribe ────────────────
     *
     * A hard bounce or a spam complaint on the same address stays exactly where
     * it is. Releasing a hard bounce because somebody ticked a checkbox sends
     * mail to a mailbox that does not exist, which is the behaviour that gets a
     * sending domain blocklisted — and an erasure suppression cannot be
     * released at all, which the model enforces itself.
     */
    private function syncMarketingSuppressions(ProfileUpdateRequest $request): void
    {
        $user = $request->user();

        if ($user === null) {
            return;
        }

        $channels = [
            [Suppression::CHANNEL_EMAIL, $request->normalisedEmail(), $request->boolean('accepts_email_marketing')],
            [Suppression::CHANNEL_SMS, (string) $user->phone, $request->boolean('accepts_sms_marketing')],
        ];

        foreach ($channels as [$channel, $address, $wanted]) {
            // No phone number on the account means nothing to suppress and
            // nothing to release. `tryNormalise` rather than `normalise`
            // because a number the model kept as typed can fail here, and a
            // saved profile must not 500 over a preference checkbox.
            $normalised = $address === ''
                ? null
                : Suppression::tryNormaliseAddress($channel, $address);

            if ($normalised === null) {
                continue;
            }

            $existing = Suppression::where('channel', $channel)
                ->where('address', $normalised)
                ->whereNull('released_at')
                ->first();

            if (! $wanted) {
                Suppression::record(
                    channel: $channel,
                    address: $normalised,
                    reason: Suppression::REASON_UNSUBSCRIBE,
                    detail: 'Unticked in the account settings.',
                    source: 'account',
                    actor: $user,
                );

                continue;
            }

            if ($existing?->reason === Suppression::REASON_UNSUBSCRIBE) {
                $existing->release($user, 'The account holder opted back in from their account settings.');
            }
        }
    }
}
