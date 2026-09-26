<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 2 (1.6) — grants and institutional funders.
 *
 * Where the larger money comes from, and the deadlines that lose it. Four
 * tables and one column:
 *
 *   funders            — who gives grants (a foundation, a ministry, a
 *                        company, a church), optionally the same
 *                        organisation as a public partner
 *   grants             — one application or award: the pipeline status
 *                        (idea → drafting → submitted → awarded / declined
 *                        → closed), the amounts asked and awarded in
 *                        integer pesewas, the deadline, the project it
 *                        funds, and whether the money is restricted to it
 *   grant_obligations  — what the funder is owed and when: a report, an
 *                        audit, a receipt, a visit; reminded before due
 *   grant_documents    — the proposal, the agreement, the reports, on the
 *                        private disk
 *   payouts.grant_id   — a payout charged to a grant, which is how spend
 *                        against each award is known from the ledger
 *                        rather than re-typed
 *
 * Deliberately small (ROADMAP §1.6: "the risk is building a CRM nobody
 * updates — keep it to deadlines and documents").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funders', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('name', 191);
            $table->string('slug', 191)->unique();
            // foundation | government | corporate | multilateral | church | individual | other
            $table->string('funder_type', 32)->default('foundation');
            $table->string('website_url')->nullable();
            $table->string('contact_name', 191)->nullable();
            $table->string('contact_email', 191)->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('partner_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('grants', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('funder_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 191);
            $table->string('funder_reference', 191)->nullable();
            // idea | drafting | submitted | awarded | declined | closed
            $table->string('status', 32)->default('idea');
            $table->unsignedBigInteger('amount_requested_minor')->nullable();
            $table->unsignedBigInteger('amount_awarded_minor')->nullable();
            $table->char('currency', 3)->default('GHS');
            $table->boolean('is_restricted')->default(true);
            $table->date('deadline_on')->nullable();
            $table->date('submitted_on')->nullable();
            $table->date('decided_on')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->text('purpose')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'deadline_on']);
        });

        Schema::create('grant_obligations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('grant_id')->constrained()->cascadeOnDelete();
            $table->string('title', 191);
            // report | audit | receipt | visit | other
            $table->string('kind', 16)->default('report');
            $table->date('due_on');
            $table->date('completed_on')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->index(['due_on', 'completed_on']);
        });

        Schema::create('grant_documents', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('grant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->string('title', 191);
            // proposal | agreement | budget | report | correspondence | other
            $table->string('kind', 16)->default('other');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('payouts', function (Blueprint $table): void {
            $table->foreignId('grant_id')->nullable()->after('cause_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('grant_id');
        });

        Schema::dropIfExists('grant_documents');
        Schema::dropIfExists('grant_obligations');
        Schema::dropIfExists('grants');
        Schema::dropIfExists('funders');
    }
};
