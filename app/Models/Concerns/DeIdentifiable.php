<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Contracts\Retainable;
use App\Support\Anonymiser;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The mechanical half of de-identification, driven by the model's privacy map.
 *
 * A model using this trait declares `privacyElements()` — column to privacy
 * element — and gets `deIdentify()` and `retentionDigest()` for free, both
 * behaving consistently with the policy in config('compliance.privacy').
 *
 * What it does NOT do is decide policy. Which columns are destroyed is the
 * config's decision; which columns exist and what each one holds is the model's.
 * This trait only joins the two.
 *
 * @see Retainable
 */
trait DeIdentifiable
{
    /** @var array<string, array<int, string>> per-class cache of nullable columns */
    private static array $nullableColumnCache = [];

    /**
     * Overwrite and clear every column mapped to a `destroy` element.
     *
     * Idempotent: running it twice on the same record is harmless, because a
     * column already cleared has nothing left to overwrite. That matters —
     * a retention run that fails halfway must be safe to re-run.
     */
    public function deIdentify(): void
    {
        $anonymiser = app(Anonymiser::class);
        $nullable = $this->nullableColumns();
        $updates = [];

        foreach (static::privacyElements() as $column => $element) {
            if (! $anonymiser->mustDestroy($element)) {
                // generalise and keep elements are projected into the anonymous
                // analytics dataset, not coarsened in place. An amount band
                // does not fit in a BIGINT column, and writing a keep value back
                // over itself achieves nothing.
                continue;
            }

            if (! array_key_exists($column, $this->getAttributes())) {
                continue;
            }

            $value = $this->getAttribute($column);

            // Already cleared, or already overwritten on an earlier run. Both
            // are skipped so a re-run neither fails nor churns the row.
            if ($value === null || $anonymiser->isRedacted($value)) {
                continue;
            }

            /*
             * Nullable columns are nulled; non-nullable ones get a redaction
             * marker, because a NOT NULL column cannot be emptied and leaving
             * the original in place would defeat the whole exercise.
             */
            $updates[$column] = in_array($column, $nullable, true)
                ? null
                : $anonymiser->overwriteValue();
        }

        if ($updates === []) {
            return;
        }

        // forceFill: several of these columns are deliberately not fillable
        // (identity documents, case notes), and mass-assignment protection
        // exists to stop a request writing them, not to stop this.
        $this->forceFill($updates)->save();

        $this->deIdentifyRelated();
    }

    /**
     * Detach media, related documents and anything else outside this row.
     *
     * Overridden by models that have such things. The default is a no-op rather
     * than an abstract method so a model with nothing attached does not have to
     * write an empty body to say so.
     */
    protected function deIdentifyRelated(): void
    {
        //
    }

    /**
     * A one-way digest of this record's direct identifiers.
     *
     * Salted with the application key so the digest cannot be reproduced from
     * outside the application — an unsalted hash of a Ghana Card number is
     * trivially reversible by anyone who can guess the format.
     */
    public function retentionDigest(): ?string
    {
        $anonymiser = app(Anonymiser::class);
        $parts = [];

        foreach (static::privacyElements() as $column => $element) {
            if (! $anonymiser->mustDestroy($element)) {
                continue;
            }

            $value = $this->getAttribute($column);

            // A redaction marker is not an identifier, so digesting it would
            // produce a value that looks like evidence and matches nobody.
            if ($value === null || $value === '' || $anonymiser->isRedacted($value)) {
                continue;
            }

            $parts[$column] = is_scalar($value) ? (string) $value : json_encode($value);
        }

        if ($parts === []) {
            return null;
        }

        ksort($parts);

        return hash_hmac('sha256', json_encode($parts), (string) config('app.key'));
    }

    /**
     * Assert that every column on this table has been classified.
     *
     * Called from a test, not from application code. The point is that adding a
     * column without deciding what happens to it at retention expiry should
     * break the build, not quietly ship.
     *
     * @return array<int, string> unclassified columns
     */
    public static function unclassifiedColumns(): array
    {
        $model = new static;

        $columns = Schema::getColumnListing($model->getTable());

        return array_values(array_diff(
            $columns,
            array_keys(static::privacyElements()),
            static::privacyExempt(),
        ));
    }

    /**
     * Columns that hold no personal data and need no classification.
     *
     * The defaults cover what every table has. A model adds its own structural
     * columns to this list.
     *
     * @return array<int, string>
     */
    public static function privacyExempt(): array
    {
        return ['id', 'ulid', 'uuid', 'created_at', 'updated_at', 'deleted_at'];
    }

    /** @return array<int, string> */
    private function nullableColumns(): array
    {
        $table = $this->getTable();

        if (isset(self::$nullableColumnCache[$table])) {
            return self::$nullableColumnCache[$table];
        }

        $columns = Schema::getColumns($table);

        if ($columns === []) {
            throw new RuntimeException(
                "Cannot de-identify [{$table}]: the table has no columns, or does not exist."
            );
        }

        return self::$nullableColumnCache[$table] = array_values(array_map(
            static fn (array $column): string => $column['name'],
            array_filter($columns, static fn (array $column): bool => (bool) $column['nullable']),
        ));
    }
}
