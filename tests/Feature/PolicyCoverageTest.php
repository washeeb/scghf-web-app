<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\Consent;
use App\Models\Donation;
use App\Models\EmailLog;
use App\Models\Menu;
use App\Models\Order;
use App\Models\Page;
use App\Models\Payout;
use App\Models\Setting;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Policies\PolicyMap;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Policies
|--------------------------------------------------------------------------
|
| CLAUDE.md: "Policies for every model. No authorisation logic scattered in
| controllers."
|
| The first half of that is enforced mechanically by the coverage test below
| rather than by anybody remembering — a model added in a year with no policy
| fails the suite instead of quietly answering false to every check, or
| disappearing from the admin panel with no error to explain why.
|
| The second half is what the base policy is for: the rule lives in one place
| and a concrete policy usually just names its permission prefix. The ones that
| override something are therefore the ones worth reading.
|
*/

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    BasePolicy::forgetKnownPermissions();
});

// ── Coverage ────────────────────────────────────────────────────────────────

it('has a policy for every model', function () {
    /*
     * The test that makes "policies for every model" true and keeps it true.
     *
     * A model in neither list fails here. Adding it to `authorisedElsewhere()`
     * is allowed, but that list demands a stated reason — so the decision is
     * recorded either way, and neither option is "do nothing".
     */
    $models = collect(glob(app_path('Models/*.php')))
        ->map(fn (string $path): string => 'App\\Models\\'.basename($path, '.php'))
        ->filter(fn (string $class): bool => class_exists($class)
            && is_subclass_of($class, Model::class))
        ->values();

    $covered = array_keys(PolicyMap::policies());
    $excused = array_keys(PolicyMap::authorisedElsewhere());

    $unaccounted = $models
        ->reject(fn (string $class): bool => in_array($class, $covered, true))
        ->reject(fn (string $class): bool => in_array($class, $excused, true))
        ->values();

    expect($unaccounted->all())->toBe(
        [],
        'These models have no policy and no recorded reason for not needing one: '
        .$unaccounted->map(fn (string $c): string => class_basename($c))->implode(', '),
    );
});

it('records a reason for every model that has no policy of its own', function () {
    /*
     * The excuse list is a list of decisions, not of exemptions.
     *
     * Asserted unconditionally rather than only inside the loop: an empty list
     * means every model has a policy, which is the state to be in — but a test
     * that asserts nothing when the list is empty would go green for the wrong
     * reason the day somebody added an entry with no reason.
     */
    $excused = PolicyMap::authorisedElsewhere();

    expect($excused)->toBeArray();

    foreach ($excused as $model => $reason) {
        expect($reason)->toBeString()
            ->and(trim($reason))->not->toBe('', "No reason recorded for {$model}.");
    }
});

it('actually registers the policies with the gate', function () {
    // A map nothing reads is a map that lies.
    foreach ([Page::class, Donation::class, Beneficiary::class] as $model) {
        expect(Gate::getPolicyFor($model))->not->toBeNull();
    }
});

it('points every mapping at a policy class that exists', function () {
    foreach (PolicyMap::policies() as $model => $policy) {
        expect(class_exists($policy))->toBeTrue("Missing policy class {$policy}.")
            ->and(is_subclass_of($policy, BasePolicy::class))->toBeTrue();
    }
});

// ── Deny by default ─────────────────────────────────────────────────────────

it('denies somebody holding no permissions at all', function () {
    $nobody = User::factory()->staff()->create();

    expect($nobody->can('viewAny', Page::class))->toBeFalse()
        ->and($nobody->can('create', Page::class))->toBeFalse()
        ->and($nobody->can('create', Donation::class))->toBeFalse();
});

it('denies rather than throws when a permission does not exist', function () {
    /*
     * The likeliest way this breaks is a permission renamed in the seeder and
     * not renamed in a policy. Failing closed makes that a support ticket;
     * failing open makes it a breach nobody notices — and throwing makes it a
     * 500 on a page that should have hidden a button.
     */
    $user = User::factory()->staff()->create();

    expect(fn () => $user->can('viewAny', Setting::class))->not->toThrow(RuntimeException::class);
});

// ── The permission mapping ──────────────────────────────────────────────────

it('maps an ability onto the permission that already exists', function () {
    $editor = User::factory()->staff()->create();
    $editor->assignRole('Content Editor');

    expect($editor->fresh()->can('viewAny', Page::class))->toBeTrue()
        ->and($editor->fresh()->can('create', Page::class))->toBeTrue();
});

it('falls back to .manage where a resource has no finer permissions', function () {
    /*
     * Pages have view/create/update/delete because editing and publishing are
     * genuinely different jobs. Menus have a single `menus.manage`, because
     * nobody has ever wanted to let somebody reorder a menu but not rename one.
     * Both shapes work without either being forced onto the other.
     *
     * `viewAny` is used rather than `update` because it needs no model
     * instance — the fallback being tested is in the permission lookup, not in
     * anything to do with a particular menu.
     */
    $editor = User::factory()->staff()->create();
    $editor->assignRole('Content Editor');

    expect($editor->fresh()->can('viewAny', Menu::class))->toBeTrue()
        // No `menus.view` exists; this can only have resolved through
        // `menus.manage`.
        ->and(Permission::where('name', 'menus.view')->exists())
        ->toBeFalse();
});

it('does not let a shop manager near beneficiary records', function () {
    /*
     * The separation that matters most. `beneficiaries.*` sits apart from the
     * content permissions precisely so that somebody who needs a broad grant to
     * do their job does not acquire children's case files along the way.
     */
    $shop = User::factory()->staff()->create();
    $shop->assignRole('Shop Manager');

    expect($shop->fresh()->can('viewAny', Beneficiary::class))->toBeFalse();
});

it('gives an auditor sight of the money and no way to touch it', function () {
    $auditor = User::factory()->staff()->create();
    $auditor->assignRole('Auditor');
    $auditor = $auditor->fresh();

    expect($auditor->can('viewAny', Donation::class))->toBeTrue()
        ->and($auditor->can('create', Donation::class))->toBeFalse()
        ->and($auditor->can('update', Donation::factory()->create()))->toBeFalse();
});

// ── The ledger cannot be deleted ────────────────────────────────────────────

it('refuses to delete a financial record, whatever permissions somebody holds', function (string $model) {
    /*
     * Not "no permission grants it" — refused outright, so no future grant can
     * turn it on. A correction to the ledger is a new row.
     *
     * This is also what stops Filament rendering a "delete selected" checkbox
     * on the donations table, which would throw a stack trace after somebody
     * had selected forty rows.
     */
    $admin = User::factory()->staff()->create();
    $admin->assignRole('Admin');

    $record = $model::factory()->create();

    expect($admin->fresh()->can('delete', $record))->toBeFalse()
        ->and($admin->fresh()->can('forceDelete', $record))->toBeFalse();
})->with([
    Donation::class,
    Payout::class,
    Order::class,
]);

// ── Read-only records ───────────────────────────────────────────────────────

it('lets nobody write a delivery log', function () {
    // A log somebody can edit is not a log.
    $admin = User::factory()->staff()->create();
    $admin->assignRole('Admin');

    $log = EmailLog::factory()->create();

    expect($admin->fresh()->can('update', $log))->toBeFalse()
        ->and($admin->fresh()->can('delete', $log))->toBeFalse()
        ->and($admin->fresh()->can('create', EmailLog::class))->toBeFalse();
});

it('lets nobody write the audit trail', function () {
    /*
     * There is no `audit.manage` permission and there must not be. The trail is
     * hash-chained and append-only precisely so nobody can tidy an inconvenient
     * entry away, and a permission implying otherwise would be a promise the
     * model refuses to keep.
     */
    $admin = User::factory()->staff()->create();
    $admin->assignRole('Admin');

    expect($admin->fresh()->can('create', AuditLog::class))->toBeFalse();
});

// ── Records that are evidence ───────────────────────────────────────────────

it('refuses to edit a consent record', function () {
    /*
     * The value of this table is showing, two years later, exactly what
     * somebody agreed to. A consent that can be edited proves nothing —
     * "we had permission" becomes an assertion about the current contents of a
     * row. Getting it wrong means capturing a new one, as it would on paper.
     */
    $officer = User::factory()->staff()->create();
    $officer->assignRole('Programme Officer');

    $beneficiary = Beneficiary::factory()->create();
    $consent = Consent::factory()->create([
        'consentable_type' => $beneficiary->getMorphClass(),
        'consentable_id' => $beneficiary->id,
    ]);

    expect($officer->fresh()->can('update', $consent))->toBeFalse()
        ->and($officer->fresh()->can('delete', $consent))->toBeFalse();
});

it('refuses to hard-delete a beneficiary record', function () {
    /*
     * Not because the data must be kept — Act 843 requires the opposite. But
     * destruction is the retention runner's job, and it does five things a
     * delete button cannot: checks for a legal hold, writes the audit entry
     * BEFORE destroying anything, projects the anonymous record first, records
     * a one-way digest, and does it inside a transaction.
     */
    $officer = User::factory()->staff()->create();
    $officer->assignRole('Programme Officer');

    expect($officer->fresh()->can('forceDelete', Beneficiary::factory()->create()))->toBeFalse();
});

it('treats downloading a beneficiary document as its own decision', function () {
    // A downloaded file leaves the application's protections behind and lands
    // in somebody's Downloads folder, on a laptop, on a bus.
    $officer = User::factory()->staff()->create();
    $officer->assignRole('Programme Officer');

    $support = User::factory()->staff()->create();
    $support->assignRole('Support');

    $beneficiary = Beneficiary::factory()->create();

    expect($officer->fresh()->can('download', $beneficiary))->toBeTrue()
        ->and($support->fresh()->can('download', $beneficiary))->toBeFalse();
});

// ── Super Admin and suspension ──────────────────────────────────────────────

it('lets a Super Admin past the permission checks', function () {
    $super = User::factory()->staff()->create();
    $super->assignRole('Super Admin');

    expect($super->fresh()->can('viewAny', Beneficiary::class))->toBeTrue();
});

it('still refuses a Super Admin the things nothing may do', function () {
    /*
     * Gate::before returns true for a Super Admin, which short-circuits the
     * policy — so the ledger's protection cannot come from the policy alone.
     * The models refuse it too, and that is the layer that actually holds.
     */
    $super = User::factory()->staff()->create();
    $super->assignRole('Super Admin');

    $donation = Donation::factory()->create();

    expect(fn () => $donation->delete())->toThrow(RuntimeException::class);
});

it('passes nothing at all for a suspended account', function () {
    // Registered before the Super Admin wildcard, so it applies to them too —
    // the account you most need to be able to switch off.
    $super = User::factory()->staff()->suspended('Under investigation.')->create();
    $super->assignRole('Super Admin');

    expect($super->fresh()->can('viewAny', Page::class))->toBeFalse();
});

it('uses a permission name that is actually seeded', function () {
    // Guards the rename-drift the deny-by-default rule exists to survive.
    $seeded = Permission::pluck('name');

    foreach (['beneficiaries.view', 'donations.view', 'audit.view', 'compliance.view',
        'suppressions.release', 'sponsorships.manage', 'messages.cancel'] as $name) {
        expect($seeded)->toContain($name);
    }
});
