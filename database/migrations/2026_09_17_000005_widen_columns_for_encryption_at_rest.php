<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 — encryption at rest for the columns that would hurt most.
 *
 * A disclosed conviction, a next of kin, two referees, a police clearance
 * certificate number, a safeguarding concern. A database backup that
 * leaks — the likeliest breach on shared hosting — should not hand those
 * over in clear. Laravel's `encrypted` cast does the work with APP_KEY;
 * the ciphertext is longer than the value, so the columns become TEXT.
 *
 * Existing rows are re-encrypted by `scghf:encrypt-at-rest --execute`,
 * which must run once after this deploys. Until it does, a legacy
 * plaintext value fails to decrypt and reads as null — a visible gap,
 * not a silent one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('volunteer_applications', function (Blueprint $table): void {
            $table->text('next_of_kin_name')->nullable()->change();
            $table->text('next_of_kin_phone')->nullable()->change();
            $table->text('referees')->nullable()->change();
        });

        Schema::table('safeguarding_checks', function (Blueprint $table): void {
            $table->text('reference')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('volunteer_applications', function (Blueprint $table): void {
            $table->string('next_of_kin_name', 191)->nullable()->change();
            $table->string('next_of_kin_phone', 32)->nullable()->change();
            $table->json('referees')->nullable()->change();
        });

        Schema::table('safeguarding_checks', function (Blueprint $table): void {
            $table->string('reference', 191)->nullable()->change();
        });
    }
};
