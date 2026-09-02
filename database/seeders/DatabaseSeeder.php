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
                PageSeeder::class,
                MenuSeeder::class,
            ]);
        } finally {
            activity()->enableLogging();
        }
    }
}
