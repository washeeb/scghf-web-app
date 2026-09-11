<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Support\PageMeta;
use App\ValueObjects\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * A donor's standing gifts: see, pause, resume, change the amount, cancel.
 *
 * ── Reached two ways, and both prove the same thing ─────────────────────────
 *
 * From the account area, signed in — the subscription must belong to the
 * signed-in user's donor record. Or from the signed link in every recurring
 * email, valid for sixty days and needing no account, because a donor who set
 * up a monthly gift from a phone at a church event should not have to make an
 * account in order to stop it.
 *
 * ── Every change is a POST with its own confirmation ────────────────────────
 *
 * Nothing changes on a GET, so a forwarded or prefetched link can only show
 * the page. Cancel asks twice: the page, then the model, which records why.
 *
 * ── The amount can go down as well as up ────────────────────────────────────
 *
 * The obvious temptation is to make "change amount" a way to increase it. A
 * donor whose circumstances have changed and who can reduce a gift rather
 * than cancel it is a donor the foundation keeps.
 */
class RegularGivingController extends Controller
{
    public function index(Request $request): View
    {
        $subscriptions = Subscription::query()
            ->whereHas('donor', fn ($q) => $q->where('user_id', $request->user()->getKey()))
            ->with(['cause', 'charges' => fn ($q) => $q->latest('scheduled_on')->limit(6)])
            ->orderByDesc('created_at')
            ->get();

        return view('account.giving', [
            'subscriptions' => $subscriptions,
            'signed' => false,
            'meta' => PageMeta::site(__('Your regular giving'), noindex: true),
        ]);
    }

    /** The signed link from an email: one subscription, no account needed. */
    public function manage(Request $request, Subscription $subscription): View
    {
        $this->authorise($request, $subscription);

        return view('account.giving', [
            'subscriptions' => collect([$subscription->load(['cause', 'charges' => fn ($q) => $q->latest('scheduled_on')->limit(6)])]),
            'signed' => true,
            'meta' => PageMeta::site(__('Your regular gift'), noindex: true),
        ]);
    }

    public function pause(Request $request, Subscription $subscription): RedirectResponse
    {
        $this->authorise($request, $subscription);

        if (! $subscription->status->isFinished()) {
            $subscription->pause(__('Paused by the donor.'));
        }

        return $this->back($request, $subscription, __('Your gift is paused. Nothing will be taken until you start it again.'));
    }

    public function resume(Request $request, Subscription $subscription): RedirectResponse
    {
        $this->authorise($request, $subscription);

        try {
            $subscription->resume();
        } catch (RuntimeException $e) {
            return $this->back($request, $subscription, $e->getMessage());
        }

        return $this->back($request, $subscription, __('Your gift is active again. The next one is on :date.', [
            'date' => $subscription->fresh()->next_charge_on?->format('j F Y'),
        ]));
    }

    public function amount(Request $request, Subscription $subscription): RedirectResponse
    {
        $this->authorise($request, $subscription);

        $request->validate(['amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01']]);

        $amount = Money::ofMajor((float) $request->input('amount'));
        $minimum = setting('donations.min_amount');

        if ($minimum instanceof Money && $amount->lessThan($minimum)) {
            return $this->back($request, $subscription, __('The smallest regular gift we can take is :amount.', ['amount' => $minimum->format()]));
        }

        if ($subscription->status->isFinished()) {
            return $this->back($request, $subscription, __('This gift has ended; set up a new one to give again.'));
        }

        $subscription->forceFill(['amount' => $amount])->save();

        return $this->back($request, $subscription, __('Changed. From the next gift you will give :amount.', ['amount' => $amount->format()]));
    }

    public function cancel(Request $request, Subscription $subscription): RedirectResponse
    {
        $this->authorise($request, $subscription);

        $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        if (! $subscription->status->isFinished()) {
            $subscription->cancel($request->string('reason')->toString() ?: __('Cancelled by the donor.'));
        }

        return $this->back($request, $subscription, __('Your regular gift has been stopped. Thank you for everything you gave.'));
    }

    /**
     * Signed in as the donor, or holding a valid signed link.
     *
     * The signature is checked on the URL of the management page, not of the
     * POST; the forms on that page carry the same signed parameters so a
     * request that changes something is as authenticated as the one that
     * showed it.
     */
    private function authorise(Request $request, Subscription $subscription): void
    {
        $user = $request->user();

        if ($user !== null && $subscription->donor?->user_id === $user->getKey()) {
            return;
        }

        if ($request->hasValidSignature()) {
            return;
        }

        throw new AccessDeniedHttpException;
    }

    private function back(Request $request, Subscription $subscription, string $message): RedirectResponse
    {
        $to = $request->hasValidSignature()
            ? URL::temporarySignedRoute('giving.manage', now()->addDays(60), ['subscription' => $subscription->ulid])
            : route('account.giving');

        return redirect()->to($to)->with('status', $message);
    }
}
