<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeders that must run on EVERY deploy, in dependency order.
 *
 * All three are idempotent and none overwrite content the foundation has
 * supplied, which is what makes it safe to run this as part of the release
 * step: a permission added in a new module reaches production automatically
 * rather than waiting for someone to remember.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            RoleAndPermissionSeeder::class,
            SettingsSeeder::class,
            ThemeSettingsSeeder::class,
        ]);
    }
}
