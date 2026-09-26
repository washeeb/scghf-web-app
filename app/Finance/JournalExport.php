<?php

declare(strict_types=1);

namespace App\Finance;

use App\Enums\DonationStatus;
use App\Models\Donation;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Refund;
use App\ValueObjects\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A month of the foundation's money as journal lines — Wave 2 (1.10).
 *
 * ── What it is ──────────────────────────────────────────────────────────────
 *
 * The treasurer re-keys the monthly CSV into the accounts today. This is
 * the same month as double-entry journal lines that import into
 * QuickBooks, Xero, Zoho or a spreadsheet: every settled gift, every paid
 * order, every processing fee, every refund, every payout, each as a
 * balanced pair (or triple) of debit and credit against a chart of
 * accounts the foundation sets under Settings → Accounting. No live API
 * — the accountant's package is not yet known, and a CSV imports into all
 * of them.
 *
 * ── What it is not ──────────────────────────────────────────────────────────
 *
 * Not the ledger. The ledger is the donations, orders, refunds and
 * payouts tables, append-only, with the webhook as the source of truth.
 * This is a view of it for one purpose, and running it twice gives the
 * same lines twice — the treasurer imports a month once, which is the
 * "idempotency" a CSV can offer. The reference column carries the
 * application's own reference so a line can always be traced back.
 *
 * ── The entries ─────────────────────────────────────────────────────────────
 *
 *   gift (gateway)   Dr Paystack clearing (gross)   Cr Donation income (gross)
 *                    Dr Processing fees (fee)       Cr Paystack clearing (fee)
 *   gift (offline)   Dr Cash / Bank / MoMo (gross)  Cr Donation income (gross)
 *   order            Dr Paystack clearing (total)   Cr Shop sales (total − shipping), Cr Shipping (shipping)
 *                    Dr Processing fees (fee)       Cr Paystack clearing (fee)
 *   refund           Dr the income it reverses      Cr Paystack clearing
 *   payout           Dr Programme expense (category) Cr Bank / MoMo / Cash
 *
 * Settlements from Paystack to the bank are not here: the application
 * does not hold them as rows, and the bank statement is their evidence.
 * The clearing account's balance after import is what Paystack owes the
 * foundation, which is the figure reconciliation checks.
 *
 * Amounts are decimal strings in cedis from Money — never floats — and
 * every entry balances by construction; `totals()` proves it.
 */
final class JournalExport
{
    public const PACKAGES = ['generic' => 'Generic (any spreadsheet)', 'quickbooks' => 'QuickBooks', 'xero' => 'Xero', 'zoho' => 'Zoho Books'];

    /** The accounts, as setting keys under `accounting.` → default code and name. */
    public const ACCOUNTS = [
        'clearing' => ['1150', 'Paystack clearing'],
        'bank' => ['1100', 'Bank account'],
        'cash' => ['1000', 'Cash'],
        'momo' => ['1120', 'Mobile Money float'],
        'donations' => ['4000', 'Donations'],
        'shop_sales' => ['4100', 'Shop sales'],
        'shipping' => ['4110', 'Shipping recovered'],
        'fees' => ['6100', 'Payment processing fees'],
        'programme' => ['5000', 'Programme expenses'],
        'programme_school_fees' => ['5010', 'Programme — school fees'],
        'programme_medical' => ['5020', 'Programme — medical'],
        'programme_food' => ['5030', 'Programme — food'],
        'programme_rent' => ['5040', 'Programme — rent'],
        'programme_stipend' => ['5050', 'Programme — stipends'],
        'programme_supplier' => ['5060', 'Programme — suppliers'],
        'programme_transport' => ['5070', 'Programme — transport'],
        'programme_equipment' => ['5080', 'Programme — equipment'],
    ];

    /**
     * @return Collection<int, array{date: string, journal: string, reference: string, account_code: string, account_name: string, debit: string, credit: string, description: string, contact: string, fund: string}>
     */
    public function lines(Carbon $from, Carbon $until): Collection
    {
        $lines = collect();

        foreach ($this->gifts($from, $until) as $gift) {
            $lines = $lines->merge($this->giftLines($gift));
        }

        foreach ($this->orders($from, $until) as $order) {
            $lines = $lines->merge($this->orderLines($order));
        }

        foreach ($this->refunds($from, $until) as $refund) {
            $lines = $lines->merge($this->refundLines($refund));
        }

        foreach ($this->payouts($from, $until) as $payout) {
            $lines = $lines->merge($this->payoutLines($payout));
        }

        return $lines->sortBy([['date', 'asc'], ['reference', 'asc']])->values();
    }

    /** A month, by name: 2026-09. */
    public function month(string $yearMonth): Collection
    {
        $from = Carbon::createFromFormat('Y-m-d', $yearMonth.'-01')->startOfMonth();

        return $this->lines($from, $from->copy()->endOfMonth());
    }

    /**
     * Debits, credits, and the difference — which is always zero.
     *
     * @param  Collection<int, array<string, string>>  $lines
     * @return array{debits: Money, credits: Money, difference: Money, lines: int}
     */
    public function totals(Collection $lines): array
    {
        $debits = 0;
        $credits = 0;

        foreach ($lines as $line) {
            $debits += Money::ofMajor((float) ($line['debit'] ?: 0))->toMinor();
            $credits += Money::ofMajor((float) ($line['credit'] ?: 0))->toMinor();
        }

        return [
            'debits' => Money::ofMinor($debits),
            'credits' => Money::ofMinor($credits),
            'difference' => Money::ofMinor($debits - $credits),
            'lines' => $lines->count(),
        ];
    }

    /**
     * The CSV columns for a package. The values are the same; the headers
     * are what each importer expects, so the treasurer maps nothing.
     *
     * @return array<string, string> header => line key
     */
    public function columns(string $package): array
    {
        return match ($package) {
            'quickbooks' => ['JournalNo' => 'reference', 'JournalDate' => 'date', 'Account' => 'account_name', 'Debits' => 'debit', 'Credits' => 'credit', 'Description' => 'description', 'Name' => 'contact', 'Class' => 'fund'],
            'xero' => ['*Narration' => 'description', '*Date' => 'date', '*AccountCode' => 'account_code', '*Amount' => 'signed', 'Description' => 'reference', 'TrackingName1' => 'fund_label', 'TrackingOption1' => 'fund'],
            'zoho' => ['Journal Date' => 'date', 'Reference Number' => 'reference', 'Notes' => 'description', 'Account' => 'account_name', 'Debit' => 'debit', 'Credit' => 'credit', 'Contact Name' => 'contact'],
            default => ['Date' => 'date', 'Journal' => 'journal', 'Reference' => 'reference', 'Account code' => 'account_code', 'Account' => 'account_name', 'Debit' => 'debit', 'Credit' => 'credit', 'Description' => 'description', 'Contact' => 'contact', 'Fund / project' => 'fund'],
        };
    }

    /** One row of the CSV for a package. */
    public function row(array $line, string $package): array
    {
        $line['signed'] = $line['debit'] !== '' ? $line['debit'] : '-'.$line['credit'];
        $line['fund_label'] = $line['fund'] !== '' ? 'Fund' : '';
        $line['date'] = $package === 'generic' ? $line['date'] : Carbon::parse($line['date'])->format('d/m/Y');

        return array_map(fn (string $key): string => (string) ($line[$key] ?? ''), array_values($this->columns($package)));
    }

    // ── Sources ──────────────────────────────────────────────────────────────

    /** @return Collection<int, Donation> */
    private function gifts(Carbon $from, Carbon $until): Collection
    {
        return Donation::query()
            ->whereIn('status', [DonationStatus::Completed->value, DonationStatus::Refunded->value])
            ->whereBetween('paid_at', [$from, $until])
            ->with('cause:id,title')
            ->orderBy('paid_at')
            ->get();
    }

    /** @return Collection<int, Order> */
    private function orders(Carbon $from, Carbon $until): Collection
    {
        return Order::query()
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from, $until])
            ->orderBy('paid_at')
            ->get();
    }

    /** @return Collection<int, Refund> */
    private function refunds(Carbon $from, Carbon $until): Collection
    {
        return Refund::query()
            ->where('status', Refund::STATUS_PROCESSED)
            ->whereBetween('processed_at', [$from, $until])
            ->with('transaction.payable')
            ->orderBy('processed_at')
            ->get();
    }

    /** @return Collection<int, Payout> */
    private function payouts(Carbon $from, Carbon $until): Collection
    {
        return Payout::query()
            ->where('status', Payout::STATUS_PAID)
            ->whereBetween('paid_at', [$from, $until])
            ->with(['project:id,title', 'cause:id,title'])
            ->orderBy('paid_at')
            ->get();
    }

    // ── Entries ──────────────────────────────────────────────────────────────

    /** @return array<int, array<string, string>> */
    private function giftLines(Donation $gift): array
    {
        $date = ($gift->paid_at ?? $gift->created_at)->toDateString();
        $gross = $gift->amount;
        $fee = $gift->fee ?? Money::zero();
        $fund = $gift->cause ? $gift->cause->title : '';
        $who = $gift->is_anonymous ? 'Anonymous donor' : (string) ($gift->donor_name ?: 'Donor');
        $description = 'Donation '.$gift->reference.($fund !== '' ? ' — '.$fund : '');

        $offline = $gift->channel === 'offline';
        $receiving = $offline ? match ((string) $gift->offline_method) {
            'cash' => 'cash',
            'mobile_money', 'momo' => 'momo',
            default => 'bank',
        } : 'clearing';

        $lines = [
            $this->line($date, 'Donations', $gift->reference, $receiving, $gross, null, $description, $who, $fund),
            $this->line($date, 'Donations', $gift->reference, 'donations', null, $gross, $description, $who, $fund),
        ];

        if (! $offline && $fee->isPositive()) {
            $lines[] = $this->line($date, 'Donations', $gift->reference, 'fees', $fee, null, 'Processing fee on '.$gift->reference, 'Paystack', $fund);
            $lines[] = $this->line($date, 'Donations', $gift->reference, 'clearing', null, $fee, 'Processing fee on '.$gift->reference, 'Paystack', $fund);
        }

        return $lines;
    }

    /** @return array<int, array<string, string>> */
    private function orderLines(Order $order): array
    {
        $date = $order->paid_at->toDateString();
        $total = $order->total ?? Money::zero();
        $shipping = $order->shipping ?? Money::zero();
        $sales = $total->minus($shipping);
        $fee = $order->fee ?? Money::zero();
        $who = (string) ($order->customer_name ?: 'Customer');
        $description = 'Shop order '.$order->reference;

        $lines = [
            $this->line($date, 'Shop', $order->reference, 'clearing', $total, null, $description, $who, ''),
            $this->line($date, 'Shop', $order->reference, 'shop_sales', null, $sales, $description, $who, ''),
        ];

        if ($shipping->isPositive()) {
            $lines[] = $this->line($date, 'Shop', $order->reference, 'shipping', null, $shipping, 'Shipping on '.$order->reference, $who, '');
        }

        if ($fee->isPositive()) {
            $lines[] = $this->line($date, 'Shop', $order->reference, 'fees', $fee, null, 'Processing fee on '.$order->reference, 'Paystack', '');
            $lines[] = $this->line($date, 'Shop', $order->reference, 'clearing', null, $fee, 'Processing fee on '.$order->reference, 'Paystack', '');
        }

        return $lines;
    }

    /** @return array<int, array<string, string>> */
    private function refundLines(Refund $refund): array
    {
        $date = ($refund->processed_at ?? $refund->updated_at)->toDateString();
        $amount = $refund->amount;
        $payable = $refund->transaction?->payable;

        [$income, $reference, $who, $fund] = match (true) {
            $payable instanceof Donation => ['donations', $payable->reference, $payable->is_anonymous ? 'Anonymous donor' : (string) $payable->donor_name, $payable->cause ? $payable->cause->title : ''],
            $payable instanceof Order => ['shop_sales', $payable->reference, (string) $payable->customer_name, ''],
            default => ['donations', (string) $refund->gateway_reference, '', ''],
        };

        $description = 'Refund of '.$reference.($refund->reason ? ' — '.$refund->reason : '');

        return [
            $this->line($date, 'Refunds', 'R-'.$reference, $income, $amount, null, $description, $who, $fund),
            $this->line($date, 'Refunds', 'R-'.$reference, 'clearing', null, $amount, $description, $who, $fund),
        ];
    }

    /** @return array<int, array<string, string>> */
    private function payoutLines(Payout $payout): array
    {
        $date = $payout->paid_at->toDateString();
        $amount = $payout->amount ?? Money::zero();
        $expense = array_key_exists('programme_'.$payout->category, self::ACCOUNTS) ? 'programme_'.$payout->category : 'programme';
        $paidFrom = match ((string) $payout->method) {
            'cash' => 'cash',
            'mobile_money', 'momo' => 'momo',
            default => 'bank',
        };
        $fund = $payout->cause ? $payout->cause->title : ($payout->project ? $payout->project->title : '');
        $description = 'Payout '.$payout->reference.' — '.$payout->purpose;

        return [
            $this->line($date, 'Payouts', $payout->reference, $expense, $amount, null, $description, (string) $payout->payee_name, $fund),
            $this->line($date, 'Payouts', $payout->reference, $paidFrom, null, $amount, $description, (string) $payout->payee_name, $fund),
        ];
    }

    /** @return array<string, string> */
    private function line(string $date, string $journal, string $reference, string $account, ?Money $debit, ?Money $credit, string $description, string $contact, string $fund): array
    {
        [$code, $name] = $this->account($account);

        return [
            'date' => $date,
            'journal' => $journal,
            'reference' => $reference,
            'account_code' => $code,
            'account_name' => $name,
            'debit' => $debit?->toMajorString() ?? '',
            'credit' => $credit?->toMajorString() ?? '',
            'description' => $description,
            'contact' => $contact,
            'fund' => $fund,
        ];
    }

    /**
     * The code and name for an account, from the settings, with the
     * defaults above when the foundation has not set one.
     *
     * @return array{0: string, 1: string}
     */
    public function account(string $key): array
    {
        [$code, $name] = self::ACCOUNTS[$key];

        return [
            (string) (setting('accounting.'.$key.'_code') ?: $code),
            (string) (setting('accounting.'.$key.'_name') ?: $name),
        ];
    }
}
