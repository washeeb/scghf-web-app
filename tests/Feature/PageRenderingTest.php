<?php

declare(strict_types=1);

use App\Blocks\BlockDataResolver;
use App\Blocks\BlockRegistry;
use App\Models\Media;
use App\Models\Page;
use App\Models\Testimonial;
use App\Models\User;
use App\Policies\BasePolicy;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Rendering a page
|--------------------------------------------------------------------------
|
| A DRAFT IS A 404, NOT A 403. An unpublished page does not exist as far as the
| public is concerned, and a 403 confirms something is there — which is exactly
| what an unannounced appeal must not do.
|
| THE BLOCK TYPE NEVER CHOOSES A FILE. `@include` with a variable name would let
| a database column pick which template is executed. The registry is consulted
| first, and a type it does not know renders nothing.
|
| REMOVING A BLOCK FROM THE CODE MUST NOT TAKE DOWN A PAGE. Sections whose type
| has vanished are dropped; the page still loads.
|
| PREVIEW IS SIGNED AND STAFF-ONLY. A signed URL is still a string somebody can
| paste into a chat.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(RoleAndPermissionSeeder::class);

    BasePolicy::forgetKnownPermissions();
});

/** A published page carrying one block. */
function pageWithBlock(string $type, array $data = [], array $settings = []): Page
{
    $page = Page::factory()->published()->create(['title' => 'Our Story', 'slug' => 'our-story']);

    $page->sections()->create([
        'block_type' => $type,
        'data' => $data,
        'settings' => $settings,
    ]);

    return $page->fresh();
}

// ── What the public can reach ───────────────────────────────────────────────

it('renders a published page at its path', function () {
    $page = pageWithBlock('rich-text', ['body' => '<p>Founded in memory of Cecilia.</p>']);

    $this->get($page->path)
        ->assertOk()
        ->assertSee('Founded in memory of Cecilia.', escape: false);
});

it('answers a draft with a 404, not a 403', function () {
    /*
     * A 403 would confirm that something is there. The address of an
     * unannounced appeal is worth guessing.
     */
    $page = Page::factory()->create(['slug' => 'unannounced']);
    $page->sections()->create(['block_type' => 'rich-text', 'data' => ['body' => 'Secret']]);

    $this->get($page->fresh()->path)->assertNotFound();
});

it('answers a scheduled page with a 404 until its date passes', function () {
    $page = Page::factory()->scheduled()->create(['slug' => 'christmas-appeal']);

    $this->get($page->path)->assertNotFound();
});

it('serves the homepage from the page marked as home', function () {
    $page = pageWithBlock('rich-text', ['body' => '<p>Welcome to the foundation.</p>']);
    $page->setAsHomepage();

    $this->get('/')
        ->assertOk()
        ->assertSee('Welcome to the foundation.', escape: false);
});

it('still answers the front page when nobody has marked a homepage', function () {
    // A fresh install must not answer its own front page with a 404.
    expect(Page::where('is_homepage', true)->exists())->toBeFalse();

    $this->get('/')->assertOk();
});

// ── The catch-all does not swallow the application ──────────────────────────

it('leaves the named routes alone', function () {
    /*
     * `{path}` with `.*` matches everything. Laravel matches in registration
     * order, so every named route above it wins — and this is what proves the
     * catch-all is still last in the file.
     */
    $this->get('/login')->assertOk();
    $this->get('/register')->assertOk();
    $this->get('/account')->assertRedirect(route('login'));
});

// ── Blocks ──────────────────────────────────────────────────────────────────

it('has a view for every block in the registry', function () {
    /*
     * A block an editor can place and the site cannot draw is a block that
     * renders as nothing, with no error anywhere — which reads as a broken CMS
     * rather than a missing file.
     */
    foreach (app(BlockRegistry::class)->keys() as $key) {
        expect(view()->exists('blocks.'.$key))->toBeTrue("blocks.{$key} is missing");
    }
});

it('renders nothing for a block type that is not in the registry', function () {
    // Removing a block from the code must not take down every page that still
    // has one placed.
    $page = Page::factory()->published()->create(['slug' => 'legacy']);

    $page->sections()->create(['block_type' => 'a-block-from-2019', 'data' => []]);

    $this->get($page->fresh()->path)
        ->assertOk()
        ->assertDontSee('a-block-from-2019');
});

it('does not render a hidden block', function () {
    $page = Page::factory()->published()->create(['slug' => 'partly-hidden']);

    $page->sections()->create([
        'block_type' => 'rich-text',
        'data' => ['body' => '<p>Visible</p>'],
    ]);

    $page->sections()->create([
        'block_type' => 'cta-band',
        'data' => ['heading' => 'Hidden band'],
        'is_visible' => false,
    ]);

    $this->get($page->fresh()->path)
        ->assertSee('Visible', escape: false)
        ->assertDontSee('Hidden band');
});

it('does not render a block outside its date window', function () {
    // An appeal that appears on a date and disappears by itself, so nobody has
    // to remember to take it down.
    $page = Page::factory()->published()->create(['slug' => 'timed']);

    $page->sections()->create([
        'block_type' => 'cta-band',
        'data' => ['heading' => 'Christmas appeal'],
        'visible_from' => now()->addWeek(),
    ]);

    $this->get($page->fresh()->path)->assertDontSee('Christmas appeal');
});

it('applies the presentation settings to the section', function () {
    $page = pageWithBlock(
        'rich-text',
        ['body' => '<p>Text</p>'],
        ['background' => 'brand', 'padding' => 'large'],
    );

    $this->get($page->path)
        ->assertSee('bg-[var(--brand-primary)]', escape: false)
        ->assertSee('py-16', escape: false);
});

it('renders a page with no blocks as its title rather than a blank response', function () {
    /*
     * A blank response looks like a server fault to a visitor and a deleted
     * page to whoever built it. The admin list flags this case with a badge,
     * which is where it gets fixed.
     */
    $page = Page::factory()->published()->create(['title' => 'Coming soon', 'slug' => 'coming-soon']);

    $this->get($page->path)
        ->assertOk()
        ->assertSee('Coming soon');
});

// ── Images obey the publication gate ────────────────────────────────────────

it('does not render an image that is not publishable', function () {
    /*
     * `Media::isPublishable()` refuses anything without alt text or with its
     * camera metadata still on it. A hero whose image is blocked renders
     * without the image rather than with a broken one — and never with a
     * photograph that still carries the coordinates it was taken at.
     */
    $media = Media::factory()->create(['metadata_stripped_at' => null, 'alt_text' => null]);

    $page = pageWithBlock('hero', [
        'heading' => 'A headline',
        'image' => $media->getKey(),
    ]);

    $this->get($page->path)
        ->assertOk()
        ->assertSee('A headline')
        ->assertDontSee($media->file_name);
});

// ── The resolver ────────────────────────────────────────────────────────────

it('clamps a limit an editor typed', function () {
    /*
     * `limit` comes from a form. A block asking for ten thousand causes is a
     * page that times out, and the editor who typed it would have no idea why.
     */
    $page = Page::factory()->create();

    $section = $page->sections()->create([
        'block_type' => 'featured-causes',
        'data' => ['limit' => 10_000],
    ]);

    $data = app(BlockDataResolver::class)->for($section);

    expect($data)->toHaveKey('causes')
        ->and($data['causes']->count())->toBeLessThanOrEqual(24);
});

it('never shows a testimonial without recorded consent', function () {
    /*
     * The model refuses to save a published testimonial that needs consent and
     * does not have it, and the resolver relies on that rather than re-checking
     * — one guarantee, in the place that owns it.
     */
    $page = Page::factory()->create();

    $section = $page->sections()->create(['block_type' => 'testimonials', 'data' => []]);

    // Created directly rather than through a factory: the point is a
    // testimonial that has NOT been published, and a factory state for
    // "unpublishable" would be a state nothing else ever wants.
    Testimonial::create([
        'author_name' => 'Anonymous',
        'quote' => 'Never publishable',
        'is_published' => false,
        'has_consent' => false,
    ]);

    $data = app(BlockDataResolver::class)->for($section);

    expect($data['testimonials']->pluck('quote'))->not->toContain('Never publishable');
});

it('returns nothing rather than throwing when a block\'s data cannot be loaded', function () {
    // A block whose data fails to load should be an absent block, not a 500 on
    // a donation page.
    $page = Page::factory()->create();

    $section = $page->sections()->create(['block_type' => 'gallery', 'data' => ['gallery_id' => 99_999]]);

    expect(app(BlockDataResolver::class)->for($section))->toBe(['gallery' => null]);
});

// ── Preview ─────────────────────────────────────────────────────────────────

it('shows a draft to staff through a signed preview link', function () {
    $user = User::factory()->staff()->create();
    $user->givePermissionTo('pages.view');

    $page = Page::factory()->create(['slug' => 'draft-page']);
    $page->sections()->create(['block_type' => 'rich-text', 'data' => ['body' => '<p>Not yet live</p>']]);

    $this->actingAs($user)
        ->get(previewUrl($page))
        ->assertOk()
        ->assertSee('Not yet live', escape: false);
});

it('says plainly that a preview is not published', function () {
    /*
     * Somebody looking at a preview needs to know the public cannot see it, or
     * they will wonder why nobody responded to a campaign that never went live.
     */
    $user = User::factory()->staff()->create();
    $user->givePermissionTo('pages.view');

    $page = Page::factory()->create(['slug' => 'draft-page']);

    $this->actingAs($user)
        ->get(previewUrl($page))
        ->assertSee(__('It is not published, so visitors cannot see it.'));
});

it('refuses a preview link to somebody who is not signed in', function () {
    // A signed URL is still a string somebody can paste into a chat.
    $page = Page::factory()->create(['slug' => 'draft-page']);

    $this->get(previewUrl($page))->assertRedirect(route('login'));
});

it('refuses a preview to a signed-in donor', function () {
    $page = Page::factory()->create(['slug' => 'draft-page']);

    $this->actingAs(User::factory()->create())
        ->get(previewUrl($page))
        ->assertNotFound();
});

it('refuses a preview link whose signature has been tampered with', function () {
    $user = User::factory()->staff()->create();
    $user->givePermissionTo('pages.view');

    $page = Page::factory()->create(['slug' => 'draft-page']);

    $this->actingAs($user)
        ->get(previewUrl($page).'&extra=1')
        ->assertForbidden();
});

// ── SEO ─────────────────────────────────────────────────────────────────────

it('noindexes a page whose own settings ask for it', function () {
    /*
     * Two independent reasons not to be indexed. A thank-you page is noindexed
     * on its own account even once the site switch is on.
     */
    $page = pageWithBlock('rich-text', ['body' => '<p>Thank you</p>']);

    $page->seo()->create(['no_index' => true]);

    $this->get($page->path)->assertSee('noindex', escape: false);
});

/** A valid, signed preview URL. */
function previewUrl(Page $page): string
{
    return URL::temporarySignedRoute('pages.preview', now()->addMinutes(20), ['page' => $page->ulid]);
}
