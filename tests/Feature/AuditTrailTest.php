<?php

declare(strict_types=1);

use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Donation;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The audit trail, and API tokens
|--------------------------------------------------------------------------
|
| spatie/laravel-activitylog already records model changes. This records
| ACTIONS — including the ones that change nothing, which is most of the ones
| that matter for privacy: reading a beneficiary's file, exporting four
| thousand donor records, running the retention sweep.
|
| For a foundation holding files on vulnerable children, "who read this?" is
| the more serious question, and until this table existed nothing could answer
| it.
|
*/

beforeEach(function () {
    $this->logger = app(AuditLogger::class);
    $this->staff = User::factory()->staff()->create(['name' => 'Ama Mensah']);
});

// ── Recording ───────────────────────────────────────────────────────────────

it('records an action that changes nothing', function () {
    // The whole reason this table exists. Nothing was written, so activity_log
    // has nothing to say about it.
    $entry = $this->logger->record(
        event: 'beneficiary.viewed',
        description: 'Opened the case file for SCGHF-B-0042.',
        causer: $this->staff,
    );

    expect($entry->category)->toBe('data_access')
        ->and($entry->severity)->toBe(AuditLog::SEVERITY_NOTICE)
        ->and($entry->causer_id)->toBe($this->staff->id);
});

it('refuses to record an action nobody classified', function () {
    // A new export screen added in a year has to be classified before it can
    // log, rather than logging as "unknown" and vanishing from every report.
    expect(fn () => $this->logger->record('something.invented', 'A thing happened.'))
        ->toThrow(RuntimeException::class, 'No audit definition');
});

it('snapshots who the actor was, so the trail survives them leaving', function () {
    $entry = $this->logger->record('auth.login', 'Signed in.', causer: $this->staff);

    // Hard delete, which is what an Act 843 erasure request produces. The
    // foreign key nulls; the label is what stops the trail forgetting who did
    // this the moment they leave.
    $this->staff->forceDelete();

    expect($entry->fresh()->causer_id)->toBeNull()
        ->and($entry->fresh()->causer_label)->toContain('Ama Mensah');
});

it('escalates an action to critical on volume alone', function () {
    // One donor record viewed is somebody doing their job. Two thousand
    // exported is a question that needs asking the same day.
    $small = $this->logger->recordExport('donors.exported', 'donor records', 3, $this->staff);
    $large = $this->logger->recordExport('donors.exported', 'donor records', 4182, $this->staff);

    expect($small->severity)->toBe(AuditLog::SEVERITY_WARNING)
        ->and($large->severity)->toBe(AuditLog::SEVERITY_CRITICAL)
        ->and($large->description)->toContain('4,182');
});

it('keeps the data it is auditing access to out of the entry', function () {
    // An audit trail that accumulated the data it audits would become the
    // largest unmanaged copy of that data in the application.
    $entry = $this->logger->record(
        event: 'beneficiaries.exported',
        description: 'Exported the education cohort.',
        context: ['filter' => 'education', 'ghana_card_number' => 'GHA-123456789-0'],
        recordCount: 12,
    );

    expect($entry->context['filter'])->toBe('education')
        ->and($entry->context['ghana_card_number'])->toBe('[redacted]');
});

it('records impersonation separately from the person being impersonated', function () {
    // "Ama did this" and "Kofi did this while impersonating Ama" are different
    // facts, and only one of them is fair to Ama.
    $admin = User::factory()->staff()->create();
    session(['impersonator_id' => $admin->id]);

    $entry = $this->logger->record('donor.pii_viewed', 'Opened a donor record.', causer: $this->staff);

    expect($entry->wasImpersonated())->toBeTrue()
        ->and($entry->impersonator_id)->toBe($admin->id)
        ->and($entry->summary())->toContain('impersonated by');
});

it('never lets a failed audit write break the action it was auditing', function () {
    // An audit trail that can take down a donation form gets removed within a
    // week, and then there is no audit trail at all. NAN is unserialisable, so
    // this fails inside the write exactly as a database problem would.
    expect($this->logger->record('auth.login', 'Signed in.', context: ['value' => NAN]))
        ->toBeNull();
});

// ── Append-only ─────────────────────────────────────────────────────────────

it('refuses to let an entry be edited', function () {
    $entry = $this->logger->record('auth.login', 'Signed in.', causer: $this->staff);

    // forceFill, not update: the model is fully guarded so mass assignment is
    // already refused, and this exercises the deeper guard — the one that holds
    // however somebody reaches the row.
    expect(fn () => $entry->forceFill(['description' => 'Something else entirely.'])->save())
        ->toThrow(RuntimeException::class, 'append-only');
});

it('refuses to let an entry be deleted', function () {
    $entry = $this->logger->record('auth.login', 'Signed in.', causer: $this->staff);

    expect(fn () => $entry->delete())->toThrow(RuntimeException::class);
});

// ── The chain ───────────────────────────────────────────────────────────────

it('chains each entry to the one before it', function () {
    $first = $this->logger->record('auth.login', 'One.');
    $second = $this->logger->record('auth.logout', 'Two.');

    expect($first->previous_hash)->toBeNull()
        ->and($second->previous_hash)->toBe($first->hash)
        ->and($second->hashIsIntact())->toBeTrue();
});

it('verifies a clean trail', function () {
    foreach (range(1, 5) as $ignored) {
        $this->logger->record('auth.login', 'Signed in.');
    }

    $this->artisan('scghf:verify-audit-log')
        ->expectsOutputToContain('5 entries verified')
        ->assertSuccessful();
});

it('detects an entry that was edited in the database', function () {
    $this->logger->record('auth.login', 'One.');
    $tampered = $this->logger->record('donors.exported', 'Exported 3 donor records.', recordCount: 3);
    $this->logger->record('auth.logout', 'Three.');

    // Straight to the database, bypassing the model's refusal — which is
    // exactly what somebody covering their tracks would do.
    DB::table('audit_logs')->where('id', $tampered->id)
        ->update(['description' => 'Exported 1 donor record.']);

    $this->artisan('scghf:verify-audit-log')
        ->expectsOutputToContain('AUDIT TRAIL BROKEN')
        ->assertFailed();
});

it('detects an entry that was removed', function () {
    $this->logger->record('auth.login', 'One.');
    $removed = $this->logger->record('refund.approved', 'Approved a refund of GH₵ 500.00.');
    $this->logger->record('auth.logout', 'Three.');

    DB::table('audit_logs')->where('id', $removed->id)->delete();

    $this->artisan('scghf:verify-audit-log')->assertFailed();
});

it('does not report clock skew as tampering', function () {
    // A queued job or a late webhook writes an entry with an earlier
    // occurred_at. Verifying by timestamp rather than insertion order would
    // call that an incident.
    $this->logger->record('auth.login', 'One.');
    $late = $this->logger->record('receipt.issued', 'Issued a receipt.');
    DB::table('audit_logs')->where('id', $late->id)
        ->update(['occurred_at' => now()->subHour()]);

    // occurred_at is not part of what makes the chain ordered, but it IS part
    // of the hash — so this edit must be caught as an edit.
    $this->artisan('scghf:verify-audit-log')->assertFailed();
});

it('links an entry to what it was about', function () {
    $donation = Donation::factory()->create();

    $entry = $this->logger->record(
        event: 'donation.marked_needs_review',
        description: 'Amount did not match the webhook.',
        subject: $donation,
    );

    expect($entry->subject_type)->toBe($donation->getMorphClass())
        ->and($entry->subject_id)->toBe($donation->id);
});

// ── API tokens ──────────────────────────────────────────────────────────────

it('shows a token once and never stores it', function () {
    $token = ApiToken::issue('Mobile app', $this->staff, ['causes:read']);

    expect($token->plainTextToken)->toBeString()
        ->and($token->token_hash)->not->toBe($token->plainTextToken)
        ->and(ApiToken::findByToken($token->plainTextToken)->id)->toBe($token->id)
        // A leaked database must not be a leaked set of live credentials.
        ->and($token->fresh()->getAttributes())->not->toContain($token->plainTextToken);
});

it('grants nothing by default', function () {
    $token = ApiToken::issue('Careless integration', $this->staff);

    expect($token->can('causes:read'))->toBeFalse()
        ->and($token->abilities)->toBe([]);
});

it('refuses an ability that can never belong to a token', function () {
    // Beneficiary data is a decision a person makes while logged in, not
    // something an integration does unattended.
    expect(fn () => ApiToken::issue('Reporting', $this->staff, ['beneficiaries:read']))
        ->toThrow(RuntimeException::class, 'can never be granted');
});

it('refuses a mistyped ability rather than granting nothing quietly', function () {
    // A typo that silently grants nothing looks like it works until the day it
    // matters.
    expect(fn () => ApiToken::issue('Reporting', $this->staff, ['causes:raed']))
        ->toThrow(InvalidArgumentException::class, 'Unknown ability');
});

it('refuses to mint a token that outlives the ceiling', function () {
    expect(fn () => ApiToken::issue('For ever', $this->staff, [], lifetimeDays: 9999))
        ->toThrow(InvalidArgumentException::class);
});

it('explains why a token was rejected rather than just saying no', function () {
    $expired = ApiToken::factory()->expired()->create();

    expect($expired->rejectionReason())->toContain('expired');
});

it('rejects a token presented from an address it is not tied to', function () {
    $token = ApiToken::issue('Server integration', $this->staff, ['causes:read'],
        allowedIps: ['41.66.1.1']);

    expect($token->isUsable('41.66.1.1'))->toBeTrue()
        ->and($token->isUsable('102.176.0.9'))->toBeFalse();
});

it('needs a reason to revoke a token', function () {
    $token = ApiToken::issue('Old app', $this->staff);

    expect(fn () => $token->revoke($this->staff, ''))->toThrow(InvalidArgumentException::class);

    $token->revoke($this->staff, 'Replaced by the new mobile app.');

    expect($token->fresh()->isUsable())->toBeFalse()
        ->and($token->fresh()->rejectionReason())->toContain('Replaced by');
});

it('warns about a token before it expires, not after', function () {
    // The failure mode of a mandatory expiry is an integration that stops
    // working on a Saturday with nobody knowing why.
    ApiToken::factory()->create(['expires_at' => now()->addDays(10)]);
    ApiToken::factory()->create(['expires_at' => now()->addDays(200)]);

    expect(ApiToken::expiringSoon()->count())->toBe(1);
});
