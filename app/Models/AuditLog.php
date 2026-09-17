<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * One recorded action. Append-only, and chained.
 *
 * ── What belongs here that belongs nowhere else ─────────────────────────────
 *
 * spatie/laravel-activitylog records model CHANGES. This records ACTIONS —
 * including the ones that change nothing, which is most of the ones that
 * matter for privacy:
 *
 *   - somebody opened a beneficiary's file and read their medical history
 *   - somebody exported four thousand donor records to a spreadsheet
 *   - somebody ran the retention sweep, or released a suppression
 *
 * None of those write to a model, so none of them appear anywhere today. For a
 * foundation holding files on vulnerable children, "who read this?" is a more
 * serious question than "who changed this?", and until now nothing could
 * answer it.
 *
 * ── The chain ───────────────────────────────────────────────────────────────
 *
 * Each row hashes its own content together with the previous row's hash. Delete
 * a row, or edit one, and every hash after it stops matching —
 * `scghf:verify-audit-log` reports the first break.
 *
 * What that buys, precisely: tampering becomes DETECTABLE. It does not become
 * impossible. Somebody with database access can recompute the chain from the
 * tampered point onward and leave it internally consistent. The control that
 * defeats THAT is anchoring the head hash somewhere outside the database, which
 * the verify command writes to the application log; an auditor comparing the
 * two is what makes the chain meaningful. Claiming more than this for a hash
 * chain would be dishonest.
 */
class AuditLog extends Model
{
    use HasFactory;
    use HasUlids;

    public const UPDATED_AT = null;

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_NOTICE = 'notice';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_CRITICAL = 'critical';

    /**
     * Nothing is mass-assignable.
     *
     * Every row is written by App\Support\AuditLogger, which is the only thing
     * that knows how to compute the chain. A row created any other way would
     * have no valid hash and would break verification for every row after it —
     * so the model makes creating one that way impossible rather than merely
     * discouraged.
     */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'record_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException(
                'Audit entries cannot be edited. The trail is append-only — a correction is a '
                .'new entry, never a rewrite of an old one.'
            );
        });

        static::deleting(function (): void {
            throw new RuntimeException(
                'Audit entries cannot be deleted. Removing one breaks the hash chain for every '
                .'entry after it, which is exactly what the chain exists to make visible.'
            );
        });
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<User, $this> */
    public function causer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'causer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    // ── The chain ────────────────────────────────────────────────────────────

    /**
     * The canonical string this row's hash is taken over.
     *
     * Every field that carries meaning, in a fixed order, separated by a
     * character that cannot appear in any of them. Order and separator both
     * matter: `causer 12 | count 3` and `causer 123 | count ''` must not
     * produce the same string, or two different events could share a hash.
     */
    public function canonicalPayload(): string
    {
        return implode("\x1f", [
            (string) $this->ulid,
            (string) $this->event,
            (string) $this->category,
            (string) $this->severity,
            (string) $this->description,
            (string) $this->subject_type,
            (string) $this->subject_id,
            (string) $this->causer_id,
            (string) $this->causer_label,
            (string) $this->impersonator_id,
            json_encode($this->context ?? [], JSON_THROW_ON_ERROR),
            (string) $this->record_count,
            (string) $this->ip_address,
            $this->occurred_at?->toIso8601String() ?? '',
            (string) $this->previous_hash,
        ]);
    }

    public function computeHash(): string
    {
        return hash('sha256', $this->canonicalPayload());
    }

    /** Whether this row's stored hash still matches its content. */
    public function hashIsIntact(): bool
    {
        return hash_equals((string) $this->hash, $this->computeHash());
    }

    /**
     * The head of the chain — the most recent entry.
     *
     * Ordered by `id`, not by `occurred_at`: a backdated entry (a webhook
     * processed late, a queued job) must still chain in insertion order, or the
     * chain would depend on clock skew.
     */
    public static function head(): ?self
    {
        return static::query()->orderByDesc('id')->first();
    }

    // ── Reading ──────────────────────────────────────────────────────────────

    public function isCritical(): bool
    {
        return $this->severity === self::SEVERITY_CRITICAL;
    }

    /**
     * Whether this entry represents somebody acting as somebody else.
     *
     * Worth surfacing prominently. "Ama did this" and "Kofi did this while
     * impersonating Ama" are different facts, and only one of them is fair
     * to Ama.
     */
    public function wasImpersonated(): bool
    {
        return $this->impersonator_id !== null;
    }

    /** The one-line summary an admin screen or an export shows. */
    public function summary(): string
    {
        $actor = $this->causer_label ?? 'System';

        if ($this->wasImpersonated()) {
            $actor .= ' (impersonated by '.($this->impersonator?->name ?? 'an administrator').')';
        }

        return sprintf(
            '%s · %s · %s',
            $this->occurred_at?->format('j M Y H:i') ?? '',
            $actor,
            $this->description,
        );
    }

    #[Scope]
    protected function critical(Builder $query): void
    {
        $query->where('severity', self::SEVERITY_CRITICAL);
    }

    #[Scope]
    protected function ofCategory(Builder $query, string $category): void
    {
        $query->where('category', $category);
    }

    /**
     * Everything that took personal data out of the application.
     *
     * The report somebody asks for after an incident, and the one worth
     * reviewing monthly before there is one.
     */
    #[Scope]
    protected function dataMovements(Builder $query): void
    {
        $query->whereIn('category', ['data_export', 'data_access'])
            ->orderByDesc('occurred_at');
    }
}
