<?php

declare(strict_types=1);

namespace App\Shop;

use App\Models\Cart;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Which basket belongs to the person making this request.
 *
 * ── A cookie carrying the token, not the session ────────────────────────────
 *
 * `carts.session_token` is a 48-character random string, and the cookie holds
 * it (encrypted by the framework on top). Keying on Laravel's session id would
 * tie the basket to the session lifetime — two hours by default — so somebody
 * who filled a basket on the bus and came back that evening would find it
 * empty. A basket lives thirty days; its cookie lives the same.
 *
 * ── Nothing is created until something is added ─────────────────────────────
 *
 * `current()` never writes. Every visitor to every page would otherwise leave
 * a cart row behind, and on a host with an inode quota and a small database
 * that is a table of empty baskets growing by the crawl.
 *
 * ── Signing in merges, and the guest basket is retired ──────────────────────
 *
 * A guest who adds a mug and then signs in should still have the mug. The
 * guest basket is folded into the account's one, or simply adopted if the
 * account had none, and the cookie is pointed at the survivor.
 */
final class CurrentCart
{
    public const COOKIE = 'scghf_basket';

    private ?Cart $cart = null;

    private bool $resolved = false;

    public function __construct(private readonly Request $request) {}

    /** The basket, if there is one. Never creates. */
    public function current(): ?Cart
    {
        if ($this->resolved) {
            return $this->cart;
        }

        $this->resolved = true;

        $user = $this->request->user();

        if ($user instanceof User) {
            $this->cart = $this->forUser($user);
        }

        $this->cart ??= $this->fromCookie();

        return $this->cart;
    }

    /** The basket, created if there is none. Only for a write. */
    public function resolve(): Cart
    {
        $cart = $this->current();

        if ($cart !== null) {
            return $cart;
        }

        $cart = Cart::create(['user_id' => $this->request->user()?->getKey()]);

        $this->remember($cart);

        return $this->cart = $cart;
    }

    /** How many things are in it, for the header. Zero without a query when there is no cookie. */
    public function itemCount(): int
    {
        if ($this->request->user() === null && ! $this->request->hasCookie(self::COOKIE)) {
            return 0;
        }

        return $this->current()?->itemCount() ?? 0;
    }

    /**
     * The basket has become an order. Empty it.
     *
     * Emptied rather than deleted: the row and its cookie survive so the
     * customer's next visit starts from the same basket, and the coupon is
     * cleared because it has been redeemed against the order.
     */
    public function finish(): void
    {
        $cart = $this->current();

        if ($cart === null) {
            return;
        }

        $cart->items()->delete();
        $cart->forceFill(['coupon_id' => null])->save();
        $cart->load('items');
    }

    /**
     * A guest who has just signed in.
     *
     * Called from the login listener. The guest basket — if the cookie names
     * one — is merged into the account's, or adopted outright.
     */
    public function attachTo(User $user): void
    {
        $guest = $this->fromCookie();

        if ($guest === null) {
            return;
        }

        if ($guest->user_id === $user->getKey()) {
            return;
        }

        $mine = Cart::query()->where('user_id', $user->getKey())->first();

        if ($mine === null) {
            $guest->forceFill(['user_id' => $user->getKey()])->save();
            $this->cart = $guest;
            $this->resolved = true;

            return;
        }

        $guest->load('items.variant');
        $mine->load('items');
        $mine->mergeFrom($guest);

        $this->remember($mine);
        $this->cart = $mine;
        $this->resolved = true;
    }

    private function forUser(User $user): ?Cart
    {
        $cart = Cart::query()->where('user_id', $user->getKey())->with('items.variant.product')->first();

        if ($cart !== null && $this->request->cookie(self::COOKIE) !== $cart->session_token) {
            $this->remember($cart);
        }

        return $cart;
    }

    private function fromCookie(): ?Cart
    {
        $token = $this->request->cookie(self::COOKIE);

        if (! is_string($token) || $token === '') {
            return null;
        }

        return Cart::query()
            ->where('session_token', $token)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->with('items.variant.product')
            ->first();
    }

    private function remember(Cart $cart): void
    {
        Cookie::queue(Cookie::make(
            self::COOKIE,
            (string) $cart->session_token,
            Cart::LIFETIME_DAYS * 24 * 60,
            '/',
            null,
            null,
            true,   // httpOnly: nothing on the page needs to read it
            false,
            'lax',
        ));
    }
}
