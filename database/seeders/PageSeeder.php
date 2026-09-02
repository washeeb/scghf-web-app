<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PageStatus;
use App\Models\Page;
use Illuminate\Database\Seeder;

/**
 * The CMS pages from PHASE-1-BLUEPRINT.md §6.1.
 *
 * Only the pages whose CONTENT is CMS-managed. Routes backed by code — /donate,
 * /shop, /projects, /causes — are not rows here; they get their own controllers
 * in later phases. Seeding them as pages would create two things claiming the
 * same URL.
 *
 * `is_locked` marks pages the application links to by name. An editor may
 * rewrite them freely but must not delete them or the footer breaks.
 *
 * Idempotent, and never overwrites content that has been edited.
 */
class PageSeeder extends Seeder
{
    /**
     * [slug, title, parent slug|null, locked, excerpt]
     *
     * @var array<int, array{0:string,1:string,2:?string,3:bool,4:string}>
     */
    private const PAGES = [
        // ── Home ─────────────────────────────────────────────────────────────
        ['home', 'Home', null, true,
            'Turning remembrance into impact across health, education, welfare and evangelism.'],

        // ── About ────────────────────────────────────────────────────────────
        ['about', 'About the Foundation', null, true,
            'A Ghanaian foundation created to continue a life of faith, compassion and service.'],
        ['our-story', 'Our Story', 'about', true,
            'How grief became a reason to serve — the life of Mrs Cecilia Anyatuik Adam.'],
        ['vision-mission', 'Vision & Mission', 'about', false,
            'What we are working towards, and how.'],
        ['core-values', 'Our Core Values', 'about', false,
            'Faith, Compassion, Love, Dignity, Service, Integrity, Legacy.'],
        ['leadership', 'Leadership', 'about', false,
            'The people who carry this work.'],
        ['how-we-work', 'How We Work', 'about', false,
            'Identify, assess, support, and follow up.'],
        ['transparency', 'Transparency & Accountability', 'about', true,
            'Governance, reports and how donations are used.'],
        ['partners', 'Partners & Supporters', 'about', false,
            'The churches, institutions and individuals standing with us.'],

        // ── Get involved ─────────────────────────────────────────────────────
        ['get-involved', 'Get Involved', null, true,
            'Ways to give, serve and partner.'],
        ['partner-with-us', 'Partner With Us', 'get-involved', false,
            'For churches, companies and institutions.'],
        ['donate-goods', 'Donate Goods', 'get-involved', false,
            'Food, clothing, books and medical supplies.'],
        ['prayer', 'Prayer Requests', 'get-involved', false,
            'Share a request, or join us in praying.'],

        // ── Giving information ───────────────────────────────────────────────
        ['other-ways-to-give', 'Other Ways to Give', null, true,
            'Bank transfer, Mobile Money and in-kind gifts.'],
        ['donation-faq', 'Donation Questions', null, false,
            'Common questions about giving.'],

        // ── Support ──────────────────────────────────────────────────────────
        ['contact', 'Contact Us', null, true,
            'Reach the team that can help.'],
        ['faq', 'Frequently Asked Questions', null, false,
            'Answers to what we are asked most.'],
        ['downloads', 'Reports & Documents', null, false,
            'Annual reports, policies and forms.'],

        // ── Legal and trust ──────────────────────────────────────────────────
        // Locked without exception: several are linked from receipts and from
        // the Paystack merchant profile, and a 404 there is a compliance issue,
        // not a broken link.
        ['privacy-policy', 'Privacy Policy', null, true,
            'How we collect, use and protect personal data under Act 843.'],
        ['terms', 'Terms of Use', null, true,
            'The terms on which this site is offered.'],
        ['donation-policy', 'Donation Policy', null, true,
            'How donations are allocated and used.'],
        ['refund-policy', 'Refund Policy', null, true,
            'When and how a donation or order can be refunded.'],
        ['shipping-and-delivery', 'Shipping & Delivery', null, true,
            'Delivery areas, times and costs.'],
        ['cookie-policy', 'Cookie Policy', null, true,
            'What we store in your browser, and why.'],
        ['safeguarding', 'Safeguarding', null, true,
            'Our commitment to protecting children and vulnerable adults.'],
        ['accessibility', 'Accessibility', null, true,
            'Our accessibility commitment and how to report a barrier.'],
        ['whistleblowing', 'Raising a Concern', null, true,
            'How to report a concern, confidentially.'],
    ];

    public function run(): void
    {
        $created = 0;

        foreach (self::PAGES as [$slug, $title, $parentSlug, $locked, $excerpt]) {
            $parent = $parentSlug !== null
                ? Page::where('slug', $parentSlug)->whereNull('parent_id')->first()
                : null;

            $page = Page::withTrashed()
                ->where('slug', $slug)
                ->where('parent_id', $parent?->getKey())
                ->first();

            if ($page !== null) {
                // Only ever refresh the structural flag. Title and excerpt are
                // editable content and must survive a re-run.
                $page->forceFill(['is_locked' => $locked])->save();

                continue;
            }

            $page = new Page([
                'title' => $title,
                'slug' => $slug,
                'excerpt' => $excerpt,
                'parent_id' => $parent?->getKey(),
                'status' => PageStatus::Draft,
                'sort_order' => $created,
            ]);

            $page->is_locked = $locked;
            $page->save();

            $created++;
        }

        // The homepage sets its own path to '/', so this must happen after it
        // exists rather than being a column default.
        Page::where('slug', 'home')->whereNull('parent_id')->first()?->setAsHomepage();

        $this->command?->info(sprintf(
            'Pages: %d created, %d total. All seeded as DRAFT — nothing is public until content is written.',
            $created,
            Page::count(),
        ));
    }
}
