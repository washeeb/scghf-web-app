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
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

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

    // Through the real ledger: completed, with a transaction row, and counted
    // on the appeal. Not acknowledged — a receipt is an email, and a demo
    // donor's address is nobody's.
    expect(Donation::where('status', DonationStatus::Completed)->count())->toBe(36)
        ->and(Donation::whereDoesntHave('transaction')->count())->toBe(0)
        ->and(Cause::where('slug', 'back-to-school-2026')->sole()->raisedAmount()->toMinor())->toBeGreaterThan(0);

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
