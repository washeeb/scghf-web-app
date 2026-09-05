<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Removing the second announcement bar.
 *
 * ── What happened ───────────────────────────────────────────────────────────
 *
 * Phase 5 Module 3 seeded an `announcement.*` settings group — message, link,
 * start and end dates — and rendered a bar from it. The `announcements` table
 * had existed since Phase 3, doing the same job with dismissal, path targeting,
 * three placements and impression counting, and nothing rendered it. So the
 * settings version looked like the only one there was.
 *
 * Two mechanisms for one bar is worse than either alone. The foundation would
 * have edited one and wondered why the site kept showing the other, and there
 * is no order of investigation that leads anywhere pleasant from there.
 *
 * The table wins: it does everything the settings did, and five things they
 * could not. These rows go, so nothing is left offering a second answer.
 *
 * ── A data migration, not a seeder change ───────────────────────────────────
 *
 * The seeder no longer creates them, which covers a fresh install. It does not
 * touch an existing one — the seeder is deliberately write-once — and nothing
 * runs `db:seed` on deploy anyway. A row nobody reads is exactly the kind of
 * thing somebody finds in six months and spends an afternoon on.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('group', 'announcement')->delete();

        /*
         * The history stays.
         *
         * `settings_history` is append-only by design — the model refuses
         * updates and deletes — and it is the only record of what the site said
         * on a given day. Deleting the setting does not un-say it.
         */
    }

    public function down(): void
    {
        /*
         * Deliberately empty.
         *
         * Recreating these rows would put the second mechanism back, and the
         * views that read it are gone. An irreversible data migration is the
         * honest shape here: the reversal is "check out the earlier commit".
         */
    }
};
