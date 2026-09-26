<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 2 (1.3) — WhatsApp as a fifth channel.
 *
 * Three small changes rather than a parallel set of tables:
 *
 *   whatsapp_templates   the Meta-approved templates, by the same key
 *                        scheme as email and SMS templates. Meta's
 *                        templates are rigid — a name, a language, and
 *                        numbered placeholders — so the row maps our named
 *                        variables onto their positions and records
 *                        whether Meta has approved it yet
 *   sms_logs.channel     `sms` or `whatsapp`. A WhatsApp message is a
 *                        phone-addressed, provider-charged, delivery-
 *                        reported message: the SMS log already holds every
 *                        column it needs, and the delivery-log screen and
 *                        the webhook processor work on it unchanged
 *   consent_whatsapp     on donors and donations, beside consent_sms.
 *                        Meta and Act 843 both want an explicit opt-in
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name', 191);
            $table->text('description')->nullable();
            // transactional | marketing | system — the same vocabulary as the
            // other channels, and the same suppression rules.
            $table->string('category', 32)->default('transactional');
            // Meta's name for it, and the language it was approved in.
            $table->string('meta_name', 191)->nullable();
            $table->string('language', 16)->default('en');
            // Our variable names, in the order of Meta's {{1}}, {{2}} …
            $table->json('variables')->nullable();
            // What the approved template says, with {{name}} placeholders —
            // for the log and the preview; Meta holds the real text.
            $table->text('body')->nullable();
            $table->boolean('is_approved')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('sms_logs', function (Blueprint $table): void {
            $table->string('channel', 16)->default('sms')->after('ulid')->index();
        });

        Schema::table('donors', function (Blueprint $table): void {
            $table->boolean('consent_whatsapp')->default(false)->after('consent_sms');
        });

        Schema::table('donations', function (Blueprint $table): void {
            $table->boolean('consent_whatsapp')->default(false)->after('consent_sms');
        });
    }

    public function down(): void
    {
        Schema::table('donations', fn (Blueprint $table) => $table->dropColumn('consent_whatsapp'));
        Schema::table('donors', fn (Blueprint $table) => $table->dropColumn('consent_whatsapp'));
        Schema::table('sms_logs', function (Blueprint $table): void {
            $table->dropIndex(['channel']);
            $table->dropColumn('channel');
        });
        Schema::dropIfExists('whatsapp_templates');
    }
};
