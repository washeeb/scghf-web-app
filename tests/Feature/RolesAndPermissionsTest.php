<?php

declare(strict_types=1);

use App\Enums\UserType;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('seeds every role from the blueprint matrix', function () {
    expect(Role::pluck('name')->all())
        ->toContain(
            'Super Admin', 'Admin', 'Content Editor', 'Finance Officer',
            'Shop Manager', 'Volunteer Coordinator', 'Programme Officer',
            'Safeguarding Lead', 'Auditor', 'Support', 'Donor',
        );
});

it('is idempotent, so it can run on every deploy', function () {
    $before = Permission::count();

    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(RoleAndPermissionSeeder::class);

    expect(Permission::count())->toBe($before);
});

// ── The Super Admin wildcard ─────────────────────────────────────────────────

it('lets a super admin pass a permission that was never granted to anyone', function () {
    $user = User::factory()->staff()->create();
    $user->assignRole('Super Admin');

    // Deliberately a permission that does not exist in the matrix at all.
    expect($user->can('some.permission.invented.next.year'))->toBeTrue();
});

it('blocks a SUSPENDED super admin from everything', function () {
    $user = User::factory()->staff()->suspended()->create();
    $user->assignRole('Super Admin');

    // The suspension gate is registered before the wildcard precisely so that
    // the most privileged account is still switch-off-able.
    expect($user->can('donations.view'))->toBeFalse()
        ->and($user->can('anything.at.all'))->toBeFalse();
});

it('blocks a deactivated user regardless of role', function () {
    $user = User::factory()->staff()->inactive()->create();
    $user->assignRole('Admin');

    expect($user->can('pages.update'))->toBeFalse();
});

// ── Separation of duties ─────────────────────────────────────────────────────

it('does not let an Admin rewrite the permission matrix or restore backups', function () {
    $user = User::factory()->staff()->create();
    $user->assignRole('Admin');

    expect($user->can('pages.update'))->toBeTrue()
        ->and($user->can('donations.refund'))->toBeTrue()
        // An Admin who can grant themselves permissions is a Super Admin.
        ->and($user->can('roles.manage'))->toBeFalse()
        ->and($user->can('backups.restore'))->toBeFalse()
        ->and($user->can('payments.view_keys'))->toBeFalse();
});

it('does not let a Content Editor send a newsletter or touch money', function () {
    $user = User::factory()->staff()->create();
    $user->assignRole('Content Editor');

    expect($user->can('pages.update'))->toBeTrue()
        ->and($user->can('newsletter.draft'))->toBeTrue()
        // A mistake here reaches every subscriber at once and cannot be recalled.
        ->and($user->can('newsletter.send'))->toBeFalse()
        ->and($user->can('donations.view'))->toBeFalse()
        ->and($user->can('beneficiaries.view'))->toBeFalse()
        ->and($user->can('theme.colours.manage'))->toBeFalse();
});

it('does not let a Finance Officer replay a webhook or exceed the refund limit', function () {
    $user = User::factory()->staff()->create();
    $user->assignRole('Finance Officer');

    expect($user->can('donations.refund'))->toBeTrue()
        ->and($user->can('payments.reconcile'))->toBeTrue()
        // Replaying a webhook rewrites financial history.
        ->and($user->can('payments.replay_webhook'))->toBeFalse()
        ->and($user->can('donations.refund_over_limit'))->toBeFalse()
        ->and($user->can('pages.update'))->toBeFalse();
});

it('lets a Shop Manager request a refund but not issue one', function () {
    $user = User::factory()->staff()->create();
    $user->assignRole('Shop Manager');

    expect($user->can('orders.fulfil'))->toBeTrue()
        ->and($user->can('orders.refund_request'))->toBeTrue()
        // Fulfilment and money stay separate.
        ->and($user->can('donations.refund'))->toBeFalse();
});

it('gives the Auditor read access and no write access anywhere', function () {
    $user = User::factory()->staff()->create();
    $user->assignRole('Auditor');

    expect($user->can('donations.view'))->toBeTrue()
        ->and($user->can('payments.view_transactions'))->toBeTrue()
        ->and($user->can('activity_log.view_all'))->toBeTrue();

    // Nothing the Auditor holds may mutate anything.
    $writes = $user->getAllPermissions()
        ->pluck('name')
        ->filter(fn (string $p): bool => (bool) preg_match('/\.(create|update|delete|manage|send|refund|fulfil|restore|replay)/', $p));

    expect($writes)->toBeEmpty();
});

it('gives the Donor role no admin permissions at all', function () {
    $user = User::factory()->donor()->create();
    $user->assignRole('Donor');

    expect($user->getAllPermissions())->toBeEmpty()
        ->and($user->can('admin.access'))->toBeFalse();
});

// ── Panel access ─────────────────────────────────────────────────────────────

it('only lets active unsuspended staff into the admin panel', function () {
    expect(User::factory()->staff()->create()->canAccessPanel())->toBeTrue()
        ->and(User::factory()->donor()->create()->canAccessPanel())->toBeFalse()
        ->and(User::factory()->staff()->suspended()->create()->canAccessPanel())->toBeFalse()
        ->and(User::factory()->staff()->inactive()->create()->canAccessPanel())->toBeFalse();
});

it('requires two factor for staff but not donors', function () {
    $staff = User::factory()->staff()->create();
    $donor = User::factory()->donor()->create();

    expect($staff->mustEnrolInTwoFactor())->toBeTrue()
        ->and($donor->mustEnrolInTwoFactor())->toBeFalse()
        ->and(UserType::Staff->requiresTwoFactor())->toBeTrue();

    expect(User::factory()->staff()->withTwoFactor()->create()->mustEnrolInTwoFactor())->toBeFalse();
});

// ── Regression: the Gate::before ordering trap ───────────────────────────────

it('denies a permission the inactive user genuinely holds', function () {
    // The subtle case. An inactive user with a role that DOES grant the
    // permission must still be denied.
    //
    // This originally passed when it should not have: spatie/laravel-permission
    // registers its own Gate::before, package providers boot before app
    // providers, so spatie returned true and short-circuited our suspension
    // gate. The guard now lives on the model. Do not move it back into a gate.
    $user = User::factory()->staff()->inactive()->create();
    $user->assignRole('Admin');

    expect($user->hasRole('Admin'))->toBeTrue()
        ->and($user->getRoleNames())->toContain('Admin')
        // Held via the role, denied by account state.
        ->and($user->can('pages.update'))->toBeFalse()
        ->and($user->hasPermissionTo('pages.update'))->toBeFalse();
});

it('denies a suspended user a permission they genuinely hold', function () {
    $user = User::factory()->staff()->suspended()->create();
    $user->assignRole('Finance Officer');

    expect($user->can('donations.view'))->toBeFalse()
        ->and($user->hasPermissionTo('donations.view'))->toBeFalse();
});

it('restores permissions when the account is reactivated', function () {
    $user = User::factory()->staff()->inactive()->create();
    $user->assignRole('Admin');

    expect($user->can('pages.update'))->toBeFalse();

    $user->reinstate();

    expect($user->fresh()->can('pages.update'))->toBeTrue();
});

// ── Regression: wildcard expansion must not over-grant ───────────────────────

it('honours every negated permission in the matrix', function () {
    // Guards the '!' mechanism itself. A group wildcard is convenient but blunt,
    // and a comment claiming a permission is withheld must not disagree with
    // what the seeder actually grants.
    $admin = User::factory()->staff()->create();
    $admin->assignRole('Admin');

    expect($admin->can('donations.view'))->toBeTrue()      // from fundraising.*
        ->and($admin->can('payments.replay_webhook'))->toBeTrue()
        // Reading the live secret key moves money outside the audit trail.
        ->and($admin->can('payments.view_keys'))->toBeFalse();
});

it('will not let suspension be set by mass assignment', function () {
    $user = User::factory()->staff()->create();

    // Cutting off access is a decision, not a form field. A request payload
    // must never be able to suspend — or un-suspend — an account.
    expect(fn () => $user->update(['suspended_at' => now()]))
        ->toThrow(MassAssignmentException::class);

    $user->suspend('Policy breach');
    expect($user->fresh()->isSuspended())->toBeTrue()
        ->and($user->fresh()->can('pages.update'))->toBeFalse();

    $user->reinstate();
    expect($user->fresh()->isSuspended())->toBeFalse();
});
