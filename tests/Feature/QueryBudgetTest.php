<?php

declare(strict_types=1);

use App\Models\Cause;
use App\Models\Donor;
use App\Models\Event;
use App\Models\Post;
use App\Models\Product;
use App\Models\Project;
use App\Models\User;
use App\Models\VolunteerOpportunity;
use App\Support\Settings;
use App\Support\ThemeTokens;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 15 — a query budget for every public page
|--------------------------------------------------------------------------
|
| Shared hosting bills in PHP seconds and MySQL connections, and a page that
| runs a query per card is the commonest way to spend both. This test
| renders every kind of public page against the demo data (four projects,
| three appeals, six posts, four products — enough for an N+1 to show) and
| holds each to a budget, and to ZERO repeated statements: the same SQL
| with the same bindings twice is a loop somebody forgot to eager-load.
|
| The budgets are the figure measured at the end of Phase 15 (with the
| fragment cache warm and the full-page cache off) plus four: the four
| visitor-statistics upserts that every counted page view writes after the
| response. Raising one is a deliberate decision recorded in the changelog.
|
| Run with QUERY_BUDGET_REPORT=1 to print the per-page counts and the
| duplicated statements instead of asserting.
|
*/

beforeEach(function () {
    // The budget is for a RENDER. The full-page cache would answer every
    // second request from a file and hide any N+1 behind it.
    config(['performance.page_cache.enabled' => false]);

    $this->seed(DatabaseSeeder::class);
    $this->seed(DemoDataSeeder::class);

    ThemeTokens::flush();
    app(Settings::class)->flush();
});

/** @return array<string, array{string, int}> label => [url, budget] */
function queryBudgets(): array
{
    $post = Post::query()->orderBy('id')->firstOrFail();
    $project = Project::query()->orderBy('id')->firstOrFail();
    $cause = Cause::query()->where('slug', 'back-to-school-2026')->firstOrFail();
    $product = Product::query()->orderBy('id')->firstOrFail();
    $event = Event::query()->orderBy('id')->firstOrFail();
    $role = VolunteerOpportunity::query()->orderBy('id')->firstOrFail();

    return [
        'home' => ['/', 12],
        'donate' => [route('donate'), 10],
        'donate for an appeal' => [route('donate', ['cause' => $cause->slug]), 25],
        'appeals' => [route('causes.index'), 12],
        'appeal' => [route('causes.show', $cause), 15],
        'projects' => [route('projects.index'), 15],
        'project' => [route('projects.show', $project), 18],
        'areas of work' => [route('focus-areas.index'), 10],
        'impact' => [route('impact'), 10],
        'news' => [route('news.index'), 13],
        'post' => [route('news.show', $post), 16],
        'shop' => [route('shop.index'), 13],
        'product' => [route('shop.show', $product), 16],
        'basket (empty)' => [route('shop.cart'), 8],
        'events' => [route('events.index'), 11],
        'event' => [route('events.show', $event), 10],
        'volunteer' => [route('volunteer.index'), 10],
        'role' => [route('volunteer.show', $role), 10],
        'team' => [route('team'), 10],
        'partners' => [route('partners'), 10],
        'faq' => [route('faq'), 10],
        'contact' => [route('contact'), 10],
        'search' => [route('search', ['q' => 'bongo']), 30],
        'give (offline)' => [route('give'), 8],
        'sitemap index' => [route('sitemap'), 8],
        'login' => [route('login'), 8],
        '404' => ['/nothing-here', 8],
        'offline' => [route('pwa.offline'), 8],
        'screen' => [route('screen.show', $cause), 12],
        'screen feed' => [route('screen.feed', $cause), 8],
        'manifest' => [route('pwa.manifest'), 8],
    ];
}

/** @return array{count: int, log: array<int, array<string, mixed>>, duplicates: array<string, int>} */
function measureQueries(string $url): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    test()->get($url);

    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $seen = [];
    foreach ($log as $q) {
        $key = $q['query'].' '.json_encode($q['bindings']);
        $seen[$key] = ($seen[$key] ?? 0) + 1;
    }

    return [
        'count' => count($log),
        'log' => $log,
        'duplicates' => array_filter($seen, fn (int $n): bool => $n > 1),
    ];
}

it('renders every public page within its query budget and never repeats a statement', function () {
    $report = (bool) env('QUERY_BUDGET_REPORT', false);
    $failures = [];

    foreach (array_intersect_key(queryBudgets(), array_flip(env('QUERY_BUDGET_ONLY') ? explode(',', env('QUERY_BUDGET_ONLY')) : array_keys(queryBudgets()))) as $label => [$url, $budget]) {
        // Warm the caches a real visitor would find warm (settings, theme,
        // menus); the first request after a deploy is not the budget.
        test()->get($url);

        $measured = measureQueries($url);

        if ($report) {
            fwrite(STDERR, sprintf("%-22s %3d queries  (budget %d)\n", $label, $measured['count'], $budget));
            if (env('QUERY_BUDGET_REPORT') === 'all') {
                foreach ($measured['log'] as $q) {
                    fwrite(STDERR, '      '.mb_substr((string) $q['query'], 0, 150)."\n");
                }
            }
            foreach ($measured['duplicates'] as $sql => $n) {
                fwrite(STDERR, "    ×{$n}  ".mb_substr($sql, 0, 160)."\n");
            }

            continue;
        }

        if ($measured['count'] > $budget) {
            $failures[] = "{$label}: {$measured['count']} queries, budget {$budget}";
        }

        foreach ($measured['duplicates'] as $sql => $n) {
            $failures[] = "{$label}: statement repeated ×{$n}: ".mb_substr($sql, 0, 120);
        }
    }

    expect($failures)->toBe([]);
});

it('renders the account pages within budget for a donor with a giving history', function () {
    $user = User::factory()->donor()->create()->fresh();
    $this->actingAs($user);

    foreach ([route('account.profile') => 15, route('account.giving') => 20] as $url => $budget) {
        test()->get($url);
        $measured = measureQueries($url);

        expect($measured['count'])->toBeLessThanOrEqual($budget, $url)
            ->and($measured['duplicates'])->toBe([], $url);
    }
});

it('renders the impact timeline and the receipts archive within budget for the busiest demo donor', function () {
    /*
     * The timeline reads per appeal (its updates) and per project (its
     * metrics), so the budget grows with how widely a donor has given —
     * bounded by the number of appeals, which is small, not by the number
     * of gifts, which is not.
     */
    $donor = Donor::query()->orderByDesc('donation_count')->firstOrFail();
    $user = User::factory()->donor()->create(['email' => $donor->email])->fresh();
    $donor->forceFill(['user_id' => $user->id])->save();
    $this->actingAs($user);

    foreach ([route('account.impact') => 40, route('account.receipts') => 15] as $url => $budget) {
        test()->get($url);
        $measured = measureQueries($url);

        expect($measured['count'])->toBeLessThanOrEqual($budget, $url)
            ->and($measured['duplicates'])->toBe([], $url);
    }
});
