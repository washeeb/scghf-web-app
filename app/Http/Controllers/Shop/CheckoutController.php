<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shop;

use App\Communications\PhoneNumber;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\CheckoutRequest;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\ShippingZone;
use App\Shop\CheckoutService;
use App\Shop\CurrentCart;
use App\Support\PageMeta;
use App\ValueObjects\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use RuntimeException;

/**
 * From a basket to a paid order.
 *
 * ── The same rule as the donation page: the redirect proves nothing ─────────
 *
 * A customer coming back from Paystack proves only that a browser followed a
 * link. The order is marked paid by the signed webhook, and the confirmation
 * page reports what the DATABASE says: paid, being confirmed, or not paid. It
 * never reads `?status=` — a page that did could be made to show a paid order
 * to anybody who typed the URL, and the foundation would post a parcel for
 * money it never received.
 *
 * ── One step, then the gateway ──────────────────────────────────────────────
 *
 * Details are collected on one page and the order is written when the customer
 * presses "pay". A separate review step would mean an order — with its stock
 * hold — existing before the customer has committed to anything, and stock
 * held by people who were only looking is how a shop with twelve mugs shows
 * as sold out. The delivery charge for each region is listed on the page, and
 * the gateway shows the exact total before taking anything.
 *
 * ── `CheckoutService` does the work, in the right order ─────────────────────
 *
 * Availability is checked, prices are read live, the order and its snapshot
 * lines are written in one transaction, the stock is held, the arithmetic is
 * reconciled, and only then is the gateway called. Nothing here duplicates
 * any of it.
 */
class CheckoutController extends Controller
{
    public function __construct(
        private readonly CurrentCart $current,
        private readonly CheckoutService $checkout,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $cart = $this->current->current();

        if ($cart === null || $cart->isEmpty()) {
            return redirect()->route('shop.cart');
        }

        $cart->load('items.variant.product');

        if (! $cart->isCheckoutable()) {
            // The basket page names what is wrong; this page would only be
            // able to refuse.
            return redirect()->route('shop.cart');
        }

        $user = $request->user();

        return view('shop.checkout', [
            'cart' => $cart,
            'subtotal' => $cart->subtotal(),
            'discount' => $cart->coupon?->discountFor($cart->subtotal()),
            'delivery' => $this->deliveryOptions($cart->subtotal(), $cart->totalWeightGrams()),
            'pickup' => ShippingZone::query()->active()->where('is_pickup', true)->first(),
            'giftOptions' => $this->giftOptions($cart->subtotal()->minus($cart->coupon?->discountFor($cart->subtotal()) ?? Money::zero())),
            'prefill' => [
                'customer_name' => $user?->name,
                'customer_email' => $user?->email,
                'customer_phone' => $user?->phone ?? null,
            ],
            'meta' => PageMeta::site(__('Checkout'), noindex: true),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Shop'), 'url' => route('shop.index')],
                ['label' => __('Basket'), 'url' => route('shop.cart')],
                ['label' => __('Checkout'), 'url' => null],
            ],
        ]);
    }

    public function store(CheckoutRequest $request): RedirectResponse
    {
        $cart = $this->current->current();

        if ($cart === null || $cart->isEmpty()) {
            return redirect()->route('shop.cart');
        }

        $cart->load('items.variant.product');

        try {
            $result = $this->checkout->start($cart, [
                'user_id' => $request->user()?->getKey(),
                'customer_name' => $request->string('customer_name')->toString(),
                'customer_email' => $request->string('customer_email')->toString(),
                'customer_phone' => $request->string('customer_phone')->toString() ?: null,
                'is_pickup' => $request->isCollection(),
                'delivery_region' => $request->isCollection() ? null : $request->string('delivery_region')->toString(),
                'delivery_area' => $request->string('delivery_area')->toString() ?: null,
                'delivery_city' => $request->string('delivery_city')->toString() ?: null,
                'delivery_address' => $request->string('delivery_address')->toString() ?: null,
                'delivery_landmark' => $request->string('delivery_landmark')->toString() ?: null,
                'delivery_gps' => $request->string('delivery_gps')->toString() ?: null,
                'delivery_notes' => $request->string('delivery_notes')->toString() ?: null,
                'donation' => $request->input('donation_amount'),
                'callback_url' => route('shop.checkout.callback'),
            ]);
        } catch (RuntimeException $e) {
            /*
             * Something changed between the basket page and this one — a
             * variant sold out, a coupon expired, a courier was switched off.
             * The service says exactly what, in words written for a customer,
             * and nothing has been charged.
             */
            return redirect()->route('shop.cart')->withErrors(['checkout' => $e->getMessage()]);
        }

        /** @var Order $order */
        $order = $result['order'];
        /** @var PaymentTransaction $transaction */
        $transaction = $result['transaction'];

        $this->current->finish();
        $this->remember($request, $order);

        if ($transaction->authorization_url === null) {
            /*
             * The gateway refused to start. The order exists, pending, with
             * its stock held — the hourly sweep releases it if the customer
             * does not come back — and the confirmation page says honestly
             * that it is unpaid and how to try again.
             */
            return redirect()
                ->route('shop.order', $order)
                ->withErrors(['payment' => __(
                    'We could not start the payment just now. Nothing has been charged — please try '
                    .'again in a moment.'
                )]);
        }

        return redirect()->away($transaction->authorization_url);
    }

    /**
     * Where the gateway sends the customer back to.
     *
     * The reference is looked UP; it says nothing about the outcome.
     */
    public function callback(Request $request): RedirectResponse
    {
        $reference = $request->string('reference')->toString()
            ?: $request->string('trxref')->toString();

        $transaction = $reference === ''
            ? null
            : PaymentTransaction::query()->where('gateway_reference', $reference)->first();

        $order = $transaction?->payable instanceof Order ? $transaction->payable : null;

        return $order === null
            ? redirect()->route('shop.index')
            : redirect()->to($order->trackingUrl(days: 1));
    }

    /**
     * What happened, according to the database.
     *
     * By ULID, so nobody can walk the orders table by counting. The page is
     * the customer's record of the order — it stays reachable, and it says the
     * reference, so somebody who never receives the email has something to
     * quote.
     */
    public function order(Request $request, Order $order): View
    {
        /*
         * Who may look: the signed link from the email or the tracking form,
         * the account that placed it, or the browser that placed it in this
         * session. A ULID is not guessable, but "not guessable" is not a
         * permission, and an order page carries a name, a phone number and
         * an address.
         */
        abort_unless(
            $request->hasValidSignature()
                || ($request->user() !== null && $order->user_id === $request->user()->getKey())
                || in_array($order->ulid, (array) $request->session()->get('shop.orders', []), true),
            403,
        );

        $order->load(['items.product', 'transaction', 'shippingZone']);

        return view('shop.order', [
            'order' => $order,
            'retryUrl' => $order->status->value === 'pending' ? $order->transaction?->authorization_url : null,
            'meta' => PageMeta::site(__('Your order'), noindex: true),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Shop'), 'url' => route('shop.index')],
                ['label' => __('Your order'), 'url' => null],
            ],
        ]);
    }

    /** The form for a guest looking for their order. */
    public function track(): View
    {
        return view('shop.track', [
            'meta' => PageMeta::site(__('Find your order'), noindex: true),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Shop'), 'url' => route('shop.index')],
                ['label' => __('Find your order'), 'url' => null],
            ],
        ]);
    }

    /**
     * Reference plus the email or phone it was placed with, and nothing less.
     *
     * The reference alone is on a packing slip anybody in the house can
     * read; the pair is what proves it is the customer asking. A miss is one
     * message whichever half was wrong, so the form does not confirm that a
     * reference exists.
     */
    public function lookup(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:32'],
            'contact' => ['required', 'string', 'max:191'],
        ]);

        $contact = trim((string) $data['contact']);
        $order = Order::query()->where('reference', strtoupper(trim((string) $data['reference'])))->first();

        $matches = $order !== null && (
            mb_strtolower($contact) === mb_strtolower((string) $order->customer_email)
            || (PhoneNumber::tryNormalise($contact) !== null && PhoneNumber::tryNormalise($contact) === PhoneNumber::tryNormalise((string) $order->customer_phone))
        );

        if (! $matches) {
            return back()->withInput()->withErrors(['reference' => __('We could not find an order with that reference and contact. Check both against your confirmation email.')]);
        }

        return redirect()->to($order->trackingUrl(days: 1));
    }

    private function remember(Request $request, Order $order): void
    {
        $orders = (array) $request->session()->get('shop.orders', []);
        $orders[] = $order->ulid;
        $request->session()->put('shop.orders', array_slice(array_unique($orders), -10));
    }

    /**
     * The add-a-gift chips: round the basket up, or add a round sum.
     *
     * Computed here, on the server, so the chips are real amounts with no
     * script: "round up to GH₵ 100" is a radio worth GH₵ 3.50, not a promise
     * the browser has to keep.
     *
     * @return array<string, Money> label => amount
     */
    private function giftOptions(Money $basket): array
    {
        $options = [];

        foreach ([1_000, 5_000, 10_000] as $step) {
            $remainder = $basket->toMinor() % $step;

            if ($remainder > 0 && $basket->toMinor() >= $step) {
                $options[__('Round up to :amount', ['amount' => Money::ofMinor($basket->toMinor() - $remainder + $step, $basket->currency)->format()])] = Money::ofMinor($step - $remainder, $basket->currency);

                break;
            }
        }

        foreach ([500, 1_000, 2_000] as $add) {
            $options[__('Add :amount', ['amount' => Money::ofMinor($add, $basket->currency)->format()])] = Money::ofMinor($add, $basket->currency);
        }

        return $options;
    }

    /**
     * What delivery costs, region by region, for THIS basket.
     *
     * Computed for the basket's subtotal and weight so a free-above threshold
     * and a weight band are both reflected — "Greater Accra — free" when the
     * basket qualifies, rather than a flat table that is wrong for this order.
     *
     * @return Collection<int, array{zone: ShippingZone, regions: array<int, string>, label: string}>
     */
    private function deliveryOptions(Money $subtotal, int $weightGrams): Collection
    {
        return ShippingZone::query()
            ->active()
            ->where('is_pickup', false)
            ->with('rates')
            ->get()
            ->map(function (ShippingZone $zone) use ($subtotal, $weightGrams): ?array {
                $rate = $zone->rateFor($subtotal, $weightGrams);

                if ($rate === null || (array) $zone->regions === []) {
                    return null;
                }

                return [
                    'zone' => $zone,
                    'regions' => array_values((array) $zone->regions),
                    'label' => $rate->label($subtotal),
                ];
            })
            ->filter()
            ->values();
    }
}
