<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 — a photograph of a person is a consent question.
 *
 * `depicts_people` and `depicts_children` are set by whoever uploads; with
 * either set, `Media::isPublishable()` also requires a valid photo consent
 * record (`consents`, polymorphic, since Phase 3) — and for a child, one
 * given by a named parent or guardian. Revoking the consent, or
 * withdrawing the image outright, unpublishes it everywhere at once
 * because every render goes through the same gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->boolean('depicts_people')->default(false)->after('alt_text');
            $table->boolean('depicts_children')->default(false)->after('depicts_people');
            $table->timestamp('withdrawn_at')->nullable()->after('depicts_children');
            $table->string('withdrawn_reason', 500)->nullable()->after('withdrawn_at');
            $table->foreignId('withdrawn_by')->nullable()->after('withdrawn_reason')->constrained('users')->nullOnDelete();

            $table->index(['depicts_people', 'withdrawn_at']);
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('withdrawn_by');
            $table->dropIndex(['depicts_people', 'withdrawn_at']);
            $table->dropColumn(['depicts_people', 'depicts_children', 'withdrawn_at', 'withdrawn_reason']);
        });
    }
};
