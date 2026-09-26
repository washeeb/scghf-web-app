<?php

declare(strict_types=1);

use App\Enums\DonationStatus;
use App\Models\Cause;
use App\Models\Donation;
use App\Models\Order;
use App\Models\Post;
use App\Models\Product;
use App\Models\Project;
use App\Models\User;
use App\Payments\ReconciliationService;
use App\Support\Settings;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 14 — the demo data seeder
|--------------------------------------------------------------------------
|
| Two things matter about seed data for a staging site: that it refuses to
| exist on the live one, and that running it twice does not produce twice
| as much of it. Everything else is a nicety this checks in passing.
|
*/

it('refuses to run in production before touching anything', function () {
    $this->seed(DatabaseSeeder::class);
    app()->detectEnvironment(fn (): string => 'production');

    try {
        // Called directly: `db:seed` itself asks for confirmation in production
        // before any seeder runs, and this is about the seeder's own guard.
        expect(fn () => app(DemoDataSeeder::class)->run())->toThrow(RuntimeException::class, 'does not run in production');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }

    expect(User::where('email', 'like', 'demo.%')->count())->toBe(0)
        ->and(Donation::count())->toBe(0);
});

it('seeds a believable foundation once, and only once', function () {
    Mail::fake();
    $this->seed(DatabaseSeeder::class);
    $this->seed(DemoDataSeeder::class);

    $counts = fn (): array => [
        'staff' => User::where('email', 'like', 'demo.%@example.test')->count(),
        'projects' => Project::count(),
        'causes' => Cause::count(),
        'posts' => Post::count(),
        'products' => Product::count(),
        'donations' => Donation::count(),
        'orders' => Order::count(),
    ];

    $first = $counts();

    expect($first['staff'])->toBe(count(DemoDataSeeder::STAFF))
        ->and($first['projects'])->toBeGreaterThanOrEqual(4)
        ->and($first['posts'])->toBe(6)
        ->and($first['products'])->toBe(4)
        ->and($first['donations'])->toBe(36)
        ->and($first['orders'])->toBe(8);

    // Through the real ledger: completed, with a transaction row, acknowledged
    // (a numbered receipt row — no email is sent for it), and counted on the
    // appeal. Unacknowledged completed gifts are what the nightly
    // reconciliation reports; a demo ledger must not wake anybody up.
    expect(Donation::where('status', DonationStatus::Completed)->count())->toBe(36)
        ->and(Donation::whereDoesntHave('transaction')->count())->toBe(0)
        ->and(Donation::whereDoesntHave('receipt')->count())->toBe(0)
        ->and(Cause::where('slug', 'back-to-school-2026')->sole()->raisedAmount()->toMinor())->toBeGreaterThan(0);

    Mail::assertNothingSent();
    Mail::assertNothingQueued();

    $summary = app(ReconciliationService::class)->run(execute: false);
    expect($summary['missing_receipts'])->toBe(0);

    $this->seed(DemoDataSeeder::class);

    expect($counts())->toBe($first);
});

it('gives every seeded role a staff account that can sign in and has no second factor yet', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DemoDataSeeder::class);

    foreach (DemoDataSeeder::STAFF as $role => $email) {
        $user = User::where('email', $email)->sole();

        expect($user->hasRole($role))->toBeTrue("{$email} should hold {$role}")
            ->and(Hash::check(DemoDataSeeder::DEMO_PASSWORD, $user->password))->toBeTrue()
            ->and($user->canAccessPanel())->toBeTrue()
            ->and($user->two_factor_confirmed_at)->toBeNull();
    }
});

it('acknowledges under the demo TIN only when the real one is unfilled, and never overwrites a real one', function () {
    $this->seed(DatabaseSeeder::class);

    // Fresh install: the placeholder. The seeder fills it so receipts can issue.
    expect(app(Settings::class)->get('general.tin'))->toBeNull();
    $this->seed(DemoDataSeeder::class);
    expect(app(Settings::class)->get('general.tin'))->toBe(DemoDataSeeder::DEMO_TIN);

    // A real TIN stays exactly as it was.
    $this->artisan('migrate:fresh', ['--force' => true]);
    $this->seed(DatabaseSeeder::class);
    app(Settings::class)->set('general.tin', 'C0001234567');
    app(Settings::class)->flush();
    $this->seed(DemoDataSeeder::class);
    expect(app(Settings::class)->get('general.tin'))->toBe('C0001234567');
});
