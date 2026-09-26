<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 — the archive, and the reminder.
 *
 * A past event is what the foundation actually did, and the page for one
 * should say so: the photographs (a gallery, which already carries the
 * consent flag the photographs need), what came of it, how many came.
 *
 * `reminders_sent_at` is set once by `scghf:event-reminders`; the
 * per-person idempotency key stops duplicates, this stops the query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->foreignId('gallery_id')->nullable()->after('featured_image_id')
                ->constrained('galleries')->nullOnDelete();
            $table->longText('outcomes')->nullable()->after('accessibility_notes');
            $table->unsignedSmallInteger('attendance_count')->nullable()->after('outcomes');
            $table->timestamp('reminders_sent_at')->nullable()->after('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('gallery_id');
            $table->dropColumn(['outcomes', 'attendance_count', 'reminders_sent_at']);
        });
    }
};
