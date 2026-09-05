<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Support\AuditLogger;
use Filament\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exporting a table to CSV.
 *
 * ── It streams, and that is not an optimisation ─────────────────────────────
 *
 * Shared hosting gives PHP a memory limit measured in tens of megabytes.
 * Building a CSV of four thousand donations in an array and handing it to
 * `response()->download()` is how an export becomes a 500 that only appears
 * once the foundation has real data — the worst time to find out. Rows are
 * chunked out of the database and written straight to the output buffer, so
 * peak memory is one chunk regardless of how big the table gets.
 *
 * ── CSV, not Excel ──────────────────────────────────────────────────────────
 *
 * The brief asked for CSV or Excel. Excel means another dependency, a
 * templating layer and a format that has to be built in memory before it can
 * be sent — all three of which are things this host is bad at. Every
 * spreadsheet opens CSV.
 *
 * ── The byte-order mark is deliberate ───────────────────────────────────────
 *
 * Excel on Windows reads a UTF-8 CSV as Latin-1 unless the file starts with a
 * BOM, which turns `GH₵` into `GHâ‚µ` and every Ghanaian name with an accent
 * into mojibake. Three bytes fixes it.
 *
 * ── Exporting is audited ────────────────────────────────────────────────────
 *
 * `AuditLogger::recordExport()` was written in Phase 3 and called from nowhere.
 * An export takes data OUT of the application — past the policies, the
 * retention sweep and the audit trail — onto somebody's laptop. One person
 * exporting twenty donor records is doing their job; one exporting four
 * thousand at 11pm is a question, and it can only be asked if the export was
 * recorded.
 */
class ExportAction
{
    /**
     * @param  array<string, string|\Closure>  $columns  header => attribute or closure
     * @param  array<int, string>  $with  relations any of those closures reach through
     */
    public static function make(string $auditEvent, string $what, array $columns, array $with = []): Action
    {
        return Action::make('export')
            ->label(__('Export to CSV'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('Export this list'))
            ->modalDescription(__(
                'Downloads whatever the current filters show, not the whole table. The export is '
                .'recorded in the audit trail — including who ran it and how many rows it contained.'
            ))
            ->modalSubmitActionLabel(__('Download'))
            ->action(function (Table $table) use ($auditEvent, $what, $columns, $with): StreamedResponse {
                /*
                 * The FILTERED query, not the model's.
                 *
                 * Exporting everything when the screen shows a filtered subset
                 * is the commonest way an export quietly hands over more than
                 * somebody meant to share — and they have no way of noticing,
                 * because the file looks right until they scroll.
                 */
                $query = $table->getQuery()->clone();

                /*
                 * Eager-loaded, and this is not a micro-optimisation.
                 *
                 * A column like `fn ($record) => $record->category?->name`
                 * issues one query per row. On four thousand contact messages
                 * that is four thousand round trips inside a streaming
                 * response, on a host that will time the request out long
                 * before it finishes — and the failure arrives only once the
                 * foundation has real data. Tests catch it because lazy loading
                 * is disabled there; production would just be slow, then dead.
                 */
                if ($with !== []) {
                    $query->with($with);
                }

                $count = (clone $query)->count();

                app(AuditLogger::class)->recordExport(
                    event: $auditEvent,
                    what: $what,
                    recordCount: $count,
                    causer: auth()->user(),
                    /*
                     * Which filters were on when this ran.
                     *
                     * "Exported 4,000 rows" and "exported 4,000 rows with no
                     * filter applied" are the same sentence to a reader of the
                     * audit trail, and only the second one is answerable
                     * without this. `tableFilters` is the Livewire state — read
                     * directly, because `getTableFilterState()` wants the name
                     * of a single filter and there is no accessor for all of
                     * them.
                     */
                    context: ['filters' => static::activeFilters($table)],
                );

                $filename = str($what)->slug()->toString().'-'.now()->format('Y-m-d').'.csv';

                return response()->streamDownload(function () use ($query, $columns): void {
                    $handle = fopen('php://output', 'wb');

                    // See the note above: without this Excel mangles every
                    // accented name and the cedi sign.
                    fwrite($handle, "\xEF\xBB\xBF");

                    fputcsv($handle, array_keys($columns));

                    $query->chunk(500, function ($rows) use ($handle, $columns): void {
                        foreach ($rows as $row) {
                            fputcsv($handle, array_map(
                                fn (string|\Closure $accessor): string => static::value($row, $accessor),
                                array_values($columns),
                            ));
                        }

                        // Pushed out per chunk rather than held: the whole
                        // point is that the file never exists in memory.
                        flush();
                    });

                    fclose($handle);
                }, $filename, [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                    // Not cached anywhere between here and the browser. An
                    // export of donor records has no business in a proxy.
                    'Cache-Control' => 'no-store, no-cache, must-revalidate',
                ]);
            });
    }

    /**
     * The names of the filters that were actually narrowing the list.
     *
     * @return array<int, string>
     */
    private static function activeFilters(Table $table): array
    {
        /** @var array<string, mixed> $state */
        $state = $table->getLivewire()->tableFilters ?? [];

        return collect($state)
            ->filter(fn (mixed $value): bool => filled(array_filter(
                is_array($value) ? $value : [$value],
                fn (mixed $v): bool => $v !== null && $v !== '' && $v !== false,
            )))
            ->keys()
            ->all();
    }

    private static function value(Model $row, string|\Closure $accessor): string
    {
        $value = $accessor instanceof \Closure ? $accessor($row) : data_get($row, $accessor);

        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'Yes' : 'No',
            $value instanceof \BackedEnum => (string) $value->value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i'),
            default => (string) $value,
        };
    }
}
