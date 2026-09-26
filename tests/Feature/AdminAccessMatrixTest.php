<?php

declare(strict_types=1);

use App\Filament\Pages\HelpPage;
use App\Filament\Resources\Pages\PageResource;
use App\Models\User;
use App\Policies\BasePolicy;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 14 — every admin route, every role
|--------------------------------------------------------------------------
|
| Each phase tested its own screens against its own permissions. Nothing
| tested that EVERY resource and page the panel registers is behind a
| policy, for every role the seeder creates. That is the gap a new resource
| falls through: registered, reachable, and authorised by nobody.
|
| The oracle is the policy itself — `$user->can('viewAny', Model)` for a
| resource, `canAccess()` for a page — so the assertion is that Filament
| enforces what the policies say, on every URL, for every role. A resource
| without a policy fails the first test outright; a policy Filament ignores
| fails the matrix.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(RoleAndPermissionSeeder::class);

    BasePolicy::forgetKnownPermissions();
});

/** @return array<class-string, string> resource class => the URL of its landing page */
function adminResourceUrls(): array
{
    $urls = [];

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        $pages = $resource::getPages();
        $key = array_key_exists('index', $pages) ? 'index' : array_key_first($pages);

        // A landing page that needs a record (a single-record resource with
        // only an edit page) cannot be reached without one; nothing here is
        // built that way today, and this keeps the walk honest if one appears.
        try {
            $urls[$resource] = $resource::getUrl($key);
        } catch (UrlGenerationException) {
            continue;
        }
    }

    expect($urls)->not->toBeEmpty();

    return $urls;
}

/** @return array<class-string, string> custom page class => URL, dashboard excluded */
function adminPageUrls(): array
{
    $urls = [];

    foreach (Filament::getPanel('admin')->getPages() as $page) {
        if (is_a($page, Dashboard::class, true)) {
            continue;
        }

        $urls[$page] = $page::getUrl();
    }

    expect($urls)->not->toBeEmpty();

    return $urls;
}

function staffWithTwoFactor(string ...$permissions): User
{
    $user = User::factory()->staff()->withTwoFactor()->create();

    if ($permissions !== []) {
        $user->givePermissionTo($permissions);
    }

    return $user->fresh();
}

/** @return array<int, string> "label: detail" lines for whatever did not match */
function walkAdmin(User $user, array $expectations): array
{
    $mismatches = [];

    foreach ($expectations as $url => [$label, $expected]) {
        $status = test()->get($url)->status();

        if ($status !== $expected) {
            $mismatches[] = "{$label} ({$url}): expected {$expected}, got {$status}";
        }
    }

    return $mismatches;
}

// ── The shape of the panel ──────────────────────────────────────────────────

it('registers a policy for every resource model, so nothing is authorised by default', function () {
    $unprotected = [];

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        if (Gate::getPolicyFor($resource::getModel()) === null) {
            $unprotected[] = $resource;
        }
    }

    expect($unprotected)->toBe([]);
});

it('gives every custom page its own canAccess() rather than Filament\'s open default', function () {
    $open = [];

    foreach (array_keys(adminPageUrls()) as $page) {
        $method = new ReflectionMethod($page, 'canAccess');

        if ($method->getDeclaringClass()->getName() !== $page) {
            $open[] = $page;
        }
    }

    expect($open)->toBe([]);
});

// ── Who gets through the door ───────────────────────────────────────────────

it('sends a signed-out visitor to the sign-in page from every admin URL', function () {
    $login = Filament::getPanel('admin')->getLoginUrl();
    $wrong = [];

    foreach (adminResourceUrls() + adminPageUrls() as $class => $url) {
        $response = $this->get($url);

        if (! $response->isRedirect() || $response->headers->get('Location') !== $login) {
            $wrong[] = "{$class}: {$response->status()} → ".($response->headers->get('Location') ?? '-');
        }
    }

    expect($wrong)->toBe([]);
});

it('refuses a donor every admin URL', function () {
    $this->actingAs(User::factory()->donor()->create()->fresh());

    $expectations = [];
    foreach (adminResourceUrls() + adminPageUrls() as $class => $url) {
        $expectations[$url] = [$class, 403];
    }

    expect(walkAdmin(auth()->user(), $expectations))->toBe([]);
});

it('lets staff holding admin.access alone see the dashboard and the manual, and nothing else', function () {
    $this->actingAs($staff = staffWithTwoFactor('admin.access'));

    $this->get(Dashboard::getUrl())->assertOk();
    $this->get(HelpPage::getUrl())->assertOk();

    $expectations = [];
    foreach (adminResourceUrls() + adminPageUrls() as $class => $url) {
        // The manual is documentation, open to every member of staff by
        // design; every other page needs the permission it protects.
        $expectations[$url] = [$class, $class === HelpPage::class ? 200 : 403];
    }

    expect(walkAdmin($staff, $expectations))->toBe([]);
});

it('opens every resource and page to a Super Admin', function () {
    $admin = User::factory()->staff()->withTwoFactor()->create();
    $admin->assignRole('Super Admin');
    $this->actingAs($admin = $admin->fresh());

    $expectations = [];
    foreach (adminResourceUrls() + adminPageUrls() as $class => $url) {
        $expectations[$url] = [$class, 200];
    }

    expect(walkAdmin($admin, $expectations))->toBe([]);
});

// ── The matrix ──────────────────────────────────────────────────────────────

it('enforces the policy of every resource and page for the :dataset role', function (string $role) {
    $user = User::factory()->staff()->withTwoFactor()->create();
    $user->assignRole($role);
    $this->actingAs($user = $user->fresh());

    $expectations = [];

    foreach (adminResourceUrls() as $resource => $url) {
        $allowed = $user->can('viewAny', $resource::getModel());
        $expectations[$url] = [$resource, $allowed ? 200 : 403];
    }

    foreach (adminPageUrls() as $page => $url) {
        $expectations[$url] = [$page, $page::canAccess() ? 200 : 403];
    }

    // A role that can see nothing is a seeding mistake, not a secure default.
    expect(array_filter($expectations, fn (array $e): bool => $e[1] === 200))->not->toBeEmpty();

    expect(walkAdmin($user, $expectations))->toBe([]);
})->with([
    'Admin', 'Content Editor', 'Finance Officer', 'Shop Manager',
    'Volunteer Coordinator', 'Programme Officer', 'Safeguarding Lead', 'Auditor', 'Support',
]);

it('takes a permission away and the screen goes with it', function () {
    // The matrix proves the policies are enforced; this proves they are
    // consulted live, not cached from the first request of the session.
    $this->actingAs($staff = staffWithTwoFactor('admin.access', 'pages.view'));

    $pages = PageResource::getUrl('index');

    $this->get($pages)->assertOk();

    $staff->revokePermissionTo('pages.view');
    BasePolicy::forgetKnownPermissions();
    app()->make(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->get($pages)->assertForbidden();
});
