<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Finance\JournalExport;
use App\Support\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * The same journal the Finance page downloads, from the terminal — for a
 * treasurer with SSH, or for a runbook step that files each month's CSV
 * under storage/app/journals/. Nothing is emailed: a file with payee names
 * is handed over, not sent.
 */
class JournalExportCommand extends Command
{
    protected $signature = 'scghf:journal-export
        {month? : The month, as 2026-09 (default: last month)}
        {--package= : generic, quickbooks, xero or zoho (default: the Accounting setting)}
        {--store : Write to storage/app/journals/ instead of standard output}';

    protected $description = 'Export a month of the accounts as journal lines (CSV)';

    public function handle(JournalExport $export): int
    {
        $month = (string) ($this->argument('month') ?: now()->subMonthNoOverflow()->format('Y-m'));

        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            $this->error('Give the month as YYYY-MM.');

            return self::INVALID;
        }

        $package = (string) ($this->option('package') ?: setting('accounting.package', 'generic'));

        if (! array_key_exists($package, JournalExport::PACKAGES)) {
            $this->error('Unknown package. One of: '.implode(', ', array_keys(JournalExport::PACKAGES)));

            return self::INVALID;
        }

        $lines = $export->month($month);
        $totals = $export->totals($lines);

        if (! $totals['difference']->isZero()) {
            $this->error(sprintf('The journal for %s does not balance (difference %s). Nothing written.', $month, $totals['difference']->format()));

            return self::FAILURE;
        }

        $csv = "\xEF\xBB\xBF".$this->csv([array_keys($export->columns($package))]);

        foreach ($lines as $line) {
            $csv .= $this->csv([$export->row($line, $package)]);
        }

        app(AuditLogger::class)->record('report.generated', sprintf('Accounting journal for %s (%s) exported from the console: %d lines.', $month, $package, $totals['lines']), null, null, ['month' => $month, 'package' => $package, 'lines' => $totals['lines']]);

        if ($this->option('store')) {
            $path = "journals/journal-{$month}-{$package}.csv";
            Storage::disk('local')->put($path, $csv);
            $this->info(sprintf('%d lines, debits %s, credits %s → storage/app/%s', $totals['lines'], $totals['debits']->format(), $totals['credits']->format(), $path));

            return self::SUCCESS;
        }

        $this->output->write($csv);

        return self::SUCCESS;
    }

    /** @param  array<int, array<int, string>>  $rows */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $out = (string) stream_get_contents($handle);
        fclose($handle);

        return $out;
    }
}
