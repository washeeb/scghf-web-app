<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The footer's Legal group has always listed `shipping-and-delivery` among
 * its slugs, and the page has always existed — but no menu item pointed at
 * it, so the page was published and linked from nowhere.
 *
 * This is a migration rather than a line in MenuSeeder alone because the
 * seeder only fills an empty menu, and because the seeder runs on every
 * deploy: a backfill there would put the link back every time somebody
 * removed it. A migration runs once. The item is an ordinary menu item from
 * the moment it lands — rename it, reorder it, hide it or delete it in
 * *Site → Menus → Footer — Legal*, and nothing will re-create it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $menu = DB::table('menus')->where('key', 'footer_legal')->first();
        $page = DB::table('pages')->where('slug', 'shipping-and-delivery')->first();

        if ($menu === null || $page === null) {
            return;
        }

        // An empty menu is a fresh install: MenuSeeder is about to fill it,
        // this row included. Anything already pointing at the page is the
        // editor's own, and is left alone.
        $items = DB::table('menu_items')->where('menu_id', $menu->id);

        if ((clone $items)->count() === 0 || (clone $items)->where('page_id', $page->id)->exists()) {
            return;
        }

        // Straight after the cookie policy, which is where the Legal group
        // expects it; everything below shifts down by one.
        $cookiePage = DB::table('pages')->where('slug', 'cookie-policy')->first();
        $after = $cookiePage === null
            ? (int) (clone $items)->max('sort_order')
            : (int) ((clone $items)->where('page_id', $cookiePage->id)->value('sort_order') ?? (clone $items)->max('sort_order'));

        (clone $items)->where('sort_order', '>', $after)->increment('sort_order');

        DB::table('menu_items')->insert([
            'ulid' => (string) Str::ulid(),
            'menu_id' => $menu->id,
            'parent_id' => null,
            'label' => 'Shipping & Delivery',
            'link_type' => 'page',
            'page_id' => $page->id,
            'sort_order' => $after + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $menu = DB::table('menus')->where('key', 'footer_legal')->first();
        $page = DB::table('pages')->where('slug', 'shipping-and-delivery')->first();

        if ($menu === null || $page === null) {
            return;
        }

        DB::table('menu_items')
            ->where('menu_id', $menu->id)
            ->where('page_id', $page->id)
            ->where('label', 'Shipping & Delivery')
            ->delete();
    }
};
