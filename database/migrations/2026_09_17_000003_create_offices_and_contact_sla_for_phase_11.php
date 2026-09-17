<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 — offices, and the SLA that tells somebody.
 *
 * ── Offices ─────────────────────────────────────────────────────────────────
 *
 * The contact settings describe one office. The brief asks for locations,
 * plural, each with hours, a phone, WhatsApp and directions. The settings
 * stay — the header and footer read them, and they are the primary office
 * — and this table holds every office the contact page lists.
 *
 * ── `sla_reminded_at` ───────────────────────────────────────────────────────
 *
 * `ContactMessage::isOverdue()` has driven a badge since Phase 5 and told
 * nobody. The reminder goes once per message, to whoever owns it or the
 * department's mailbox, and this is what stops it going twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offices', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 191);
            $table->string('address', 500)->nullable();
            $table->string('gps_address', 32)->nullable();
            $table->string('city', 191)->nullable();
            $table->string('region', 191)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('whatsapp', 32)->nullable();
            $table->string('email', 191)->nullable();
            // {mon: "8:00–17:00", tue: …, sun: "Closed"} — text per day, because
            // "8–12, then 2–5" and "by appointment" are both real answers.
            $table->json('hours')->nullable();
            $table->string('directions_url', 500)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::table('contact_messages', function (Blueprint $table): void {
            $table->timestamp('sla_reminded_at')->nullable()->after('replied_by');
        });
    }

    public function down(): void
    {
        Schema::table('contact_messages', function (Blueprint $table): void {
            $table->dropColumn('sla_reminded_at');
        });

        Schema::dropIfExists('offices');
    }
};
