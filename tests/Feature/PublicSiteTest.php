<?php

declare(strict_types=1);

use App\Enums\PageStatus;
use App\Models\ContactDepartment;
use App\Models\ContactMessage;
use App\Models\Faq;
use App\Models\Page;
use App\Models\Post;
use App\Models\Subscriber;
use App\Models\Suppression;
use App\Support\Settings;
use App\Support\ThemeTokens;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The public site
|--------------------------------------------------------------------------
|
| SHARING WAS BROKEN EVERYWHERE. `HasSeo::seoOpenGraph()` was written in Phase 3
| and read by nothing, so there were no Open Graph tags, no Twitter card and no
| canonical on any page. Every donation appeal shared on WhatsApp — which is how
| most of this foundation's supporters share anything — rendered as a bare blue
| link with no title, no summary and no picture.
|
| THE SITEMAP TOGGLE DID NOTHING. `show_in_sitemap` has been a column since
| Phase 3 and a switch in the page builder since Phase 5, with no sitemap for it
| to affect.
|
| ROBOTS.TXT LIED. A static file allowing every crawler in, while `.env.example`
| claimed the indexing setting drove it. A staging site was indexable regardless.
|
| TWO TABLES HAD NO WAY IN. The contact inbox and the newsletter both had a
| complete admin side, a seeded email template and model methods written in
| Phase 3 — and nothing anywhere could create a record in either.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);

    ThemeTokens::flush();
    app(Settings::class)->flush();

    /*
     * The honeypot needs no arrangement here. `ProtectAgainstSpam` treats an
     * ABSENT decoy field as an ordinary submission and only rejects one that is
     * filled in — which is the right default, because a bot that reads the HTML
     * fills it and a form posted by a test does not have it. The rejection
     * paths themselves are covered against the registration form, which was the
     * first to use them.
     */
});

/** Indexing is seeded OFF; several of these assertions only mean anything when it is on. */
function allowIndexing(): void
{
    app(Settings::class)->set('seo.allow_indexing', true);
}

// ── Sharing ─────────────────────────────────────────────────────────────────

it('gives every page the tags a shared link needs', function () {
    /*
     * ⚠ There were none of these until Phase 6. A link with no og:title is a
     * link WhatsApp renders as a bare URL, and a bare URL is one nobody taps.
     */
    $this->get('/')
        ->assertOk()
        ->assertSee('property="og:title"', escape: false)
        ->assertSee('property="og:url"', escape: false)
        ->assertSee('name="twitter:card"', escape: false)
        ->assertSee('rel="canonical"', escape: false);
});

it('describes the organisation to search engines', function () {
    // `NGO` structured data with the legal name and registration number is one
    // of the signals separating a real charity from a site impersonating one.
    $this->get('/')
        ->assertOk()
        ->assertSee('application/ld+json', escape: false)
        ->assertSee('"@type":"NGO"', escape: false);
});

it('takes a page title from its own SEO fields', function () {
    $page = Page::factory()->create(['title' => 'Our Story', 'status' => PageStatus::Published]);

    $this->get($page->path)
        ->assertOk()
        ->assertSee('Our Story');
});

// ── Indexing ────────────────────────────────────────────────────────────────

it('tells crawlers to stay away while indexing is off', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('noindex', escape: false)
        // ⚠ The header, not only the tag. A crawler fetching a PDF or an image
        // never parses HTML, so the meta tag alone left every uploaded file on
        // a staging site indexable.
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noimageindex');
});

it('stops saying so once indexing is switched on', function () {
    allowIndexing();

    $response = $this->get('/')->assertOk();

    expect($response->headers->has('X-Robots-Tag'))->toBeFalse();
});

it('serves a robots.txt that refuses everything while indexing is off', function () {
    /*
     * ⚠ `public/robots.txt` was a static file saying "allow everything", and
     * `.env.example` claimed the indexing switch drove it. Staging invited
     * every crawler in — and a staging site indexed beside the real one splits
     * its search ranking and shows donors a test site.
     */
    $this->get('/robots.txt')
        ->assertOk()
        ->assertSee('Disallow: /');
});

it('points crawlers at the sitemap once indexing is on', function () {
    allowIndexing();

    $this->get('/robots.txt')
        ->assertOk()
        ->assertSee('Sitemap: '.route('sitemap'))
        // Nothing behind a login is worth a crawl budget.
        ->assertSee('Disallow: /account/');
});

it('keeps error pages out of search results', function () {
    allowIndexing();

    // An indexed 404 is a search result that leads somebody to a dead end on
    // the foundation's own domain.
    $this->get('/a-page-that-is-not-here')
        ->assertNotFound()
        ->assertSee('noindex', escape: false);
});

// ── The sitemap ─────────────────────────────────────────────────────────────

it('lists a published page in the sitemap', function () {
    allowIndexing();

    $page = Page::factory()->create(['status' => PageStatus::Published, 'show_in_sitemap' => true]);

    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee(url($page->path));
});

it('honours the switch an editor actually toggles', function () {
    /*
     * ⚠ The point of this module. `show_in_sitemap` was a switch in the page
     * builder with nothing reading it — an editor could turn it off on a
     * thank-you page and change nothing whatsoever.
     */
    allowIndexing();

    $page = Page::factory()->create(['status' => PageStatus::Published, 'show_in_sitemap' => false]);

    $this->get('/sitemap.xml')->assertOk()->assertDontSee(url($page->path));
});

it('keeps drafts out of the sitemap', function () {
    allowIndexing();

    $page = Page::factory()->create(['status' => PageStatus::Draft, 'show_in_sitemap' => true]);

    $this->get('/sitemap.xml')->assertOk()->assertDontSee(url($page->path));
});

// ── News ────────────────────────────────────────────────────────────────────

it('shows a published post', function () {
    $post = Post::create([
        'title' => 'A visit to Bongo',
        'slug' => 'a-visit-to-bongo',
        'status' => PageStatus::Published,
        'published_at' => now()->subDay(),
        'body' => '<p>What happened.</p>',
    ]);

    $this->get(route('news.show', $post))
        ->assertOk()
        ->assertSee('A visit to Bongo')
        // `article`, which is what puts the date and byline on a shared link.
        ->assertSee('property="og:type" content="article"', escape: false);
});

it('answers 404 for a post that is not published', function () {
    // A 403 confirms something is there, and the address of an unannounced
    // appeal is worth guessing.
    $post = Post::create(['title' => 'Not yet', 'slug' => 'not-yet', 'status' => PageStatus::Draft]);

    $this->get(route('news.show', $post))->assertNotFound();
});

it('holds a scheduled post until its date passes', function () {
    // Evaluated in the query on every request, so a post scheduled for 6am
    // appears at 6am without the cron having had to run.
    $post = Post::create([
        'title' => 'Tomorrow',
        'slug' => 'tomorrow',
        'status' => PageStatus::Scheduled,
        'published_at' => now()->addDay(),
    ]);

    $this->get(route('news.show', $post))->assertNotFound();

    $this->travel(2)->days();

    $this->get(route('news.show', $post))->assertOk();
});

it('counts that a post was read', function () {
    $post = Post::create(['title' => 'Read me', 'slug' => 'read-me', 'status' => PageStatus::Published]);

    $this->get(route('news.show', $post));

    expect($post->fresh()->view_count)->toBe(1);
});

// ── The contact form ────────────────────────────────────────────────────────

it('accepts an enquiry', function () {
    /*
     * ⚠ The missing half of a finished feature. The table, the departments, the
     * inbox, the reply flow and the acknowledgement template were all built by
     * Phase 5, and nothing could create a message.
     */
    $this->seed(MessageTemplateSeeder::class);

    $this->post(route('contact.store'), [
        'name' => 'Ama Mensah',
        'email' => 'ama@example.test',
        'message' => 'How do I volunteer with you?',
        'consent' => '1',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $message = ContactMessage::first();

    expect($message)->not->toBeNull()
        ->and($message->name)->toBe('Ama Mensah')
        ->and($message->status)->toBe('new')
        ->and($message->reference)->not->toBeNull();
});

it('will not take a message without permission to hold the details', function () {
    // Act 843 needs a lawful basis, and "they typed it into a box" is not one.
    $this->post(route('contact.store'), [
        'name' => 'Ama',
        'email' => 'ama@example.test',
        'message' => 'A message long enough to pass.',
    ])->assertSessionHasErrors('consent');

    expect(ContactMessage::count())->toBe(0);
});

it('stores the exact wording that was agreed to', function () {
    /*
     * Snapshotted, not referenced. Consent to a privacy notice that has since
     * been rewritten is not evidence of anything, and Act 843 asks what they
     * agreed to rather than which page it was on.
     */
    $this->seed(MessageTemplateSeeder::class);
    app(Settings::class)->set('compliance.contact_consent_text', 'The wording on the day.');

    $this->post(route('contact.store'), [
        'name' => 'Ama',
        'email' => 'ama@example.test',
        'message' => 'A message long enough to pass.',
        'consent' => '1',
    ]);

    expect(ContactMessage::first()->consent_text)->toBe('The wording on the day.');
});

it('does not offer a confidential department on the public form', function () {
    /*
     * ⚠ A safeguarding route in a dropdown beside "Shop enquiries" is one that
     * gets used for shop enquiries — and one whose existence and address are
     * published to every scraper.
     */
    ContactDepartment::create([
        'key' => 'safeguarding',
        'name' => 'Safeguarding',
        'email' => 'safeguarding@example.test',
        'is_confidential' => true,
    ]);

    $this->get(route('contact'))->assertOk()->assertDontSee('Safeguarding');
});

it('refuses to file an enquiry under a confidential department', function () {
    $department = ContactDepartment::create([
        'key' => 'safeguarding',
        'name' => 'Safeguarding',
        'email' => 'safeguarding@example.test',
        'is_confidential' => true,
    ]);

    $this->seed(MessageTemplateSeeder::class);

    $this->post(route('contact.store'), [
        'name' => 'Ama',
        'email' => 'ama@example.test',
        'message' => 'A message long enough to pass.',
        'consent' => '1',
        'contact_department_id' => $department->getKey(),
    ]);

    // Filed as a general enquiry rather than routed into the one inbox most
    // staff cannot read.
    expect(ContactMessage::first()?->contact_department_id)->toBeNull();
});

it('keeps the enquiry even when the acknowledgement cannot be sent', function () {
    /*
     * The message is stored first and the email attempted afterwards. The other
     * order loses the enquiry and tells the sender it failed, so they give up —
     * and on shared hosting the mail host is periodically down.
     */
    // No templates seeded, so the dispatcher cannot find `contact.acknowledgement`.
    $this->post(route('contact.store'), [
        'name' => 'Ama',
        'email' => 'ama@example.test',
        'message' => 'A message long enough to pass.',
        'consent' => '1',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(ContactMessage::count())->toBe(1);
});

// ── The newsletter ──────────────────────────────────────────────────────────

it('signs somebody up as pending, not confirmed', function () {
    /*
     * ⚠ Double opt-in. Single opt-in is the fastest way to destroy a sending
     * domain's reputation, and a foundation whose donation receipts stop
     * arriving has a much bigger problem than a smaller mailing list.
     */
    $this->seed(MessageTemplateSeeder::class);

    $this->post(route('newsletter.subscribe'), ['email' => 'ama@example.test'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $subscriber = Subscriber::first();

    expect($subscriber)->not->toBeNull()
        ->and($subscriber->status)->toBe(Subscriber::STATUS_PENDING)
        ->and($subscriber->consent_at)->not->toBeNull();
});

it('confirms from the link in the email', function () {
    $this->seed(MessageTemplateSeeder::class);
    $this->post(route('newsletter.subscribe'), ['email' => 'ama@example.test']);

    $token = Subscriber::first()->confirmation_token;

    $this->get(route('newsletter.confirm', $token))->assertOk();

    expect(Subscriber::first()->status)->toBe(Subscriber::STATUS_CONFIRMED);
});

it('does not tell somebody their link failed when they click it twice', function () {
    // A token is burnt on use, and following a confirmation link twice is a
    // thing people do constantly.
    $this->seed(MessageTemplateSeeder::class);
    $this->post(route('newsletter.subscribe'), ['email' => 'ama@example.test']);

    $token = Subscriber::first()->confirmation_token;

    $this->get(route('newsletter.confirm', $token))->assertOk();
    $this->get(route('newsletter.confirm', $token))->assertOk();
});

it('unsubscribes in one click', function () {
    /*
     * No sign-in, no confirmation step. A friction-filled unsubscribe is how a
     * recipient reports the message as spam instead — and a spam complaint
     * damages delivery for every other message the foundation sends, receipts
     * among them.
     */
    $this->seed(MessageTemplateSeeder::class);
    $this->post(route('newsletter.subscribe'), ['email' => 'ama@example.test']);

    $subscriber = Subscriber::first();

    $this->get(route('newsletter.unsubscribe', $subscriber->unsubscribe_token))->assertOk();

    expect($subscriber->fresh()->status)->toBe(Subscriber::STATUS_UNSUBSCRIBED);
});

it('never re-enrols an address that has been suppressed', function () {
    /*
     * ⚠ `suppressions` holds addresses that hard-bounced or reported a message
     * as spam. Mailing one again is the single fastest route to a blocked
     * sending domain, so a signup for one is accepted silently and does nothing.
     */
    Suppression::create([
        'channel' => Suppression::CHANNEL_EMAIL,
        'address' => 'bounced@example.test',
        'reason' => Suppression::REASON_HARD_BOUNCE,
        'suppressed_at' => now(),
    ]);

    $this->post(route('newsletter.subscribe'), ['email' => 'bounced@example.test'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Subscriber::count())->toBe(0);
});

it('answers the same way whether or not the address is already on the list', function () {
    /*
     * "You are already subscribed" is an address-existence oracle: anybody can
     * type an email in and learn whether that person supports this foundation.
     * On a charity working with vulnerable people that is not a neutral fact.
     */
    $this->seed(MessageTemplateSeeder::class);

    $first = $this->post(route('newsletter.subscribe'), ['email' => 'ama@example.test']);
    Subscriber::first()->confirm();
    $second = $this->post(route('newsletter.subscribe'), ['email' => 'ama@example.test']);

    expect($second->getSession()->get('status'))->toBe($first->getSession()->get('status'));
});

// ── Search ──────────────────────────────────────────────────────────────────

it('finds a published post', function () {
    Post::create([
        'title' => 'Boreholes in Bongo',
        'slug' => 'boreholes-in-bongo',
        'status' => PageStatus::Published,
        'published_at' => now()->subDay(),
    ]);

    $this->get(route('search', ['q' => 'borehole']))
        ->assertOk()
        ->assertSee('Boreholes in Bongo');
});

it('does not surface a draft through the search box', function () {
    // A search that reaches unpublished content is a way to read it by guessing
    // words that are in it.
    Post::create(['title' => 'Unannounced appeal', 'slug' => 'unannounced', 'status' => PageStatus::Draft]);

    $this->get(route('search', ['q' => 'Unannounced']))
        ->assertOk()
        ->assertDontSee('Unannounced appeal');
});

it('treats a percent sign as a character, not a wildcard', function () {
    /*
     * ⚠ `%` is LIKE's own wildcard. Unescaped, a visitor searching for "100%"
     * matches every row on the site — bindings stop injection, they do not stop
     * this.
     */
    Faq::create(['question' => 'Where does my money go?', 'answer' => 'All of it.', 'is_published' => true]);

    $this->get(route('search', ['q' => '%']))
        ->assertOk()
        ->assertDontSee('Where does my money go?');
});

it('keeps search results out of the index', function () {
    // Result pages are near-duplicates of each other and of the pages they link
    // to; indexing them spends the crawl budget on query strings.
    allowIndexing();

    $this->get(route('search', ['q' => 'water']))->assertOk()->assertSee('noindex', escape: false);
});

// ── The pages open ──────────────────────────────────────────────────────────

it('opens every public content page', function (string $route) {
    $this->get(route($route))->assertOk();
})->with(['news.index', 'faq', 'galleries.index', 'documents.index', 'team', 'partners', 'testimonials', 'contact', 'search']);
