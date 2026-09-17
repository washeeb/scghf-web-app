<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PageStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserType;
use App\Models\BlogCategory;
use App\Models\Cause;
use App\Models\CauseUpdate;
use App\Models\Division;
use App\Models\Donation;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Partner;
use App\Models\Post;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Project;
use App\Models\ProjectUpdate;
use App\Models\Subscriber;
use App\Models\TeamMember;
use App\Models\Testimonial;
use App\Models\User;
use App\Models\Volunteer;
use App\Models\VolunteerOpportunity;
use App\Payments\OfflineDonationService;
use App\ValueObjects\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * A believable foundation for staging, demos and UAT — never for production.
 *
 * `DatabaseSeeder` seeds what the application needs to run: roles, settings,
 * the General Fund, the page tree. It leaves the site empty of the things a
 * tester needs to see it working: projects with progress, appeals partly
 * raised, a shop with stock, gifts across six months, a volunteer roster,
 * a staff member for every role. This seeder adds those, and only those.
 *
 * ── Never in production ─────────────────────────────────────────────────────
 *
 * It refuses to run there, before touching anything. Demo donations are
 * recorded through the real offline-gift service, so they would be real
 * rows in a real ledger with real receipt numbers; there is no "just
 * delete them afterwards" for a ledger.
 *
 * ── Idempotent ──────────────────────────────────────────────────────────────
 *
 * Every record is keyed (a slug, an email), so running it twice on staging
 * adds nothing the second time. The gifts are keyed on the demo donors'
 * emails: if one of them has already given, the giving history is skipped.
 *
 *   php artisan db:seed --class=DemoDataSeeder
 *
 * The demo staff accounts are listed at the end of the output. All share the
 * password `password` and none has two-factor set up, so the first sign-in
 * walks through enrolment exactly as a real first sign-in does.
 */
class DemoDataSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'password';

    /** @var array<string, string> role => email */
    public const STAFF = [
        'Super Admin' => 'demo.superadmin@example.test',
        'Admin' => 'demo.admin@example.test',
        'Content Editor' => 'demo.editor@example.test',
        'Finance Officer' => 'demo.finance@example.test',
        'Shop Manager' => 'demo.shop@example.test',
        'Volunteer Coordinator' => 'demo.volunteers@example.test',
        'Programme Officer' => 'demo.programmes@example.test',
        'Auditor' => 'demo.auditor@example.test',
        'Support' => 'demo.support@example.test',
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException(
                'DemoDataSeeder does not run in production. It records gifts through the real ledger, '
                .'and a demo gift in the production ledger is a gift that never happened on the books.'
            );
        }

        activity()->disableLogging();

        try {
            $staff = $this->staff();
            $division = Division::query()->orderBy('id')->firstOrFail();

            $projects = $this->projects($division, $staff['Programme Officer']);
            $causes = $this->causes($division, $projects);
            $this->posts($staff['Content Editor']);
            $products = $this->products();
            $this->events();
            $this->volunteers();
            $this->people($division);
            $this->giving($causes, $staff['Finance Officer']);
            $this->orders($products);
            $this->subscribers();
        } finally {
            activity()->enableLogging();
        }

        $this->command?->info('Demo staff (password: '.self::DEMO_PASSWORD.'):');
        foreach (self::STAFF as $role => $email) {
            $this->command?->line("  {$role}: {$email}");
        }
    }

    /** @return array<string, User> role => user */
    private function staff(): array
    {
        $users = [];

        foreach (self::STAFF as $role => $email) {
            $user = User::query()->firstOrCreate(['email' => $email], [
                'name' => 'Demo '.$role,
                'password' => Hash::make(self::DEMO_PASSWORD),
                'type' => UserType::Staff,
                'job_title' => $role,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }

            $users[$role] = $user;
        }

        return $users;
    }

    /** @return array<string, Project> */
    private function projects(Division $division, User $author): array
    {
        $rows = [
            ['bongo-school-kits', 'School kits for Bongo District', 'Exercise books, uniforms and sandals for 240 children at three primary schools before the September term.', ProjectStatus::Active, 12_000_000, '2026-06-01', '2026-10-31'],
            ['widows-livelihood-tamale', 'Widows\' livelihood groups, Tamale', 'Shea-butter and soap-making cooperatives for 45 widows, with a starter kit and six months of mentoring.', ProjectStatus::Active, 8_500_000, '2026-03-01', '2027-02-28'],
            ['orphanage-borehole-navrongo', 'A borehole for the Navrongo children\'s home', 'A mechanised borehole and storage tank so 60 children stop fetching water from the dam.', ProjectStatus::Completed, 4_200_000, '2025-11-01', '2026-04-30'],
            ['medical-outreach-upper-east', 'Medical outreach, Upper East', 'Quarterly clinics in six villages: screening, medicines, and referrals to Bolgatanga Regional Hospital.', ProjectStatus::Planned, 6_000_000, '2026-11-01', '2027-10-31'],
        ];

        $projects = [];

        foreach ($rows as $i => [$slug, $title, $summary, $status, $budget, $starts, $ends]) {
            $project = Project::query()->firstOrCreate(['slug' => $slug], [
                'division_id' => $division->id,
                'title' => $title,
                'summary' => $summary,
                'description' => '<p>'.$summary.'</p><p>The foundation works with the district assembly, the schools and the parents\' associations so that what is given is what was asked for.</p>',
                'status' => $status,
                'starts_on' => $starts,
                'ends_on' => $ends,
                'budget' => $budget,
                'currency' => 'GHS',
                'is_published' => true,
                'published_at' => now()->subMonths(4 - $i),
            ]);

            if ($project->updates()->doesntExist()) {
                foreach ([
                    ['Site visit and planning with the community', 90],
                    ['Procurement complete; delivery scheduled', 45],
                    ['First distribution done — thank you', 12],
                ] as [$updateTitle, $daysAgo]) {
                    ProjectUpdate::create([
                        'project_id' => $project->id,
                        'title' => $updateTitle,
                        'body' => '<p>'.$updateTitle.'. Photographs will follow once consent forms are back.</p>',
                        'author_id' => $author->id,
                        'is_published' => true,
                        'published_at' => now()->subDays($daysAgo),
                    ]);
                }
            }

            $projects[$slug] = $project;
        }

        return $projects;
    }

    /**
     * @param  array<string, Project>  $projects
     * @return array<string, Cause>
     */
    private function causes(Division $division, array $projects): array
    {
        $rows = [
            ['back-to-school-2026', 'Back to school 2026', 'GH₵ 50 sends one child to school with everything on the list.', 6_000_000, 'bongo-school-kits'],
            ['navrongo-water', 'Clean water for the Navrongo home', 'Every gift goes to the borehole, the tank and the taps.', 4_200_000, 'orphanage-borehole-navrongo'],
            ['widows-starter-kits', 'Starter kits for 45 widows', 'A kit costs GH₵ 180 and starts a business.', 8_100_000, 'widows-livelihood-tamale'],
        ];

        $causes = [];

        foreach ($rows as $i => [$slug, $title, $summary, $goal, $projectSlug]) {
            $cause = Cause::query()->firstOrCreate(['slug' => $slug], [
                'division_id' => $division->id,
                'project_id' => $projects[$projectSlug]->id ?? null,
                'title' => $title,
                'summary' => $summary,
                'description' => '<p>'.$summary.'</p>',
                'goal' => $goal,
                'currency' => 'GHS',
                'status' => 'active',
                'is_published' => true,
                'published_at' => now()->subMonths(3 - $i),
            ]);

            if ($cause->updates()->doesntExist()) {
                CauseUpdate::create([
                    'cause_id' => $cause->id,
                    'title' => 'Halfway there',
                    'body' => '<p>Thank you to everyone who has given so far. Here is what it has already paid for.</p>',
                    'is_published' => true,
                    'published_at' => now()->subDays(20),
                ]);
            }

            $causes[$slug] = $cause;
        }

        return $causes;
    }

    private function posts(User $author): void
    {
        $category = BlogCategory::query()->firstOrCreate(['slug' => 'from-the-field'], ['name' => 'From the field']);
        $news = BlogCategory::query()->firstOrCreate(['slug' => 'news'], ['name' => 'News']);

        $rows = [
            ['what-gh50-actually-pays-for', 'What GH₵ 50 actually pays for', $category, 3],
            ['a-day-at-bongo-primary', 'A day at Bongo Primary', $category, 12],
            ['how-we-choose-who-we-help', 'How we choose who we help', $news, 30],
            ['where-the-money-went-q2', 'Where the money went: April to June', $news, 48],
            ['volunteering-what-a-saturday-looks-like', 'Volunteering with us: what a Saturday looks like', $category, 70],
            ['giving-by-momo-step-by-step', 'How to give by MoMo, step by step', $news, 95],
        ];

        foreach ($rows as [$slug, $title, $cat, $daysAgo]) {
            Post::query()->firstOrCreate(['slug' => $slug], [
                'blog_category_id' => $cat->id,
                'author_id' => $author->id,
                'title' => $title,
                'excerpt' => $title.' — a short account from the team, with the numbers.',
                'body' => '<p>'.$title.'.</p><p>This is demonstration content. Replace it with the real story before launch.</p>',
                'status' => PageStatus::Published,
                'published_at' => now()->subDays($daysAgo),
            ]);
        }
    }

    /** @return array<int, ProductVariant> */
    private function products(): array
    {
        $category = ProductCategory::query()->orderBy('id')->first();

        $rows = [
            ['tote-bag', 'Greater Hope tote bag', 'Heavy cotton, printed in Accra.', 4_500, 'TOTE-01', 40],
            ['kente-bookmark-set', 'Kente bookmark set', 'Three bookmarks woven by the Tamale cooperative.', 3_000, 'BKM-03', 25],
            ['shea-butter-250g', 'Shea butter, 250 g', 'Unrefined, made by the widows\' group in Tamale.', 2_500, 'SHEA-250', 60],
            ['annual-report-2025-print', 'Annual report 2025 (printed)', 'The full report, posted to you.', 1_500, 'AR-2025', 100],
        ];

        $variants = [];

        foreach ($rows as [$slug, $name, $summary, $price, $sku, $stock]) {
            $product = Product::query()->firstOrCreate(['slug' => $slug], [
                'product_category_id' => $category?->id,
                'name' => $name,
                'summary' => $summary,
                'description' => '<p>'.$summary.'</p><p>Every cedi of profit goes to the General Fund.</p>',
                'product_type' => 'physical',
                'is_published' => true,
                'published_at' => now()->subMonths(2),
            ]);

            $variant = ProductVariant::query()->firstOrCreate(['sku' => $sku], [
                'product_id' => $product->id,
                'name' => 'Standard',
                'price' => $price,
                'currency' => 'GHS',
                'tracks_stock' => true,
                'is_active' => true,
                'weight_grams' => 300,
            ]);

            if ($variant->wasRecentlyCreated) {
                $variant->restock($stock, 'demo data');
            }

            $variants[] = $variant->fresh();
        }

        return $variants;
    }

    private function events(): void
    {
        $rows = [
            ['community-clean-up-bolgatanga', 'Community clean-up, Bolgatanga', now()->addDays(18)->setTime(7, 0), 'Bolgatanga central market', 'Upper East', 80],
            ['donor-evening-accra', 'Donor thank-you evening, Accra', now()->addDays(40)->setTime(18, 30), 'Alisa Hotel, North Ridge', 'Greater Accra', 60],
            ['school-kit-distribution-bongo', 'School kit distribution, Bongo', now()->subDays(25)->setTime(9, 0), 'Bongo D/A Primary', 'Upper East', 200],
        ];

        foreach ($rows as [$slug, $title, $starts, $venue, $region, $capacity]) {
            Event::query()->firstOrCreate(['slug' => $slug], [
                'title' => $title,
                'summary' => $title.'. Everyone welcome; please register so we can plan for you.',
                'description' => '<p>'.$title.'.</p>',
                'event_type' => 'community',
                'starts_at' => $starts,
                'ends_at' => $starts->copy()->addHours(3),
                'venue_name' => $venue,
                'region' => $region,
                'registration_required' => true,
                'capacity' => $capacity,
                'is_published' => true,
                'published_at' => now()->subDays(60),
            ]);
        }
    }

    private function volunteers(): void
    {
        $rows = [
            ['saturday-tutor-bolgatanga', 'Saturday tutor, Bolgatanga', true, 'Upper East'],
            ['events-helper-accra', 'Events helper, Accra', false, 'Greater Accra'],
            ['photographer-any-region', 'Volunteer photographer', false, null],
        ];

        foreach ($rows as [$slug, $title, $vulnerable, $region]) {
            VolunteerOpportunity::query()->firstOrCreate(['slug' => $slug], [
                'title' => $title,
                'summary' => $title.' — two to four hours a week, training provided.',
                'description' => '<p>'.$title.'.</p>',
                'involves_vulnerable_contact' => $vulnerable,
                'placement_type' => 'ongoing',
                'region' => $region,
                'is_published' => true,
                'published_at' => now()->subDays(45),
            ]);
        }

        foreach ([
            ['Abena Osei', 'abena.osei@example.test', '+233241000001', 'Saturday tutor', true],
            ['Kwame Boadu', 'kwame.boadu@example.test', '+233201000002', 'Events helper', false],
            ['Efua Mensah', 'efua.mensah@example.test', '+233271000003', 'Photographer', false],
            ['Yaw Agyeman', 'yaw.agyeman@example.test', '+233551000004', 'Saturday tutor', true],
        ] as [$name, $email, $phone, $role, $vulnerable]) {
            $volunteer = Volunteer::query()->firstOrCreate(['email' => $email], [
                'full_name' => $name,
                'phone' => $phone,
                'role' => $role,
                'status' => Volunteer::STATUS_ACTIVE,
                'started_on' => now()->subMonths(3)->toDateString(),
            ]);

            // Not cleared: there is no application and no police check behind
            // a demo volunteer, and the roster must say so. That is the
            // safeguarding rule showing itself, not a gap in the demo.
            if ($volunteer->wasRecentlyCreated) {
                $volunteer->forceFill(['involves_vulnerable_contact' => $vulnerable])->save();
            }
        }
    }

    private function people(Division $division): void
    {
        foreach ([
            ['Adam Kingsley Washeeb', 'Founder and Executive Director', true, 1],
            ['Rev. Sr. Mary Atinga', 'Trustee', true, 2],
            ['Dr. Kofi Anane', 'Trustee and Medical Adviser', true, 3],
            ['Gifty Abugri', 'Programmes Officer, Upper East', false, 4],
        ] as [$name, $title, $trustee, $sort]) {
            TeamMember::query()->firstOrCreate(['slug' => str($name)->slug()->toString()], [
                'division_id' => $division->id,
                'name' => $name,
                'role_title' => $title,
                'bio' => '<p>'.$name.' — '.$title.'. Demonstration biography.</p>',
                'member_type' => $trustee ? 'trustee' : 'staff',
                'is_trustee' => $trustee,
                'sort_order' => $sort,
                'is_published' => true,
            ]);
        }

        foreach ([
            ['Mma Atampoka', 'Widow, Tamale', 'Tamale', 'The soap I make now pays my grandchildren\'s fees.', 'beneficiary'],
            ['Samuel Adjei', 'Monthly donor since 2025', 'Accra', 'I get a message every time my gift is used. That is why I keep giving.', 'donor'],
            ['Headteacher, Bongo D/A Primary', 'Partner school', 'Bongo', 'Attendance went up the term the kits arrived.', 'partner'],
        ] as [$name, $role, $location, $quote, $type]) {
            Testimonial::query()->firstOrCreate(['author_name' => $name], [
                'division_id' => $division->id,
                'author_role' => $role,
                'author_location' => $location,
                'quote' => $quote,
                'author_type' => $type,
                'has_consent' => true,
                'consent_date' => now()->subMonths(2)->toDateString(),
                'is_published' => true,
                'is_featured' => true,
            ]);
        }

        foreach ([
            ['bolgatanga-municipal-assembly', 'Bolgatanga Municipal Assembly', 'government'],
            ['st-cecilia-parish', 'St. Cecilia\'s Parish', 'church'],
            ['northern-shea-cooperative', 'Northern Shea Cooperative', 'ngo'],
        ] as [$slug, $name, $type]) {
            Partner::query()->firstOrCreate(['slug' => $slug], [
                'division_id' => $division->id,
                'name' => $name,
                'description' => $name.' — demonstration partner.',
                'partner_type' => $type,
                'partnership_started_on' => now()->subYear()->toDateString(),
                'is_published' => true,
            ]);
        }
    }

    /**
     * Six months of gifts, through the real offline-gift service so the
     * ledger and the appeal totals are exactly what a real cash gift
     * produces. `record()`, not `recordAndAcknowledge()`: a receipt is an
     * email, and these addresses belong to nobody.
     *
     * @param  array<string, Cause>  $causes
     */
    private function giving(array $causes, User $finance): void
    {
        $donors = [
            ['Samuel Adjei', 'samuel.adjei@example.test', '+233244000001'],
            ['Akosua Frimpong', 'akosua.frimpong@example.test', '+233204000002'],
            ['Ibrahim Alhassan', 'ibrahim.alhassan@example.test', '+233274000003'],
            ['Grace Nyarko', 'grace.nyarko@example.test', '+233554000004'],
            ['Daniel Owusu-Ansah', 'daniel.owusu@example.test', '+233264000005'],
            ['Ama Serwaa', 'ama.serwaa@example.test', '+233504000006'],
        ];

        if (Donation::query()->whereIn('donor_email', array_column($donors, 1))->exists()) {
            return;
        }

        $service = app(OfflineDonationService::class);
        $general = Cause::query()->where('is_general_fund', true)->first();
        $targets = array_values(array_filter([$general, ...array_values($causes)]));
        $amounts = [2_000, 5_000, 10_000, 15_000, 20_000, 50_000, 100_000];
        $methods = [OfflineDonationService::METHOD_CASH, OfflineDonationService::METHOD_BANK_TRANSFER, OfflineDonationService::METHOD_CHEQUE];

        mt_srand(2026);

        for ($i = 0; $i < 36; $i++) {
            [$name, $email, $phone] = $donors[$i % count($donors)];
            $method = $methods[$i % count($methods)];

            $service->record([
                'amount' => Money::ofMinor($amounts[mt_rand(0, count($amounts) - 1)], 'GHS'),
                'cause' => $targets[$i % count($targets)],
                'donor_name' => $name,
                'donor_email' => $email,
                'donor_phone' => $phone,
                'consent_email' => true,
                'offline_method' => $method,
                'offline_reference' => $method === OfflineDonationService::METHOD_CASH ? null : 'DEMO-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                'received_on' => now()->subDays(mt_rand(1, 180))->toDateString(),
            ], $finance);
        }
    }

    /** @param array<int, ProductVariant> $variants */
    private function orders(array $variants): void
    {
        if ($variants === [] || Order::query()->where('customer_email', 'like', 'demo.buyer%')->exists()) {
            return;
        }

        for ($i = 1; $i <= 8; $i++) {
            $variant = $variants[$i % count($variants)];
            $quantity = 1 + ($i % 2);
            $subtotal = $variant->price->toMinor() * $quantity;
            $pickup = $i % 3 === 0;
            $shipping = $pickup ? 0 : 2_500;

            $order = Order::factory()->paid()->create([
                'customer_name' => 'Demo buyer '.$i,
                'customer_email' => "demo.buyer{$i}@example.test",
                'subtotal' => $subtotal,
                'shipping' => $shipping,
                'discount' => 0,
                'total' => $subtotal + $shipping,
                'is_pickup' => $pickup,
                'paid_at' => now()->subDays($i * 6),
                'created_at' => now()->subDays($i * 6),
            ]);

            OrderItem::create([
                'order_id' => $order->id,
                'product_variant_id' => $variant->id,
                'product_id' => $variant->product_id,
                'product_name' => $variant->product->name,
                'variant_name' => $variant->name,
                'sku' => $variant->sku,
                'quantity' => $quantity,
                'unit_price' => $variant->price->toMinor(),
                'line_total' => $subtotal,
                'currency' => 'GHS',
                'weight_grams' => $variant->weight_grams,
            ]);
        }
    }

    private function subscribers(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $email = "demo.reader{$i}@example.test";

            if (Subscriber::query()->where('email', $email)->exists()) {
                continue;
            }

            Subscriber::factory()->confirmed()->create([
                'email' => $email,
                'name' => 'Demo reader '.$i,
                'source' => 'footer',
            ]);
        }
    }
}
