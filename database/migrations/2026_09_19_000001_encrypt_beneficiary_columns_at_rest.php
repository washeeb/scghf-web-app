<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 2 — encryption at rest for the beneficiary record, before it has a
 * screen.
 *
 * Phase 12 encrypted the volunteer columns that would hurt most and left
 * these, because nothing wrote them. Case management is about to. A Ghana
 * Card number, a medical note, a bank account, a next of kin and the case
 * notes about a child are the columns a leaked backup on shared hosting
 * must not hand over in clear, so they become `encrypted` casts (APP_KEY)
 * and the columns widen to TEXT for the ciphertext.
 *
 * What stays in clear, and why: `full_name`, `other_names`, `gender`,
 * `region`, `district`, `community`, `date_of_birth`, the amounts and the
 * dates. The list and its search need the name; the anonymous projection
 * needs the geography and the birth date for its bands; these are Tier A
 * in the design note and every one of them is destroyed or generalised by
 * the retention runner as before. Encrypting the name would make the case
 * list unsearchable and unsortable for no gain a backup thief would notice
 * beside the rest.
 *
 * An encrypted column cannot be searched, so the ID number gets a BLIND
 * INDEX: `ghana_card_index` holds an HMAC-SHA256 of the normalised number
 * keyed on APP_KEY. "Has this person applied before?" is answered by
 * hashing the number typed and looking the hash up; the number itself is
 * never in a WHERE clause and never in the index.
 *
 * Existing rows (there should be none outside demo data) are re-encrypted
 * by `scghf:encrypt-at-rest --execute` after the deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table): void {
            $table->text('phone')->nullable()->change();
            $table->text('email')->nullable()->change();
            $table->text('ghana_card_number')->nullable()->change();
            $table->text('address')->nullable()->change();
            $table->text('bank_account')->nullable()->change();
            $table->text('momo_number')->nullable()->change();
            $table->text('next_of_kin_name')->nullable()->change();
            $table->text('next_of_kin_phone')->nullable()->change();
            $table->text('religion')->nullable()->change();
            $table->text('school_or_employer')->nullable()->change();

            $table->char('ghana_card_index', 64)->nullable()->after('ghana_card_number')->index();
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table): void {
            $table->dropIndex(['ghana_card_index']);
            $table->dropColumn('ghana_card_index');

            $table->string('phone', 32)->nullable()->change();
            $table->string('email', 191)->nullable()->change();
            $table->string('ghana_card_number', 64)->nullable()->change();
            $table->string('address')->nullable()->change();
            $table->string('bank_account', 64)->nullable()->change();
            $table->string('momo_number', 32)->nullable()->change();
            $table->string('next_of_kin_name', 191)->nullable()->change();
            $table->string('next_of_kin_phone', 32)->nullable()->change();
            $table->string('religion', 64)->nullable()->change();
            $table->string('school_or_employer', 191)->nullable()->change();
        });
    }
};
