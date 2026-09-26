<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Cause;
use App\Models\Donation;
use App\Models\Media;
use App\Models\Payout;
use App\Models\Pledge;
use App\Models\User;
use App\ValueObjects\Money;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Pledges and payouts
|--------------------------------------------------------------------------
|
| Both named in the ERD, neither built until now.
|
| A PLEDGE IS NOT INCOME. A promise of GH₵ 5,000 at harvest is a promise. If
| it could sit in `donations` with a "pledged" status, every query that sums
| donations would have to remember to exclude it — and the one that forgot
| would report money the foundation does not have to somebody making
| decisions with it.
|
| A PAYOUT is the direction nothing else recorded. Everything built so far
| records money coming in; a foundation is judged on what it does with it.
| The control that matters is that whoever requests a payment is not whoever
| approves it.
|
*/

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $this->officer = User::factory()->staff()->create();
    $this->officer->assignRole('Finance Officer');

    $this->director = User::factory()->staff()->create();
    $this->director->assignRole('Admin');
});

// ── A pledge is not income ──────────────────────────────────────────────────

it('keeps a promise out of the money entirely', function () {
    // The property the whole table exists for.
    $cause = Cause::factory()->create();
    Pledge::factory()->create(['cause_id' => $cause->id, 'amount' => Money::ofMinor(500000)]);

    expect(Donation::count())->toBe(0)
        ->and($cause->fresh()->raised->minor)->toBe(0);
});

it('names the outstanding total so it cannot be mistaken for revenue', function () {
    Pledge::factory()->count(2)->create(['amount' => Money::ofMinor(500000)]);

    // A forecasting figure, and nothing else. It is deliberately not exposed
    // anywhere near a raised total.
    expect(Pledge::outstandingTotal())->toEqualPesewas(1000000);
});

it('turns a promise into income only through a real donation', function () {
    $pledge = Pledge::factory()->create(['amount' => Money::ofMinor(500000)]);

    Donation::factory()->create([
        'pledge_id' => $pledge->id,
        'cause_id' => $pledge->cause_id,
        'amount' => Money::ofMinor(200000),
        'status' => 'completed',
    ]);

    $pledge->recalculateFulfilment();

    expect($pledge->fresh()->status)->toBe(Pledge::STATUS_PARTIAL)
        ->and($pledge->fresh()->outstanding())->toEqualPesewas(300000);
});

it('does not count a donation that has not completed', function () {
    // Money that has not arrived is not fulfilment, whatever the donor intended.
    $pledge = Pledge::factory()->create(['amount' => Money::ofMinor(500000)]);

    Donation::factory()->create([
        'pledge_id' => $pledge->id,
        'cause_id' => $pledge->cause_id,
        'amount' => Money::ofMinor(500000),
        'status' => 'pending',
    ]);

    $pledge->recalculateFulfilment();

    expect($pledge->fresh()->fulfilled_minor)->toBe(0);
});

it('marks a pledge fulfilled once the money is all in', function () {
    $pledge = Pledge::factory()->create(['amount' => Money::ofMinor(500000)]);

    Donation::factory()->create([
        'pledge_id' => $pledge->id, 'cause_id' => $pledge->cause_id,
        'amount' => Money::ofMinor(500000), 'status' => 'completed',
    ]);

    $pledge->recalculateFulfilment();

    expect($pledge->fresh()->isFulfilled())->toBeTrue()
        ->and($pledge->fresh()->fulfilled_at)->not->toBeNull();
});

it('reads lapsed from the date, not from a nightly job', function () {
    $pledge = Pledge::factory()->overdue()->create();

    expect($pledge->hasLapsed())->toBeTrue();
});

// ── Chasing, decently ───────────────────────────────────────────────────────

it('will not chase somebody who never agreed to be chased', function () {
    // A pledge is not consent to be contacted about it.
    $pledge = Pledge::factory()->create(['consent_to_remind' => false]);

    expect($pledge->canBeReminded())->toBeFalse()
        ->and($pledge->reminderRejectionReason())->toContain('did not agree');
});

it('stops after three reminders', function () {
    // A fourth is a conversation somebody should have, not another automated
    // message. Chasing weekly is how a supporter becomes a former supporter.
    $pledge = Pledge::factory()->remindable()->create();

    foreach (range(1, 3) as $ignored) {
        $pledge->recordReminder();
        $pledge->forceFill(['last_reminded_at' => now()->subMonth()])->save();
    }

    expect($pledge->fresh()->canBeReminded())->toBeFalse()
        ->and($pledge->fresh()->reminderRejectionReason())->toContain('conversation');
});

it('will not chase twice in a fortnight', function () {
    $pledge = Pledge::factory()->remindable()->create();
    $pledge->recordReminder();

    expect($pledge->fresh()->canBeReminded())->toBeFalse()
        ->and($pledge->fresh()->reminderRejectionReason())->toContain('fortnight');
});

it('will not chase a pledge that is already settled', function () {
    $pledge = Pledge::factory()->remindable()->create(['amount' => Money::ofMinor(100)]);
    $pledge->forceFill(['fulfilled_minor' => 100])->save();

    expect($pledge->fresh()->canBeReminded())->toBeFalse();
});

// ── Payouts: separation of duties ───────────────────────────────────────────

it('refuses a payout approved by the person who requested it', function () {
    /*
     * The single most effective control against both fraud and honest error in
     * an organisation where two or three people do everything — and worth the
     * friction precisely because it is inconvenient for the person it
     * constrains.
     */
    $payout = Payout::factory()->create();
    $payout->submit($this->director);

    expect(fn () => $payout->fresh()->approve($this->director))
        ->toThrow(RuntimeException::class, 'cannot be approved by the person who requested it');
});

it('lets a second person approve it', function () {
    $payout = Payout::factory()->create();
    $payout->submit($this->officer);

    $payout->fresh()->approve($this->director);

    expect($payout->fresh()->status)->toBe(Payout::STATUS_APPROVED)
        ->and($payout->fresh()->approved_by)->toBe($this->director->id);
});

it('does not give the officer who prepares a payment the power to authorise it', function () {
    // Enforced by the permission set as well as by the model, so the two cannot
    // drift apart.
    expect($this->officer->can('payouts.request'))->toBeTrue()
        ->and($this->officer->can('payouts.approve'))->toBeFalse()
        ->and($this->director->can('payouts.approve'))->toBeTrue();
});

it('records an approval in the audit trail as critical', function () {
    $payout = Payout::factory()->create();
    $payout->submit($this->officer);
    $payout->fresh()->approve($this->director);

    $entry = AuditLog::where('event', 'payout.approved')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->severity)->toBe(AuditLog::SEVERITY_CRITICAL)
        ->and($entry->causer_id)->toBe($this->director->id);
});

// ── Payouts: evidence and attribution ───────────────────────────────────────

it('refuses a payout attributed to nothing', function () {
    // "What did you spend our grant on?" is the question a funder always asks.
    expect(fn () => Payout::factory()->create([
        'division_id' => null, 'project_id' => null, 'cause_id' => null,
    ]))->toThrow(RuntimeException::class, 'must be attributed');
});

it('refuses to mark a payout paid with no evidence', function () {
    // A disbursement with no receipt is the finding every audit opens with.
    $payout = Payout::factory()->create();
    $payout->submit($this->officer);
    $payout->fresh()->approve($this->director);

    expect(fn () => $payout->fresh()->markPaid($this->officer))
        ->toThrow(RuntimeException::class, 'without evidence');
});

it('records the payment once there is evidence', function () {
    $payout = Payout::factory()->create();
    $payout->submit($this->officer);
    $payout->fresh()->approve($this->director);

    $evidence = Media::factory()->create();
    $payout->fresh()->markPaid($this->officer, $evidence);

    expect($payout->fresh()->status)->toBe(Payout::STATUS_PAID)
        ->and($payout->fresh()->paid_at)->not->toBeNull();
});

it('refuses to cancel money that has already gone', function () {
    // A correction is a new record, not an edit to this one.
    $payout = Payout::factory()->create();
    $payout->submit($this->officer);
    $payout->fresh()->approve($this->director);
    $payout->fresh()->markPaid($this->officer, Media::factory()->create());

    expect(fn () => $payout->fresh()->cancel('Changed our minds.'))
        ->toThrow(RuntimeException::class, 'has been paid cannot be cancelled');
});

it('counts only money that actually moved', function () {
    // An approved payout is a decision. Reporting it as expenditure overstates
    // what the foundation has done.
    $paid = Payout::factory()->create();
    $paid->submit($this->officer);
    $paid->fresh()->approve($this->director);
    $paid->fresh()->markPaid($this->officer, Media::factory()->create());

    $approvedOnly = Payout::factory()->create();
    $approvedOnly->submit($this->officer);
    $approvedOnly->fresh()->approve($this->director);

    expect(Payout::paidBetween(now()->subDay(), now()))->toEqualPesewas(1200000);
});

it('needs a reason to reject a payout', function () {
    $payout = Payout::factory()->create();
    $payout->submit($this->officer);

    expect(fn () => $payout->fresh()->reject($this->director, ' '))
        ->toThrow(RuntimeException::class, 'needs a reason');
});
