<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 — a text to many people at once.
 *
 * The broadcast is the bookkeeping: what was said, to whom, what it was
 * estimated to cost, who approved it. The sending is the ordinary outbox —
 * one `scheduled_messages` row per recipient, throttled, quiet-hours-aware,
 * suppression-checked at the moment each one goes — and `sms_logs` is the
 * send log, related back to the broadcast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('title', 191);
            $table->text('body');

            // donors_sms · custom
            $table->string('audience', 32)->default('donors_sms');
            $table->text('custom_numbers')->nullable();

            $table->string('status', 32)->default('draft');
            $table->timestamp('scheduled_for')->nullable();

            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('queued_count')->default(0);
            $table->unsignedSmallInteger('segments')->default(1);
            $table->unsignedBigInteger('estimated_cost_minor')->default(0);
            $table->char('currency', 3)->default('GHS');

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->string('cancel_reason', 191)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_broadcasts');
    }
};
