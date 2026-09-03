<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 6, part 1 — volunteers and safeguarding.
 *
 * ── The safeguarding gate ───────────────────────────────────────────────────
 *
 * Two of the foundation's four divisions exist to work with orphans, vulnerable
 * children, widows and the elderly. A volunteer role involving unsupervised
 * contact with them cannot be approved until every required check is recorded
 * — enforced in `VolunteerApplication::approve()`, not left to a form.
 *
 * `safeguarding_checks` is one row per check per application, with a reference
 * and a date, so "was this person checked?" has an evidenced answer rather than
 * a remembered one. A police clearance goes STALE: it is a statement about a
 * point in time, not a permanent property of a person.
 *
 * ── CVs and documents live outside the web root ─────────────────────────────
 *
 * A CV carries a home address, a date of birth and an employment history. It is
 * stored on the private disk and served through an authorised, signed route —
 * never a public URL. A file on a guessable path is a file everyone has.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        |----------------------------------------------------------------------
        | Opportunities
        |----------------------------------------------------------------------
        */
        Schema::create('volunteer_opportunities', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title', 191);
            $table->string('slug', 191)->unique();
            $table->text('summary')->nullable();
            $table->longText('description')->nullable();
            $table->longText('requirements')->nullable();

            /*
             * THE FLAG THE WHOLE MODULE TURNS ON.
             *
             * True means this role involves unsupervised contact with children
             * or other vulnerable people, and the full check set applies. It
             * defaults to TRUE: for a foundation whose work is orphans and
             * widows, the safe default is to assume contact and require the
             * recruiter to say otherwise deliberately.
             */
            $table->boolean('involves_vulnerable_contact')->default(true);

            // office | field | remote | events
            $table->string('placement_type', 32)->default('field');

            $table->string('location', 191)->nullable();
            $table->string('region', 191)->nullable();
            $table->string('time_commitment', 191)->nullable();

            $table->unsignedSmallInteger('positions_available')->nullable();
            $table->unsignedSmallInteger('positions_filled')->default(0);

            $table->date('starts_on')->nullable();
            $table->date('closes_on')->nullable();

            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();

            $table->foreignId('contact_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_published', 'closes_on']);
            $table->index(['division_id', 'is_published']);
            $table->index('involves_vulnerable_contact');
        });

        /*
        |----------------------------------------------------------------------
        | Applications
        |----------------------------------------------------------------------
        */
        Schema::create('volunteer_applications', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->string('reference', 32)->unique();

            $table->foreignId('volunteer_opportunity_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // draft | submitted | under_review | approved | declined | withdrawn
            $table->string('status', 32)->default('draft');

            $table->string('full_name', 191);
            $table->string('email', 191);
            $table->string('phone', 32)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('address')->nullable();
            $table->string('region', 191)->nullable();
            $table->string('occupation', 191)->nullable();

            $table->longText('motivation')->nullable();
            $table->longText('experience')->nullable();
            $table->string('availability', 191)->nullable();

            $table->string('next_of_kin_name', 191)->nullable();
            $table->string('next_of_kin_phone', 32)->nullable();

            /*
             * Stored on the PRIVATE disk. A CV carries a home address, a date
             * of birth and an employment history — it is served through an
             * authorised signed route, never a public URL.
             */
            $table->foreignId('cv_media_id')->nullable()->constrained('media')->nullOnDelete();

            /*
             * The declaration itself, kept verbatim rather than as a boolean.
             * "They ticked a box" is not evidence of what they were asked; the
             * text they agreed to is.
             */
            $table->boolean('declaration_agreed')->default(false);
            $table->longText('declaration_text')->nullable();
            $table->timestamp('declaration_at')->nullable();
            $table->string('declaration_ip', 45)->nullable();

            /*
             * Disclosed convictions. Free text on purpose: a checkbox invites a
             * yes/no where the useful information is the circumstances, and a
             * disclosure the foundation then judged is exactly what an inquiry
             * would ask to see.
             */
            $table->text('disclosed_convictions')->nullable();

            $table->text('assessor_notes')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->string('decline_reason', 191)->nullable();

            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'submitted_at']);
            $table->index(['volunteer_opportunity_id', 'status']);
            $table->index('decided_at');
            $table->index('last_activity_at');
        });

        /*
        |----------------------------------------------------------------------
        | Safeguarding checks — one row per check, with evidence
        |----------------------------------------------------------------------
        */
        Schema::create('safeguarding_checks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('volunteer_application_id')->constrained()->cascadeOnDelete();

            // A key from config('compliance.safeguarding.required_checks').
            $table->string('check_type', 64);

            // pending | passed | failed | waived
            $table->string('outcome', 32)->default('pending');

            /*
             * The evidence. A certificate number, a referee's name, a date the
             * interview happened. Required to record a PASS — a check with no
             * reference is a claim, not a check.
             */
            $table->string('reference', 191)->nullable();
            $table->text('notes')->nullable();

            $table->date('completed_on')->nullable();

            /*
             * When this check stops being current. A police clearance is a
             * statement about a point in time, not a permanent property of a
             * person.
             */
            $table->date('expires_on')->nullable();

            $table->foreignId('evidence_media_id')->nullable()->constrained('media')->nullOnDelete();

            /*
             * Who signed it off. Required for a pass, and deliberately not
             * defaultable to the current user in a seeder or an import: a check
             * nobody put their name to is not a check.
             */
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             * A waiver needs a reason and an authoriser. Waiving a police check
             * for somebody who will work with children unsupervised is a
             * decision somebody has to own.
             */
            $table->string('waiver_reason', 191)->nullable();
            $table->foreignId('waived_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One row per check type per application. A re-check updates it.
            $table->unique(['volunteer_application_id', 'check_type'], 'safeguarding_check_unique');
            $table->index(['outcome', 'expires_on']);
        });

        /*
        |----------------------------------------------------------------------
        | Volunteers — an approved application becomes one of these
        |----------------------------------------------------------------------
        */
        Schema::create('volunteers', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('volunteer_application_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();

            $table->string('full_name', 191);
            $table->string('email', 191)->nullable();
            $table->string('phone', 32)->nullable();

            $table->string('role', 191)->nullable();

            // active | inactive | suspended | left
            $table->string('status', 32)->default('active');

            /*
             * Denormalised from the application's checks, so a list screen can
             * show at a glance who is cleared without joining. Recomputed, never
             * incremented — a stale clearance flag is worse than none.
             */
            $table->boolean('is_cleared')->default(false);
            $table->date('clearance_expires_on')->nullable();
            $table->boolean('involves_vulnerable_contact')->default(true);

            $table->date('started_on');
            $table->date('ended_on')->nullable();
            $table->string('leaving_reason', 191)->nullable();

            /*
             * A live safeguarding concern. Suspends immediately — before any
             * investigation, and without implying a finding. That order of
             * events is what any safeguarding policy worth having insists on.
             */
            $table->timestamp('concern_raised_at')->nullable();
            $table->text('concern_note')->nullable();
            $table->foreignId('concern_raised_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedInteger('total_hours')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'is_cleared']);
            $table->index(['division_id', 'status']);
            $table->index('clearance_expires_on');
            $table->index('ended_on');
        });

        /*
        |----------------------------------------------------------------------
        | Hours
        |----------------------------------------------------------------------
        |
        | Recorded because a foundation reporting "3,400 volunteer hours" to a
        | funder needs rows behind the number, and because an unverified figure
        | is one nobody can stand behind.
        */
        Schema::create('volunteer_hours', function (Blueprint $table) {
            $table->id();

            $table->foreignId('volunteer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('volunteer_opportunity_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->date('worked_on');

            // Minutes, not fractional hours. "2.5 hours" as a float is how a
            // total ends up at 3,399.9999999.
            $table->unsignedInteger('minutes');

            $table->string('activity', 191)->nullable();
            $table->text('notes')->nullable();

            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['volunteer_id', 'worked_on']);
            $table->index(['project_id', 'worked_on']);
            $table->index('verified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volunteer_hours');
        Schema::dropIfExists('volunteers');
        Schema::dropIfExists('safeguarding_checks');
        Schema::dropIfExists('volunteer_applications');
        Schema::dropIfExists('volunteer_opportunities');
    }
};
