<?php

declare(strict_types=1);

use App\Models\User;
use App\ValueObjects\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests get the full framework: database, container, HTTP kernel.
|
| Unit tests deliberately do NOT. Money, enums and other pure logic should be
| testable without booting Laravel — it keeps them honest about their
| dependencies and keeps the suite fast. If a Unit test needs the container,
| it belongs in Feature.
|
*/

pest()->extend(TestCase::class)->in('Feature');

/*
| Browser tests get the framework AND a fresh database per test, because a
| real browser walking a donation leaves a donation behind.
*/
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Browser');

// A locator that never matches must fail the test, not hang the run.
pest()->browser()->timeout(15_000);

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

/**
 * Assert a Money holds an exact number of minor units.
 *
 * Reads better than ->minor->toBe() at the call site, and keeps the intent
 * ("this is 5000 pesewas") visible in the failure message.
 */
expect()->extend('toEqualPesewas', function (int $expected) {
    expect($this->value)->toBeInstanceOf(Money::class);
    expect($this->value->minor)->toBe($expected);

    return $this;
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * A staff user holding one role, ready to assert permissions against.
 */
function staffWithRole(string $role): User
{
    $user = User::factory()->staff()->create();
    $user->assignRole($role);

    return $user->fresh();
}
