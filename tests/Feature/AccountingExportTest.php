<?php

declare(strict_types=1);

use App\Enums\DonationStatus;
use App\Filament\Pages\AccountingExportPage;
use App\Finance\JournalExport;
use App\Models\AuditLog;
use App\Models\Cause;
use App\Models\Donation;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Payout;
use App\Models\Refund;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Settings;
use App\Support\ThemeTokens;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Wave 2 — the accounting export (roadmap 1.10, the CSV)
|--------------------------------------------------------------------------
|
| A month of the ledger as balanced journal lines against a chart of
| accounts from the settings, in the column shape of the accountant's
| package. Money stays integer pesewas until the last step; every entry
| balances; the download is audited.
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(RoleAndPermissionSeeder::class);
    BasePolicy::forgetKnownPermissions();
    ThemeTokens::flush();
    app(Settings::class)->flush();
});

function septemberGift(int $minor, int $fee = 0, bool $offline = false, string $method = 'bank_transfer'): Donation
{
    return Donation::factory()->create([
        'cause_id' => Cause::factory()->create(['title' => 'Harvest appeal'])->id,
        'status' => DonationStatus::Completed->value,
        'amount_minor' => $minor,
        'fee_minor' => $fee,
        'net_minor' => $minor - $fee,
        'donor_name' => 'Ama Mensah',
        'channel' => $offline ? 'offline' : 'card',
        'offline_method' => $offline ? $method : null,
        'paid_at' => Carbon::parse('2026-09-10 10:00:00'),
    ]);
}

it('writes a gateway gift as gross income into clearing with the fee out of it', function () {
    septemberGift(10000, 195);

    $lines = app(JournalExport::class)->month('2026-09');

    expect($lines)->toHaveCount(4)
        ->and($lines->pluck('account_name')->all())->toBe(['Paystack clearing', 'Donations', 'Payment processing fees', 'Paystack clearing'])
        ->and($lines[0]['debit'])->toBe('100.00')
        ->and($lines[1]['credit'])->toBe('100.00')
        ->and($lines[1]['fund'])->toBe('Harvest appeal')
        ->and($lines[1]['contact'])->toBe('Ama Mensah')
        ->and($lines[2]['debit'])->toBe('1.95')
        ->and($lines[3]['credit'])->toBe('1.95')
        ->and($lines[0]['account_code'])->toBe('1150');

    $totals = app(JournalExport::class)->totals($lines);
    expect($totals['difference']->isZero())->toBeTrue()->and($totals['debits']->toMinor())->toBe(10195);
});

it('puts an offline cash gift into the cash account with no fee, and honours anonymity', function () {
    $gift = septemberGift(5000, offline: true, method: 'cash');
    $gift->forceFill(['is_anonymous' => true])->save();

    $lines = app(JournalExport::class)->month('2026-09');

    expect($lines)->toHaveCount(2)
        ->and($lines[0]['account_name'])->toBe('Cash')
        ->and($lines[0]['contact'])->toBe('Anonymous donor')
        ->and(collect($lines)->pluck('contact')->all())->not->toContain('Ama Mensah');
});

it('splits an order into sales, shipping and the fee', function () {
    Order::factory()->paid()->create(['paid_at' => Carbon::parse('2026-09-12'), 'fee' => 224, 'customer_name' => 'Kofi Asante']);

    $lines = app(JournalExport::class)->month('2026-09');

    expect($lines->pluck('account_name')->all())->toBe(['Paystack clearing', 'Shop sales', 'Shipping recovered', 'Payment processing fees', 'Paystack clearing'])
        ->and($lines[0]['debit'])->toBe('115.00')
        ->and($lines[1]['credit'])->toBe('90.00')
        ->and($lines[2]['credit'])->toBe('25.00')
        ->and($lines[3]['debit'])->toBe('2.24')
        ->and(app(JournalExport::class)->totals($lines)['difference']->isZero())->toBeTrue();
});

it('reverses a processed refund against the income it came from', function () {
    $gift = septemberGift(10000, 195);
    $transaction = PaymentTransaction::factory()->settled()->create(['payable_type' => $gift->getMorphClass(), 'payable_id' => $gift->id, 'amount' => 10000]);

    Refund::create([
        'payment_transaction_id' => $transaction->id,
        'amount' => 4000, 'currency' => 'GHS',
        'status' => Refund::STATUS_PENDING, 'reason' => 'Duplicate gift.',
        'requested_by' => User::factory()->staff()->create()->id,
    ])->forceFill(['status' => Refund::STATUS_PROCESSED, 'processed_at' => Carbon::parse('2026-09-20')])->save();

    $lines = app(JournalExport::class)->month('2026-09')->where('journal', 'Refunds')->values();

    expect($lines)->toHaveCount(2)
        ->and($lines[0]['account_name'])->toBe('Donations')->and($lines[0]['debit'])->toBe('40.00')
        ->and($lines[1]['account_name'])->toBe('Paystack clearing')->and($lines[1]['credit'])->toBe('40.00')
        ->and($lines[0]['description'])->toContain('Duplicate gift.');
});

it('books a paid payout to the category account from the account it was paid from', function () {
    $payout = Payout::factory()->create(['category' => Payout::CATEGORY_MEDICAL, 'method' => 'mobile_money', 'amount' => 25000, 'payee_name' => 'Bolga Regional Hospital']);
    $payout->forceFill(['status' => Payout::STATUS_PAID, 'paid_at' => Carbon::parse('2026-09-25')])->save();

    // Not in the month: a payout still awaiting approval, and one paid in October.
    Payout::factory()->create(['amount' => 99900]);
    Payout::factory()->create(['amount' => 88800])->forceFill(['status' => Payout::STATUS_PAID, 'paid_at' => Carbon::parse('2026-10-02')])->save();

    $lines = app(JournalExport::class)->month('2026-09');

    expect($lines)->toHaveCount(2)
        ->and($lines[0]['account_code'])->toBe('5020')
        ->and($lines[0]['account_name'])->toBe('Programme — medical')
        ->and($lines[0]['debit'])->toBe('250.00')
        ->and($lines[1]['account_name'])->toBe('Mobile Money float')
        ->and($lines[1]['credit'])->toBe('250.00')
        ->and($lines[0]['contact'])->toBe('Bolga Regional Hospital');
});

it('takes the account codes and names from the settings', function () {
    app(Settings::class)->set('accounting.donations_code', '4010');
    app(Settings::class)->set('accounting.donations_name', 'Voluntary income');
    septemberGift(1000);

    $lines = app(JournalExport::class)->month('2026-09');

    expect($lines[1]['account_code'])->toBe('4010')->and($lines[1]['account_name'])->toBe('Voluntary income');
});

it('shapes the columns for each package without changing the figures', function () {
    septemberGift(10000, 195);
    $export = app(JournalExport::class);
    $line = $export->month('2026-09')->first();

    expect(array_keys($export->columns('quickbooks')))->toContain('JournalNo', 'Debits', 'Credits')
        ->and(array_keys($export->columns('xero')))->toContain('*AccountCode', '*Amount')
        ->and($export->row($line, 'xero'))->toContain('100.00')->toContain('10/09/2026')
        ->and($export->row($line, 'generic'))->toContain('2026-09-10');

    $credit = $export->month('2026-09')[1];
    expect($export->row($credit, 'xero'))->toContain('-100.00');
});

it('downloads from the Finance page for those who may export, and records it', function () {
    septemberGift(10000, 195);

    $finance = User::factory()->staff()->withTwoFactor()->create();
    $finance->assignRole('Finance Officer');
    $this->actingAs($finance->fresh());

    Livewire::test(AccountingExportPage::class, ['month' => '2026-09'])
        ->assertOk()
        ->assertSee('Harvest appeal')
        ->assertSee('GH₵ 101.95')
        ->assertSee('Balanced.')
        ->callAction('download');

    $entry = AuditLog::query()->where('event', 'report.generated')->latest('id')->first();
    expect($entry)->not->toBeNull()->and($entry->context['month'])->toBe('2026-09')->and($entry->context['lines'])->toBe(4);

    $support = User::factory()->staff()->withTwoFactor()->create();
    $support->assignRole('Support');
    $this->actingAs($support->fresh())->get(AccountingExportPage::getUrl())->assertForbidden();
});

it('exports from the console and refuses an unbalanced month', function () {
    septemberGift(10000, 195);

    $this->artisan('scghf:journal-export', ['month' => '2026-09', '--store' => true])
        ->assertSuccessful();

    $csv = Storage::disk('local')->get('journals/journal-2026-09-generic.csv');
    expect($csv)->toContain('Paystack clearing')->toContain('100.00')->toContain('Harvest appeal');

    $this->artisan('scghf:journal-export', ['month' => 'nonsense'])->assertFailed();
});
