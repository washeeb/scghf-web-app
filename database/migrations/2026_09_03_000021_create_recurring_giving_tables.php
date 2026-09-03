<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4, part 4 — recurring giving.
 *
 * ── Two ways to run a recurring gift, and why both exist ────────────────────
 *
 * `gateway`  Paystack owns the schedule. We create a plan and a subscription,
 *            and Paystack charges on its own timetable, telling us through
 *            `invoice.create` / `charge.success` webhooks. Less code, but the
 *            foundation cannot change an amount or a date without going
 *            through the gateway.
 *
 * `managed`  We own the schedule. A stored authorization code is charged by a
 *            cron-driven command on `next_charge_on`. More code, but the
 *            foundation can pause, change the amount, or move the date, and the
 *            whole thing is visible in its own admin rather than in Paystack's.
 *
 * Both are supported because the right answer depends on something not yet
 * settled — see the caveat below — and committing to one now would mean
 * rebuilding if that answer goes the other way.
 *
 * ── ⚠ The Ghana caveat, recorded here because it is a product decision ──────
 *
 * Recurring charges rely on a REUSABLE authorization. Paystack issues those
 * readily for cards. For MOBILE MONEY — which is how most Ghanaian donors
 * actually pay — reusability depends on the network and on the merchant
 * account's configuration, and cannot be assumed.
 *
 * So `subscriptions.authorization_reusable` is stored per subscription, taken
 * from what Paystack actually said about that authorization, and the charging
 * command SKIPS anything not marked reusable rather than failing a donor's gift
 * every month. If it turns out MoMo authorizations are not reusable on this
 * merchant account, recurring giving becomes card-only or moves to a
 * "remind me to give again" model — and that is a decision for the foundation,
 * not something to paper over in code.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        |----------------------------------------------------------------------
        | Plans — the named options a donor picks from
        |----------------------------------------------------------------------
        */
        Schema::create('donation_plans', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('cause_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name', 191);
            $table->string('slug', 191)->unique();
            $table->text('description')->nullable();

            /*
             * Nullable: a plan can either fix the amount ("Sponsor a child,
             * GH₵ 50 a month") or let the donor choose one ("Monthly giving").
             * Zero would mean "free", which is a different and wrong thing.
             */
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->char('currency', 3)->default('GHS');

            // monthly | quarterly | annually
            $table->string('interval', 32)->default('monthly');

            // Set once the plan exists on Paystack's side, for gateway-driven
            // subscriptions. Null for managed ones.
            $table->string('paystack_plan_code', 191)->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
        });

        /*
        |----------------------------------------------------------------------
        | Subscriptions
        |----------------------------------------------------------------------
        */
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('reference', 32)->unique();

            $table->foreignId('donor_id')->constrained()->restrictOnDelete();
            $table->foreignId('donation_plan_id')->nullable()->constrained()->nullOnDelete();

            // NOT NULL, same reasoning as donations: every recurring gift has a
            // destination, and the General Fund is the fallback.
            $table->foreignId('cause_id')->constrained()->restrictOnDelete();
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('GHS');
            $table->string('interval', 32)->default('monthly');

            // gateway | managed — see the migration docblock.
            $table->string('driver', 32)->default('managed');

            // active | paused | cancelled | completed | failing
            $table->string('status', 32)->default('active');

            $table->date('started_on');
            $table->date('next_charge_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->string('cancel_reason', 191)->nullable();

            /*
             * The token Paystack issues for charging the same instrument again.
             * NOT card data: it is meaningless outside this merchant account and
             * cannot be used to reconstruct a card number.
             */
            $table->string('authorization_code', 191)->nullable();

            /*
             * Whether Paystack said this authorization can actually be reused.
             * The charging command skips anything false rather than failing a
             * donor's gift every month — see the Ghana caveat above.
             */
            $table->boolean('authorization_reusable')->default(false);
            $table->string('channel', 32)->nullable();
            $table->string('card_last4', 4)->nullable();

            $table->string('paystack_subscription_code', 191)->nullable();
            $table->string('paystack_customer_code', 191)->nullable();
            $table->string('paystack_email_token', 191)->nullable();

            $table->unsignedInteger('charge_count')->default(0);
            $table->unsignedBigInteger('total_charged_minor')->default(0);

            /*
             * Consecutive failures. Reset on any success. A subscription that
             * fails repeatedly is suspended rather than retried for ever —
             * hammering a donor's card monthly after it has expired is how a
             * charity ends up on a card network's watch list.
             */
            $table->unsignedSmallInteger('failed_attempts')->default(0);

            $table->timestamps();

            $table->index(['status', 'next_charge_on']);
            $table->index(['donor_id', 'status']);
            $table->index('paystack_subscription_code');
        });

        /*
        |----------------------------------------------------------------------
        | Charges — one row per cycle, attempted or not
        |----------------------------------------------------------------------
        |
        | Exists even for a cycle that was skipped or failed, so a donor's
        | recurring history is complete. "Nothing happened in April" is an
        | answer; a missing row is not.
        */
        Schema::create('subscription_charges', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();

            // Set when the charge produced an actual gift.
            $table->foreignId('donation_id')->nullable()->constrained()->nullOnDelete();

            $table->date('scheduled_on');
            $table->timestamp('attempted_at')->nullable();

            // scheduled | succeeded | failed | skipped
            $table->string('status', 32)->default('scheduled');

            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('GHS');

            $table->text('failure_reason')->nullable();
            $table->unsignedSmallInteger('attempt')->default(1);

            $table->timestamps();

            // One attempt row per cycle. A retry updates it rather than adding a
            // second, so the count of rows is the count of cycles.
            $table->unique(['subscription_id', 'scheduled_on']);
            $table->index(['status', 'scheduled_on']);
        });

        /*
        |----------------------------------------------------------------------
        | The deferred column on donations
        |----------------------------------------------------------------------
        |
        | Left out of the donations migration on purpose: `subscriptions` did
        | not exist, and an unconstrained integer would have been a foreign key
        | in all but name. It arrives with its constraint, which is what
        | expand-only migrations are for.
        */
        Schema::table('donations', function (Blueprint $table) {
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->dropForeign(['subscription_id']);
            $table->dropColumn('subscription_id');
        });

        Schema::dropIfExists('subscription_charges');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('donation_plans');
    }
};
