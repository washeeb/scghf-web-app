<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Our Divisions" gets its four pages under it, and the hero's slides stop
 * pointing at an address that cannot exist.
 *
 * The pages themselves are made by PageSeeder and filled by
 * LaunchContentSeeder, both of which run on every deploy and both of which
 * add what is missing without touching what is there. The MENU is the part
 * that needs this: MenuSeeder only fills an empty menu, so on an install
 * that already has a header the four children would never appear — and on
 * every deploy after that, a backfill in the seeder would put back children
 * somebody had deliberately removed.
 *
 * A migration runs once. Remove a child in *Site → Menus → Header* and it
 * stays removed.
 */
return new class extends Migration
{
    /** slug => label, in the order they should appear. */
    private const CHILDREN = [
        'health' => 'Health',
        'education' => 'Education',
        'orphans-widows-and-widowers' => 'Orphans, Widows & Widowers',
        'missions' => 'Missions',
    ];

    /**
     * The addresses the hero's slides were first seeded with.
     *
     * `/what-we-do/{slug}` is the FOCUS AREA route, and these are DIVISION
     * slugs — so every one of them was a 404 from the moment it was written.
     * They are repointed at the division pages this migration is about.
     */
    private const REPOINT = [
        '/what-we-do/brightpath' => 'education',
        '/what-we-do/life-spring' => 'health',
        '/what-we-do/legacy-of-love' => 'orphans-widows-and-widowers',
        '/what-we-do/every-soul-missions' => 'missions',
    ];

    public function up(): void
    {
        $this->repointHeroSlides();

        $menu = DB::table('menus')->where('key', 'header')->first();

        if ($menu === null) {
            return;
        }

        $items = DB::table('menu_items')->where('menu_id', $menu->id);

        // An empty header is a fresh install: MenuSeeder is about to fill it,
        // these children included.
        if ((clone $items)->count() === 0) {
            return;
        }

        $parent = (clone $items)
            ->where('link_type', 'route')
            ->where('route_name', 'focus-areas.index')
            ->whereNull('parent_id')
            ->first();

        if ($parent === null) {
            return;
        }

        $order = (int) ((clone $items)->where('parent_id', $parent->id)->max('sort_order') ?? -1);

        foreach (self::CHILDREN as $slug => $label) {
            $page = DB::table('pages')->where('slug', $slug)->first();

            // The page is made by the seeder in the same deploy. If it is not
            // there yet, skip it rather than making a link to nothing.
            if ($page === null) {
                continue;
            }

            // Anything already pointing at that page under this parent is the
            // editor's own and is left alone.
            if ((clone $items)->where('parent_id', $parent->id)->where('page_id', $page->id)->exists()) {
                continue;
            }

            DB::table('menu_items')->insert([
                'ulid' => (string) Str::ulid(),
                'menu_id' => $menu->id,
                'parent_id' => $parent->id,
                'label' => $label,
                'link_type' => 'page',
                'page_id' => $page->id,
                'sort_order' => ++$order,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Rewrite those addresses wherever a hero block holds one.
     *
     * The block's `data` is JSON, so this reads each hero, walks its slides
     * and writes back only the rows that actually changed — a blind string
     * replace over a JSON column would be a good way to corrupt one.
     */
    private function repointHeroSlides(): void
    {
        $paths = DB::table('pages')
            ->whereIn('slug', array_values(self::REPOINT))
            ->pluck('path', 'slug');

        if ($paths->isEmpty()) {
            return;
        }

        foreach (DB::table('page_sections')->where('block_type', 'hero')->get(['id', 'data']) as $section) {
            $data = json_decode((string) $section->data, true);

            if (! is_array($data) || ! is_array($data['slides'] ?? null)) {
                continue;
            }

            $changed = false;

            foreach ($data['slides'] as $i => $slide) {
                if (! is_array($slide)) {
                    continue;
                }

                foreach (['primary_cta_url', 'secondary_cta_url'] as $field) {
                    $slug = self::REPOINT[$slide[$field] ?? ''] ?? null;

                    if ($slug !== null && $paths->has($slug)) {
                        $data['slides'][$i][$field] = (string) $paths->get($slug);
                        $changed = true;
                    }
                }
            }

            if ($changed) {
                DB::table('page_sections')->where('id', $section->id)->update([
                    'data' => json_encode($data),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $menu = DB::table('menus')->where('key', 'header')->first();

        if ($menu === null) {
            return;
        }

        $pageIds = DB::table('pages')->whereIn('slug', array_keys(self::CHILDREN))->pluck('id');

        DB::table('menu_items')
            ->where('menu_id', $menu->id)
            ->whereIn('page_id', $pageIds)
            ->whereIn('label', array_values(self::CHILDREN))
            ->delete();
    }
};
