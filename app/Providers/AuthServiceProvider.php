<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /*
         * ORDER MATTERS. Gate::before callbacks run in registration order and
         * the first non-null result wins. The suspension check is registered
         * FIRST so that it also applies to Super Admins — registering the
         * wildcard first would let a suspended Super Admin pass every gate,
         * which is precisely the account you most need to be able to switch off.
         */

        // 1. A deactivated or suspended account passes nothing, whatever roles
        //    remain attached to it. Revoking access must not require unpicking
        //    a permission matrix first.
        Gate::before(function (User $user): ?bool {
            return (! $user->is_active || $user->isSuspended()) ? false : null;
        });

        // 2. Super Admin passes everything else.
        //
        //    A wildcard rather than granting the role every permission
        //    individually: enumerating them means a permission added later is
        //    silently missing from the one role that must never lack it.
        //
        //    Returning null rather than false for non-super-admins is
        //    deliberate — false would DENY and short-circuit every other check.
        //    null means "no opinion, carry on to the real gate".
        Gate::before(function (User $user, string $ability): ?bool {
            return $user->hasRole('Super Admin') ? true : null;
        });
    }
}
