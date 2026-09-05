<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A requested email address, held separately until it is proved.
 *
 * ── Why the new address does not go in `email` ──────────────────────────────
 *
 * Changing the address on an account is how a stolen session becomes a
 * permanent takeover: password resets then arrive in an inbox the attacker
 * controls, and the real owner is locked out of their own giving history with
 * no way back that does not involve a person.
 *
 * So the change is a two-party affair. The new address has to prove itself by
 * opening a link, and the OLD address is told what is happening and given a way
 * to stop it. Until both of those have had their chance, `email` is untouched —
 * which means an attacker who gets this far has changed nothing, and the person
 * whose account it is receives a warning at the address they still control.
 *
 * `pending_email` is unique for the same reason `email` is: two accounts must
 * not be able to race for one address, and MySQL permits many NULLs in a unique
 * index, so the common case of nobody changing anything costs nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pending_email', 191)->nullable()->unique()->after('email_verified_at');

            /*
             * When it was asked for, so the request can expire.
             *
             * A confirmation link that works forever is a standing key to the
             * account sitting in an old inbox. The expiry is enforced in the
             * model rather than only by the signed URL, so a request left
             * hanging also stops showing "change pending" on the profile page
             * long after anybody could act on it.
             */
            $table->timestamp('pending_email_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['pending_email']);
            $table->dropColumn(['pending_email', 'pending_email_requested_at']);
        });
    }
};
