<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The deferred `division_id` columns, arriving with their foreign keys.
 *
 * Module 2 left these out deliberately. From
 * `2026_09_03_000010_create_content_tables.php`:
 *
 *   "Adding an unconstrained integer now would be a foreign key in all but
 *    name, with none of the integrity. The column and its constraint arrive
 *    together in Module 3, which is what expand-only migrations are for."
 *
 * This is that migration. Nullable throughout, and null means FOUNDATION-WIDE,
 * not unknown — a testimonial about the foundation as a whole is not missing
 * data, and `ON DELETE SET NULL` reflects the same thing: if a division were
 * ever removed, its content becomes foundation-wide rather than vanishing.
 */
return new class extends Migration
{
    /**
     * Content tables scoped to a division.
     *
     * @var array<int, string>
     */
    private const TABLES = [
        'faqs',
        'testimonials',
        'partners',
        'team_members',
        'galleries',
        'documents',
        'announcements',
        'posts',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('division_id')
                    ->nullable()
                    ->constrained()
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['division_id']);
                $blueprint->dropColumn('division_id');
            });
        }
    }
};
