<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeders that must run on EVERY deploy, in dependency order.
 *
 * All are idempotent and none overwrite content the foundation has supplied,
 * which is what makes it safe to run this as part of the release step: a
 * permission or block added in a new module reaches production automatically
 * rather than waiting for someone to remember.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * NOTE: `WithoutModelEvents` was REMOVED, and must not be added back.
 *
 * Laravel's default stub includes it, and it broke this project twice:
 *
 *   1. spatie/laravel-permission busts its permission cache from model save
 *      events. With those muted, `syncPermissions()` could not see permissions
 *      written moments earlier and threw PermissionDoesNotExist.
 *   2. Page derives its materialised `path` in a `saving` hook. With events
 *      muted the column was never populated, and the insert failed with
 *      "Field 'path' doesn't have a default value".
 *
 * Both are the same underlying problem: our models rely on lifecycle hooks for
 * correctness, not merely for side-effects. Muting them does not make seeding
 * quieter, it makes it wrong.
 *
 * Activity logging IS disabled below, because that genuinely is noise — the
 * distinction is between events that maintain invariants and events that only
 * record.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        activity()->disableLogging();

        try {
            $this->call([
                RoleAndPermissionSeeder::class,
                SettingsSeeder::class,
                ThemeSettingsSeeder::class,
                BlockTypeSeeder::class,
                // Before pages and menus: both link to divisions, and a menu
                // item pointing at a division that does not exist yet is a
                // broken link seeded on purpose.
                DivisionSeeder::class,
                // After divisions, because the General Fund and the per-division
                // funds both need them. Before anything that could take a
                // donation.
                CauseSeeder::class,
                // The shop taxonomy comes from the compliance policy, so the
                // two cannot drift: nothing appears in the shop that is not in
                // the agreed list.
                ShopCategorySeeder::class,
                // Zones only. What delivery costs is a commercial decision the
                // foundation makes with a courier, not one to invent here.
                ShippingZoneSeeder::class,
                PageSeeder::class,
                MenuSeeder::class,
                CmsReferenceSeeder::class,
                // Templates before newsletters: a list points at the campaign
                // wrapper template, and a list seeded first would point at
                // nothing.
                MessageTemplateSeeder::class,
                NewsletterSeeder::class,
            ]);
        } finally {
            activity()->enableLogging();
        }
    }
}
