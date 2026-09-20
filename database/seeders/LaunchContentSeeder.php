<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Console\Commands\LaunchImages;
use App\Enums\PageStatus;
use App\Enums\ProjectStatus;
use App\Models\BlogCategory;
use App\Models\Cause;
use App\Models\Division;
use App\Models\Event;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\FocusArea;
use App\Models\Gallery;
use App\Models\GalleryItem;
use App\Models\ImpactMetric;
use App\Models\ImpactMetricValue;
use App\Models\Page;
use App\Models\Post;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\ProjectUpdate;
use App\Models\TeamMember;
use App\Models\Testimonial;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Database\Seeder;

/**
 * The site as it launches: the foundation's own words, and placeholder
 * programmes to be replaced as the real ones happen.
 *
 * ── What is real and what is placeholder ────────────────────────────────────
 *
 * Real, from the foundation profile: the vision, the mission, the purpose
 * ("to turn remembrance into impact"), the seven values, the four
 * divisions and their focus areas, the beneficiary groups, the ways to
 * support, the founder, the name it honours. Nothing in that set is
 * invented here, and none of it is marked as placeholder.
 *
 * Placeholder, written in the foundation's voice so the site reads as a
 * whole: the programmes, appeals, news, events, testimonials and impact
 * goals. The profile names the kinds of work but not a single dated
 * programme, so these describe work the divisions are set up to do, in
 * places Ghanaian readers will recognise, at sizes a new foundation could
 * carry. Every one of them carries `[Placeholder]` in a field staff will
 * see when they edit it, and the launch check counts them — the site
 * cannot pass its launch checklist while one remains.
 *
 * Photographs are the licensed launch set (`scghf:launch-images`), each
 * addressed by the slot it fills. A slot that has no picture yet is
 * simply left empty: text seeds without images, never the other way
 * round.
 *
 * ── Idempotent, and never over an editor ────────────────────────────────────
 *
 * Every record is keyed by slug and created only if absent. A page that
 * already has sections is left exactly as it is — the seeder gives first
 * drafts, it does not take edits back. Run it on every environment; run
 * it twice and nothing changes. A record staff have trashed still owns its
 * slug and is left in the bin — trashing a placeholder is a decision too.
 *
 *     php artisan scghf:launch-images
 *     php artisan db:seed --class=LaunchContentSeeder
 */
class LaunchContentSeeder extends Seeder
{
    /** The marker staff see on every invented record. The launch check counts it. */
    public const PLACEHOLDER = '[Placeholder — replace with the real thing]';

    /** Written to the settings once the seeder has run; DemoDataSeeder reads it. */
    public const SEEDED_AT = 'content.launch_seeded_at';

    /** @var array<string, Division> */
    private array $divisions = [];

    public function run(): void
    {
        $this->divisions = Division::query()->get()->keyBy('slug')->all();

        if (count($this->divisions) < 4) {
            $this->command?->error('Run DatabaseSeeder first: the four divisions are missing.');

            return;
        }

        $this->settings();
        $this->divisionImages();
        $projects = $this->projects();
        $this->causes($projects);
        $this->posts();
        $this->events();
        $this->testimonials();
        $metrics = $this->metrics();
        $this->faqs();
        $gallery = $this->gallery();
        $this->team();
        $this->pages($metrics, $gallery);

        app(Settings::class)->set(self::SEEDED_AT, now()->toIso8601String());
        app(Settings::class)->flush();

        $this->command?->info('Launch content seeded. Placeholder programmes, appeals, news, events and testimonials are marked and counted by the launch check.');
    }

    /** The foundation's own picture: `LaunchImages` slot → media id, or null when the slot has no picture yet. */
    private function image(string $slot): ?int
    {
        return LaunchImages::forSlot($slot)?->getKey();
    }

    private function division(string $slug): Division
    {
        return $this->divisions[$slug];
    }

    // ── Settings the profile fills in ────────────────────────────────────────

    private function settings(): void
    {
        $settings = app(Settings::class);

        // The seven values, from the profile, verbatim. Only if still the placeholder.
        if ($settings->get('general.core_values') === null) {
            $settings->set('general.core_values', ['Faith', 'Compassion', 'Love', 'Dignity', 'Service', 'Integrity', 'Legacy']);
        }

        $settings->flush();
    }

    private function divisionImages(): void
    {
        foreach ([
            'life-spring' => 'division-health',
            'brightpath' => 'division-education',
            'legacy-of-love' => 'division-legacy',
            'every-soul-missions' => 'division-missions',
        ] as $slug => $slot) {
            $division = $this->division($slug);

            if ($division->hero_image_id === null && ($id = $this->image($slot)) !== null) {
                $division->forceFill(['hero_image_id' => $id])->save();
            }
        }
    }

    // ── Programmes ───────────────────────────────────────────────────────────

    /** @return array<string, Project> */
    private function projects(): array
    {
        $rows = [
            // slug, division, focus area, title, summary, body, status, starts, ends, budget (pesewas), image slot, region, district, community
            ['community-health-screening-days', 'life-spring', 'Community health screening',
                'Community health screening days',
                'Free blood-pressure, blood-sugar and malaria screening in communities far from a clinic, with a nurse on hand and a referral letter for anyone who needs one.',
                '<p>A screening day is a table under a tree, two nurses, a community health volunteer who knows every household, and a morning. People who have never had their blood pressure taken find out where they stand; the few who need a doctor leave with a referral letter and, where the fare is the obstacle, the fare.</p><p>Each day is planned with the district health directorate so that it adds to the public system rather than sitting beside it.</p>',
                ProjectStatus::Active, '2026-10-01', '2027-12-31', 3_600_000, 'health-project-1', 'Upper East', 'Bolgatanga', 'Sumbrungu'],
            ['maternal-and-child-health-awareness', 'life-spring', 'Maternal and child health awareness',
                'Safe motherhood sessions',
                'Monthly sessions for expectant and new mothers on antenatal care, nutrition, immunisation and warning signs, led by a midwife, with a small kit for each mother who completes the course.',
                '<p>The sessions are held where mothers already gather — the clinic on weighing day, the church hall, the market — and are run by a midwife the community knows. The kit at the end is modest: a mosquito net, soap, a thermometer and the immunisation card, filled in together.</p>',
                ProjectStatus::Active, '2026-10-01', '2028-02-28', 2_400_000, 'health-project-2', 'Northern', 'Tamale Metropolitan', 'Nyohini'],

            ['brightpath-scholarships', 'brightpath', 'Scholarships and bursaries',
                'BrightPath scholarships',
                'Fees, uniforms and books for needy but promising students through senior high school, chosen with their heads of school and followed all the way to graduation.',
                '<p>A BrightPath scholar is a student whose results say they should stay in school and whose home says they cannot. The school nominates, the foundation visits, and the award covers what the family cannot: fees, the uniform, the books, and the small things that quietly push a child out — the exam registration, the calculator, the fare home at the end of term.</p><p>Every scholar has a mentor and a termly check-in. The award follows the student, not the year.</p>',
                ProjectStatus::Active, '2026-10-01', '2029-12-31', 9_000_000, 'education-project-1', 'Greater Accra', 'Ga West', 'Amasaman'],
            ['school-supplies-drive', 'brightpath', 'School supplies and learning materials',
                'Back-to-school supplies drive',
                'Exercise books, pens, mathematical sets and sandals for pupils at partner primary schools before the September term, so that no child starts the year without the basics.',
                '<p>Teachers tell us which children arrive without exercise books; we make sure they have them before the first lesson. The supplies are bought locally, packed by volunteers and handed over at the school with the parents present.</p>',
                ProjectStatus::Active, '2026-10-01', '2027-09-30', 1_800_000, 'education-project-2', 'Eastern', 'New Juaben South', 'Koforidua'],

            ['widows-livelihood-training', 'legacy-of-love', 'Livelihood and skills training',
                'Widows\' livelihood training',
                'Six months of sewing, soap-making or trading skills for widows supporting children alone, with a starter kit at the end and a savings group that continues after the course.',
                '<p>A widow with young children and no income has very few choices, and most of them are bad. The course meets twice a week, teaches a trade the local market will pay for, and ends with the tools to practise it. The savings group formed during the course is where the real support continues.</p>',
                ProjectStatus::Active, '2026-10-01', '2027-08-31', 4_200_000, 'legacy-project-1', 'Ashanti', 'Kumasi Metropolitan', 'Asawase'],
            ['bereaved-families-support', 'legacy-of-love', 'Support for bereaved families',
                'Walking with bereaved families',
                'Visits, food and household support in the first months after a death, and a small fund for the funeral costs that push a grieving family into debt.',
                '<p>The foundation is named for a mother who was loved, and this is the division that remembers what loss costs. A volunteer visits within the first week, food and household support follow for three months, and where a funeral has left debt the fund helps to close it.</p>',
                ProjectStatus::Active, '2026-10-01', '2027-12-31', 3_000_000, 'legacy-project-2', 'Central', 'Cape Coast Metropolitan', 'Abura'],

            ['community-outreach-and-bible-distribution', 'every-soul-missions', 'Bible distribution',
                'Community outreach and Bible distribution',
                'Open-air outreach with partner churches, and Bibles and Christian literature in Twi, Dagbani, Ewe and English for anyone who asks.',
                '<p>Every outreach is held with a local church that will still be there the following Sunday. The foundation brings the Bibles, the literature and the sound; the church brings its people and its welcome.</p>',
                ProjectStatus::Active, '2026-10-01', '2027-12-31', 2_000_000, 'missions-project-1', 'Volta', 'Ho Municipal', 'Ho'],
            ['hospital-and-home-visits', 'every-soul-missions', 'Hospital and home visits',
                'Hospital and home visits',
                'Trained volunteers who visit patients on the wards and the housebound at home: prayer, a listening ear, and practical help with the things a stay in hospital leaves undone.',
                '<p>Volunteers are trained in listening before they are trained in anything else, and every one has a safeguarding check. They visit in pairs, they come back, and they carry a small fund for the practical things — a phone credit, a meal, the fare for a relative.</p>',
                ProjectStatus::Active, '2026-10-01', '2028-01-31', 1_500_000, 'missions-project-2', 'Greater Accra', 'Accra Metropolitan', 'Korle Bu'],
        ];

        $projects = [];
        $lead = User::query()->where('type', 'staff')->orderBy('id')->first();

        foreach ($rows as $i => [$slug, $divisionSlug, $focusArea, $title, $summary, $body, $status, $starts, $ends, $budget, $slot, $region, $district, $community]) {
            $division = $this->division($divisionSlug);

            $project = Project::withTrashed()->firstOrCreate(['slug' => $slug], [
                'division_id' => $division->id,
                'title' => $title,
                'summary' => $summary,
                'description' => $body.'<p><em>'.self::PLACEHOLDER.'</em></p>',
                'status' => $status,
                'starts_on' => $starts,
                'ends_on' => $ends,
                'budget' => $budget,
                'currency' => 'GHS',
                'featured_image_id' => $this->image($slot),
                'lead_id' => $lead?->id,
                'is_featured' => $i < 4,
                'is_published' => true,
                'published_at' => now()->subDays(30 - $i),
            ]);

            if ($project->wasRecentlyCreated) {
                ProjectLocation::create([
                    'project_id' => $project->id,
                    'name' => $community.', '.$district,
                    'region' => $region,
                    'district' => $district,
                    'community' => $community,
                    'is_primary' => true,
                    'sort_order' => 0,
                ]);

                $area = FocusArea::query()->where('division_id', $division->id)->where('name', $focusArea)->first();

                if ($area !== null) {
                    $project->focusAreas()->syncWithoutDetaching([$area->id]);
                }

                ProjectUpdate::create([
                    'project_id' => $project->id,
                    'title' => 'Under way: planning with the community',
                    'body' => '<p>We are meeting the community, the district and the partners who will carry this with us. The first dates will be published here.</p>',
                    'author_id' => $lead?->id,
                    'is_published' => true,
                    'published_at' => now()->subDays(7),
                ]);
            }

            $projects[$slug] = $project;
        }

        return $projects;
    }

    /** @param array<string, Project> $projects */
    private function causes(array $projects): void
    {
        $rows = [
            // slug, division, project, title, summary, goal (pesewas), image slot, urgent
            ['a-clinic-day-for-a-village', 'life-spring', 'community-health-screening-days',
                'A clinic day for a village',
                'GH₵ 3,000 pays for one screening day: two nurses, the test strips, the medicines for what they find, and the fares for the people who need the hospital.',
                3_600_000, 'appeal-health', false],
            ['keep-a-student-in-school', 'brightpath', 'brightpath-scholarships',
                'Keep a student in school',
                'GH₵ 1,200 keeps one BrightPath scholar in senior high school for a term — fees, books and uniform — and GH₵ 3,600 for the year.',
                9_000_000, 'appeal-education', true],
            ['a-starter-kit-for-a-widow', 'legacy-of-love', 'widows-livelihood-training',
                'A starter kit for a widow',
                'GH₵ 850 is a sewing machine, or a soap-making kit, or trading stock: what a widow leaves the course with, and the beginning of an income of her own.',
                4_200_000, 'appeal-legacy', false],
            ['bibles-for-an-outreach', 'every-soul-missions', 'community-outreach-and-bible-distribution',
                'Bibles for an outreach',
                'GH₵ 45 is one Bible in the reader\'s own language. An outreach with a partner church gives away two hundred.',
                2_000_000, 'appeal-missions', false],
        ];

        foreach ($rows as $i => [$slug, $divisionSlug, $projectSlug, $title, $summary, $goal, $slot, $urgent]) {
            Cause::withTrashed()->firstOrCreate(['slug' => $slug], [
                'division_id' => $this->division($divisionSlug)->id,
                'project_id' => $projects[$projectSlug]->id ?? null,
                'title' => $title,
                'summary' => $summary,
                'description' => '<p>'.$summary.'</p><p>Every gift to this appeal is restricted to it. If it is over-subscribed, the surplus goes to the same division\'s fund, and the appeal page says so.</p><p><em>'.self::PLACEHOLDER.'</em></p>',
                'goal' => $goal,
                'currency' => 'GHS',
                'starts_on' => now()->toDateString(),
                'ends_on' => now()->addMonths(12)->toDateString(),
                'status' => 'active',
                'is_urgent' => $urgent,
                'featured_image_id' => $this->image($slot),
                'is_featured' => true,
                'is_published' => true,
                'published_at' => now()->subDays(20 - $i),
                'sort_order' => $i + 1,
            ]);
        }

        // The General Fund exists from the base seed; give it its picture and
        // a description if nobody has written one.
        $general = Cause::query()->where('is_general_fund', true)->first();

        if ($general !== null) {
            $general->forceFill(array_filter([
                'featured_image_id' => $general->featured_image_id ?? $this->image('appeal-general'),
                'summary' => $general->summary ?: 'Where the need is greatest. An unrestricted gift lets the trustees put money where a restricted appeal cannot reach: the emergency, the gap, the cost nobody budgeted for.',
            ], static fn (mixed $v): bool => $v !== null))->save();
        }
    }

    // ── News and events ──────────────────────────────────────────────────────

    private function posts(): void
    {
        $news = BlogCategory::withTrashed()->firstOrCreate(['slug' => 'news'], ['name' => 'News']);
        $field = BlogCategory::withTrashed()->firstOrCreate(['slug' => 'from-the-field'], ['name' => 'From the field']);
        $author = User::query()->where('type', 'staff')->orderBy('id')->first();

        $rows = [
            ['why-we-exist', 'news', null, 'Why we exist', 'post-1',
                'A foundation named for a mother, and what it means to turn remembrance into impact.',
                '<p>St. Cecilia\'s Greater Hope Foundations is named in honour of Mrs Cecilia Anyatuik Adam. She was a mother whose care did not stop at her own door, and the foundation is the shape her family gave to remembering her: not a plaque, but work.</p><p>The work has four parts, because her care had four parts. Health, through the Life Spring Foundation. Education, through the BrightPath Fund Initiative. Orphans, widows and widowers, through the Legacy of Love Initiative. And the love of Christ, through Every Soul Missions.</p><p>Our purpose is to turn remembrance into impact. This site is where you will see whether we do.</p>', 40],
            ['how-we-choose-what-to-support', 'news', null, 'How we choose what to support', 'post-3',
                'Four questions every proposal has to answer before the foundation spends a cedi.',
                '<p>Is it within one of the four divisions? Is there a partner on the ground — a clinic, a school, a church — who will still be there when we are not? Can we say, before we start, what "done" looks like? And will we be able to show donors where the money went, line by line?</p><p>A proposal that answers all four goes to the trustees. One that cannot is not a bad idea; it is not yet ours.</p>', 24],
            ['what-a-gift-pays-for', 'from-the-field', 'brightpath', 'What your gift actually pays for', 'post-4',
                'The real prices behind the appeals, so that a gift of GH₵ 50 means something specific.',
                '<p>We publish what things cost because a donor deserves to know what their money became. A term of senior high school fees. A set of exercise books. A mosquito net. A Bible in Dagbani. The appeal pages carry the current prices, and the annual report will carry the receipts.</p><p><em>'.self::PLACEHOLDER.'</em></p>', 12],
            ['volunteers-welcome', 'from-the-field', null, 'Volunteers: what we need, and what we ask of you', 'post-2',
                'Time and skill are as welcome as money — and every volunteer who works with children or vulnerable adults is checked first.',
                '<p>Nurses for a screening day. Teachers for a mentoring afternoon. Drivers, packers, counters, people who can sit with somebody who is grieving. The volunteer page lists what is open.</p><p>Everybody who volunteers with children or vulnerable adults completes a safeguarding check before they start, without exception. That is not a doubt about anyone; it is a promise to the people we serve.</p>', 5],
        ];

        foreach ($rows as [$slug, $categorySlug, $divisionSlug, $title, $slot, $excerpt, $body, $daysAgo]) {
            Post::withTrashed()->firstOrCreate(['slug' => $slug], [
                'division_id' => $divisionSlug ? $this->division($divisionSlug)->id : null,
                'blog_category_id' => $categorySlug === 'news' ? $news->id : $field->id,
                'author_id' => $author?->id,
                'title' => $title,
                'excerpt' => $excerpt,
                'body' => $body,
                'featured_image_id' => $this->image($slot),
                'status' => PageStatus::Published,
                'is_featured' => in_array($slug, ['why-we-exist', 'how-we-choose-what-to-support'], true),
                'published_at' => now()->subDays($daysAgo),
            ]);
        }
    }

    private function events(): void
    {
        $rows = [
            ['community-health-screening-day', 'life-spring', 'Community health screening day', 'event-1',
                'Free blood-pressure, blood-sugar and malaria screening, with a nurse on hand. Bring your family; bring a neighbour.',
                now()->addDays(45)->setTime(8, 0), 'Sumbrungu Community Centre', 'Upper East', 300],
            ['thanksgiving-and-outreach-service', 'every-soul-missions', 'Thanksgiving and outreach service', 'event-2',
                'An open-air service with our partner churches, and Bibles for anyone who would like one.',
                now()->addDays(75)->setTime(15, 0), 'Ho Jubilee Park', 'Volta', 500],
        ];

        foreach ($rows as [$slug, $divisionSlug, $title, $slot, $summary, $starts, $venue, $region, $capacity]) {
            Event::withTrashed()->firstOrCreate(['slug' => $slug], [
                'division_id' => $this->division($divisionSlug)->id,
                'title' => $title,
                'summary' => $summary,
                'description' => '<p>'.$summary.'</p><p>Everyone is welcome. Registering helps us plan for numbers; it is not a condition of coming.</p><p><em>'.self::PLACEHOLDER.'</em></p>',
                'event_type' => 'community',
                'starts_at' => $starts,
                'ends_at' => $starts->copy()->addHours(4),
                'venue_name' => $venue,
                'region' => $region,
                'registration_required' => false,
                'capacity' => $capacity,
                'featured_image_id' => $this->image($slot),
                'is_featured' => true,
                'is_published' => true,
                'published_at' => now()->subDays(3),
            ]);
        }
    }

    // ── Voices, numbers, answers ─────────────────────────────────────────────

    private function testimonials(): void
    {
        $rows = [
            ['Ama, mother of two', 'Safe motherhood sessions', 'Tamale', 'life-spring', 'testimonial-1', 'beneficiary',
                'Nobody had ever explained the danger signs to me. Now I know when to go, and I know they will take me seriously.'],
            ['Kwame, tailor', 'Widows\' livelihood training', 'Kumasi', 'legacy-of-love', 'testimonial-2', 'partner',
                'I teach the sewing class. Six months later I see my students at the market with their own machines. That is the whole point.'],
        ];

        foreach ($rows as $i => [$name, $role, $location, $divisionSlug, $slot, $type, $quote]) {
            Testimonial::withTrashed()->firstOrCreate(['author_name' => $name], [
                'division_id' => $this->division($divisionSlug)->id,
                'author_role' => $role.' — '.self::PLACEHOLDER,
                'author_location' => $location,
                'quote' => $quote,
                'author_type' => $type,
                'photo_id' => $this->image($slot),
                // A placeholder voice, not a person: there is nobody whose consent
                // this could be, and the model refuses to publish a beneficiary's
                // words without one. So it stays unpublished — the shape for staff
                // to fill with a real voice and a real consent — and the block
                // hides itself while there is nothing published to show.
                'has_consent' => false,
                'sort_order' => $i,
                'is_published' => false,
                'is_featured' => true,
            ]);
        }
    }

    /** @return array<int, ImpactMetric> */
    private function metrics(): array
    {
        $rows = [
            ['people-screened', 'life-spring', 'People screened', 'people', 'heroicon-o-heart', 1_200],
            ['students-supported', 'brightpath', 'Students kept in school', 'students', 'heroicon-o-academic-cap', 60],
            ['widows-trained', 'legacy-of-love', 'Widows with a trade', 'women', 'heroicon-o-hand-raised', 90],
            ['bibles-given', 'every-soul-missions', 'Bibles given', 'Bibles', 'heroicon-o-book-open', 2_000],
        ];

        $metrics = [];

        foreach ($rows as $i => [$slug, $divisionSlug, $name, $unit, $icon, $target]) {
            $metric = ImpactMetric::withTrashed()->firstOrCreate(['slug' => $slug], [
                'division_id' => $this->division($divisionSlug)->id,
                'name' => $name,
                'description' => 'Our goal for the first full year of programmes. '.self::PLACEHOLDER,
                'unit' => $unit,
                'value_type' => ImpactMetric::TYPE_INTEGER,
                'aggregation' => ImpactMetric::AGGREGATION_SUM,
                'counts_people' => $unit !== 'Bibles',
                'target_value' => $target,
                'icon' => $icon,
                'sort_order' => $i,
                'is_public' => true,
                'is_featured' => true,
            ]);

            // The number shown is the goal, and the block's heading says so. A
            // new foundation has no achievements to publish yet, and inventing
            // some would be the one thing this seeder must never do.
            if ($metric->wasRecentlyCreated) {
                ImpactMetricValue::create([
                    'impact_metric_id' => $metric->id,
                    'period_start' => now()->startOfYear()->toDateString(),
                    'period_end' => now()->endOfYear()->toDateString(),
                    'value' => $target,
                    'notes' => 'Goal, not a result. '.self::PLACEHOLDER,
                    'source' => 'Launch plan',
                ]);
            }

            $metrics[] = $metric;
        }

        return $metrics;
    }

    private function faqs(): void
    {
        $category = fn (string $name): ?FaqCategory => FaqCategory::query()->where('slug', str($name)->slug()->toString())->first();

        $rows = [
            ['Giving', 'How do I give by mobile money?', '<p>Choose an amount on the donate page and pick MoMo at the payment step. You will get a prompt on your phone to approve; the receipt follows by email or SMS within a minute of the network confirming.</p>'],
            ['Giving', 'Will I get a receipt?', '<p>Yes — every completed gift gets a numbered acknowledgement by email, and you can download it again from your account at any time.</p>'],
            ['Giving', 'Can I choose which division my gift supports?', '<p>Yes. Every appeal belongs to a division, and each division has its own fund. A gift to the General Fund is unrestricted and goes where the trustees judge the need is greatest.</p>'],
            ['Our work', 'Where does the foundation work?', '<p>Across Ghana, through partners on the ground: clinics, schools, churches and district assemblies. The project pages show where each programme is.</p>'],
            ['Our work', 'Is the foundation a church?', '<p>No. It is a registered Ghanaian foundation with an explicitly Christian basis. Every Soul Missions is one of four divisions; the other three serve anyone in need, of any faith or none.</p>'],
            ['Volunteering', 'Do I need a safeguarding check to volunteer?', '<p>If your role brings you into contact with children or vulnerable adults, yes, before you start. The volunteer page says which roles that applies to.</p>'],
        ];

        foreach ($rows as $i => [$categoryName, $question, $answer]) {
            Faq::withTrashed()->firstOrCreate(['question' => $question], [
                'faq_category_id' => $category($categoryName)?->id,
                'answer' => $answer,
                'sort_order' => $i,
                'is_published' => true,
                'is_featured' => $i < 3,
            ]);
        }
    }

    private function gallery(): ?Gallery
    {
        $slots = ['gallery-1', 'gallery-2', 'gallery-3', 'gallery-4', 'gallery-5', 'gallery-6'];
        $ids = array_values(array_filter(array_map(fn (string $s) => $this->image($s), $slots)));

        if ($ids === []) {
            return null;
        }

        $gallery = Gallery::withTrashed()->firstOrCreate(['slug' => 'life-in-the-communities-we-serve'], [
            'title' => 'Life in the communities we serve',
            'description' => 'Licensed photography from the launch, to be replaced by our own as programmes begin. '.self::PLACEHOLDER,
            'cover_id' => $ids[0],
            'has_consent' => true,
            'is_published' => true,
        ]);

        if ($gallery->wasRecentlyCreated) {
            foreach ($ids as $i => $id) {
                GalleryItem::create(['gallery_id' => $gallery->id, 'media_id' => $id, 'sort_order' => $i]);
            }
        }

        return $gallery;
    }

    private function team(): void
    {
        TeamMember::withTrashed()->firstOrCreate(['slug' => 'adam-kingsley-washeeb'], [
            'name' => 'Adam Kingsley Washeeb',
            'role_title' => 'Founder',
            'bio' => '<p>Founder of St. Cecilia\'s Greater Hope Foundations, established in honour of his late mother, Mrs Cecilia Anyatuik Adam.</p>',
            'member_type' => 'trustee',
            'is_trustee' => true,
            'sort_order' => 0,
            'is_published' => true,
        ]);
    }

    // ── Pages ────────────────────────────────────────────────────────────────

    /** @param array<int, ImpactMetric> $metrics */
    private function pages(array $metrics, ?Gallery $gallery): void
    {
        $metricIds = array_map(fn (ImpactMetric $m): int => $m->id, $metrics);
        $path = fn (string $slug): string => (string) (Page::query()->where('slug', $slug)->value('path') ?? '/'.$slug);

        $content = [
            'home' => [
                ['type' => 'hero', 'data' => [
                    'eyebrow' => 'St. Cecilia\'s Greater Hope Foundations',
                    'heading' => 'Turning remembrance into impact',
                    'subheading' => 'Hope, healing, education, care and the love of Christ for vulnerable people, families and communities across Ghana.',
                    'image' => $this->image('home-hero'),
                    'image_mobile' => $this->image('home-hero'),
                    'primary_cta_label' => 'Give today',
                    'primary_cta_url' => '/donate',
                    'secondary_cta_label' => 'What we do',
                    'secondary_cta_url' => '/what-we-do',
                    'overlay_opacity' => 50,
                ]],
                ['type' => 'divisions', 'data' => [
                    'eyebrow' => 'How we help',
                    'heading' => 'Four ways we serve',
                    'intro' => 'Health, education, care for orphans, widows and widowers, and the gospel — one foundation, four divisions, each with its own fund.',
                    'show_focus_areas' => true,
                ]],
                ['type' => 'split-content', 'data' => [
                    'eyebrow' => 'Our story',
                    'heading' => 'Named for a mother. Built for a community.',
                    'body' => '<p>Mrs Cecilia Anyatuik Adam cared for people the way a mother does — without being asked, and without keeping count. The foundation that carries her name does the same work, in the open, with the accounts published.</p>',
                    'image' => $this->image('about-legacy'),
                    'image_position' => 'left',
                    'cta_label' => 'Read our story',
                    'cta_url' => $path('our-story'),
                ]],
                ['type' => 'impact-stats', 'data' => [
                    'eyebrow' => 'Where we are going',
                    'heading' => 'Our goals for the first full year',
                    'metric_ids' => $metricIds,
                    'show_as_of_date' => false,
                ], 'settings' => ['background' => 'brand', 'padding' => 'large']],
                ['type' => 'featured-causes', 'data' => [
                    'eyebrow' => 'Featured appeals',
                    'heading' => 'Appeals that need you now',
                    'intro' => 'Each appeal names what a gift pays for, and every cedi given to it stays with it.',
                    'limit' => 3,
                    'cta_label' => 'Donate now',
                ]],
                ['type' => 'donation-widget', 'data' => [
                    'eyebrow' => 'Make a gift',
                    'heading' => 'Make an impact all year long',
                    'intro' => 'Choose an amount and how often. Card or mobile money; a receipt every time.',
                    'show_frequency_toggle' => true,
                ], 'settings' => ['background' => 'brand', 'padding' => 'large', 'width' => 'narrow']],
                ['type' => 'featured-projects', 'data' => [
                    'eyebrow' => 'On the ground',
                    'heading' => 'Work under way',
                    'intro' => 'Programmes under way with the communities and partners who carry them with us.',
                    'limit' => 3,
                ]],
                ['type' => 'testimonials', 'data' => ['eyebrow' => 'Testimonials', 'heading' => 'In their words', 'limit' => 2, 'layout' => 'grid']],
                ['type' => 'gallery', 'data' => ['eyebrow' => 'In pictures', 'heading' => 'Life in the communities we serve', 'gallery_id' => $gallery?->id, 'layout' => 'mosaic']],
                ['type' => 'faq', 'data' => [
                    'eyebrow' => 'Common questions',
                    'heading' => 'Frequently asked questions',
                    'image' => $this->image('gallery-3'),
                ]],
                ['type' => 'cta-band', 'data' => [
                    'eyebrow' => 'Give hope',
                    'heading' => 'Give hope today',
                    'body' => 'A gift of any size, by card or mobile money, goes straight to the work. You will see where it went.',
                    'cta_label' => 'Donate',
                    'cta_url' => '/donate',
                    'background' => 'image',
                    'image' => $this->image('home-cta'),
                ]],
                ['type' => 'newsletter', 'data' => [
                    'eyebrow' => 'Newsletter',
                    'heading' => 'Hear how it goes',
                    'intro' => 'One email a month: what happened, what it cost, what is next. No more than that.',
                ]],
            ],

            'about' => [
                ['type' => 'page-header', 'data' => [
                    'heading' => 'About the Foundation',
                    'subheading' => 'A Ghanaian foundation, formed on 25 October 2025 and registered on 15 January 2026, in honour of Mrs Cecilia Anyatuik Adam.',
                    'image' => $this->image('about-header'),
                ]],
                ['type' => 'rich-text', 'data' => [
                    'heading' => 'Vision',
                    'body' => '<p>To become a trusted Ghanaian foundation that brings hope, healing, education, care, and the love of Christ to vulnerable individuals, families, and communities.</p><h3>Mission</h3><p>To honour and continue the legacy of Mrs Cecilia Anyatuik Adam by providing compassionate support in health, education, family welfare, and evangelism, while restoring dignity and hope to people in need.</p><h3>Purpose</h3><p>To turn remembrance into impact.</p>',
                ]],
                ['type' => 'core-values', 'data' => ['heading' => 'What we hold to', 'intro' => 'Seven values, from the foundation\'s profile, that every programme is measured against.']],
                ['type' => 'divisions', 'data' => ['heading' => 'The four divisions', 'show_focus_areas' => true]],
                ['type' => 'feature-grid', 'data' => [
                    'heading' => 'Who we serve',
                    'columns' => 2,
                    'items' => [
                        ['title' => 'Orphans and vulnerable children', 'body' => 'Care, schooling and someone who checks on them.', 'icon' => 'face-smile'],
                        ['title' => 'Widows and widowers', 'body' => 'Support after loss, and a way to earn.', 'icon' => 'hand-raised'],
                        ['title' => 'Needy students', 'body' => 'Fees, books and a mentor through to graduation.', 'icon' => 'academic-cap'],
                        ['title' => 'Low-income families', 'body' => 'Food, household support and health outreach.', 'icon' => 'home'],
                        ['title' => 'Sick and vulnerable individuals', 'body' => 'Screening, referral and a visit on the ward.', 'icon' => 'beaker'],
                        ['title' => 'Elderly persons', 'body' => 'Wellness, company and practical help at home.', 'icon' => 'users'],
                        ['title' => 'Families affected by loss', 'body' => 'Presence in the first months, and help with what a funeral costs.', 'icon' => 'heart'],
                        ['title' => 'Communities needing spiritual encouragement', 'body' => 'Outreach, prayer and the Scriptures in their own language.', 'icon' => 'sparkles'],
                    ],
                ]],
                ['type' => 'team', 'data' => ['heading' => 'Leadership']],
                ['type' => 'cta-band', 'data' => ['heading' => 'Work with us', 'body' => 'Partner, volunteer, give — there is more than one way to help.', 'cta_label' => 'Get involved', 'cta_url' => $path('get-involved')]],
            ],

            'our-story' => [
                ['type' => 'page-header', 'data' => ['heading' => 'Our story', 'subheading' => 'Remembrance, turned into work.', 'image' => $this->image('about-legacy')]],
                ['type' => 'rich-text', 'data' => [
                    'body' => '<p>St. Cecilia\'s Greater Hope Foundations is named in honour of Mrs Cecilia Anyatuik Adam, the founder\'s late mother. It was formed on 25 October 2025 and registered on 15 January 2026.</p><p>The foundation\'s purpose is to turn remembrance into impact. Its work has four parts — health, education, care for orphans, widows and widowers, and evangelism — because those were the parts of a life spent caring for people, and each is carried by a division with its own name, its own focus areas and its own fund.</p><p>The motto is four words: <strong>Faith. Compassion. Service. Hope.</strong></p>',
                ]],
                ['type' => 'split-content', 'data' => [
                    'heading' => 'What "trusted" means to us',
                    'body' => '<p>Objective eight of the foundation\'s nine is to operate with transparency and accountability. In practice: every gift acknowledged with a number, every programme with a published budget, the accounts published, and the policies on this site rather than in a drawer.</p>',
                    'image' => $this->image('transparency-header'),
                    'image_position' => 'left',
                    'cta_label' => 'Transparency and accountability',
                    'cta_url' => $path('transparency'),
                ]],
            ],

            'vision-mission' => [
                ['type' => 'page-header', 'data' => ['heading' => 'Vision and mission']],
                ['type' => 'rich-text', 'data' => [
                    'body' => '<h3>Vision</h3><p>To become a trusted Ghanaian foundation that brings hope, healing, education, care, and the love of Christ to vulnerable individuals, families, and communities.</p><h3>Mission</h3><p>To honour and continue the legacy of Mrs Cecilia Anyatuik Adam by providing compassionate support in health, education, family welfare, and evangelism, while restoring dignity and hope to people in need.</p><h3>Purpose</h3><p>To turn remembrance into impact.</p><h3>Strategic objectives</h3><ol><li>Preserve the legacy of Mrs Cecilia Anyatuik Adam.</li><li>Support vulnerable people across health, education, welfare and spiritual growth.</li><li>Provide educational assistance to needy but promising students.</li><li>Support orphans, widows and widowers.</li><li>Promote health awareness and outreach.</li><li>Carry out evangelism and missions.</li><li>Build partnerships that extend the work.</li><li>Operate with transparency and accountability.</li><li>Create visible, lasting impact in Ghanaian communities.</li></ol>',
                ]],
            ],

            'core-values' => [
                ['type' => 'page-header', 'data' => ['heading' => 'Our core values']],
                ['type' => 'core-values', 'data' => ['intro' => 'Faith, Compassion, Love, Dignity, Service, Integrity and Legacy — each defined in the foundation\'s profile, and each a test a programme has to pass.']],
            ],

            'leadership' => [
                ['type' => 'page-header', 'data' => ['heading' => 'Leadership']],
                ['type' => 'team', 'data' => []],
            ],

            'how-we-work' => [
                ['type' => 'page-header', 'data' => ['heading' => 'How we work', 'image' => $this->image('gallery-5')]],
                ['type' => 'feature-grid', 'data' => [
                    'heading' => 'Four questions before a cedi is spent',
                    'columns' => 2,
                    'items' => [
                        ['title' => 'Is it within a division?', 'body' => 'Health, education, orphans and widows, evangelism. If it is not one of those, it is not ours to do.', 'icon' => 'shield-check'],
                        ['title' => 'Is there a partner on the ground?', 'body' => 'A clinic, a school, a church or an assembly that will still be there when we are not.', 'icon' => 'user-group'],
                        ['title' => 'Do we know what "done" looks like?', 'body' => 'A number, a date and a way to check, agreed before the first payment.', 'icon' => 'light-bulb'],
                        ['title' => 'Can we show where the money went?', 'body' => 'Line by line, to the donor who gave it. If we cannot, we do not start.', 'icon' => 'globe-alt'],
                    ],
                ]],
            ],

            'transparency' => [
                ['type' => 'page-header', 'data' => ['heading' => 'Transparency and accountability', 'subheading' => 'Objective eight of nine: to operate with transparency and accountability.', 'image' => $this->image('transparency-header')]],
                ['type' => 'rich-text', 'data' => [
                    'body' => '<p>Every gift is acknowledged with a numbered receipt. Every programme is published with its budget, and its updates say what was spent. The annual report and the financial summary will be published here each year, and the policies that govern the work — safeguarding, data protection, donations, refunds, anti-fraud, raising a concern — are on this site.</p><p>If something on this site does not add up, say so: <a href="'.$path('whistleblowing').'">Raising a concern</a> explains how, and that you can do it confidentially.</p>',
                ]],
                ['type' => 'cta-band', 'data' => ['heading' => 'Reports and documents', 'body' => 'Annual reports, financial summaries and policies, as they are published.', 'cta_label' => 'See the documents', 'cta_url' => $path('downloads')]],
            ],

            'contact' => [
                ['type' => 'page-header', 'data' => ['heading' => 'Contact us', 'subheading' => 'A person reads every message.', 'image' => $this->image('contact-header')]],
                ['type' => 'contact-details', 'data' => ['heading' => 'How to reach us', 'show_map' => false]],
            ],

            'faq' => [
                ['type' => 'page-header', 'data' => ['heading' => 'Frequently asked questions']],
                ['type' => 'faq', 'data' => ['intro' => 'Giving, our work, volunteering, the shop and your data. If your question is not here, ask us.']],
            ],
        ];

        // The get-involved page already has a first draft from PageContentSeeder;
        // it gets its picture, nothing else.
        $getInvolved = Page::query()->where('slug', 'get-involved')->first();
        if ($getInvolved !== null && $getInvolved->sections()->where('block_type', 'page-header')->doesntExist()) {
            // First on the page: sort_order is unsigned, so the rest move down one.
            $getInvolved->sections()->increment('sort_order');
            $getInvolved->sections()->create([
                'block_type' => 'page-header',
                'data' => ['heading' => 'Get involved', 'subheading' => 'Time, skill, goods, a word to a friend — and money. Whichever you have, there is a place for it here.', 'image' => $this->image('get-involved-header')],
                'sort_order' => 0,
            ]);
        }

        $seeded = 0;

        foreach ($content as $slug => $sections) {
            $page = Page::query()->where('slug', $slug)->first();

            if ($page === null) {
                continue;
            }

            if ($page->sections()->exists()) {
                $this->refreshLayout($page, $sections);

                continue;
            }

            foreach ($sections as $order => $section) {
                $page->sections()->create([
                    'block_type' => $section['type'],
                    'data' => $section['data'],
                    'settings' => $section['settings'] ?? null,
                    'sort_order' => $order,
                ]);
            }

            $seeded++;
        }

        // The pages this seeder wrote are the foundation's own words and can
        // be public. Legal pages stay drafts: they are the trustees' undertakings.
        foreach (array_merge(array_keys($content), ['get-involved', 'partner-with-us', 'corporate-giving', 'donate-goods', 'fundraise-for-us', 'prayer', 'other-ways-to-give', 'donation-faq', 'downloads']) as $slug) {
            $page = Page::query()->where('slug', $slug)->first();

            if ($page !== null && $page->status !== PageStatus::Published && $page->sections()->exists()) {
                $page->publish();
            }
        }

        $this->command?->info(sprintf('Pages: %d given their launch content.', $seeded));
    }

    /**
     * Bring a page seeded by an earlier version of this seeder up to the
     * current arrangement — without touching a word an editor has changed.
     *
     * ── What it does, and what it refuses to do ─────────────────────────────
     *
     * The template restyle added eyebrows, icons and band settings to the
     * home page, and a donation band and an FAQ that were not there before.
     * A staging site seeded a week earlier has the old arrangement, and this
     * seeder's rule is to leave a page alone once it has sections. So the
     * refresh fills in only what is EMPTY: a data key the section does not
     * have yet (an eyebrow, an icon on a card), presentation settings when
     * the section has none, and a block the page has none of — inserted at
     * the position the arrangement gives it. A heading somebody rewrote, a
     * background somebody chose, a block somebody removed: all kept. (A
     * removed block is one the page has none of, so it does come back once;
     * remove it again and it stays gone until the seeder is next changed.)
     *
     * @param  array<int, array{type: string, data: array<string, mixed>, settings?: array<string, mixed>}>  $sections
     */
    private function refreshLayout(Page $page, array $sections): void
    {
        $existing = $page->sections()->orderBy('sort_order')->get();
        $seen = [];

        foreach ($sections as $position => $wanted) {
            // The first section of this type not already matched to an
            // earlier entry of the arrangement.
            $section = $existing->first(fn ($s) => $s->block_type === $wanted['type'] && ! in_array($s->getKey(), $seen, true));

            if ($section === null) {
                $section = $page->sections()->create([
                    'block_type' => $wanted['type'],
                    'data' => $wanted['data'],
                    'settings' => $wanted['settings'] ?? null,
                    // Just after the previous section in the arrangement, so a
                    // new band lands where the layout puts it, not at the end.
                    'sort_order' => $this->slotAfter($page, $seen),
                ]);
                $seen[] = $section->getKey();

                continue;
            }

            $seen[] = $section->getKey();
            $data = $section->data ?? [];

            foreach ($wanted['data'] as $key => $value) {
                if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                    $data[$key] = $value;
                } elseif ($key === 'items' && is_array($value) && is_array($data[$key])) {
                    // Cards keep their words; each gains an icon if it has none.
                    foreach ($value as $i => $item) {
                        if (isset($data[$key][$i]) && blank($data[$key][$i]['icon'] ?? null) && filled($item['icon'] ?? null)) {
                            $data[$key][$i]['icon'] = $item['icon'];
                        }
                    }
                }
            }

            $section->data = $data;

            if (blank($section->settings) && isset($wanted['settings'])) {
                $section->settings = $wanted['settings'];
            }

            if ($section->isDirty()) {
                $section->save();
            }
        }

        // A clean 0..n sequence, whatever gaps deletions left behind.
        foreach ($page->sections()->orderBy('sort_order')->orderBy('id')->get() as $i => $section) {
            if ((int) $section->sort_order !== $i) {
                $section->forceFill(['sort_order' => $i])->save();
            }
        }
    }

    /**
     * A sort_order just after the last section already placed, moving what
     * follows down by one. `sort_order` is unsigned, so the increment comes
     * before anything is written at the new position.
     *
     * @param  array<int, int>  $placed  ids of the sections placed so far, in order
     */
    private function slotAfter(Page $page, array $placed): int
    {
        $lastId = end($placed);
        $after = $lastId ? (int) $page->sections()->whereKey($lastId)->value('sort_order') : -1;

        $page->sections()->where('sort_order', '>', $after)->increment('sort_order');

        return $after + 1;
    }
}
