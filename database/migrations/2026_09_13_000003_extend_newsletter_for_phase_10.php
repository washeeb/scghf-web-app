<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 — the newsletter, composed and measured.
 *
 * `blocks` is what the editor builds a campaign from — headings, paragraphs,
 * buttons, an appeal — compiled into `body_html`/`body_text` on save, so the
 * sender and the test send read the same HTML they always did.
 *
 * The open and click columns are written only when tracking is switched on
 * in `.env`, and only for marketing mail: a receipt is not something to
 * watch somebody read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('newsletter_campaigns', function (Blueprint $table) {
            $table->json('blocks')->nullable()->after('body_text');
            $table->string('failure_reason', 500)->nullable()->after('pause_reason');
        });

        // `opened_at` and `clicked_at` have been on the table since Phase 3,
        // written by nothing until now; the counts are new.
        Schema::table('email_logs', function (Blueprint $table) {
            $table->unsignedSmallInteger('open_count')->default(0)->after('opened_at');
            $table->unsignedSmallInteger('click_count')->default(0)->after('clicked_at');
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropColumn(['open_count', 'click_count']);
        });

        Schema::table('newsletter_campaigns', function (Blueprint $table) {
            $table->dropColumn(['blocks', 'failure_reason']);
        });
    }
};
