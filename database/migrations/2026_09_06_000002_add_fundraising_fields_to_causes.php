<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The parts of an appeal that decide how much it raises.
 *
 * ── Giving levels are the single highest-value field here ───────────────────
 *
 * "GH₵ 50 provides a school kit for one child" raises materially more than a
 * blank amount box, because it answers the question a hesitant donor is
 * actually asking — not "how much should I give?" but "what does my money do?".
 * Stored as JSON rather than a table: they are an ordered list belonging to one
 * appeal, never queried across appeals, and never joined to. A table would be
 * three files and a relation for something that is read whole and written whole.
 *
 * ── Goal-reached behaviour, and the default is KEEP ACCEPTING ───────────────
 *
 * The brief left this per-cause and asked for a choice. The default is to keep
 * accepting, for two reasons: a foundation that hits its target and then refuses
 * money is leaving gifts on the table, and a donor who has already decided to
 * give is not somebody to turn away at the last step. What must NOT happen is
 * taking money silently against a goal that is met — so the appeal page says the
 * goal has been reached either way. Closing and redirecting are there for the
 * appeals where continuing would be wrong: a specific, funded, finite thing.
 *
 * ── A minimum per appeal, above the site floor ──────────────────────────────
 *
 * The site-wide floor exists because a gift smaller than the transaction fee
 * costs money to accept. A per-appeal minimum is different: it is the smallest
 * gift that buys anything in that appeal's terms, and it is optional because
 * most appeals have no such number.
 *
 * ── The fund code is for the accountant, not the donor ──────────────────────
 *
 * Restricted funds have to be reported separately, and "which appeal was this?"
 * is a question answered by a title today and by a code in a ledger. Never
 * shown publicly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('causes', function (Blueprint $table): void {
            /*
             * [{"amount_minor": 5000, "label": "A school kit", "description": "…"}]
             *
             * Amounts in minor units, like every other amount in this schema.
             */
            $table->json('giving_levels')->nullable()->after('goal_minor');

            $table->unsignedBigInteger('min_donation_minor')->nullable()->after('giving_levels');

            /*
             * Shown as a flag on the appeal card and used to order the list.
             * Deliberately blunt: an appeal marked urgent that is not is the
             * fastest way to make every future one ignored.
             */
            $table->boolean('is_urgent')->default(false)->after('is_featured');

            // continue | close | redirect
            $table->string('goal_reached_behaviour', 16)->default('continue')->after('status');

            /*
             * Where to send a donor when this appeal is full and set to
             * redirect. Nullable and ON DELETE SET NULL: deleting the target
             * appeal must not take this one with it, and a redirect pointing at
             * nothing falls back to continuing rather than to an error.
             */
            $table->foreignId('redirect_cause_id')->nullable()->after('goal_reached_behaviour')
                ->constrained('causes')->nullOnDelete();

            // For the ledger. Never rendered publicly.
            $table->string('fund_code', 32)->nullable()->after('redirect_cause_id');

            $table->index(['is_urgent', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('causes', function (Blueprint $table): void {
            $table->dropForeign(['redirect_cause_id']);
            $table->dropIndex(['is_urgent', 'sort_order']);

            $table->dropColumn([
                'giving_levels',
                'min_donation_minor',
                'is_urgent',
                'goal_reached_behaviour',
                'redirect_cause_id',
                'fund_code',
            ]);
        });
    }
};
