<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 — what the maintenance jobs need.
 *
 * Every hot query on the public site already had its index (Phase 3). What
 * did not was the maintenance the site needs to keep running on shared
 * hosting: pruning logs by age, archiving webhook payloads by age. A range
 * on `created_at` with no index is a full scan of the largest tables in the
 * database, monthly, on a metered host.
 *
 * The payload archive columns let a webhook event keep its row — the audit
 * trail is never deleted — while its raw body moves to a compressed file
 * once it is a year old and processed. `payload_hash` is what proves the
 * archived body is the one that was received.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->index('created_at', 'activity_log_created_at_index');
        });

        Schema::table('email_logs', function (Blueprint $table): void {
            $table->index('created_at', 'email_logs_created_at_index');
        });

        Schema::table('sms_logs', function (Blueprint $table): void {
            $table->index('created_at', 'sms_logs_created_at_index');
        });

        foreach (['payment_webhook_events', 'inbound_webhook_events'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->longText('raw_payload')->nullable()->change();
                $blueprint->char('payload_hash', 64)->nullable()->after('raw_payload');
                $blueprint->string('payload_archive', 191)->nullable()->after('payload_hash');
                $blueprint->timestamp('payload_archived_at')->nullable()->after('payload_archive');

                $blueprint->index(['payload_archived_at', 'received_at'], $table.'_archiving_index');
            });
        }
    }

    public function down(): void
    {
        Schema::table('activity_log', fn (Blueprint $t) => $t->dropIndex('activity_log_created_at_index'));
        Schema::table('email_logs', fn (Blueprint $t) => $t->dropIndex('email_logs_created_at_index'));
        Schema::table('sms_logs', fn (Blueprint $t) => $t->dropIndex('sms_logs_created_at_index'));

        foreach (['payment_webhook_events', 'inbound_webhook_events'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropIndex($table.'_archiving_index');
                $blueprint->dropColumn(['payload_hash', 'payload_archive', 'payload_archived_at']);
            });
        }
    }
};
