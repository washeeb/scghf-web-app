<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4, part 1 — the gateway boundary.
 *
 * One payment path for donations AND shop orders, via a polymorphic payable.
 * Two payment paths is how a ledger diverges from the gateway, and reconciling
 * a divergence after the fact is guesswork.
 *
 * These tables are APPEND-ONLY. No soft deletes, no edits to a completed row.
 * A correction is a new row — a refund, an adjustment — because that is what
 * makes the ledger auditable, and it is the difference between a system a
 * trustee can sign off and one they cannot.
 *
 * Two indexes carry most of the correctness guarantee:
 *
 *   payment_transactions.gateway_reference  UNIQUE — one transaction per charge
 *   payment_webhook_events.event_id         UNIQUE — replay is a no-op at the
 *                                           DATABASE level, not because the
 *                                           application logic got it right
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        |----------------------------------------------------------------------
        | Payment transactions
        |----------------------------------------------------------------------
        */
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            // Polymorphic: a donation, a shop order, later anything else that
            // takes money. No foreign key, by nature of the morph.
            $table->string('payable_type', 191)->nullable();
            $table->unsignedBigInteger('payable_id')->nullable();

            // paystack | fake | offline
            $table->string('gateway', 32)->default('paystack');

            /*
             * The idempotency anchor. Unique, so a retried initialisation
             * cannot create a second transaction for the same charge, and a
             * webhook can always find exactly one row to act on.
             */
            $table->string('gateway_reference', 191)->unique();

            /*
             * WHAT WE EXPECTED, and WHAT ACTUALLY HAPPENED, stored separately.
             *
             * This separation is the entire point of the table. Comparing the
             * two is what makes the amount/currency mismatch check possible; a
             * single amount column would make it unaskable, and the site would
             * have to trust the gateway's number blindly.
             */
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('GHS');
            $table->unsignedBigInteger('amount_paid_minor')->nullable();
            $table->char('currency_paid', 3)->nullable();

            // The fee Paystack ACTUALLY charged, from the webhook — not our
            // model of it. Reconciliation compares the two.
            $table->unsignedBigInteger('fee_minor')->nullable();

            // initialised | pending | success | failed | abandoned | mismatch
            $table->string('status', 32)->default('initialised');

            $table->string('channel', 32)->nullable();
            $table->string('momo_network', 32)->nullable();

            /*
             * Card metadata ONLY. An authorization code is a Paystack token for
             * charging again; it is not card data and cannot be used outside
             * the merchant account. The full number, CVV, PIN and expiry are
             * never received, never stored, never logged — which is what keeps
             * this a PCI DSS SAQ-A posture.
             */
            $table->string('authorization_code', 191)->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->string('card_brand', 32)->nullable();
            $table->string('bank', 191)->nullable();

            $table->string('customer_email', 191)->nullable();
            $table->string('customer_code', 191)->nullable();

            $table->string('authorization_url', 500)->nullable();
            $table->string('access_code', 191)->nullable();

            $table->timestamp('initialised_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            // When WE verified it against the gateway, as distinct from when
            // the gateway said it was paid. A transaction the site never
            // verified is a transaction the site should not act on.
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();

            /*
             * Scrubbed of card, CVV, PIN and account fields before storage.
             * Paystack does not send them today; a payload is kept verbatim,
             * and this is the one place worth being paranoid rather than
             * trusting the gateway never to change what it sends.
             */
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();

            // Set when expected and actual disagree. Finance reads this; the
            // system never resolves it on its own.
            $table->text('mismatch_reason')->nullable();

            $table->timestamps();

            $table->index(['payable_type', 'payable_id']);
            $table->index(['status', 'created_at']);
            $table->index('paid_at');
            $table->index('reconciled_at');
        });

        /*
        |----------------------------------------------------------------------
        | Webhook events — replay-proof
        |----------------------------------------------------------------------
        */
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();

            $table->string('gateway', 32)->default('paystack');

            /*
             * THE idempotency guarantee.
             *
             * Unique at the database level, so a replayed webhook is rejected
             * by the engine rather than by application logic that has to be
             * right every time. Paystack retries aggressively, and a
             * double-counted donation is not a bug anyone can undo cleanly.
             *
             * Nullable because a malformed payload may carry no id at all — and
             * MySQL permits many NULLs in a unique index, which is what lets
             * those be stored as evidence rather than dropped.
             */
            $table->string('event_id', 191)->nullable()->unique();

            $table->string('event_type', 64)->nullable();
            $table->string('gateway_reference', 191)->nullable();

            /*
             * Written BEFORE any parsing.
             *
             * A malformed webhook is still evidence — of an integration change,
             * of an attack, of a bug. Parsing first and storing second means
             * the one payload worth having is the one that gets thrown away.
             */
            $table->longText('raw_payload');

            // The HMAC check, audited. Signature-invalid events are stored and
            // never processed, because a run of them is the signal that
            // somebody is probing the endpoint.
            $table->string('signature', 191)->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->string('source_ip', 45)->nullable();

            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);

            $table->timestamps();

            $table->index('gateway_reference');
            $table->index(['event_type', 'processed_at']);
            $table->index(['signature_valid', 'received_at']);
        });

        /*
        |----------------------------------------------------------------------
        | Refunds
        |----------------------------------------------------------------------
        |
        | Their own rows, never negative donations. A negative amount in a
        | ledger destroys the ability to sum it meaningfully: "total raised"
        | and "total refunded" are different questions and a signed column
        | answers neither well.
        */
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('payment_transaction_id')->constrained()->restrictOnDelete();

            $table->string('gateway_reference', 191)->nullable()->unique();

            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('GHS');

            // requested | pending | processed | failed | cancelled
            $table->string('status', 32)->default('requested');

            $table->text('reason');

            /*
             * A refund is money leaving the foundation, so who asked and who
             * approved are both recorded, and they are allowed to differ.
             * A single "created_by" cannot express an approval.
             */
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->text('failure_reason')->nullable();
            $table->json('response_payload')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payment_transactions');
    }
};
