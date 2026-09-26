<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 3, part 3 — beneficiaries, consent, stories and impact analytics.
 *
 * The most sensitive tables in the application. Two structural decisions carry
 * most of the compliance weight:
 *
 * ── 1. Two datasets, not one ────────────────────────────────────────────────
 *
 * `beneficiaries` is the OPERATIONAL case database: identifiable, restricted,
 * used for actual case management, and destroyed when its retention period
 * expires.
 *
 * `beneficiary_impact_records` is the ANALYTICS dataset: no name, no contact
 * details, no identifiers, no exact address, no source documents, no case
 * notes, no payment references, no exact amounts, no exact dates. It is
 * projected when a case closes and it outlives the case record.
 *
 * The operational table is deliberately NOT reused as the long-term statistics
 * table. Stripping a table in place leaves exact amounts and exact days beside
 * a division and a district, and that combination singles out one person with
 * no name anywhere in the row.
 *
 * `source_beneficiary_id` exists only while the case record does. It is
 * `ON DELETE SET NULL`, so destroying the beneficiary severs the linkage in the
 * same statement. During the retention period the analytics row is therefore
 * pseudonymous, which is lawful because the identifiable record lawfully exists
 * anyway; after it, there is no reversible linkage at all — not a hash, not an
 * encrypted id, nothing.
 *
 * ── 2. Consent is a table, not a checkbox ───────────────────────────────────
 *
 * A photograph or a story cannot be published without a matching consent row
 * that is granted, unexpired and unrevoked — enforced in the model layer and
 * asserted by tests, not left to an administrator remembering. Consent for a
 * child is given by a guardian, and whose consent it was is recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        |----------------------------------------------------------------------
        | Beneficiaries — the operational case record
        |----------------------------------------------------------------------
        |
        | Every column here is classified in config('compliance.privacy.elements')
        | through Beneficiary::privacyElements(), and a test fails on any column
        | that is not. The risk being guarded is not a wrong decision about a
        | field; it is a field added later that nobody classified at all, which
        | would then survive de-identification untouched.
        */
        Schema::create('beneficiaries', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            // Quotable over the phone, and destroyed at retention expiry
            // because it links straight back to the case.
            $table->string('case_reference', 32)->unique();

            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('focus_area_id')->nullable()->constrained()->nullOnDelete();

            // draft | submitted | under_review | approved | declined | withdrawn | closed
            $table->string('status', 32)->default('draft');

            // ── Direct identifiers ────────────────────────────────────────────
            $table->string('full_name', 191);
            $table->string('other_names', 191)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('ghana_card_number', 64)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('address')->nullable();
            $table->string('community', 191)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('bank_account', 64)->nullable();
            $table->string('momo_number', 32)->nullable();
            $table->string('next_of_kin_name', 191)->nullable();
            $table->string('next_of_kin_phone', 32)->nullable();
            $table->text('household_details')->nullable();
            $table->string('school_or_employer', 191)->nullable();
            $table->string('religion', 64)->nullable();

            // Highly sensitive. Note the retention class for supporting
            // documents is 24 months from closure, far shorter than the case
            // record's 72 — a medical report proving eligibility has served its
            // purpose once the case closes.
            $table->text('medical_notes')->nullable();

            $table->longText('application_narrative')->nullable();
            $table->longText('case_notes')->nullable();

            $table->foreignId('photo_id')->nullable()->constrained('media')->nullOnDelete();
            $table->foreignId('signature_id')->nullable()->constrained('media')->nullOnDelete();
            $table->foreignId('id_document_id')->nullable()->constrained('media')->nullOnDelete();

            // Captured with the online application form, as Act 843 evidence of
            // when and from where consent was given. Destroyed with the rest.
            $table->string('intake_ip', 45)->nullable();

            // ── Kept or generalised ───────────────────────────────────────────
            $table->string('gender', 16)->nullable();
            $table->string('region', 191)->nullable();
            $table->string('district', 191)->nullable();

            $table->unsignedBigInteger('assistance_minor')->nullable();
            $table->char('currency', 3)->default('GHS');
            $table->date('assisted_on')->nullable();

            // A short coded category, not free text — free text about an
            // outcome is a case note by another name.
            $table->string('outcome', 32)->nullable();

            // ── Lifecycle. These drive the retention clock. ───────────────────
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->foreignId('case_worker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /*
             * Soft deletes for day-to-day operations only. A genuine Act 843
             * erasure request, and every retention expiry, uses forceDelete —
             * a soft-deleted row still holds the personal data and would not
             * satisfy the Act at all.
             */
            $table->softDeletes();

            $table->index(['status', 'closed_at']);
            $table->index(['project_id', 'status']);
            $table->index(['division_id', 'status']);
            $table->index('decided_at');
            $table->index('last_activity_at');
        });

        /*
        |----------------------------------------------------------------------
        | Supporting documents — a shorter life than the case they support
        |----------------------------------------------------------------------
        */
        Schema::create('beneficiary_documents', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('beneficiary_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();

            $table->string('title', 191);
            // medical | financial | identity | school | referral | other
            $table->string('document_type', 32)->default('other');
            $table->text('description')->nullable();

            /*
             * Medical reports, and anything else of that character. Sensitive
             * documents fall under `beneficiary_sensitive_document`: 24 months
             * from case closure, against the case record's 72. Keeping a
             * medical report for six years beside a financial record would be
             * retention without a purpose.
             */
            $table->boolean('is_sensitive')->default(false);

            $table->timestamp('closed_at')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['beneficiary_id', 'document_type']);
            $table->index(['is_sensitive', 'closed_at']);
        });

        /*
        |----------------------------------------------------------------------
        | Consent
        |----------------------------------------------------------------------
        |
        | Polymorphic over beneficiaries, stories, testimonials and galleries —
        | anything whose publication depends on somebody having agreed to it.
        */
        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->morphs('consentable');

            // photo | story | video | name_use | data_processing
            $table->string('consent_type', 32);

            // website | print | social | all
            $table->string('scope', 32)->default('website');

            $table->string('granted_by_name', 191);

            // self | parent | guardian | next_of_kin
            $table->string('granted_by_relationship', 32)->default('self');

            /*
             * Consent for a child is given by a guardian, and WHO gave it is
             * part of the record — an unnamed guardian is not evidence of
             * anything. The model refuses to save a minor's consent without one.
             */
            $table->boolean('is_minor')->default(false);
            $table->string('guardian_name', 191)->nullable();

            $table->timestamp('granted_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();

            // The signed form. Consent nobody can produce evidence of is not
            // consent anybody can rely on.
            $table->foreignId('evidence_media_id')->nullable()->constrained('media')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->string('captured_ip', 45)->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['consent_type', 'revoked_at', 'expires_at'], 'consents_validity_index');
        });

        /*
        |----------------------------------------------------------------------
        | Stories
        |----------------------------------------------------------------------
        */
        Schema::create('stories', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            // ON DELETE SET NULL: when the case record is destroyed at retention
            // expiry, a published story survives on its own. By then it holds
            // only what consent covered.
            $table->foreignId('beneficiary_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title', 191);
            $table->string('slug', 191)->unique();
            $table->text('summary')->nullable();
            $table->longText('body');

            /*
             * The name as PUBLISHED, which is not necessarily the beneficiary's
             * own. Publishing a real name needs a `name_use` consent on top of
             * the story consent; a pseudonym needs only the story consent.
             */
            $table->string('subject_display_name', 191)->nullable();
            $table->boolean('uses_pseudonym')->default(false);

            $table->foreignId('image_id')->nullable()->constrained('media')->nullOnDelete();

            $table->boolean('is_featured')->default(false);
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_published', 'published_at']);
            $table->index(['division_id', 'is_published']);
        });

        /*
        |----------------------------------------------------------------------
        | Impact analytics — the dataset that outlives the case record
        |----------------------------------------------------------------------
        |
        | What is deliberately ABSENT is the specification:
        |
        |   no name, no phone, no email, no ID number, no address, no community,
        |   no coordinates, no date of birth, no photograph, no case notes,
        |   no narrative, no documents, no case reference, no payment reference,
        |   no exact assistance amount, no exact assistance date.
        |
        | A test asserts each of those columns does not exist, so adding one back
        | is a decision somebody has to make deliberately rather than by
        | accident.
        */
        Schema::create('beneficiary_impact_records', function (Blueprint $table) {
            $table->id();

            /*
             * Severed automatically when the case record is destroyed. See the
             * migration docblock: pseudonymous while the identifiable record
             * lawfully exists, genuinely unlinked afterwards.
             */
            $table->foreignId('source_beneficiary_id')->nullable()
                ->constrained('beneficiaries')->nullOnDelete();

            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('focus_area_id')->nullable()->constrained()->nullOnDelete();

            // Generalised, never exact.
            $table->string('region', 191)->nullable();
            $table->string('district', 191)->nullable();     // district is the finest permitted
            $table->string('gender', 16)->nullable();
            $table->string('age_band', 16)->nullable();      // "13-17", "65+"
            $table->string('assistance_band', 64)->nullable(); // "2,500.00 - 5,000.00"
            $table->string('assistance_period', 16)->nullable(); // "2026-03"
            $table->string('outcome', 32)->nullable();

            $table->timestamps();

            $table->index(['division_id', 'assistance_period']);
            $table->index(['region', 'district']);
            $table->index('outcome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beneficiary_impact_records');
        Schema::dropIfExists('stories');
        Schema::dropIfExists('consents');
        Schema::dropIfExists('beneficiary_documents');
        Schema::dropIfExists('beneficiaries');
    }
};
