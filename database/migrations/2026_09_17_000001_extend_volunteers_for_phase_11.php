<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 — what the volunteer brief asks for and Phase 3 did not have.
 *
 * ── Referees ────────────────────────────────────────────────────────────────
 *
 * Two of the safeguarding checks are "first reference, taken up" and "second
 * reference, taken up". Nobody could take one up: the application never asked
 * who the referees were. `referees` holds two — name, relationship, phone,
 * email — as JSON, because the two checks name them by position.
 *
 * ── Two stages the brief names ──────────────────────────────────────────────
 *
 * new → shortlisted → interviewed → approved. Phase 3 had submitted and
 * under-review; a shortlist and an interview are what the reviewer actually
 * does between the two, and each is a message the applicant should get.
 *
 * ── Shifts ──────────────────────────────────────────────────────────────────
 *
 * The smallest thing that makes hours logging concrete and gives the reminder
 * deferred from Phase 10 something to remind about: a volunteer, a start, an
 * end, a place. Completing a shift writes the hours; it is not a rota.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('volunteer_opportunities', function (Blueprint $table): void {
            $table->json('skills_needed')->nullable()->after('requirements');
        });

        Schema::table('volunteer_applications', function (Blueprint $table): void {
            $table->text('skills')->nullable()->after('experience');
            $table->json('referees')->nullable()->after('next_of_kin_phone');

            $table->timestamp('shortlisted_at')->nullable()->after('submitted_at');
            $table->timestamp('interview_at')->nullable()->after('shortlisted_at');
            $table->string('interview_location', 191)->nullable()->after('interview_at');
            $table->timestamp('interviewed_at')->nullable()->after('interview_location');
            $table->text('interview_notes')->nullable()->after('interviewed_at');
        });

        Schema::create('volunteer_shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('volunteer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('volunteer_opportunity_id')->nullable()
                ->constrained('volunteer_opportunities')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('location', 191)->nullable();
            $table->string('activity', 191)->nullable();
            $table->text('notes')->nullable();

            // scheduled | completed | missed | cancelled
            $table->string('status', 16)->default('scheduled');

            // The reminder goes once, the evening before. Null means not yet.
            $table->timestamp('reminder_sent_at')->nullable();
            // The hours row written when the shift was completed, so it is
            // never written twice.
            $table->foreignId('volunteer_hour_id')->nullable()->constrained('volunteer_hours')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'starts_at']);
            $table->index(['volunteer_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volunteer_shifts');

        Schema::table('volunteer_applications', function (Blueprint $table): void {
            $table->dropColumn(['skills', 'referees', 'shortlisted_at', 'interview_at', 'interview_location', 'interviewed_at', 'interview_notes']);
        });

        Schema::table('volunteer_opportunities', function (Blueprint $table): void {
            $table->dropColumn('skills_needed');
        });
    }
};
