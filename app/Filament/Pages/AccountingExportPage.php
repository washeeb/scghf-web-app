<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Finance\JournalExport;
use App\Support\AuditLogger;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Finance → Accounting export — a month of journal lines for the accounts.
 *
 * Pick the month, see the entries and the proof that debits equal
 * credits, download the CSV in the shape the foundation's package
 * imports (Settings → Accounting). The download is recorded as
 * `report.generated` with the month and the line count; it carries donor
 * and payee names, which is why it sits behind `donations.export`.
 */
class AccountingExportPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 55;

    protected string $view = 'filament.pages.accounting-export';

    protected static ?string $slug = 'accounting-export';

    #[Url]
    public string $month = '';

    public static function getNavigationLabel(): string
    {
        return __('Accounting export');
    }

    public function getTitle(): string
    {
        return __('Accounting export');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('donations.export') ?? false;
    }

    public function mount(): void
    {
        $this->month = preg_match('/^\d{4}-\d{2}$/', $this->month) === 1
            ? $this->month
            : now()->subMonthNoOverflow()->format('Y-m');
    }

    /** @return array<string, string> */
    public function months(): array
    {
        $out = [];

        for ($i = 0; $i < 18; $i++) {
            $m = now()->subMonthsNoOverflow($i);
            $out[$m->format('Y-m')] = $m->format('F Y');
        }

        return $out;
    }

    public function package(): string
    {
        $package = (string) setting('accounting.package', 'generic');

        return array_key_exists($package, JournalExport::PACKAGES) ? $package : 'generic';
    }

    /** @return Collection<int, array<string, string>> */
    public function lines(): Collection
    {
        return app(JournalExport::class)->month($this->month);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('download')
                ->label(__('Download CSV'))
                ->icon('heroicon-o-arrow-down-tray')
                ->action(function (): StreamedResponse {
                    $export = app(JournalExport::class);
                    $package = $this->package();
                    $lines = $export->month($this->month);
                    $totals = $export->totals($lines);

                    app(AuditLogger::class)->record('report.generated', sprintf('Accounting journal for %s (%s): %d lines, debits %s, credits %s.', $this->month, $package, $totals['lines'], $totals['debits']->format(), $totals['credits']->format()), null, auth()->user(), [
                        'month' => $this->month,
                        'package' => $package,
                        'lines' => $totals['lines'],
                    ]);

                    $filename = 'journal-'.$this->month.'-'.$package.'.csv';

                    return response()->streamDownload(function () use ($export, $lines, $package): void {
                        $handle = fopen('php://output', 'wb');
                        fwrite($handle, "\xEF\xBB\xBF");
                        fputcsv($handle, array_keys($export->columns($package)));

                        foreach ($lines as $line) {
                            fputcsv($handle, $export->row($line, $package));
                        }

                        fclose($handle);
                    }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
                }),
        ];
    }

    public function monthLabel(): string
    {
        return Carbon::createFromFormat('Y-m', $this->month)?->format('F Y') ?? $this->month;
    }
}
