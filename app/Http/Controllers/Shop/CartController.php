<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\AddToCartRequest;
use App\Models\Coupon;
use App\Models\ProductVariant;
use App\Shop\CurrentCart;
use App\Support\PageMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The basket.
 *
 * ── Every change is a form POST, and the page works without a script ────────
 *
 * Add, change a quantity, remove, apply a code: each is a plain form that
 * redirects back to the basket. On a low-end Android phone on a metered
 * connection — the real usage context — a basket that needs JavaScript to
 * change a quantity is a basket somebody gives up on.
 *
 * ── Stock is checked on every view, not only at checkout ────────────────────
 *
 * `Cart::unavailableLines()` is asked each time the basket is drawn, and a line
 * that can no longer be bought is marked on the page with the reason. A
 * customer discovering at the payment page that a mug sold out a fortnight ago
 * has had a worse experience than being told on the basket page.
 *
 * ── The basket has no prices of its own ─────────────────────────────────────
 *
 * Everything shown is read live from the variant. A cart is a wish, not a
 * contract; the price is frozen when the order is written, and not before.
 */
class CartController extends Controller
{
    public function __construct(private readonly CurrentCart $current) {}

    public function show(): View
    {
        $cart = $this->current->current();
        $cart?->load('items.variant.product.featuredImage');

        return view('shop.cart', [
            'cart' => $cart,
            'unavailable' => $cart?->unavailableLines() ?? [],
            'meta' => PageMeta::site(__('Your basket'), noindex: true),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Shop'), 'url' => route('shop.index')],
                ['label' => __('Basket'), 'url' => null],
            ],
        ]);
    }

    public function add(AddToCartRequest $request): RedirectResponse
    {
        $variant = $request->variant();
        $quantity = (int) $request->integer('quantity', 1);

        /*
         * The check counts what is ALREADY in the basket. Adding two when one
         * is in there and two remain is a request for three, and the honest
         * answer to that is no.
         */
        $cart = $this->current->resolve();
        $already = (int) ($cart->items->firstWhere('product_variant_id', $variant->getKey())?->quantity ?? 0);

        if (! $variant->isSellable($already + $quantity)) {
            return back()->withErrors([
                'quantity' => $variant->sellableQuantity() === 0
                    ? __('Sorry — this has sold out.')
                    : __('Only :count of these are available.', ['count' => $variant->sellableQuantity()]),
            ]);
        }

        $cart->add($variant, $quantity);

        return redirect()
            ->route('shop.cart')
            ->with('status', __(':item added to your basket.', ['item' => $variant->displayName()]));
    }

    public function update(Request $request, ProductVariant $variant): RedirectResponse
    {
        $request->validate(['quantity' => ['required', 'integer', 'min:0', 'max:99']]);

        $cart = $this->current->current();

        if ($cart === null) {
            return redirect()->route('shop.cart');
        }

        $quantity = (int) $request->integer('quantity');

        if ($quantity > 0 && ! $variant->isSellable($quantity)) {
            return back()->withErrors([
                'quantity' => __('Only :count of these are available.', ['count' => $variant->sellableQuantity()]),
            ]);
        }

        $cart->setQuantity($variant, $quantity);

        return redirect()->route('shop.cart');
    }

    public function remove(ProductVariant $variant): RedirectResponse
    {
        $this->current->current()?->remove($variant);

        return redirect()->route('shop.cart');
    }

    /**
     * A discount code.
     *
     * Refused with the reason rather than silently ignored. A customer who
     * typed a code and sees no discount will assume the shop is broken, which
     * is worse than being told the code has expired.
     */
    public function applyCoupon(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:32']]);

        $cart = $this->current->current();

        if ($cart === null || $cart->isEmpty()) {
            return redirect()->route('shop.cart');
        }

        $coupon = Coupon::query()
            ->where('code', mb_strtoupper(trim($request->string('code')->toString())))
            ->first();

        $reason = $coupon === null
            ? __('That code is not one we recognise.')
            : $coupon->rejectionReason($cart->subtotal(), $request->user()?->email);

        if ($reason !== null) {
            return back()->withErrors(['code' => $reason])->withInput();
        }

        $cart->forceFill(['coupon_id' => $coupon->getKey()])->save();

        return redirect()->route('shop.cart')->with('status', __('Code applied.'));
    }

    public function removeCoupon(): RedirectResponse
    {
        $this->current->current()?->forceFill(['coupon_id' => null])->save();

        return redirect()->route('shop.cart');
    }
}
