<?php

declare(strict_types=1);

use App\Models\BackupLogEntry;
use App\Models\ErrorReport;
use App\Models\User;
use App\Models\VisitorStat;
use App\Support\ErrorReporter;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Errors, backups and visitor counts
|--------------------------------------------------------------------------
|
| Three separate arguments, one shape:
|
|   - ten thousand copies of one fault is one problem, and on a shared plan
|     that counts inodes it is also a full disk
|   - a backup that has never been restored is a hypothesis
|   - a visitor count that identifies visitors is not a count, it is a record
|
*/

/**
 * A fault thrown from one place.
 *
 * All of these are constructed on the same line on purpose: a fingerprint
 * includes the file and line, because two exceptions with the same message
 * thrown from different places genuinely are different faults. Building them
 * inline in each test would make every one a new throw site and would test
 * nothing about grouping.
 */
function faultWith(string $message): RuntimeException
{
    return new RuntimeException($message);
}

// ── Error grouping ──────────────────────────────────────────────────────────

it('groups the same fault instead of accumulating rows', function () {
    $reporter = app(ErrorReporter::class);

    foreach (range(1, 5) as $ignored) {
        $reporter->report(faultWith('The cause could not be loaded.'));
    }

    expect(ErrorReport::count())->toBe(1)
        ->and(ErrorReport::first()->occurrences)->toBe(5);
});

it('treats the same message with different ids as one fault', function () {
    // Without normalising the variable parts, a failing page produces a new row
    // per visitor — which is one problem wearing ten thousand faces.
    $reporter = app(ErrorReporter::class);

    $reporter->report(faultWith('No query results for model [Donation] 41'));
    $reporter->report(faultWith('No query results for model [Donation] 42'));

    expect(ErrorReport::count())->toBe(1);
});

it('keeps genuinely different faults apart', function () {
    $reporter = app(ErrorReporter::class);

    $reporter->report(faultWith('One thing broke.'));
    $reporter->report(new LogicException('A different thing broke.'));

    expect(ErrorReport::count())->toBe(2);
});

it('ignores ordinary traffic', function () {
    // A bot probing for /wp-login.php is not an application error, and a table
    // full of them hides the one that is.
    $reporter = app(ErrorReporter::class);

    $reporter->report(new NotFoundHttpException('Not Found'));
    $reporter->report(new AuthenticationException('Unauthenticated.'));

    expect(ErrorReport::count())->toBe(0);
});

it('reopens a fault that recurs after being marked resolved', function () {
    // It was not resolved. A green tick on something still happening is worse
    // than no tick.
    $reporter = app(ErrorReporter::class);
    $reporter->report(faultWith('Still broken.'));

    ErrorReport::first()->resolve(User::factory()->staff()->create(), 'Fixed in release 12.');

    expect(ErrorReport::first()->isResolved())->toBeTrue();

    $reporter->report(faultWith('Still broken.'));

    expect(ErrorReport::first()->isResolved())->toBeFalse()
        ->and(ErrorReport::first()->occurrences)->toBe(2);
});

it('never lets error reporting turn one failure into two', function () {
    // An error reporter that turns a 500 into a different 500 has made things
    // strictly worse.
    config()->set('system.errors.ignore', ['not-a-real-class']);

    expect(fn () => app(ErrorReporter::class)->report(faultWith('x')))
        ->not->toThrow(Throwable::class);
});

it('prunes what somebody has already dealt with first', function () {
    config()->set('system.errors.max_groups', 3);

    ErrorReport::factory()->count(2)->resolved()->create();
    ErrorReport::factory()->count(3)->create();

    expect(ErrorReport::pruneToCeiling())->toBe(2)
        ->and(ErrorReport::whereNotNull('resolved_at')->count())->toBe(0)
        ->and(ErrorReport::count())->toBe(3);
});

// ── Backups ─────────────────────────────────────────────────────────────────

it('says so when no backup has ever been restored', function () {
    // Which is the state every project is in until somebody does it, and the
    // state most projects stay in.
    BackupLogEntry::factory()->create();

    expect(BackupLogEntry::restoreTestIsOverdue())->toBeTrue()
        ->and(BackupLogEntry::healthWarnings())
        ->toContain('No backup has ever been restored and verified. Until one has, the backups are '
            .'a hypothesis rather than a plan.');
});

it('refuses a restore test that verified nothing', function () {
    $backup = BackupLogEntry::factory()->create();

    expect(fn () => BackupLogEntry::recordRestoreTest(
        $backup, User::factory()->staff()->create(), 0, 'Looked fine.'
    ))->toThrow(InvalidArgumentException::class, 'count what came back');
});

it('refuses a restore test nobody wrote anything about', function () {
    $backup = BackupLogEntry::factory()->create();

    expect(fn () => BackupLogEntry::recordRestoreTest(
        $backup, User::factory()->staff()->create(), 4182, '   '
    ))->toThrow(InvalidArgumentException::class, 'needs notes');
});

it('records a restore test somebody actually did', function () {
    $backup = BackupLogEntry::factory()->create();
    $officer = User::factory()->staff()->create();

    BackupLogEntry::recordRestoreTest(
        $backup, $officer, 4182,
        'Restored to the staging database; donations table matched production row counts.',
    );

    expect(BackupLogEntry::restoreTestIsOverdue())->toBeFalse()
        ->and(BackupLogEntry::lastRestoreTest()->verified_by)->toBe($officer->id)
        ->and(BackupLogEntry::lastRestoreTest()->restored_row_count)->toBe(4182);
});

it('goes overdue again once the interval passes', function () {
    config()->set('system.backups.restore_test_interval_days', 90);

    BackupLogEntry::recordRestoreTest(
        BackupLogEntry::factory()->create(),
        User::factory()->staff()->create(),
        100,
        'Verified.',
    );

    $this->travel(91)->days();

    expect(BackupLogEntry::restoreTestIsOverdue())->toBeTrue();
});

it('notices when backups have quietly stopped', function () {
    BackupLogEntry::factory()->create(['started_at' => now()->subDays(4)]);

    expect(BackupLogEntry::backupIsStale())->toBeTrue()
        ->and(implode(' ', BackupLogEntry::healthWarnings()))->toContain('last successful backup');
});

it('does not count a failed backup as a backup', function () {
    BackupLogEntry::factory()->failed()->create();

    expect(BackupLogEntry::lastSuccessfulBackup())->toBeNull()
        ->and(BackupLogEntry::backupIsStale())->toBeTrue();
});

// ── Visitor statistics ──────────────────────────────────────────────────────

it('counts a page view and its session', function () {
    VisitorStat::increment_(VisitorStat::DIMENSION_TOTAL, '', newSession: true);
    VisitorStat::increment_(VisitorStat::DIMENSION_TOTAL, '', newSession: false);

    $row = VisitorStat::first();

    expect($row->views)->toBe(2)
        ->and($row->sessions)->toBe(1);
});

it('holds no column anywhere that could identify a visitor', function () {
    // The guarantee is structural, not a policy somebody could relax. If this
    // test starts failing, somebody has added a column that needs a very good
    // argument behind it.
    $columns = Schema::getColumnListing('visitor_stats');

    expect($columns)->toBe([
        'id', 'date', 'dimension', 'value', 'views', 'sessions', 'created_at', 'updated_at',
    ]);
});

it('keeps one row per day per value', function () {
    VisitorStat::increment_(VisitorStat::DIMENSION_PATH, '/about', newSession: true);
    VisitorStat::increment_(VisitorStat::DIMENSION_PATH, '/about', newSession: false);
    VisitorStat::increment_(VisitorStat::DIMENSION_PATH, '/donate', newSession: false);

    expect(VisitorStat::forDimension(VisitorStat::DIMENSION_PATH)->count())->toBe(2)
        ->and(VisitorStat::firstWhere('value', '/about')->views)->toBe(2);
});

it('reports the mobile share, which is what justifies the performance budget', function () {
    foreach (range(1, 7) as $ignored) {
        VisitorStat::increment_(VisitorStat::DIMENSION_DEVICE, 'mobile', newSession: false);
    }

    foreach (range(1, 3) as $ignored) {
        VisitorStat::increment_(VisitorStat::DIMENSION_DEVICE, 'desktop', newSession: false);
    }

    expect(VisitorStat::mobileSharePercent(now()->subDay(), now()))->toBe(70);
});

it('ranks the most-viewed pages', function () {
    VisitorStat::increment_(VisitorStat::DIMENSION_PATH, '/donate', newSession: false);
    VisitorStat::increment_(VisitorStat::DIMENSION_PATH, '/donate', newSession: false);
    VisitorStat::increment_(VisitorStat::DIMENSION_PATH, '/about', newSession: false);

    $top = VisitorStat::top(VisitorStat::DIMENSION_PATH, now()->subDay(), now());

    expect($top->first()->value)->toBe('/donate')
        ->and($top->first()->views)->toBe(2);
});

it('stops collecting distinct values once a dimension is full', function () {
    // A misbehaving crawler can produce ten thousand distinct paths in an
    // afternoon, and this table is not the place to absorb that.
    config()->set('system.visitors.max_values_per_dimension', 3);

    foreach (['/a', '/b', '/c'] as $path) {
        VisitorStat::increment_(VisitorStat::DIMENSION_PATH, $path, newSession: false);
    }

    expect(VisitorStat::dimensionIsFull(VisitorStat::DIMENSION_PATH))->toBeTrue();
});
