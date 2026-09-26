<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4, part 2 — donors and donations.
 *
 * `donations` is APPEND-ONLY. No `deleted_at`, and a completed gift is never
 * edited: a correction is a refund row or an adjusting entry. That is what makes
 * the ledger auditable, and it is the difference between figures a trustee can
 * sign off and figures they cannot.
 *
 * ── Why donation_items exists for every gift ────────────────────────────────
 *
 * Even a single-designation donation gets one item row. Split and designated
 * giving means one GH₵ 500 gift can be GH₵ 300 to a deductible cause and
 * GH₵ 200 to one that is not, so the deductible subtotal is a SUM OVER ITEMS,
 * not a flag on the parent. A nullable special case for the simple gift would
 * mean two code paths, and the rarely-exercised one would be the wrong one.
 *
 * ── Why the deductibility flag is snapshotted ───────────────────────────────
 *
 * `donation_items.is_tax_deductible` records what was true AT THE TIME OF THE
 * GIFT. If a cause's status changes later — or the GRA approval lapses —
 * acknowledgements already issued must not silently change meaning. The
 * snapshot is what keeps an old receipt true.
 *
 * ── Deferred columns ────────────────────────────────────────────────────────
 *
 * `subscription_id` and `fundraiser_id` are NOT here. Those tables arrive in
 * part 4 later and in a feature-flagged module respectively, and an
 * unconstrained integer now would be a foreign key in all but name with none of
 * the integrity — the same reasoning that deferred `division_id` out of
 * Module 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        |----------------------------------------------------------------------
        | Donors
        |----------------------------------------------------------------------
        |
        | Separate from `users`. Most donors never create an account — they give
        | once, from a phone, and leave. Forcing a user row for every gift would
        | fill the auth table with records that can never log in, and would make
        | "how many people can sign in" unanswerable.
        */
        Schema::create('donors', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            // Set when the donor does have an account, so their history joins up.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name', 191);
            $table->string('email', 191)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('phone_raw', 32)->nullable();

            // individual | organisation
            $table->string('donor_type', 32)->default('individual');
            $table->string('organisation_name', 191)->nullable();

            $table->string('address')->nullable();
            $table->string('city', 191)->nullable();
            $table->string('country', 2)->nullable();

            /*
             * Act 843 consent, per channel and with its evidence. Consent to be
             * emailed a receipt is not consent to be added to a newsletter, and
             * a single boolean cannot express the difference.
             */
            $table->boolean('consent_email')->default(false);
            $table->boolean('consent_sms')->default(false);
            $table->text('consent_text')->nullable();
            $table->string('consent_ip', 45)->nullable();
            $table->timestamp('consent_at')->nullable();

            /*
             * Denormalised lifetime figures, maintained inside the donation
             * transaction. Safe to increment rather than recompute because
             * donations are append-only and never change.
             */
            $table->unsignedBigInteger('total_donated_minor')->default(0);
            $table->unsignedInteger('donation_count')->default(0);
            $table->timestamp('first_donated_at')->nullable();
            $table->timestamp('last_donated_at')->nullable();

            // Hides the donor from public listings. Never from Finance.
            $table->boolean('is_anonymous_by_default')->default(false);

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            /*
             * Email is NOT unique. The same address can legitimately appear on
             * an individual and an organisation record, and a hard constraint
             * here would reject a real gift at the worst possible moment —
             * after the donor has paid.
             */
            $table->index('email');
            $table->index('phone');
            $table->index('last_donated_at');
        });

        /*
        |----------------------------------------------------------------------
        | Donations — append-only
        |----------------------------------------------------------------------
        */
        Schema::create('donations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            // Quotable over the phone, immutable once set.
            $table->string('reference', 32)->unique();

            $table->foreignId('donor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            /*
             * NOT NULL. Every gift has a destination, and the seeded General
             * Fund is what makes that guarantee keepable for a donation made
             * with no appeal chosen.
             *
             * restrictOnDelete, not cascade: deleting a cause must never delete
             * the donations made to it. The Cause model refuses to delete a
             * locked cause anyway, and this is the database saying the same.
             */
            $table->foreignId('cause_id')->constrained()->restrictOnDelete();

            // Denormalised from the cause, for reporting without a join.
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();

            /*
             * What the donor gave, what the gateway took, and what the
             * foundation actually keeps. Three different numbers; storing one
             * and computing the others would lose whichever the gateway
             * disagreed with.
             */
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('fee_minor')->default(0);
            $table->boolean('fee_covered_by_donor')->default(false);
            $table->unsignedBigInteger('net_minor');

            /*
             * The deductible subtotal, summed from the items inside the same
             * transaction. Denormalised so acknowledgements and year-end
             * statements do not recompute it and cannot disagree with what was
             * printed.
             */
            $table->unsignedBigInteger('deductible_amount_minor')->default(0);

            $table->char('currency', 3)->default('GHS');

            // pending | completed | failed | abandoned | needs_review | refunded
            $table->string('status', 32)->default('pending');

            // mobile_money | card | bank | ussd | offline
            $table->string('channel', 32)->nullable();
            $table->string('momo_network', 32)->nullable();

            /*
             * Hides the donor from the public donor wall. NOT from Finance,
             * from the receipt, or from the audit trail — an anonymous gift is
             * still a gift from a known person, and treating it otherwise would
             * make it unreceiptable and unreconcilable.
             */
            $table->boolean('is_anonymous')->default(false);

            // in_memory_of | in_honour_of
            $table->string('tribute_type', 32)->nullable();
            $table->string('tribute_name', 191)->nullable();
            $table->text('tribute_message')->nullable();
            $table->string('tribute_notify_email', 191)->nullable();

            /*
             * A SNAPSHOT of the donor's details as given, not a join to the
             * donor record. A donor can correct their name or email later; the
             * acknowledgement already issued said what it said, and the ledger
             * has to still agree with it.
             */
            $table->string('donor_name', 191)->nullable();
            $table->string('donor_email', 191)->nullable();
            $table->string('donor_phone', 32)->nullable();

            $table->boolean('consent_email')->default(false);
            $table->boolean('consent_sms')->default(false);
            $table->text('consent_text')->nullable();
            $table->string('consent_ip', 45)->nullable();
            $table->timestamp('consent_at')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            // The gateway's own id, for reconciliation against a settlement file.
            $table->string('paystack_reference', 191)->nullable();

            // Finance annotations. Never shown to the donor.
            $table->text('notes')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // NO softDeletes. Append-only.

            $table->index(['status', 'created_at']);
            $table->index(['cause_id', 'status']);
            $table->index(['division_id', 'created_at']);
            $table->index(['donor_email', 'created_at']);
            $table->index('paystack_reference');
            $table->index('paid_at');
        });

        /*
        |----------------------------------------------------------------------
        | Donation items — designated giving
        |----------------------------------------------------------------------
        */
        Schema::create('donation_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('donation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cause_id')->constrained()->restrictOnDelete();
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('GHS');

            /*
             * SNAPSHOTTED at the moment of the gift, from TaxDeductibility —
             * which requires both a qualifying cause AND a current GRA
             * approval. Recomputing it later would change the meaning of an
             * acknowledgement already in a donor's hands.
             */
            $table->boolean('is_tax_deductible')->default(false);

            // Which approval it was claimed under, so an old acknowledgement can
            // still be explained years later.
            $table->foreignId('tax_approval_id')->nullable()->constrained()->nullOnDelete();

            $table->string('description', 191)->nullable();

            $table->timestamps();

            $table->index(['cause_id', 'created_at']);
            $table->index('donation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donation_items');
        Schema::dropIfExists('donations');
        Schema::dropIfExists('donors');
    }
};
