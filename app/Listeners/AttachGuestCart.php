<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Shop\CurrentCart;
use Illuminate\Auth\Events\Login;
use Throwable;

/**
 * A guest who filled a basket and then signed in keeps the basket.
 *
 * The obvious alternative — a fresh basket per account — loses the mug the
 * moment somebody does the thing the checkout page asks them to do. Failures
 * here are reported and swallowed: a basket is not worth failing a sign-in for.
 */
class AttachGuestCart
{
    public function __construct(private readonly CurrentCart $cart) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        try {
            $this->cart->attachTo($event->user);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
