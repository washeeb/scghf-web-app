<?php

declare(strict_types=1);

use App\Models\AuditArchive;
use App\Models\AuditLog;
use App\Support\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Archiving the audit trail
|--------------------------------------------------------------------------
|
| Open question 16, answered.
|
| `audit_logs` is never swept by the retention runner, and that is correct: it
| is the evidence that the retention policy was followed, and a policy which
| destroys its own evidence cannot be demonstrated to anybody.
|
| The consequence is that on shared hosting it becomes the largest table in
| the database, dragged across a slow connection by every nightly backup.
| Deleting it is not an option. Moving it is — and the hash chain is what
| makes moving it distinguishable from deleting it.
|
*/

beforeEach(function () {
    Storage::fake('local');
    $this->logger = app(AuditLogger::class);
});

/**
 * Entries genuinely written in a past year.
 *
 * Written by travelling, not by rewriting `occurred_at` afterwards.
 * `occurred_at` is part of what each entry hashes, so editing it in the
 * database is indistinguishable from tampering — which the verifier would
 * report, correctly. Travelling produces entries that are actually old.
 */
function auditEntriesFor(int $year, int $count = 5): void
{
    $logger = app(AuditLogger::class);

    test()->travelTo(Carbon::create($year, 6, 1, 10));

    for ($i = 0; $i < $count; $i++) {
        $logger->record('auth.login', "Signed in ({$i}).");
    }

    test()->travelBack();
}

// ── Refusals ────────────────────────────────────────────────────────────────

it('refuses to archive a year that is still recent', function () {
    // Two full years stay live, because that is the window in which anybody
    // actually searches them.
    auditEntriesFor(now()->year);

    $this->artisan('scghf:archive-audit-log', ['year' => now()->year, '--execute' => true])
        ->expectsOutputToContain('too recent')
        ->assertFailed();
});

it('refuses to archive a year whose chain is already broken', function () {
    // Archiving a broken year would file the break away in a directory nobody
    // opens, and the live table would come back clean.
    $year = now()->year - 3;
    auditEntriesFor($year, 4);

    $middle = AuditLog::orderBy('id')->skip(1)->first();
    DB::table('audit_logs')->where('id', $middle->id)->update(['description' => 'Edited.']);

    // Caught as an edit rather than as a link mismatch — the edited entry's own
    // hash stops matching before anything downstream notices. Either way the
    // year is refused and nothing is removed.
    $this->artisan('scghf:archive-audit-log', ['year' => $year, '--execute' => true])
        ->expectsOutputToContain('Nothing will be archived')
        ->assertFailed();

    expect(AuditLog::count())->toBe(4);
});

it('refuses to archive the same year twice', function () {
    $year = now()->year - 3;
    auditEntriesFor($year, 3);

    $this->artisan('scghf:archive-audit-log', ['year' => $year, '--execute' => true])->assertSuccessful();

    $this->artisan('scghf:archive-audit-log', ['year' => $year, '--execute' => true])
        ->expectsOutputToContain('already been archived')
        ->assertFailed();
});

it('does nothing at all on a dry run', function () {
    $year = now()->year - 3;
    auditEntriesFor($year, 3);

    $this->artisan('scghf:archive-audit-log', ['year' => $year])
        ->expectsOutputToContain('DRY RUN')
        ->assertSuccessful();

    expect(AuditLog::count())->toBe(3)
        ->and(AuditArchive::count())->toBe(0);
});

// ── Archiving ───────────────────────────────────────────────────────────────

it('writes a verified archive and removes the rows', function () {
    $year = now()->year - 3;
    auditEntriesFor($year, 5);

    $this->artisan('scghf:archive-audit-log', ['year' => $year, '--execute' => true])
        ->expectsOutputToContain('Archive verified')
        ->assertSuccessful();

    $archive = AuditArchive::first();

    expect($archive->year)->toBe($year)
        ->and($archive->entry_count)->toBe(5)
        ->and($archive->isPruned())->toBeTrue()
        ->and(AuditLog::count())->toBe(0);

    Storage::disk('local')->assertExists($archive->filename);
});

it('writes the archive outside the web root', function () {
    // An audit archive behind a guessable URL would be a worse leak than the
    // table it came from.
    $year = now()->year - 3;
    auditEntriesFor($year, 2);

    $this->artisan('scghf:archive-audit-log', ['year' => $year, '--execute' => true]);

    expect(AuditArchive::first()->disk)->toBe('local')
        ->and(config('filesystems.disks.local.root'))->not->toContain('public');
});

it('can write the archive without removing anything', function () {
    // The first half of the operation, on its own. Useful when somebody wants
    // a copy before committing to the prune.
    $year = now()->year - 3;
    auditEntriesFor($year, 3);

    $this->artisan('scghf:archive-audit-log', [
        'year' => $year, '--execute' => true, '--keep-rows' => true,
    ])->assertSuccessful();

    expect(AuditLog::count())->toBe(3)
        ->and(AuditArchive::first()->isPruned())->toBeFalse();
});

it('keeps the whole row, so the archive can be verified against the hashes', function () {
    $year = now()->year - 3;
    auditEntriesFor($year, 2);

    $this->artisan('scghf:archive-audit-log', ['year' => $year, '--execute' => true]);

    $archive = AuditArchive::first();
    $contents = json_decode(
        (string) gzdecode((string) Storage::disk('local')->get($archive->filename)),
        true,
    );

    expect($contents['entries'])->toHaveCount(2)
        ->and($contents['entries'][0])->toHaveKeys(['ulid', 'event', 'hash', 'previous_hash']);
});

// ── Verification ────────────────────────────────────────────────────────────

it('notices an archive file that has been altered', function () {
    // A file present and wrong is worse than one missing, because the missing
    // one gets noticed.
    $year = now()->year - 3;
    auditEntriesFor($year, 2);

    $this->artisan('scghf:archive-audit-log', ['year' => $year, '--execute' => true]);

    $archive = AuditArchive::first();
    Storage::disk('local')->put($archive->filename, 'something else entirely');

    expect($archive->verify())->toBeFalse();
});

it('notices an archive file that has gone', function () {
    $year = now()->year - 3;
    auditEntriesFor($year, 2);

    $this->artisan('scghf:archive-audit-log', ['year' => $year, '--execute' => true]);

    $archive = AuditArchive::first();
    Storage::disk('local')->delete($archive->filename);

    expect($archive->verify())->toBeFalse();
});

// ── The chain across the gap ────────────────────────────────────────────────

it('keeps the chain intact across an archived period', function () {
    /*
     * The property the whole design turns on. Archiving removes rows, which
     * would normally look identical to somebody deleting them — the archive row
     * carrying the last hash of the block is what tells the two apart.
     */
    $year = now()->year - 3;
    auditEntriesFor($year, 4);

    // Entries that stay, chained onto the ones about to be archived.
    $this->logger->record('auth.login', 'A later entry.');
    $this->logger->record('auth.logout', 'Another later entry.');

    $this->artisan('scghf:archive-audit-log', ['year' => $year, '--execute' => true])
        ->assertSuccessful();

    expect(AuditLog::count())->toBe(2);

    $this->artisan('scghf:verify-audit-log')
        ->expectsOutputToContain('continues across 1 archived period')
        ->assertSuccessful();
});

it('still reports a break when entries vanish with no archive to explain them', function () {
    // The archive row is the difference between a move and a deletion. Without
    // one, a gap is exactly what it looks like.
    $this->logger->record('auth.login', 'One.');
    $removed = $this->logger->record('refund.approved', 'Approved a refund.');
    $this->logger->record('auth.logout', 'Three.');

    DB::table('audit_logs')->where('id', $removed->id)->delete();

    $this->artisan('scghf:verify-audit-log')
        ->expectsOutputToContain('no archive accounts for the gap')
        ->assertFailed();
});

it('does not accept an archive that was written but never pruned as an explanation', function () {
    /*
     * An unpruned archive means the rows should still be there. If they are
     * not, something removed them outside the archive process, and that is a
     * break rather than a move.
     */
    $year = now()->year - 3;
    auditEntriesFor($year, 3);
    $this->logger->record('auth.login', 'A later entry.');

    $this->artisan('scghf:archive-audit-log', [
        'year' => $year, '--execute' => true, '--keep-rows' => true,
    ]);

    // Somebody deletes the rows by hand instead of letting the command do it.
    DB::table('audit_logs')->whereYear('occurred_at', $year)->delete();

    $this->artisan('scghf:verify-audit-log')->assertFailed();
});

it('describes an archive in a sentence somebody can act on', function () {
    $year = now()->year - 3;
    auditEntriesFor($year, 3);

    $this->artisan('scghf:archive-audit-log', ['year' => $year, '--execute' => true]);

    $archive = AuditArchive::first();
    $archive->verify();

    expect($archive->fresh()->summary())
        ->toContain('3 entries')
        ->toContain('removed from the live table')
        ->toContain('verified');
});
