<?php

declare(strict_types=1);

use App\Models\Comment;
use App\Models\ContactDepartment;
use App\Models\ContactMessage;
use App\Models\Document;
use App\Models\Gallery;
use App\Models\MediaFolder;
use App\Models\Page;
use App\Models\Post;
use App\Models\Redirect;
use App\Models\SeoMeta;
use App\Models\Subscriber;
use App\Models\Testimonial;
use App\Models\User;
use Database\Seeders\CmsReferenceSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Redirects ────────────────────────────────────────────────────────────────

it('normalises paths so one URL does not become three rows', function (string $input, string $expected) {
    expect(Redirect::normalise($input))->toBe($expected);
})->with([
    ['/about/', '/about'],
    ['about', '/about'],
    ['  /about  ', '/about'],
    ['/', '/'],
    ['', '/'],
]);

it('refuses a redirect that points at itself', function () {
    Redirect::create(['from_path' => '/a', 'to_path' => '/a']);
})->throws(RuntimeException::class);

it('refuses a redirect with no destination unless it is 410 gone', function () {
    Redirect::create(['from_path' => '/a', 'status_code' => 301]);
})->throws(RuntimeException::class);

it('allows a 410 with no destination', function () {
    $gone = Redirect::create(['from_path' => '/removed', 'status_code' => 410]);

    expect($gone->to_path)->toBeNull();
});

it('refuses a redirect loop', function () {
    Redirect::create(['from_path' => '/a', 'to_path' => '/b']);
    Redirect::create(['from_path' => '/b', 'to_path' => '/a']);
})->throws(RuntimeException::class);

it('follows a chain to its destination in one hop', function () {
    Redirect::create(['from_path' => '/a', 'to_path' => '/b']);
    Redirect::create(['from_path' => '/b', 'to_path' => '/c']);

    // Two redirects in a row costs a mobile visitor a full extra request.
    expect(Redirect::resolve('/a')->to_path)->toBe('/c');
});

it('captures a 404 as an inactive report, never as a live redirect', function () {
    $captured = Redirect::record404('/old-page', 'https://google.com');

    expect($captured->source)->toBe('auto_404')
        // Critically NOT active. It records that a path broke; it must not
        // start redirecting anywhere until a human says where.
        ->and($captured->is_active)->toBeFalse()
        ->and($captured->to_path)->toBeNull()
        ->and($captured->hits)->toBe(1)
        ->and(Redirect::resolve('/old-page'))->toBeNull();
});

it('counts repeat 404s on the same path instead of duplicating it', function () {
    Redirect::record404('/old-page');
    Redirect::record404('/old-page');
    Redirect::record404('/old-page');

    expect(Redirect::where('from_path', '/old-page')->count())->toBe(1)
        ->and(Redirect::where('from_path', '/old-page')->first()->hits)->toBe(3);
});

it('ignores scanner noise so the table stays useful', function (string $path) {
    // Bots probe thousands of paths a day. Recording them would bury the real
    // broken links an editor needs to see.
    expect(Redirect::record404($path))->toBeNull();
})->with(['/wp-admin', '/wp-login.php', '/.env', '/.git/config', '/xmlrpc.php']);

// ── SEO ──────────────────────────────────────────────────────────────────────

it('falls back through override, entity and settings', function () {
    $this->seed(SettingsSeeder::class);
    $post = Post::create(['title' => 'A day in Tamale', 'excerpt' => 'What we saw.']);

    // No override: uses the post's own title plus the site suffix.
    expect($post->seoTitle())->toContain('A day in Tamale')
        ->and($post->seoDescription())->toBe('What we saw.');

    $post->seo()->create(['title' => 'Custom title', 'description' => 'Custom description']);

    expect($post->fresh()->seoTitle())->toContain('Custom title')
        ->and($post->fresh()->seoDescription())->toBe('Custom description');
});

it('truncates a long description on a word boundary', function () {
    $post = Post::create(['title' => 'T', 'excerpt' => str_repeat('word ', 100)]);

    expect(strlen($post->seoDescription()))->toBeLessThanOrEqual(161)
        ->and($post->seoDescription())->toEndWith('...');
});

it('never lets a page override site-wide noindex', function () {
    $this->seed(SettingsSeeder::class);
    setting()->set('seo.allow_indexing', false);

    $page = Page::create(['title' => 'P', 'slug' => 'p']);
    $page->seo()->create(['no_index' => false]);

    // A noindexed staging site that leaks one indexable page is worse than
    // useless — the whole point is that nothing is indexed.
    expect($page->seoShouldIndex())->toBeFalse();
});

it('emits a robots directive', function () {
    $meta = new SeoMeta(['no_index' => true, 'no_follow' => false]);

    expect($meta->robots())->toBe('noindex, follow');
});

// ── Consent gates ────────────────────────────────────────────────────────────

it('refuses to publish a beneficiary testimonial without consent', function () {
    Testimonial::create([
        'author_name' => 'A beneficiary',
        'quote' => 'The foundation helped my family.',
        'author_type' => 'beneficiary',
        'is_published' => true,
        'has_consent' => false,
    ]);
})->throws(RuntimeException::class);

it('publishes a beneficiary testimonial once consent is recorded', function () {
    $t = Testimonial::create([
        'author_name' => 'A beneficiary', 'quote' => 'Thank you.',
        'author_type' => 'beneficiary', 'has_consent' => true,
        'consent_date' => now(), 'is_published' => true,
    ]);

    expect($t->is_published)->toBeTrue();
});

it('does not require consent from a partner speaking professionally', function () {
    $t = Testimonial::create([
        'author_name' => 'A partner church', 'quote' => 'A pleasure to work with.',
        'author_type' => 'partner', 'is_published' => true, 'has_consent' => false,
    ]);

    expect($t->is_published)->toBeTrue();
});

it('refuses to publish a gallery without consent', function () {
    Gallery::create(['title' => 'Outreach', 'is_published' => true, 'has_consent' => false]);
})->throws(RuntimeException::class);

it('refuses to delete the locked beneficiaries media folder', function () {
    $this->seed(CmsReferenceSeeder::class);

    // Consent rules are keyed to this folder.
    MediaFolder::where('name', 'Beneficiaries')->first()->delete();
})->throws(RuntimeException::class);

// ── Documents ────────────────────────────────────────────────────────────────

it('gates a restricted document behind authentication', function () {
    $public = Document::create(['title' => 'Annual Report', 'is_published' => true]);
    $internal = Document::create(['title' => 'Internal Policy', 'is_published' => true, 'requires_auth' => true]);
    $draft = Document::create(['title' => 'Draft', 'is_published' => false]);

    $user = User::factory()->create();

    expect($public->isDownloadableBy(null))->toBeTrue()
        ->and($internal->isDownloadableBy(null))->toBeFalse()
        ->and($internal->isDownloadableBy($user))->toBeTrue()
        // An unpublished document is not downloadable by anyone via this route.
        ->and($draft->isDownloadableBy($user))->toBeFalse();
});

it('counts downloads atomically', function () {
    $doc = Document::create(['title' => 'Report', 'is_published' => true]);

    $doc->recordDownload();
    $doc->recordDownload();

    expect($doc->fresh()->download_count)->toBe(2);
});

// ── Newsletter ───────────────────────────────────────────────────────────────

it('starts a subscriber as pending with both tokens ready', function () {
    $s = Subscriber::create(['email' => 'Donor@Example.COM']);

    expect($s->status)->toBe(Subscriber::STATUS_PENDING)
        ->and($s->email)->toBe('donor@example.com')
        ->and($s->confirmation_token)->not->toBeEmpty()
        // Generated up front: every email must carry a working one-click
        // unsubscribe, including the very first one.
        ->and($s->unsubscribe_token)->not->toBeEmpty()
        ->and($s->canBeEmailed())->toBeFalse();
});

it('records what was consented to, not merely that consent happened', function () {
    $s = Subscriber::create(['email' => 'a@b.com']);
    $s->recordConsent('Yes, email me updates about the foundation.', '10.0.0.1', 'https://example.com/');

    expect($s->consent_text)->toBe('Yes, email me updates about the foundation.')
        ->and($s->consent_ip)->toBe('10.0.0.1')
        ->and($s->consent_at)->not->toBeNull();
});

it('only becomes mailable after confirming', function () {
    $s = Subscriber::create(['email' => 'a@b.com']);
    expect($s->canBeEmailed())->toBeFalse();

    $s->confirm();

    expect($s->canBeEmailed())->toBeTrue()
        // The token is burned so the link cannot be reused later by someone else.
        ->and($s->confirmation_token)->toBeNull();
});

it('stops mailing an address that unsubscribed, bounced or complained', function (string $method, string $arg) {
    $s = Subscriber::create(['email' => 'a@b.com']);
    $s->confirm();

    $arg === '' ? $s->{$method}() : $s->{$method}($arg);

    expect($s->fresh()->canBeEmailed())->toBeFalse();
})->with([
    ['unsubscribe', ''],
    ['suppress', Subscriber::STATUS_BOUNCED],
    ['suppress', Subscriber::STATUS_COMPLAINED],
]);

it('finds pending sign-ups that were never confirmed', function () {
    Subscriber::create(['email' => 'old@b.com'])->forceFill(['created_at' => now()->subDays(40)])->save();
    Subscriber::create(['email' => 'new@b.com']);

    expect(Subscriber::stalePending()->pluck('email')->all())->toBe(['old@b.com']);
});

// ── Contact ──────────────────────────────────────────────────────────────────

it('gives every message a quotable reference', function () {
    $m = ContactMessage::create(['name' => 'A', 'email' => 'A@B.com', 'message' => 'Hello']);

    expect($m->reference)->toStartWith('SCGHF-C-')
        ->and($m->email)->toBe('a@b.com');
});

it('keeps safeguarding reports out of the general inbox', function () {
    $this->seed(CmsReferenceSeeder::class);

    $general = ContactDepartment::where('key', 'general')->first();
    $safeguarding = ContactDepartment::where('key', 'safeguarding')->first();

    ContactMessage::create(['contact_department_id' => $general->id, 'name' => 'A', 'email' => 'a@b.com', 'message' => 'Shop query']);
    $report = ContactMessage::create(['contact_department_id' => $safeguarding->id, 'name' => 'B', 'email' => 'b@b.com', 'message' => 'A concern']);

    // A safeguarding report must never sit in a list beside shop queries.
    expect($report->isConfidential())->toBeTrue()
        ->and(ContactMessage::generalInbox()->pluck('message')->all())->toBe(['Shop query']);
});

it('flags a message past its department SLA', function () {
    $this->seed(CmsReferenceSeeder::class);
    $dept = ContactDepartment::where('key', 'safeguarding')->first();   // 4 hours

    $message = ContactMessage::create(['contact_department_id' => $dept->id, 'name' => 'A', 'email' => 'a@b.com', 'message' => 'x']);
    expect($message->isOverdue())->toBeFalse();

    $this->travel(5)->hours();
    expect($message->fresh()->isOverdue())->toBeTrue();
});

// ── Blog ─────────────────────────────────────────────────────────────────────

it('estimates reading time from the body', function () {
    $post = Post::create(['title' => 'T', 'body' => str_repeat('word ', 400)]);

    expect($post->reading_minutes)->toBe(2);
});

it('never reports zero reading minutes', function () {
    expect(Post::create(['title' => 'T', 'body' => 'Short.'])->reading_minutes)->toBe(1);
});

it('holds every comment for moderation', function () {
    $post = Post::create(['title' => 'T']);
    $comment = Comment::create([
        'post_id' => $post->id, 'author_name' => 'A', 'author_email' => 'a@b.com', 'body' => 'Nice',
    ]);

    expect($comment->status)->toBe(Comment::STATUS_PENDING)
        ->and($comment->isApproved())->toBeFalse()
        ->and($post->fresh()->comment_count)->toBe(0);
});

it('counts a comment only once approved, and uncounts it when removed', function () {
    $post = Post::create(['title' => 'T']);
    $moderator = User::factory()->staff()->create();

    $comment = Comment::create(['post_id' => $post->id, 'author_name' => 'A', 'author_email' => 'a@b.com', 'body' => 'x']);
    $comment->approve($moderator);
    expect($post->fresh()->comment_count)->toBe(1);

    $comment->markSpam($moderator);
    expect($post->fresh()->comment_count)->toBe(0);
});

it('hides a commenter email and IP from serialisation', function () {
    $comment = Comment::create([
        'post_id' => Post::create(['title' => 'T'])->id,
        'author_name' => 'A', 'author_email' => 'a@b.com', 'body' => 'x', 'ip_address' => '10.0.0.1',
    ]);

    expect($comment->toArray())->not->toHaveKey('author_email')
        ->and($comment->toArray())->not->toHaveKey('ip_address');
});

it('keeps the comment form closed while the feature flag is off', function () {
    config(['features.blog_comments' => false]);
    $post = Post::create(['title' => 'T']);
    $post->publish();

    expect($post->fresh()->acceptsComments())->toBeFalse();

    config(['features.blog_comments' => true]);
    expect($post->fresh()->acceptsComments())->toBeTrue();
});

it('counts prior spam from an email address', function () {
    $post = Post::create(['title' => 'T']);
    $moderator = User::factory()->staff()->create();

    foreach (range(1, 3) as $i) {
        Comment::create(['post_id' => $post->id, 'author_name' => 'S', 'author_email' => 'spam@b.com', 'body' => "x{$i}"])
            ->markSpam($moderator);
    }

    expect(Comment::spamCountFor('SPAM@B.com'))->toBe(3);
});
