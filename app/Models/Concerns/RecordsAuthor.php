<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who made this, and who last changed it.
 *
 * ── Why this exists ─────────────────────────────────────────────────────────
 *
 * Thirty tables in this schema declare `created_by`, `updated_by`, or both.
 * Until this trait, **nothing in the application wrote either of them**. The
 * columns existed, the foreign keys constrained, `->with('createdBy')` resolved
 * — and every answer was null.
 *
 * That is the worst shape an audit field can take. An obviously absent column
 * prompts somebody to add one; a present column that always says "nobody" gets
 * believed. "Who published this appeal?" and "who changed the receipt wording?"
 * are questions this schema looked able to answer and could not.
 *
 * ── On the model, not in Filament ───────────────────────────────────────────
 *
 * The same reasoning as `Setting`'s history hook: an audit field written only
 * by the admin panel has a hole in it exactly where somebody bypassed the admin
 * panel — an import, a console command, a `forceFill` in a fix script.
 *
 * ── Only for a real person ──────────────────────────────────────────────────
 *
 * A seeder, a queued job and a scheduled command all run with no authenticated
 * user. Attributing their work to whoever happens to be logged in — or to the
 * system user — would be a lie, and a lie recorded in an audit column is worse
 * than a null, because a null is visibly missing.
 *
 * ── It never overwrites a value somebody set on purpose ─────────────────────
 *
 * A few callers already pass `created_by` explicitly — recording an offline
 * donation on behalf of a colleague, for instance, where the author is not the
 * person clicking save. An explicit value always wins.
 */
trait RecordsAuthor
{
    public static function bootRecordsAuthor(): void
    {
        static::creating(function (Model $model): void {
            if (! auth()->hasUser()) {
                return;
            }

            if (static::recordsColumn($model, 'created_by') && blank($model->created_by)) {
                $model->created_by = auth()->id();
            }

            // A row created and never edited was last changed by its author.
            // Leaving `updated_by` null on creation makes "last changed by"
            // read as unknown on every brand-new record.
            if (static::recordsColumn($model, 'updated_by') && blank($model->updated_by)) {
                $model->updated_by = auth()->id();
            }
        });

        static::updating(function (Model $model): void {
            if (! auth()->hasUser() || ! static::recordsColumn($model, 'updated_by')) {
                return;
            }

            // Not when the only thing changing IS `updated_by` — that would be
            // a caller setting it deliberately, and this would overwrite them.
            if ($model->isDirty('updated_by')) {
                return;
            }

            $model->updated_by = auth()->id();
        });
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Whether this model's table actually has the column.
     *
     * The trait is applied by table shape, and the two columns do not always
     * travel together — `menus` has only `updated_by`, `galleries` only
     * `created_by`. Asking the schema rather than keeping a per-model list
     * means one trait serves both and cannot drift out of step with the
     * migrations.
     *
     * Cached for the process. `hasColumn()` is a query against the information
     * schema, and running two of them on every save of every content model
     * would be a real cost paid on every admin action for an answer that cannot
     * change while the process is alive.
     *
     * @var array<string, bool>|null
     */
    private static ?array $columnCache = null;

    private static function recordsColumn(Model $model, string $column): bool
    {
        $key = $model->getTable().'.'.$column;

        return self::$columnCache[$key] ??= $model->getConnection()
            ->getSchemaBuilder()
            ->hasColumn($model->getTable(), $column);
    }
}
