# Testing — how to run the suite, and how to add to it

The long form (the manual matrix, UAT, load, triage) is `PHASE-14-QA.md`.
This is the developer's page.

## 1. Running

```bash
php artisan test --exclude-testsuite Browser        # the gate: ~1,900 tests, MySQL required (~5 min on CI, longer locally)
```

```bash
php vendor/bin/pest tests/Feature/PaymentsTest.php   # one file
php vendor/bin/pest --filter="replayed webhook"      # one test, by a phrase from its name
php vendor/bin/pest tests/Unit                       # the pure-logic tests, no database
```

```bash
php artisan test --testsuite Browser                 # Chromium via Playwright; needs `npm ci && npm run build && npx playwright install chromium`
```

```bash
vendor/bin/phpstan analyse                           # Larastan level 5 against the baseline
vendor/bin/pint --test                               # style
```

Requirements: a MySQL database named `scghf_test` (`phpunit.xml`), reachable
with the credentials in `.env`. The suite rebuilds it per test with
`RefreshDatabase`. **Run one suite at a time against it** — two processes
migrating the same database break each other.

`phpunit.xml` overrides for tests: array cache, array session, sync
queue, array mail, `PAGE_CACHE_ENABLED=false`, `FRAGMENT_CACHE_STORE=array`,
a fake payment driver. Nothing in a test reaches a real provider.

## 2. Where things are

| Directory | What goes there |
|---|---|
| `tests/Unit` | logic with no framework: `Money`, `FeeCalculator`, `AmountInWords`, `ContrastChecker`. If it needs the container, it is a feature test |
| `tests/Feature` | everything else — one file per area, named for it (`PaymentsTest`, `ShopCheckoutTest`, `AdminAccessMatrixTest`). Pest, `it('does the thing in plain words')` |
| `tests/Browser` | journeys a person walks, in Chromium (`CriticalPathsTest`) |
| `tests/Pest.php` | the `toEqualPesewas()` expectation and `staffWithRole()` |
| `database/factories` | one per model that tests create; states like `->completed()`, `->settled()`, `->withTwoFactor()` |
| `database/seeders/DemoDataSeeder.php` | a believable site; `QueryBudgetTest` and the browser suite use it |

## 3. Conventions

- **Strict Eloquent** is on outside production: a lazy load on a
  collection throws, a missing attribute throws. Eager-load, and
  `->fresh()` a factory model before `actingAs()`.
- **Money in tests** is `toEqualPesewas(5_000)`; never assert a float.
- **Filament** pages are tested with `Livewire::test(Resource::getPages()['index']->getPage())`
  and `TestAction::make('name')->table($record)`; relation managers on a
  `ViewRecord` need `isReadOnly(): false`.
- **Helpers are global**: a function name used in two test files is a
  fatal "cannot redeclare" that only shows in the full run. Prefix them
  (`checkoutMug()`, `webhookRow()`).
- **The webhook tests** sign bodies with `FakeGateway::sign()`; the client
  tests use `Http::fake()` — never a real request.
- **Query budgets** (`QueryBudgetTest`) are measured counts; if a change
  legitimately needs more queries, raise the number in the same commit and
  say why in the changelog.

## 4. What must have a test

`CLAUDE.md`: anything touching money, authentication or webhooks. In
practice, every pull request that changes behaviour carries the test that
fails without it, and every bug fix carries the test that reproduces it.
The PR template asks; CI (`coverage` job, `COVERAGE_MIN`) notices drift.

## 5. Adding a test — the shape

```php
<?php

declare(strict_types=1);

use App\Models\Donation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SettingsSeeder::class);   // only what the test needs
});

it('refuses to complete a gift whose amount does not match', function () {
    $transaction = PaymentTransaction::factory()->shortSettling()->create();

    postWebhook(chargeSuccessBody($transaction));

    expect($transaction->fresh()->status)->toBe(PaymentStatus::Mismatch)
        ->and(Donation::where('status', DonationStatus::Completed)->count())->toBe(0);
});
```

One behaviour per test, named as a sentence a non-developer could read,
asserting on the database rather than on the response wherever the two
could disagree.

## 6. When CI is red

| Job | Look at |
|---|---|
| Lint, analyse, test | Pint (`vendor/bin/pint`), PHPStan (a new error — fix it, do not baseline it), then the failing test's output |
| Coverage | the percentage against `COVERAGE_MIN`; the report is an artifact |
| Browser tests | the `browser-screenshots` artifact shows the page at the moment of failure |
