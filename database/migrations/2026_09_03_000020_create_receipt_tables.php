<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4, part 3 — donor acknowledgements.
 *
 * The document a donor hands to the GRA with a section 100 claim. Two design
 * decisions carry the weight:
 *
 * ── 1. The wording is SNAPSHOTTED onto the row ──────────────────────────────
 *
 * Not rendered from config at read time. The GRA approval a receipt cites can
 * lapse, the trustees can change which causes qualify, and the prescribed
 * paragraphs can be revised between years — and a document already in a donor's
 * hands must keep saying what it said when it was issued. A receipt that
 * re-renders is a receipt that can quietly contradict the copy the donor is
 * holding.
 *
 * ── 2. Numbers are sequential per financial year, with no gaps ──────────────
 *
 * `SCGHF-R-2026-000148`. Auditors expect to account for every number in the
 * series, and a gap has to be explainable. The sequence is allocated from a
 * counter table under a row lock inside the same transaction that writes the
 * receipt, so two simultaneous settlements cannot take the same number and
 * neither can burn one.
 *
 * The foundation's financial year starts 1 January, so the year in the number
 * is the calendar year.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        |----------------------------------------------------------------------
        | The counter
        |----------------------------------------------------------------------
        |
        | One row per financial year. Deliberately NOT an AUTO_INCREMENT on the
        | receipts table: auto-increment burns a number on a rolled-back insert,
        | which is exactly the unexplainable gap an auditor asks about.
        */
        Schema::create('receipt_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('financial_year')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });

        /*
        |----------------------------------------------------------------------
        | Receipts
        |----------------------------------------------------------------------
        */
        Schema::create('donation_receipts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            // One receipt per donation, enforced. Issuing a second for the same
            // gift would put two numbers against one payment, and the series
            // would no longer reconcile against the ledger.
            $table->foreignId('donation_id')->unique()->constrained()->restrictOnDelete();

            $table->string('receipt_number', 32)->unique();
            $table->unsignedSmallInteger('financial_year');
            $table->unsignedInteger('sequence');

            $table->date('issued_on');

            /*
             * Snapshots of everything the document states. The donor's name can
             * be corrected on their record afterwards; this is what the
             * document says.
             */
            $table->string('donor_name', 191);
            $table->string('donor_email', 191)->nullable();

            $table->string('organisation_name', 191);
            $table->string('organisation_tin', 64);

            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('deductible_amount_minor')->default(0);
            $table->unsignedBigInteger('non_deductible_amount_minor')->default(0);
            $table->char('currency', 3)->default('GHS');

            // The amount in words, as printed. Stored rather than regenerated
            // so the figures and the words on a reprint cannot drift apart.
            $table->string('amount_in_words', 500);

            $table->string('cause', 191);
            $table->string('payment_reference', 191)->nullable();
            $table->date('donated_on');

            /*
             * Whether this document cites a GRA approval, and which one. A
             * receipt issued while no approval was held is a plain
             * acknowledgement of the gift with no tax wording — still a valid
             * receipt, just not evidence for a section 100 claim.
             */
            $table->boolean('cites_approval')->default(false);
            $table->foreignId('tax_approval_id')->nullable()->constrained()->nullOnDelete();
            $table->string('approval_reference', 191)->nullable();
            $table->string('approval_validity', 191)->nullable();

            // The full rendered paragraphs, exactly as issued.
            $table->longText('statement');

            $table->string('authentication', 191)->nullable();

            $table->foreignId('pdf_media_id')->nullable()->constrained('media')->nullOnDelete();

            $table->timestamp('sent_at')->nullable();
            $table->string('sent_to', 191)->nullable();

            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // No softDeletes. A receipt is never withdrawn — a mistake is
            // corrected by a credit note, which is its own document.

            $table->unique(['financial_year', 'sequence']);
            $table->index(['financial_year', 'issued_on']);
            $table->index('donor_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donation_receipts');
        Schema::dropIfExists('receipt_sequences');
    }
};
