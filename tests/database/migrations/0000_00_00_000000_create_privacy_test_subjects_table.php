<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A stand-in beneficiary record, for the de-identification tests.
 *
 * Lives in `tests/database/migrations`, which AppServiceProvider loads only in
 * the testing environment — so it is part of the test schema and never part of
 * the real one. Deployment runs `migrate` in the production environment and
 * never sees this file.
 *
 * **Why a migration rather than a `Schema::create()` in the test.** MySQL
 * implicitly commits on DDL, which ends the transaction RefreshDatabase wraps
 * each test in. Laravel notices the connection is no longer in a transaction
 * and resets its "already migrated" flag, so the NEXT test re-runs
 * `migrate:fresh` — which drops the fixture table, so the test recreates it, and
 * the cycle repeats. The result is a full re-migration on every single test:
 * thirteen seconds each instead of a twentieth of one.
 *
 * A real table rather than a mock because `DeIdentifiable::deIdentify()` reads
 * column nullability from the schema to choose between nulling a column and
 * overwriting it, and a mock cannot exercise that decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_test_subjects', function (Blueprint $table) {
            $table->id();

            // NOT NULL on purpose: proves a column that cannot be nulled is
            // overwritten rather than left holding the original value.
            $table->string('full_name');

            $table->string('phone')->nullable();
            $table->string('ghana_card')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('community')->nullable();
            $table->text('case_notes')->nullable();
            $table->unsignedBigInteger('assistance_minor')->nullable();
            $table->string('district')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_test_subjects');
    }
};
