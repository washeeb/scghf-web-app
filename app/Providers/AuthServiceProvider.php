<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Policies\PolicyMap;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerPolicies();

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
            return $user->isInGoodStanding() ? null : false;
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

    /**
     * Bind every model to the policy that governs it.
     *
     * Registered from an explicit list rather than left to Laravel's naming
     * convention. The convention guesses `Donation` → `DonationPolicy`, which
     * works right up until a model has no policy of its own — at which point it
     * returns nothing, every check quietly answers false, and a Filament
     * resource disappears with no error to explain why.
     *
     * Most models here ARE governed by a parent's permissions: a `DonationItem`
     * belongs to a donation and is not separately authorised. A naming
     * convention cannot express that; a list can — and `PolicyCoverageTest`
     * fails on any model missing from it, so one added in a year cannot arrive
     * unauthorised.
     */
    private function registerPolicies(): void
    {
        foreach (PolicyMap::policies() as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
