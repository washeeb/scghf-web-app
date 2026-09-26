<?php

declare(strict_types=1);

namespace App\Media;

use App\Models\Media;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Where is this image used?" — and, more importantly, "may I delete it?"
 *
 * ── This is a guard, not a report ───────────────────────────────────────────
 *
 * Thirty-four foreign keys across thirty tables point at `media`, and
 * thirty-two of them are ON DELETE SET NULL. That means deleting a file that is
 * in use does not fail, does not warn, and leaves no trace: a donation receipt
 * silently loses its PDF, a beneficiary silently loses their ID document, and a
 * CONSENT RECORD SILENTLY LOSES THE EVIDENCE IT IS EVIDENCE OF — while still
 * reading, to anybody auditing it later, as a consent that has evidence.
 *
 * The other two are ON DELETE CASCADE and take the row with them.
 *
 * So the answer this class gives is enforced by `Media::deleting()`, not merely
 * displayed next to a delete button.
 *
 * ── Discovered from the schema, deliberately ────────────────────────────────
 *
 * Every other registry in this project is an explicit list, so an omission is
 * visible. Here the columns are named a dozen different ways — `media_id`,
 * `image_id`, `photo_id`, `logo_id`, `cover_id`, `featured_image_id`,
 * `og_image_id`, `photograph_id`, `evidence_media_id`, `pdf_media_id`,
 * `cv_media_id`, `id_document_id`, `signature_id`, `document_id` — and a
 * hand-written list would be out of date within a phase. The column nobody
 * remembered is exactly the one this has to catch, so it asks the database.
 */
class MediaUsage
{
    private const CACHE_KEY = 'media.usage.columns';

    /**
     * Every (table, column) that points at `media`.
     *
     * Cached forever rather than for a TTL: this changes only when a migration
     * runs, and `php artisan migrate` is a deploy step where a cache clear
     * already happens. A TTL would mean a window after a deploy in which a new
     * column is not protected, which is the whole failure this class prevents.
     *
     * @return array<int, array{table: string, column: string}>
     */
    public function columns(): array
    {
        /** @var array<int, array{table: string, column: string}> */
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            try {
                $rows = DB::select(
                    'SELECT TABLE_NAME AS `table`, COLUMN_NAME AS `column`
                       FROM information_schema.KEY_COLUMN_USAGE
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND REFERENCED_TABLE_NAME = ?
                   ORDER BY TABLE_NAME, COLUMN_NAME',
                    [(new Media)->getTable()],
                );
            } catch (Throwable) {
                /*
                 * A database that cannot answer must not become a database
                 * where everything is deletable. An empty list here would read
                 * as "nothing uses this file" and let the deletion through, so
                 * the caller treats a failure as a refusal instead — see
                 * `isInUse()`.
                 */
                return [];
            }

            return array_map(
                static fn (object $row): array => [
                    'table' => (string) $row->table,
                    'column' => (string) $row->column,
                ],
                $rows,
            );
        });
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Everywhere this file is referenced.
     *
     * One query per referencing table, which is thirty on a full library — but
     * this runs when somebody opens a file's detail page or tries to delete it,
     * not on a page a donor loads. Correctness is worth thirty indexed lookups
     * against a foreign key here.
     *
     * @return array<int, array{table: string, column: string, label: string, id: int|string, title: ?string, confidential: bool}>
     */
    public function for(Media $media): array
    {
        $usages = [];

        foreach ($this->columns() as ['table' => $table, 'column' => $column]) {
            $titleColumn = $this->titleColumnFor($table);

            $select = ['id'];

            if ($titleColumn !== null) {
                $select[] = $titleColumn.' as usage_title';
            }

            try {
                $rows = DB::table($table)
                    ->where($column, $media->getKey())
                    ->limit(25)
                    ->get($select);
            } catch (Throwable) {
                // A table that cannot be read is reported as a use with no
                // detail rather than skipped. Skipping it would quietly make
                // the file deletable.
                $usages[] = [
                    'table' => $table,
                    'column' => $column,
                    'label' => $this->labelFor($table),
                    'id' => 0,
                    'title' => null,
                    'confidential' => $this->isConfidential($table),
                ];

                continue;
            }

            foreach ($rows as $row) {
                $usages[] = [
                    'table' => $table,
                    'column' => $column,
                    'label' => $this->labelFor($table),
                    'id' => $row->id,
                    'title' => isset($row->usage_title) ? (string) $row->usage_title : null,
                    'confidential' => $this->isConfidential($table),
                ];
            }
        }

        return $usages;
    }

    /**
     * Whether anything at all references this file.
     *
     * Stops at the first hit rather than collecting them, because the delete
     * guard only needs a yes or no and a file on a busy gallery could have
     * hundreds.
     *
     * ⚠ Returns TRUE when it cannot tell. A failure to answer must not become
     * permission — the cost of a wrong "no" here is a receipt losing its PDF
     * with nothing recording it, and the cost of a wrong "yes" is somebody
     * being told to try again.
     */
    public function isInUse(Media $media): bool
    {
        $columns = $this->columns();

        if ($columns === []) {
            return true;
        }

        foreach ($columns as ['table' => $table, 'column' => $column]) {
            try {
                if (DB::table($table)->where($column, $media->getKey())->exists()) {
                    return true;
                }
            } catch (Throwable) {
                return true;
            }
        }

        return false;
    }

    /**
     * A sentence explaining a refusal, safe to show whoever asked.
     *
     * Counts are always given. Identifying titles are given only for uses that
     * are not confidential — "this file is used by a consent record" is fine;
     * "this file is the evidence for Ama Mensah's consent" names a beneficiary
     * to whoever happens to be browsing a photo library.
     */
    public function explain(Media $media, bool $maySeeConfidential = false): string
    {
        $usages = $this->for($media);

        if ($usages === []) {
            return 'This file is not used anywhere.';
        }

        $named = [];
        $withheld = 0;

        foreach ($usages as $usage) {
            if ($usage['confidential'] && ! $maySeeConfidential) {
                $withheld++;

                continue;
            }

            $named[] = $usage['title'] === null || $usage['title'] === ''
                ? $usage['label']
                : $usage['label'].' — '.Str::limit($usage['title'], 60);
        }

        $sentence = sprintf(
            'This file is used in %d %s.',
            count($usages),
            count($usages) === 1 ? 'place' : 'places',
        );

        if ($named !== []) {
            $sentence .= ' '.implode('; ', array_slice($named, 0, 10)).'.';
        }

        if ($withheld > 0) {
            $sentence .= sprintf(
                ' %d %s a confidential record, which is not named here.',
                $withheld,
                $withheld === 1 ? 'of them is' : 'of them are',
            );
        }

        return $sentence;
    }

    // ── Labels ───────────────────────────────────────────────────────────────

    private function labelFor(string $table): string
    {
        /** @var array<string, array{label?: string, title?: string}> $labels */
        $labels = config('media.usage_labels', []);

        return $labels[$table]['label']
            // A table with no entry gets a humanised name. Worse prose, equally
            // safe — the guard does not depend on the label existing.
            ?? Str::of($table)->replace('_', ' ')->singular()->ucfirst()->toString();
    }

    private function titleColumnFor(string $table): ?string
    {
        /** @var array<string, array{label?: string, title?: string}> $labels */
        $labels = config('media.usage_labels', []);
        $column = $labels[$table]['title'] ?? null;

        if ($column === null) {
            return null;
        }

        // The label map is hand-written and the schema moves. A column named
        // here that no longer exists must degrade to "no title", not to an
        // exception on a delete attempt.
        try {
            return DB::getSchemaBuilder()->hasColumn($table, $column) ? $column : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function isConfidential(string $table): bool
    {
        /** @var array<int, string> $confidential */
        $confidential = config('media.confidential_usage', []);

        return in_array($table, $confidential, true);
    }
}
