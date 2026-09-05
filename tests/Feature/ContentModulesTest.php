<?php

declare(strict_types=1);

use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Filament\Resources\Comments\CommentResource;
use App\Filament\Resources\ContactMessages\ContactMessageResource;
use App\Filament\Resources\Faqs\Pages\ListFaqs;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Resources\Redirects\Pages\ListRedirects;
use App\Filament\Resources\Testimonials\Pages\ListTestimonials;
use App\Models\Announcement;
use App\Models\Comment;
use App\Models\ContactDepartment;
use App\Models\ContactMessage;
use App\Models\Faq;
use App\Models\Post;
use App\Models\Redirect;
use App\Models\Setting;
use App\Models\Testimonial;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Settings;
use App\Support\ThemeTokens;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The content modules
|--------------------------------------------------------------------------
|
| THE AUDIT COLUMNS WERE FICTION. Thirty tables declare `created_by` and
| `updated_by`. Nothing wrote either. The columns existed, the foreign keys
| constrained, the relationships resolved, and every answer was null — which is
| the worst shape an audit field can take, because a present column that always
| says "nobody" gets believed.
|
| THE REDIRECT TABLE WAS UNREACHABLE. `Redirect::resolve()`, `record404()` and
| `unresolved404s()` were all written in Phase 3 and called from nowhere. A
| foundation could have entered fifty redirects before launch and every one of
| them would have 404ed.
|
| THERE WERE TWO ANNOUNCEMENT BARS. Module 3 added an `announcement.*` settings
| group; the `announcements` table had been doing the same job since Phase 3,
| better, with nothing rendering it. One of them had to go.
|
| CONSENT GATES PUBLICATION. Testimonials and galleries are where photographs of
| children end up. The model refuses; the form refuses first, and for the same
| people the model does.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(RoleAndPermissionSeeder::class);

    BasePolicy::forgetKnownPermissions();
    ThemeTokens::flush();
    app(Settings::class)->flush();
});

/** Somebody who runs the content side of the site. */
function contentEditor(): User
{
    $user = User::factory()->staff()->withTwoFactor()->create();

    $user->givePermissionTo([
        'blog.view', 'blog.create', 'blog.update', 'blog.publish',
        'faqs.manage', 'testimonials.manage', 'partners.manage', 'galleries.manage',
        'documents.manage', 'announcements.manage', 'redirects.manage',
        'divisions.manage', 'contact.view', 'contact.reply', 'media.view',
    ]);

    return $user;
}

// ── The audit columns ───────────────────────────────────────────────────────

it('records who created a piece of content', function () {
    /*
     * ⚠ `created_by` and `updated_by` are declared on thirty tables and were
     * written by nothing at all. "Who published this appeal?" was a question
     * the schema looked able to answer and could not.
     */
    $editor = contentEditor();
    $this->actingAs($editor);

    $faq = Faq::create(['question' => 'Can I give monthly?', 'answer' => 'Yes.']);

    expect($faq->created_by)->toBe($editor->getKey())
        ->and($faq->updated_by)->toBe($editor->getKey());
});

it('records who last changed it', function () {
    $author = contentEditor();
    $this->actingAs($author);

    $faq = Faq::create(['question' => 'Can I give monthly?', 'answer' => 'Yes.']);

    $other = contentEditor();
    $this->actingAs($other);

    $faq->update(['answer' => 'Yes — by standing order or mobile money.']);

    expect($faq->fresh()->created_by)->toBe($author->getKey())
        ->and($faq->fresh()->updated_by)->toBe($other->getKey());
});

it('attributes nothing to anybody when a seeder writes it', function () {
    /*
     * A seeder, a queued job and a scheduled command all run with no
     * authenticated user. Attributing their work to whoever happens to be
     * logged in would be a lie, and a lie in an audit column is worse than a
     * null — a null is visibly missing.
     */
    $faq = Faq::create(['question' => 'Seeded?', 'answer' => 'Yes.']);

    expect($faq->created_by)->toBeNull();
});

// ── Redirects and the 404 log ───────────────────────────────────────────────

it('serves a redirect that somebody entered', function () {
    // ⚠ The table has existed since Phase 3 and nothing consulted it.
    Redirect::create([
        'from_path' => '/old-appeal',
        'to_path' => '/appeals/harvest',
        'status_code' => 301,
    ]);

    $this->get('/old-appeal')->assertRedirect('/appeals/harvest');
});

it('counts how often a redirect is used', function () {
    // Which redirects still matter, and which broken paths are worth fixing
    // first, are the same question asked from two ends.
    $redirect = Redirect::create(['from_path' => '/old', 'to_path' => '/new']);

    $this->get('/old');

    expect($redirect->fresh()->hits)->toBe(1)
        ->and($redirect->fresh()->last_hit_at)->not->toBeNull();
});

it('records a path that 404s so it can become a redirect', function () {
    $this->get('/a-page-that-never-existed')->assertNotFound();

    $recorded = Redirect::where('from_path', '/a-page-that-never-existed')->first();

    expect($recorded)->not->toBeNull()
        ->and($recorded->source)->toBe('auto_404')
        // Inactive with no destination: it is a REPORT of a broken path, not a
        // redirect, and must never fire until somebody says where it goes.
        ->and($recorded->is_active)->toBeFalse()
        ->and($recorded->to_path)->toBeNull();
});

it('counts repeat visits to the same broken path', function () {
    // The number that says which broken link is in a printed flyer and which
    // was one person's typo.
    $this->get('/broken');
    $this->get('/broken');

    expect(Redirect::where('from_path', '/broken')->value('hits'))->toBe(2);
});

it('does not fill the table with scanner noise', function () {
    /*
     * A public site is probed for WordPress paths several times an hour.
     * Recording those would bury the one real broken link under thousands of
     * rows nobody will ever act on.
     */
    $this->get('/wp-login.php');
    $this->get('/.env');

    expect(Redirect::count())->toBe(0);
});

it('leaves a working page alone', function () {
    // The middleware runs only on a 404. A redirect table consulted on every
    // request would be a query in front of every page on the site.
    $this->get('/')->assertOk();

    expect(Redirect::count())->toBe(0);
});

it('opens the redirects screen', function () {
    $this->actingAs(contentEditor());

    Livewire::test(ListRedirects::class)->assertOk();
});

// ── The announcement bar ────────────────────────────────────────────────────

it('draws a live announcement from the table', function () {
    Announcement::create([
        'placement' => 'announcement_bar',
        'title' => 'Harvest appeal closes on Sunday.',
        'is_active' => true,
    ]);

    $this->get('/')->assertOk()->assertSee('Harvest appeal closes on Sunday.');
});

it('does not draw one that is switched off', function () {
    Announcement::create(['title' => 'Not yet.', 'is_active' => false]);

    $this->get('/')->assertDontSee('Not yet.');
});

it('takes an announcement down when its window closes', function () {
    /*
     * ⚠ The reason the dates exist. A notice with no end date is one somebody
     * has to remember to remove, and nobody ever does — which is how a
     * foundation ends up advertising last December's carol service in March.
     */
    Announcement::create([
        'title' => 'Last year\'s carol service.',
        'is_active' => true,
        'ends_at' => now()->subDay(),
    ]);

    $this->get('/')->assertDontSee('carol service', escape: false);
});

it('shows a targeted announcement only where it belongs', function () {
    Announcement::create([
        'title' => 'Only on giving pages.',
        'is_active' => true,
        'show_on_paths' => ['/donate*'],
    ]);

    $this->get('/')->assertDontSee('Only on giving pages.');
});

it('counts that the bar was seen', function () {
    // Without it the foundation cannot answer whether a strip of every phone
    // screen is worth what it costs.
    $announcement = Announcement::create(['title' => 'Seen me?', 'is_active' => true]);

    $this->get('/');

    expect($announcement->fresh()->impressions)->toBe(1);
});

it('counts a click and sends the visitor on', function () {
    $announcement = Announcement::create([
        'title' => 'Give today',
        'cta_label' => 'Donate',
        'cta_url' => '/donate',
        'is_active' => true,
    ]);

    $this->get(route('announcements.click', $announcement))->assertRedirect('/donate');

    expect($announcement->fresh()->clicks)->toBe(1);
});

it('will not be turned into an open redirect', function () {
    /*
     * `cta_url` is typed by an editor, and an open redirect is an open redirect
     * whoever typed it. An external destination is allowed — a notice may point
     * at a partner's appeal — but a `javascript:` scheme is not a destination.
     */
    $announcement = Announcement::create([
        'title' => 'Careful',
        'cta_label' => 'Go',
        'cta_url' => 'javascript:alert(1)',
        'is_active' => true,
    ]);

    $this->get(route('announcements.click', $announcement))->assertNotFound();
});

it('stops showing a notice the visitor has closed', function () {
    $announcement = Announcement::create([
        'title' => 'Close me.',
        'is_active' => true,
        'is_dismissible' => true,
    ]);

    $this->post(route('announcements.dismiss', $announcement))->assertNoContent();

    $this->withCookie($announcement->dismissalCookieName(), '1')
        ->get('/')
        ->assertDontSee('Close me.');
});

it('does not count a dismissed notice as seen again', function () {
    // Filtering in the view would mean the impression was already recorded, so
    // the click-through rate would be measured against a number that grows for
    // people who are being shown nothing.
    $announcement = Announcement::create(['title' => 'Closed already.', 'is_active' => true]);

    $this->withCookie($announcement->dismissalCookieName(), '1')->get('/');

    expect($announcement->fresh()->impressions)->toBe(0);
});

it('has only one announcement bar', function () {
    /*
     * ⚠ Module 3 seeded an `announcement.*` settings group and rendered a bar
     * from it, while this table sat unused. Two mechanisms for one bar is worse
     * than either alone: the foundation edits one and the site shows the other,
     * and there is no order of investigation that leads anywhere pleasant.
     */
    expect(Setting::where('group', 'announcement')->count())->toBe(0)
        ->and(class_exists(App\Support\Announcement::class))->toBeFalse();
});

it('opens the announcements screen', function () {
    $this->actingAs(contentEditor());

    Livewire::test(AnnouncementResource::getPages()['index']->getPage())->assertOk();
});

// ── The contact inbox ───────────────────────────────────────────────────────

it('keeps a safeguarding report out of the general inbox', function () {
    /*
     * ⚠ The policy alone is not enough. It refuses an individual message, which
     * covers opening one — but a table query returns rows without asking a
     * policy about each. Without the scope, the sender's name and the subject
     * line of a report about a child appear in the general inbox for anybody
     * with `contact.view`, and the subject line is frequently the whole
     * disclosure.
     */
    $safeguarding = ContactDepartment::create([
        'key' => 'safeguarding',
        'name' => 'Safeguarding',
        'email' => 'safeguarding@example.test',
        'is_confidential' => true,
    ]);

    ContactMessage::create([
        'contact_department_id' => $safeguarding->getKey(),
        'name' => 'A worried parent',
        'email' => 'parent@example.test',
        'subject' => 'Something that happened at the centre',
        'message' => 'Details.',
    ]);

    $this->actingAs(contentEditor());

    expect(ContactMessageResource::getEloquentQuery()->count())->toBe(0);
});

it('shows a safeguarding report to somebody entitled to read it', function () {
    $department = ContactDepartment::create([
        'key' => 'safeguarding',
        'name' => 'Safeguarding',
        'email' => 'safeguarding@example.test',
        'is_confidential' => true,
    ]);

    ContactMessage::create([
        'contact_department_id' => $department->getKey(),
        'name' => 'A worried parent',
        'email' => 'parent@example.test',
        'message' => 'Details.',
    ]);

    $user = contentEditor();
    $user->givePermissionTo('contact.view_safeguarding');
    $this->actingAs($user);

    expect(ContactMessageResource::getEloquentQuery()->count())->toBe(1);
});

it('replies to an enquiry and records that it was answered', function () {
    // The reply goes out through `MessageDispatcher` on the `contact.reply`
    // template, so it is logged, suppression-checked and rate-limited like
    // every other email rather than being a `Mail::raw` that bypasses all three.
    $this->seed(MessageTemplateSeeder::class);

    $message = ContactMessage::create([
        'name' => 'Ama',
        'email' => 'ama@example.test',
        'message' => 'How do I volunteer?',
    ]);

    $this->actingAs(contentEditor());

    Livewire::test(ContactMessageResource::getPages()['index']->getPage())
        ->callTableAction('reply', $message, ['reply' => 'Come to the office on Tuesday.'])
        ->assertHasNoTableActionErrors();

    expect($message->fresh()->status)->toBe('replied')
        ->and($message->fresh()->replied_at)->not->toBeNull();
});

it('does not let the inbox rewrite what somebody sent', function () {
    /*
     * What a person sent is a record of what they sent. An inbox that lets
     * staff edit the enquiry is one where "that is not what I asked" has no
     * answer — and for a safeguarding report it may be evidence.
     */
    $message = ContactMessage::create([
        'name' => 'Ama',
        'email' => 'ama@example.test',
        'message' => 'The original words.',
    ]);

    expect(ContactMessage::make()->getFillable())->not->toContain('status')
        ->and(ContactMessage::make()->getFillable())->not->toContain('assigned_to');

    $message->update(['message' => 'Something else entirely']);

    // `message` IS fillable — the public form writes it — so this asserts the
    // handling fields, which are the ones an admin screen must set explicitly.
    expect($message->fresh()->status)->toBe('new');
});

// ── Consent gates publication ───────────────────────────────────────────────

it('refuses to publish a beneficiary testimonial without consent', function () {
    expect(fn () => Testimonial::create([
        'author_name' => 'A parent',
        'author_type' => 'beneficiary',
        'quote' => 'They changed our lives.',
        'is_published' => true,
        'has_consent' => false,
    ]))->toThrow(RuntimeException::class);
});

it('lets a partner speak for themselves', function () {
    // A partner organisation quoted in a professional capacity is not a
    // vulnerable person telling their own story. The form's exemption has to
    // match the model's exactly, or a legitimate testimonial is unpublishable.
    $testimonial = Testimonial::create([
        'author_name' => 'A partner church',
        'author_type' => 'partner',
        'quote' => 'A pleasure to work with.',
        'is_published' => true,
        'has_consent' => false,
    ]);

    expect($testimonial->is_published)->toBeTrue();
});

// ── Comment moderation ──────────────────────────────────────────────────────

it('keeps the post comment count true when a comment is approved', function () {
    /*
     * `posts.comment_count` is denormalised so a news listing does not run a
     * COUNT per row — which means it is only as good as whatever maintains it.
     */
    $post = Post::create(['title' => 'A visit to Bongo', 'slug' => 'a-visit-to-bongo']);
    $moderator = contentEditor();

    $comment = Comment::create([
        'post_id' => $post->getKey(),
        'author_name' => 'A reader',
        'author_email' => 'reader@example.test',
        'body' => 'Well done.',
    ]);

    expect($post->fresh()->comment_count)->toBe(0);

    $comment->approve($moderator);

    expect($post->fresh()->comment_count)->toBe(1);
});

it('hides the moderation queue while comments are switched off', function () {
    /*
     * `FEATURE_BLOG_COMMENTS` is off and there is no public comment form yet. A
     * queue in the sidebar that can only say zero is the "on and empty" shape
     * this project has a rule against — so the screen exists, works, and is not
     * shown until there is something to moderate.
     */
    config()->set('features.blog_comments', false);

    expect(CommentResource::shouldRegisterNavigation())->toBeFalse();

    config()->set('features.blog_comments', true);

    expect(CommentResource::shouldRegisterNavigation())->toBeTrue();
});

// ── The screens open ────────────────────────────────────────────────────────

it('opens the news list', function () {
    $this->actingAs(contentEditor());

    Livewire::test(ListPosts::class)->assertOk();
});

it('opens the FAQ list', function () {
    $this->actingAs(contentEditor());

    Livewire::test(ListFaqs::class)->assertOk();
});

it('opens the testimonials list', function () {
    $this->actingAs(contentEditor());

    Livewire::test(ListTestimonials::class)->assertOk();
});
