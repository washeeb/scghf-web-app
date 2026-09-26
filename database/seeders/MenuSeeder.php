<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MenuItemLinkType;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Route;

/**
 * Header, footer and mobile navigation.
 *
 * CLAUDE.md forbids hardcoded navigation in Blade, so the layout renders
 * whatever is here. These are sensible defaults, not fixtures — an editor can
 * reorder, relabel and remove items freely.
 *
 * Route-type items point at code-backed sections (/donate, /shop) that are not
 * CMS pages. They resolve to null until those routes exist, and
 * `isRenderable()` omits them — so the nav is correct at every stage rather
 * than showing links that 404.
 *
 * ⚠ The route NAMES here have to be the ones the routes were actually given.
 * Until Phase 6 this file said `donate.index`, `impact.index` and
 * `divisions.index`; the routes were built as `donate`, `impact` and
 * `focus-areas.index`. `isRenderable()` did exactly what it should with a name
 * that resolves to nothing — dropped the item — which is how a fresh install
 * had no Donate pill, no Impact link and no Divisions menu, silently, with
 * every test passing. `repointStaleRoutes()` corrects an install seeded with
 * the old names.
 *
 * Idempotent: menus are upserted, and items are only seeded into a menu that
 * has none, so a re-run never undoes an editor's arrangement.
 */
class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $header = Menu::updateOrCreate(
            ['key' => 'header'],
            ['name' => 'Header navigation', 'is_locked' => true, 'max_depth' => 1,
                'description' => 'Main navigation. Supports one level of dropdown.'],
        );

        $footerPrimary = Menu::updateOrCreate(
            ['key' => 'footer_primary'],
            ['name' => 'Footer — Explore', 'is_locked' => true, 'max_depth' => 0,
                'description' => 'First footer column. Flat.'],
        );

        $footerSupport = Menu::updateOrCreate(
            ['key' => 'footer_support'],
            ['name' => 'Footer — Support', 'is_locked' => true, 'max_depth' => 0],
        );

        $footerLegal = Menu::updateOrCreate(
            ['key' => 'footer_legal'],
            ['name' => 'Footer — Legal', 'is_locked' => true, 'max_depth' => 0,
                'description' => 'Legal bar. Several of these are linked from receipts.'],
        );

        $this->seedHeader($header);
        $this->seedFooterPrimary($footerPrimary);
        $this->seedFooterSupport($footerSupport);
        $this->seedFooterLegal($footerLegal);
        $this->repointStaleRoutes();

        $this->command?->info(sprintf(
            'Menus: %d menus, %d items.',
            Menu::count(),
            MenuItem::count(),
        ));
    }

    private function seedHeader(Menu $menu): void
    {
        if ($menu->items()->exists()) {
            return;
        }

        $order = 0;

        $this->page($menu, 'about', 'About', $order++, children: [
            ['slug' => 'our-story', 'label' => 'Our Story'],
            ['slug' => 'vision-mission', 'label' => 'Vision & Mission'],
            ['slug' => 'core-values', 'label' => 'Our Values'],
            ['slug' => 'leadership', 'label' => 'Leadership'],
            ['slug' => 'how-we-work', 'label' => 'How We Work'],
            ['slug' => 'transparency', 'label' => 'Transparency'],
        ]);

        /*
         * "Our Divisions" goes to /what-we-do, which lists the focus areas.
         * Its children are the four division pages — a page each, because a
         * visitor who wants to know what the foundation does about health
         * should not have to read a list of twenty-nine focus areas first.
         *
         * A page link rather than a route, so renaming a slug moves the menu
         * with it.
         */
        $divisions = $this->route($menu, 'focus-areas.index', 'Our Divisions', $order++);
        $childOrder = 0;

        foreach ([
            ['slug' => 'health', 'label' => 'Health'],
            ['slug' => 'education', 'label' => 'Education'],
            ['slug' => 'orphans-widows-and-widowers', 'label' => 'Orphans, Widows & Widowers'],
            ['slug' => 'missions', 'label' => 'Missions'],
        ] as $child) {
            $this->item($menu, $child, $childOrder++, $divisions->id);
        }

        $this->route($menu, 'projects.index', 'Projects', $order++);
        $this->route($menu, 'impact', 'Impact', $order++);

        $this->page($menu, 'get-involved', 'Get Involved', $order++, children: [
            ['route' => 'volunteer.index', 'label' => 'Volunteer'],
            ['route' => 'events.index', 'label' => 'Events'],
            ['slug' => 'partner-with-us', 'label' => 'Partner With Us'],
            ['slug' => 'corporate-giving', 'label' => 'Corporate Giving'],
            ['slug' => 'donate-goods', 'label' => 'Donate Goods'],
            ['slug' => 'fundraise-for-us', 'label' => 'Fundraise For Us'],
            ['slug' => 'prayer', 'label' => 'Prayer Requests'],
        ]);

        $this->route($menu, 'shop.index', 'Shop', $order++);
        $this->page($menu, 'contact', 'Contact', $order++);

        // The single most important element on the site. Highlighted so the
        // layout renders it as the pill button rather than a nav link.
        MenuItem::create([
            'menu_id' => $menu->id,
            'label' => 'Donate',
            'link_type' => MenuItemLinkType::Route,
            'route_name' => 'donate',
            'is_highlighted' => true,
            'sort_order' => $order,
        ]);
    }

    private function seedFooterPrimary(Menu $menu): void
    {
        if ($menu->items()->exists()) {
            return;
        }

        $order = 0;
        foreach ([
            ['slug' => 'about', 'label' => 'About Us'],
            ['slug' => 'our-story', 'label' => 'Our Story'],
            ['route' => 'focus-areas.index', 'label' => 'Our Divisions'],
            ['route' => 'projects.index', 'label' => 'Projects'],
            ['route' => 'impact', 'label' => 'Impact'],
            ['route' => 'news.index', 'label' => 'News'],
        ] as $row) {
            $this->item($menu, $row, $order++);
        }
    }

    private function seedFooterSupport(Menu $menu): void
    {
        if ($menu->items()->exists()) {
            return;
        }

        $order = 0;
        foreach ([
            ['route' => 'donate', 'label' => 'Donate'],
            ['slug' => 'other-ways-to-give', 'label' => 'Other Ways to Give'],
            ['route' => 'volunteer.index', 'label' => 'Volunteer'],
            ['slug' => 'partner-with-us', 'label' => 'Partner With Us'],
            ['slug' => 'downloads', 'label' => 'Reports & Documents'],
            ['slug' => 'faq', 'label' => 'FAQs'],
            ['slug' => 'contact', 'label' => 'Contact'],
        ] as $row) {
            $this->item($menu, $row, $order++);
        }
    }

    private function seedFooterLegal(Menu $menu): void
    {
        if ($menu->items()->exists()) {
            return;
        }

        $order = 0;
        foreach ([
            ['slug' => 'privacy-policy', 'label' => 'Privacy'],
            ['slug' => 'terms', 'label' => 'Terms'],
            ['slug' => 'donation-policy', 'label' => 'Donation Policy'],
            ['slug' => 'refund-policy', 'label' => 'Refunds'],
            ['slug' => 'cookie-policy', 'label' => 'Cookies'],
            ['slug' => 'shipping-and-delivery', 'label' => 'Shipping & Delivery'],
            ['slug' => 'safeguarding', 'label' => 'Safeguarding'],
            ['slug' => 'accessibility', 'label' => 'Accessibility'],
            ['slug' => 'whistleblowing', 'label' => 'Raise a Concern'],
            ['slug' => 'anti-fraud', 'label' => 'Anti-Fraud'],
        ] as $row) {
            $this->item($menu, $row, $order++);
        }
    }

    /**
     * Items seeded with a route name that was never built.
     *
     * Only names from this file's own history are touched, and only where the
     * new name is a real route — an editor's own route-type item is not this
     * seeder's to rewrite.
     */
    private function repointStaleRoutes(): void
    {
        $renamed = [
            'donate.index' => 'donate',
            'impact.index' => 'impact',
            'divisions.index' => 'focus-areas.index',
        ];

        foreach ($renamed as $old => $new) {
            if (! Route::has($new)) {
                continue;
            }

            MenuItem::query()
                ->where('link_type', MenuItemLinkType::Route)
                ->where('route_name', $old)
                ->update(['route_name' => $new]);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @param array<string, string> $row */
    private function item(Menu $menu, array $row, int $order, ?int $parentId = null): ?MenuItem
    {
        if (isset($row['route'])) {
            return $this->route($menu, $row['route'], $row['label'], $order, $parentId);
        }

        return $this->page($menu, $row['slug'], $row['label'], $order, $parentId);
    }

    /** @param array<int, array<string, string>> $children */
    private function page(
        Menu $menu,
        string $slug,
        string $label,
        int $order,
        ?int $parentId = null,
        array $children = [],
    ): ?MenuItem {
        $page = Page::where('slug', $slug)->first();

        if ($page === null) {
            return null;
        }

        $item = MenuItem::create([
            'menu_id' => $menu->id,
            'parent_id' => $parentId,
            'label' => $label,
            'link_type' => MenuItemLinkType::Page,
            'page_id' => $page->id,
            'sort_order' => $order,
        ]);

        $childOrder = 0;
        foreach ($children as $child) {
            $this->item($menu, $child, $childOrder++, $item->id);
        }

        return $item;
    }

    private function route(Menu $menu, string $routeName, string $label, int $order, ?int $parentId = null): MenuItem
    {
        return MenuItem::create([
            'menu_id' => $menu->id,
            'parent_id' => $parentId,
            'label' => $label,
            'link_type' => MenuItemLinkType::Route,
            'route_name' => $routeName,
            'sort_order' => $order,
        ]);
    }
}
