<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 8, part 3 — feature flags and settings history.
 *
 * ── The database can toggle a flag; it cannot invent one ────────────────────
 *
 * `config/features.php` stays the source of truth for WHICH flags exist. This
 * table only records an override for a flag already declared there, and the
 * service refuses a row naming a flag that does not exist.
 *
 * The reason is the comment already in `config/features.php`: flags are read
 * with `config()`, they are reviewed like code, and their history is in git
 * where it can be produced. A flag invented in an admin screen has none of
 * that, and the first thing anybody would do with one is gate something on it
 * and forget.
 *
 * What the database adds is what config cannot: turning something off at nine
 * on a Saturday evening without a deployment, with a reason, by a named person,
 * and — the part that matters — with an EXPIRY, so "temporarily disable the
 * shop" does not become permanent by being forgotten.
 *
 * ── Settings drive things that are not cosmetic ─────────────────────────────
 *
 * The settings table holds the GRA approval reference, the receipt signatory,
 * the donation presets and the organisation's legal name. Those appear on
 * documents that go to a regulator. "It used to say something else" is a
 * question somebody will ask, and `settings_history` is the only place that
 * could answer it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            /*
             * Must match a key in config/features.php. The database records an
             * override, never a new flag — a flag nothing in the code reads is
             * a switch wired to nothing.
             */
            $table->string('key', 64)->unique();

            $table->boolean('is_enabled');

            /*
             * Why. Required, and required for turning something ON as well as
             * off: "enabled the shop" with no reason is indistinguishable from
             * a mis-click, three months later.
             */
            $table->string('reason', 191);

            /*
             * When the override lapses and the config default takes over again.
             *
             * The column that stops a temporary measure becoming the permanent
             * state of the site. Null is allowed but is the deliberate choice,
             * not the easy one.
             */
            $table->timestamp('expires_at')->nullable();

            /*
             * A locked flag cannot be overridden from an admin screen at all.
             *
             * `donations` is the example. Turning donations off is a decision
             * with financial and reputational consequences, and it should
             * require a deployment by somebody who has thought about it — not a
             * toggle next to "dark mode".
             */
            $table->boolean('is_locked')->default(false);

            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_enabled', 'expires_at']);
        });

        Schema::create('settings_history', function (Blueprint $table) {
            $table->id();

            // Not a foreign key to `settings`. The history has to survive the
            // setting being removed — "what did that used to be, before we
            // deleted it?" is exactly the question this table answers.
            $table->string('setting_key', 128)->index();

            /*
             * Both sides of the change. A history that records only the new
             * value tells you what it is, which you could have read from the
             * settings table; the old value is what tells you what changed.
             */
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();

            /*
             * Encrypted and secret-bearing settings are recorded as changed and
             * their values redacted. A history table is the last place a
             * plaintext copy of a secret should accumulate, and it would
             * outlive every rotation.
             */
            $table->boolean('is_redacted')->default(false);

            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('changed_by_label', 191)->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->timestamp('changed_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['setting_key', 'changed_at']);
            $table->index('changed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings_history');
        Schema::dropIfExists('feature_flags');
    }
};
