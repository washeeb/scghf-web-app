<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;

/**
 * Somebody produced the right password and then the wrong second factor.
 *
 * Its own event because Laravel has none: `Illuminate\Auth\Events\Failed` means
 * the credentials did not match, which is a different fact and would file this
 * under the wrong outcome. `LoginOutcome::TwoFactorFailed` has existed since
 * Module 1 with nothing writing it, and this is what writes it.
 *
 * It is the most interesting failure the login history records. A wrong
 * password is somebody who mistyped; a right password followed by a wrong code
 * is either the account holder without their phone, or somebody who has the
 * password and should not.
 */
class TwoFactorChallengeFailed
{
    public function __construct(public readonly User $user) {}
}
