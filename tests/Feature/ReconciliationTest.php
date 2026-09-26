<?php

declare(strict_types=1);

use App\Enums\DonationStatus;
use App\Enums\PaymentStatus;
use App\Models\Cause;
use App\Models\Donation;
use App\Models\DonationItem;
use App\Models\DonationReceipt;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Models\User;
use App\Payments\DonationService;
use App\Payments\OfflineDonationService;
use App\Payments\ReconciliationService;
use App\ValueObjects\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Offline gifts and daily reconciliation
|--------------------------------------------------------------------------
|
| Ledgers that are never reconciled fail the same way backups that are never
| restored do: silently, and only discovered when it matters.
|
| The direction of error that actually loses money is a gift the gateway
| settled that this site never heard about — a webhook that was never
| delivered, or delivered while the site was down. That is what `recovered`
| catches.
|
*/

beforeEach(function () {
    setting()->set('general.legal_name', "St. Cecilia's Greater Hope Foundations");
    setting()->set('general.tin', 'C0001234567');

    $this->reconciliation = app(ReconciliationService::class);
    $this->offline = app(OfflineDonationService::class);
    $this->donations = app(DonationService::class);
    $this->staff = User::factory()->staff()->create();
});

/** @param array<string, mixed> $overrides */
function offlineInput(array $overrides = []): array
{
    return array_merge([
        'amount' => Money::ofMajor('500.00'),
        'cause' => Cause::factory()->create(),
        'donor_name' => 'Ama Boateng',
        'donor_email' => 'ama@example.com',
        'offline_method' => OfflineDonationService::METHOD_CASH,
    ], $overrides);
}

// ═══════════════════════════════════════════════════════════════════════════
//  Offline gifts
// ═══════════════════════════════════════════════════════════════════════════

it('records a cash gift into the same ledger as an online one', function () {
    // A parallel table would mean every report had to union two sources, and
    // the one that forgot would be the one shown to a trustee.
    $donation = $this->offline->record(offlineInput(), $this->staff);

    expect($donation->status)->toBe(DonationStatus::Completed)
        ->and($donation->channel)->toBe('offline')
        ->and($donation->offline_method)->toBe('cash')
        ->and($donation->recorded_by)->toBe($this->staff->id)
        ->and($donation->cause->fresh()->raisedAmount())->toEqualPesewas(50_000);
});

it('writes a transaction row even though no gateway was involved', function () {
    // One payment path for everything, so reconciliation and reporting need no
    // "unless it was cash" branch.
    $donation = $this->offline->record(offlineInput(), $this->staff);

    expect($donation->transaction->gateway)->toBe(PaymentTransaction::GATEWAY_OFFLINE)
        ->and($donation->transaction->status)->toBe(PaymentStatus::Success)
        ->and($donation->transaction->fee_minor)->toBe(0);
});

it('takes no fee on an offline gift, because nobody took one', function () {
    $donation = $this->offline->record(offlineInput(), $this->staff);

    expect($donation->fee)->toEqualPesewas(0)
        ->and($donation->net)->toEqualPesewas(50_000);
});

it('records when the money arrived, not when it was entered', function () {
    $donation = $this->offline->record(
        offlineInput(['received_on' => now()->subWeek()->toDateString()]),
        $this->staff,
    );

    expect($donation->received_on->toDateString())->toBe(now()->subWeek()->toDateString())
        ->and($donation->paid_at->toDateString())->toBe(now()->subWeek()->toDateString());
});

it('refuses a gift received in the future', function () {
    $this->offline->record(offlineInput(['received_on' => now()->addWeek()]), $this->staff);
})->throws(RuntimeException::class, 'received in the future');

it('insists on a cheque number for a cheque', function () {
    // It is what Finance matches against the bank statement.
    $this->offline->record(
        offlineInput(['offline_method' => OfflineDonationService::METHOD_CHEQUE]),
        $this->staff,
    );
})->throws(RuntimeException::class, 'needs the cheque number');

it('accepts a cheque with its number', function () {
    $donation = $this->offline->record(offlineInput([
        'offline_method' => OfflineDonationService::METHOD_CHEQUE,
        'offline_reference' => '000451',
    ]), $this->staff);

    expect($donation->offline_reference)->toBe('000451');
});

it('rejects an offline method it does not know', function () {
    $this->offline->record(offlineInput(['offline_method' => 'crypto']), $this->staff);
})->throws(RuntimeException::class, 'Unknown offline method');

it('acknowledges an offline gift from the same number series', function () {
    // A cash gift at an event gets the same document as an online one.
    $donation = $this->offline->recordAndAcknowledge(offlineInput(), $this->staff);

    expect($donation->receipt)->not->toBeNull()
        ->and($donation->receipt->receipt_number)->toStartWith('SCGHF-R-');
});

// ═══════════════════════════════════════════════════════════════════════════
//  Recovering a settled gift the site never heard about
// ═══════════════════════════════════════════════════════════════════════════

it('recovers a payment the gateway settled while we were not listening', function () {
    // The failure that actually loses a real gift: a webhook never delivered,
    // or delivered while the site was down.
    $cause = Cause::factory()->create();
    ['donation' => $donation] = $this->donations->start([
        'amount' => Money::ofMajor('250.00'),
        'cause' => $cause,
        'donor_name' => 'Kwabena Mensah',
        'donor_email' => 'kwabena@example.com',
    ]);

    expect($donation->fresh()->status)->toBe(DonationStatus::Pending);

    $summary = $this->reconciliation->run();

    expect($summary['recovered'])->toBe(1)
        ->and($donation->fresh()->status)->toBe(DonationStatus::Completed)
        ->and($cause->fresh()->raisedAmount())->toEqualPesewas(25_000);
});

it('holds a recovered payment for review when the amount does not match', function () {
    $cause = Cause::factory()->create();
    ['donation' => $donation, 'transaction' => $transaction] = $this->donations->start([
        'amount' => Money::ofMajor('250.00'),
        'cause' => $cause,
        'donor_name' => 'Kwabena Mensah',
        'donor_email' => 'kwabena@example.com',
    ]);

    // The fake gateway settles a -SHORT reference one pesewa light.
    $transaction->forceFill(['gateway_reference' => $transaction->gateway_reference.'-SHORT'])->save();

    $this->reconciliation->run();

    expect($transaction->fresh()->status)->toBe(PaymentStatus::Mismatch)
        ->and($cause->fresh()->raisedAmount())->toEqualPesewas(0);
});

it('changes nothing on a dry run', function () {
    $cause = Cause::factory()->create();
    ['donation' => $donation] = $this->donations->start([
        'amount' => Money::ofMajor('250.00'),
        'cause' => $cause,
        'donor_name' => 'Kwabena Mensah',
        'donor_email' => 'kwabena@example.com',
    ]);

    $summary = $this->reconciliation->run(execute: false);

    expect($summary['checked'])->toBe(1)
        ->and($summary['recovered'])->toBe(0)
        ->and($donation->fresh()->status)->toBe(DonationStatus::Pending);
});

it('leaves offline gifts alone, having no gateway to ask', function () {
    $this->offline->record(offlineInput(), $this->staff);

    expect($this->reconciliation->run()['checked'])->toBe(0);
});

// ═══════════════════════════════════════════════════════════════════════════
//  Abandonment
// ═══════════════════════════════════════════════════════════════════════════

it('verifies once more before writing off an abandoned payment', function () {
    // A mobile-money prompt approved forty minutes late is a real gift.
    $cause = Cause::factory()->create();
    ['donation' => $donation, 'transaction' => $transaction] = $this->donations->start([
        'amount' => Money::ofMajor('250.00'),
        'cause' => $cause,
        'donor_name' => 'Kwabena Mensah',
        'donor_email' => 'kwabena@example.com',
    ]);

    $transaction->forceFill(['created_at' => now()->subHours(3)])->save();

    $summary = $this->reconciliation->run();

    expect($summary['recovered'])->toBe(1)
        ->and($donation->fresh()->status)->toBe(DonationStatus::Completed);
});

it('abandons a stale payment the gateway never settled', function () {
    $cause = Cause::factory()->create();
    ['donation' => $donation, 'transaction' => $transaction] = $this->donations->start([
        'amount' => Money::ofMajor('250.00'),
        'cause' => $cause,
        'donor_name' => 'Kwabena Mensah',
        'donor_email' => 'kwabena@example.com',
    ]);

    $transaction->forceFill([
        // The fake gateway leaves a -PENDING reference unresolved: the donor
        // opened the page and the gateway never heard from them again.
        'gateway_reference' => $transaction->gateway_reference.'-PENDING',
        'created_at' => now()->subHours(3),
    ])->save();

    $this->reconciliation->run();

    // Abandoned, not failed: the gateway never declined anything, and the
    // difference decides whether it is worth following up.
    expect($donation->fresh()->status)->toBe(DonationStatus::Abandoned);
});

it('marks a payment the gateway actively declined as failed, not abandoned', function () {
    // The other half of the distinction: a decline IS a failure, and calling it
    // abandonment would suggest the donor walked away when they did not.
    $cause = Cause::factory()->create();
    ['donation' => $donation, 'transaction' => $transaction] = $this->donations->start([
        'amount' => Money::ofMajor('250.00'),
        'cause' => $cause,
        'donor_name' => 'Kwabena Mensah',
        'donor_email' => 'kwabena@example.com',
    ]);

    $transaction->forceFill([
        'gateway_reference' => $transaction->gateway_reference.'-FAIL',
        'created_at' => now()->subHours(3),
    ])->save();

    $this->reconciliation->run();

    expect($donation->fresh()->status)->toBe(DonationStatus::Failed);
});

// ═══════════════════════════════════════════════════════════════════════════
//  What is reported rather than fixed
// ═══════════════════════════════════════════════════════════════════════════

it('reports a mismatch and never resolves it automatically', function () {
    $transaction = PaymentTransaction::factory()->create();
    $transaction->settle(Money::ofMinor(24_999));

    $summary = $this->reconciliation->run();

    expect($summary['mismatches'])->toBe(1)
        ->and($this->reconciliation->needsAttention($summary))->toBeTrue()
        // Still a mismatch afterwards. Somebody has to compare the settlement
        // against what the site expected.
        ->and($transaction->fresh()->status)->toBe(PaymentStatus::Mismatch);
});

it('reports a webhook that arrived and was never acted on', function () {
    PaymentWebhookEvent::create([
        'gateway' => 'paystack',
        'event_id' => 'charge.success:stuck',
        'event_type' => 'charge.success',
        'raw_payload' => '{}',
        'signature_valid' => true,
        'received_at' => now()->subHours(4),
    ]);

    $summary = $this->reconciliation->run();

    expect($summary['unprocessed_webhooks'])->toBe(1);
});

it('does not report a webhook that only just arrived', function () {
    PaymentWebhookEvent::create([
        'gateway' => 'paystack',
        'event_id' => 'charge.success:fresh',
        'event_type' => 'charge.success',
        'raw_payload' => '{}',
        'signature_valid' => true,
        'received_at' => now(),
    ]);

    expect($this->reconciliation->run()['unprocessed_webhooks'])->toBe(0);
});

it('reports a completed gift with no acknowledgement, without issuing one', function () {
    // A duplicate acknowledgement for a section 100 claim is worse than a late
    // one, so this reports and stops.
    $cause = Cause::factory()->create();
    $donation = Donation::factory()->create(['cause_id' => $cause->id]);
    DonationItem::create(['donation_id' => $donation->id, 'cause_id' => $cause->id, 'amount' => 25_000]);
    $donation->forceFill(['status' => DonationStatus::Completed, 'paid_at' => now()])->save();

    $summary = $this->reconciliation->run();

    expect($summary['missing_receipts'])->toBe(1)
        ->and(DonationReceipt::count())->toBe(0);
});

// ═══════════════════════════════════════════════════════════════════════════
//  The receipt series
// ═══════════════════════════════════════════════════════════════════════════

it('reports an unbroken receipt series', function () {
    $this->offline->recordAndAcknowledge(offlineInput(), $this->staff);
    $this->offline->recordAndAcknowledge(offlineInput(), $this->staff);

    $series = $this->reconciliation->receiptSeriesFor(now()->year);

    expect($series['count'])->toBe(2)
        ->and($series['gaps'])->toBe([]);
});

it('finds a gap in the series before an auditor does', function () {
    $this->offline->recordAndAcknowledge(offlineInput(), $this->staff);
    $this->offline->recordAndAcknowledge(offlineInput(), $this->staff);

    // Simulate a missing number 1 — the state that has to be explainable.
    DonationReceipt::query()->orderBy('sequence')->first()
        ->forceFill(['sequence' => 3])->saveQuietly();

    expect($this->reconciliation->receiptSeriesFor(now()->year)['gaps'])->toBe([1]);
});

// ═══════════════════════════════════════════════════════════════════════════
//  The command
// ═══════════════════════════════════════════════════════════════════════════

it('exits non-zero when something needs a person, so cron emails somebody', function () {
    // A report nobody reads is the same as no report.
    $transaction = PaymentTransaction::factory()->create();
    $transaction->settle(Money::ofMinor(1));

    $this->artisan('scghf:reconcile-payments', ['--execute' => true])
        ->assertFailed();
});

it('exits zero when the ledger and the gateway agree', function () {
    $this->artisan('scghf:reconcile-payments', ['--execute' => true])
        ->expectsOutputToContain('Ledger and gateway agree')
        ->assertSuccessful();
});

it('is a dry run unless told otherwise', function () {
    $this->artisan('scghf:reconcile-payments')
        ->expectsOutputToContain('DRY RUN')
        ->assertSuccessful();
});
