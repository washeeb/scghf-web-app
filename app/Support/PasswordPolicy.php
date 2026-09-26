<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * One password policy, in one place.
 *
 * The users table holds staff and donors together specifically so there is one
 * auth path and one password policy — a comment in that migration says so. This
 * is where the policy actually lives, so registration, reset and the profile
 * password form cannot drift apart from one another.
 *
 * ── Length, not composition ─────────────────────────────────────────────────
 *
 * No "one capital, one digit, one symbol" rule. Composition requirements are
 * well documented to produce Password1! and a sticky note, and NIST SP 800-63B
 * has advised against them since 2017. Length is what resists an offline
 * attack; a breach check is what catches the long password that is already in
 * every wordlist.
 */
final class PasswordPolicy
{
    /**
     * The validation rule for a new password.
     *
     * `uncompromised()` sends the first five characters of the password's SHA-1
     * to Have I Been Pwned and compares the rest locally, so neither the
     * password nor anything identifying it leaves this server. Laravel fails
     * OPEN when that API is unreachable — deliberately: a donor must never be
     * unable to register because somebody else's service is down.
     *
     * Off under test, because a suite that makes a network call per
     * registration fails whenever the wifi does.
     */
    public static function rule(): Password
    {
        $rule = Password::min((int) config('security.passwords.min_length', 12));

        return config('security.passwords.check_compromised', true)
            ? $rule->uncompromised()
            : $rule;
    }

    /**
     * What to tell somebody before they type, so the rule is not a surprise
     * delivered as a validation error.
     */
    public static function hint(): string
    {
        return trans_choice(
            'At least one character.|At least :count characters. A phrase you will remember is stronger than a short password with symbols in it.',
            (int) config('security.passwords.min_length', 12),
            ['count' => (int) config('security.passwords.min_length', 12)],
        );
    }
}
