<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * GRA charitable-organisation approvals — Act 896 s.97 and s.100.
         *
         * The Foundation being incorporated or registered does NOT make
         * donations deductible. Deductibility messaging is gated on a current
         * written approval recorded here, and is switched off automatically the
         * day it expires.
         *
         * A table rather than settings keys because approvals have a history:
         * when the current one lapses and a renewal arrives, a receipt issued
         * last year must still be able to cite the approval that was valid at
         * the time it was issued.
         */
        Schema::create('tax_approvals', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            // section_97 — approved charitable organisation (whole entity)
            // section_100 — worthwhile cause approval for a specific cause
            $table->string('approval_type', 32)->default('section_97');

            $table->string('reference', 96);
            $table->string('tin', 32);

            $table->date('issued_on');

            // Nullable: an approval with no stated end date is possible, and
            // recording a guessed expiry would be worse than recording none.
            $table->date('expires_on')->nullable();

            // draft | active | expired | revoked | superseded
            // Derived from the dates, but stored so a revocation can be
            // recorded before the expiry date arrives.
            $table->string('status', 32)->default('draft');

            // The approval letter itself. An approval with no document is an
            // assertion; the admin UI requires one before status can go active.
            $table->foreignId('document_id')->nullable()
                ->constrained('media')->nullOnDelete();

            // For section_100, which particular cause the approval covers.
            // Constrained in Module 3 once `causes` exists.
            $table->string('covers_scope', 191)->nullable();

            $table->text('notes')->nullable();

            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['approval_type', 'reference']);
            $table->index(['status', 'expires_on']);
        });

        /*
         * Legal, audit and investigation holds.
         *
         * A hold OVERRIDES every ordinary deletion date. Destroying records
         * that are subject to an investigation or a live dispute is a far worse
         * failure than keeping them slightly too long, so the retention runner
         * refuses to touch anything under an active hold.
         *
         * Polymorphic, and able to hold a whole class of records at once —
         * "every beneficiary record for the 2025 education programme" is a
         * realistic instruction and enumerating it by hand would be error-prone.
         */
        Schema::create('legal_holds', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->string('reference', 64)->unique();
            $table->string('title', 191);
            $table->text('reason');

            // legal | audit | investigation | regulatory | dispute
            $table->string('hold_type', 32)->default('legal');

            // Either a specific record...
            $table->nullableMorphs('holdable');

            // ...or a whole retention class, optionally narrowed by a scope
            // expression the runner understands (e.g. a programme identifier).
            $table->string('retention_class', 64)->nullable();
            $table->string('scope_key', 191)->nullable();

            $table->date('placed_on');
            $table->foreignId('placed_by')->nullable()->constrained('users')->nullOnDelete();

            // Null = indefinite. A hold with no review date is how records are
            // kept forever by accident, so a review date is strongly encouraged
            // in the UI even when the end date is unknown.
            $table->date('review_on')->nullable();

            $table->date('released_on')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('release_reason')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['is_active', 'retention_class']);
            $table->index(['is_active', 'review_on']);
        });

        /*
         * The retention audit log.
         *
         * This OUTLIVES the data it describes, and holds no personal data
         * itself — only the class, a hashed reference and what was done. Being
         * able to show that a record was destroyed, when, under which policy
         * and by which run is the evidence that the policy was followed.
         *
         * Append-only. A deletion log an administrator can edit proves nothing.
         */
        Schema::create('retention_log', function (Blueprint $table) {
            $table->id();

            $table->string('retention_class', 64);

            // The model class and id that WAS acted on. Kept because a
            // regulator asking "what happened to record 412" needs an answer.
            $table->string('subject_type', 191);
            $table->unsignedBigInteger('subject_id');

            // A one-way digest of the identifying values destroyed, so a
            // specific person can be matched against the log on request without
            // the log itself holding their details.
            $table->string('subject_digest', 64)->nullable();

            // delete | de_identify | skipped_hold | skipped_error | reviewed
            $table->string('action', 32);

            $table->text('detail')->nullable();

            // Which hold blocked it, when action is skipped_hold.
            $table->foreignId('legal_hold_id')->nullable()
                ->constrained('legal_holds')->nullOnDelete();

            // The scheduled run, so one night's work can be reviewed together.
            $table->string('run_id', 36)->nullable();

            // Null when the actor was the scheduler rather than a person.
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('created_at')->nullable();

            $table->index(['retention_class', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_log');
        Schema::dropIfExists('legal_holds');
        Schema::dropIfExists('tax_approvals');
    }
};
