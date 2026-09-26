<?php

declare(strict_types=1);

use App\Enums\PageStatus;
use App\Enums\ProjectStatus;
use App\Models\Cause;
use App\Models\Event;
use App\Models\Faq;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Project;
use App\Models\User;
use App\Models\VolunteerOpportunity;
use App\Support\Settings;
use App\Support\ThemeTokens;
use Database\Seeders\CmsReferenceSeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 13 — the structural accessibility checks, on every run
|--------------------------------------------------------------------------
|
| axe was run in a browser against every public page in Phase 13 (the
| report is in docs/PHASE-13-ACCESSIBILITY-REPORT.md). A browser is not
| available in CI, so the rules that can be checked from the HTML alone
| are checked here, on every commit, for every kind of public page: one
| h1, a heading order that never skips, the landmarks, a label on every
| control, alt on every image, no positive tabindex, the skip link, and
| the lang attribute. A page that loses one of these fails the build.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(CmsReferenceSeeder::class);
    $this->seed(MessageTemplateSeeder::class);

    ThemeTokens::flush();
    app(Settings::class)->flush();
});

/** @return array<string, string> route name/URL => label */
function publicPages(): array
{
    $post = Post::create(['title' => 'A visit to Bongo', 'slug' => 'a-visit-to-bongo', 'status' => PageStatus::Published, 'published_at' => now()->subDay(), 'body' => '<p>What happened.</p>', 'excerpt' => 'What we saw.']);
    $project = Project::create(['title' => 'Bongo school kits', 'slug' => 'bongo-school-kits', 'summary' => 'Kits.', 'description' => '<p>Kits.</p>', 'is_published' => true, 'published_at' => now()->subDay(), 'status' => ProjectStatus::Active]);
    $cause = Cause::factory()->create();
    $product = Product::factory()->create(['name' => 'Tote bag', 'slug' => 'tote-bag', 'is_published' => true]);
    ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 4_500, 'is_active' => true]);
    $event = Event::factory()->create();
    $role = VolunteerOpportunity::factory()->create(['is_published' => true]);
    Faq::create(['question' => 'Is my gift tax deductible?', 'answer' => '<p>It depends.</p>', 'is_published' => true, 'sort_order' => 1]);
    $page = Page::factory()->create(['status' => PageStatus::Published]);

    return [
        '/' => 'home',
        url($page->path) => 'cms page',
        route('news.index') => 'news',
        route('news.show', $post) => 'post',
        route('projects.index') => 'projects',
        route('projects.show', $project) => 'project',
        route('causes.index') => 'appeals',
        route('causes.show', $cause) => 'appeal',
        route('shop.index') => 'shop',
        route('shop.show', $product) => 'product',
        route('events.index') => 'events',
        route('events.show', $event) => 'event',
        route('volunteer.index') => 'volunteer',
        route('volunteer.show', $role) => 'role',
        route('faq') => 'faq',
        route('contact') => 'contact',
        route('donate') => 'donate',
        route('impact') => 'impact',
        route('login') => 'login',
        route('register') => 'register',
        route('search', ['q' => 'hope']) => 'search',
    ];
}

/** @return array<int, string> problems found in one document */
function auditHtml(string $html): array
{
    $problems = [];
    libxml_use_internal_errors(true);
    $doc = new DOMDocument;
    $doc->loadHTML('<?xml encoding="UTF-8">'.$html);
    $x = new DOMXPath($doc);

    if ($x->query('//html[@lang]')->length !== 1) {
        $problems[] = 'no lang on <html>';
    }

    $h1 = $x->query('//h1')->length;
    if ($h1 !== 1) {
        $problems[] = "expected one h1, found {$h1}";
    }

    // Heading order never skips a level going down (h2 → h4 is a skip).
    $last = 1;
    foreach ($x->query('//h1|//h2|//h3|//h4|//h5|//h6') as $heading) {
        $level = (int) substr($heading->nodeName, 1);
        if ($level > $last + 1) {
            $problems[] = "heading skips from h{$last} to h{$level} (\"".trim(mb_substr($heading->textContent, 0, 40)).'")';
        }
        $last = $level;
    }

    foreach (['main' => '//main', 'header/banner' => '//header|//*[@role="banner"]', 'footer/contentinfo' => '//footer|//*[@role="contentinfo"]', 'nav' => '//nav'] as $name => $query) {
        if ($x->query($query)->length === 0) {
            $problems[] = "no {$name} landmark";
        }
    }

    if ($x->query('//a[@href="#main-content"]')->length === 0) {
        $problems[] = 'no skip link';
    }

    foreach ($x->query('//img') as $img) {
        if (! $img->hasAttribute('alt')) {
            $problems[] = 'img without alt: '.mb_substr((string) $img->getAttribute('src'), 0, 60);
        }
    }

    foreach ($x->query('//input[not(@type="hidden") and not(@type="submit") and not(@type="button")]|//select|//textarea') as $control) {
        // The honeypot sits in a display:none box: not rendered, not a control.
        if ($x->query('ancestor-or-self::*[@hidden or contains(@style, "display:none") or contains(@style, "display: none")]', $control)->length > 0) {
            continue;
        }

        $id = (string) $control->getAttribute('id');
        $labelled = ($id !== '' && $x->query('//label[@for="'.$id.'"]')->length > 0)
            || $control->hasAttribute('aria-label')
            || $control->hasAttribute('aria-labelledby')
            || $x->query('ancestor::label', $control)->length > 0;
        if (! $labelled) {
            $problems[] = 'unlabelled control: '.$control->nodeName.' name='.(string) $control->getAttribute('name');
        }
    }

    foreach ($x->query('//*[@tabindex]') as $el) {
        if ((int) $el->getAttribute('tabindex') > 0) {
            $problems[] = 'positive tabindex on '.$el->nodeName;
        }
    }

    foreach ($x->query('//a[not(@href)]|//a[@href=""]|//a[@href="#"]') as $a) {
        $problems[] = 'link without a destination: "'.trim(mb_substr($a->textContent, 0, 40)).'"';
    }

    foreach ($x->query('//button[not(normalize-space(.)) and not(@aria-label) and not(@aria-labelledby) and not(@title)]') as $b) {
        $problems[] = 'button with no accessible name';
    }

    if ($x->query('//*[@aria-live]|//*[@role="status"]|//*[@role="alert"]')->length === 0) {
        $problems[] = 'no live region for announcements';
    }

    return $problems;
}

it('keeps every public page structurally accessible', function () {
    $failures = [];

    foreach (publicPages() as $url => $label) {
        $response = $this->get($url);

        if ($response->status() !== 200) {
            $failures[] = "{$label} ({$url}): HTTP {$response->status()}";

            continue;
        }

        foreach (auditHtml($response->getContent()) as $problem) {
            $failures[] = "{$label}: {$problem}";
        }
    }

    expect($failures)->toBe([]);
});

it('keeps the error pages and the account pages accessible too', function () {
    $user = User::factory()->donor()->create()->fresh();
    $failures = [];

    foreach (['/this-page-does-not-exist' => 404] as $url => $expected) {
        $response = $this->get($url);
        expect($response->status())->toBe($expected);
        foreach (auditHtml($response->getContent()) as $problem) {
            $failures[] = "404: {$problem}";
        }
    }

    $this->actingAs($user);
    foreach ([route('account.profile') => 'profile', route('account.security') => 'security', route('account.privacy') => 'privacy'] as $url => $label) {
        $response = $this->get($url)->assertOk();
        foreach (auditHtml($response->getContent()) as $problem) {
            $failures[] = "{$label}: {$problem}";
        }
    }

    expect($failures)->toBe([]);
});

it('ties a form error to its field and announces it', function () {
    $response = $this->from(route('contact'))->post(route('contact'), ['name' => '', 'email' => 'not-an-email'])->assertRedirect(route('contact'));
    $html = $this->get(route('contact'))->assertOk()->getContent();

    expect($html)->toContain('aria-invalid="true"')
        ->and($html)->toContain('role="alert"')
        ->and($html)->toMatch('/aria-describedby="[^"]*email-error/');
});

it('respects reduced motion and never autoplays anything', function () {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));
    expect($css)->toContain('prefers-reduced-motion: reduce');

    foreach (glob(resource_path('views/**/*.blade.php')) as $view) {
        $source = (string) file_get_contents($view);
        expect($source)->not->toContain('autoplay', $view)->and($source)->not->toContain('<marquee', $view);
    }
});
