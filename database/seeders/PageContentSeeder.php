<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

/**
 * Starting content for the pages that need it before anybody can use them.
 *
 * ── Only into a page that has no sections ───────────────────────────────────
 *
 * `PageSeeder` creates the pages empty and as drafts. This seeder gives some
 * of them a first draft of content — the get-involved pages, whose enquiry
 * forms are blocks that have to be placed before they exist; and the legal
 * pages, whose text a foundation needs to review rather than write from
 * nothing. A page an editor has already touched is never overwritten: the
 * seeder skips any page with a section in it.
 *
 * ── Still drafts ────────────────────────────────────────────────────────────
 *
 * Nothing here is published. The legal pages in particular are the
 * foundation's undertakings to donors, customers and the Data Protection
 * Commission; a draft written by software is a starting point for the
 * trustees, not a policy, and publishing it is their decision.
 *
 * Wording that depends on the foundation — its name, its addresses — is read
 * from the settings layer at render time via `{{ }}` tokens where a template
 * supports them, and otherwise written as the generic "we"/"the foundation".
 */
class PageContentSeeder extends Seeder
{
    public function run(): void
    {
        $seeded = 0;

        foreach ($this->content() as $slug => $sections) {
            $page = Page::query()->where('slug', $slug)->first();

            if ($page === null || $page->sections()->exists()) {
                continue;
            }

            foreach ($sections as $order => $section) {
                $page->sections()->create([
                    'block_type' => $section['type'],
                    'name' => $section['name'] ?? null,
                    'data' => $section['data'],
                    'sort_order' => $order,
                ]);
            }

            $seeded++;
        }

        $this->command?->info(sprintf('Page content: %d pages given a first draft. All remain DRAFT.', $seeded));
    }

    /** @return array<string, array<int, array{type: string, name?: string, data: array<string, mixed>}>> */
    private function content(): array
    {
        return array_merge($this->getInvolved(), $this->legal());
    }

    /** @return array<string, array<int, array{type: string, name?: string, data: array<string, mixed>}>> */
    private function getInvolved(): array
    {
        $path = fn (string $slug): string => (string) (Page::query()->where('slug', $slug)->first()?->path ?? '/'.$slug);

        return [
            'get-involved' => [
                ['type' => 'rich-text', 'data' => [
                    'heading' => 'There is more than one way to help',
                    'body' => '<p>Money matters, and so does time, skill, goods and a word to a friend. Whichever you have to give, there is a place for it here.</p>',
                ]],
                ['type' => 'feature-grid', 'data' => [
                    'heading' => 'Ways to get involved',
                    'columns' => 3,
                    'items' => [
                        ['title' => 'Volunteer', 'body' => 'Give your time, in the field, in the office or at events.', 'url' => '/volunteer'],
                        ['title' => 'Come to an event', 'body' => 'Outreaches, services, fundraisers and trainings.', 'url' => '/events'],
                        ['title' => 'Partner with us', 'body' => 'Churches, schools, clinics and institutions working alongside us.', 'url' => $path('partner-with-us')],
                        ['title' => 'Corporate giving', 'body' => 'Sponsorship, matched giving and payroll giving.', 'url' => $path('corporate-giving')],
                        ['title' => 'Donate goods', 'body' => 'Food, clothing, books, medical supplies, equipment.', 'url' => $path('donate-goods')],
                        ['title' => 'Fundraise for us', 'body' => 'A sponsored walk, a harvest collection, a birthday appeal.', 'url' => $path('fundraise-for-us')],
                    ],
                ]],
                ['type' => 'cta-band', 'data' => [
                    'heading' => 'Or simply give',
                    'body' => 'A gift of any size goes straight to the work.',
                    'cta_label' => 'Donate',
                    'cta_url' => '/donate',
                ]],
            ],

            'partner-with-us' => [
                ['type' => 'rich-text', 'data' => [
                    'body' => '<p>Much of what we do is done with others: a church that knows its community, a clinic that can see the patients we refer, a school that has the classroom. If your organisation works with the people we serve, or could, we would like to hear from you.</p>'
                        .'<p>Tell us what you have in mind. A joint project, a referral arrangement, the use of a facility — say it plainly and we will come back to you.</p>',
                ]],
                ['type' => 'enquiry-form', 'data' => ['kind' => 'partner']],
            ],

            'corporate-giving' => [
                ['type' => 'rich-text', 'data' => [
                    'body' => '<p>Companies support us by sponsoring a project or an event, by matching what their staff give, through payroll giving, or with a one-off gift. Every arrangement comes with a clear account of where the money went.</p>'
                        .'<p>A purchase from our shop is not a donation and is invoiced as a sale; a corporate gift is acknowledged as a gift. We keep the two apart so that your finance team can too.</p>',
                ]],
                ['type' => 'enquiry-form', 'data' => ['kind' => 'corporate']],
            ],

            'donate-goods' => [
                ['type' => 'rich-text', 'data' => [
                    'body' => '<p>We can often use what you have: food, clothing, books, school supplies, medical consumables, equipment. We cannot always use it, and we would rather say so than let something go to waste — so tell us what it is, roughly how much, what condition it is in and where it is, and we will tell you whether we can take it and how to get it to us.</p>'
                        .'<p>Medicines and anything perishable need a conversation first; please do not send them.</p>',
                ]],
                ['type' => 'enquiry-form', 'data' => ['kind' => 'in-kind']],
            ],

            'fundraise-for-us' => [
                ['type' => 'rich-text', 'data' => [
                    'body' => '<p>Some of the most generous gifts we receive are raised by other people: a sponsored walk, a harvest collection, a birthday appeal, a sale at work. If you are planning something, tell us. We can help with materials and wording, give you a reference so the money is recorded against your effort, and thank you properly when it lands.</p>'
                        .'<p>Money raised in cash can be paid in by bank transfer or Mobile Money — see <a href="/give">other ways to give</a> — quoting the reference we send you.</p>',
                ]],
                ['type' => 'enquiry-form', 'data' => ['kind' => 'fundraise']],
            ],
        ];
    }

    /**
     * The policy pages.
     *
     * Each body lives in `database/seeders/content/legal/<slug>.html` — a file
     * the trustees can read and mark up without touching PHP. Every draft
     * opens with a notice saying it is a draft for review; the notice is part
     * of the content so it cannot be published without somebody deleting it
     * on purpose.
     *
     * The pages whose subject is "contact us about this" — privacy,
     * safeguarding, raising a concern, anti-fraud — end with the contact
     * details block, which reads the addresses from settings rather than
     * having them typed into the policy text where they would go stale.
     *
     * @return array<string, array<int, array{type: string, name?: string, data: array<string, mixed>}>>
     */
    private function legal(): array
    {
        $pages = [
            'privacy-policy' => true,
            'terms' => false,
            'donation-policy' => false,
            'refund-policy' => false,
            'shipping-and-delivery' => false,
            'cookie-policy' => false,
            'safeguarding' => true,
            'accessibility' => false,
            'whistleblowing' => true,
            'anti-fraud' => true,
        ];

        $content = [];

        foreach ($pages as $slug => $withContact) {
            $file = __DIR__.'/content/legal/'.$slug.'.html';

            if (! is_file($file)) {
                continue;
            }

            $sections = [
                ['type' => 'rich-text', 'data' => ['body' => trim((string) file_get_contents($file))]],
            ];

            if ($withContact) {
                $sections[] = ['type' => 'contact-details', 'data' => ['heading' => 'How to reach us']];
            }

            $content[$slug] = $sections;
        }

        return $content;
    }
}
