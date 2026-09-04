<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * A distinct fault, with a count — not a log line.
 *
 * Ten thousand copies of the same undefined-index is one problem. Stored one
 * row per occurrence it is also a full disk, on a shared plan that counts
 * inodes as well as bytes. So the fingerprint is unique and a recurrence is an
 * increment.
 *
 * ── What is deliberately not captured ───────────────────────────────────────
 *
 * The request body. Ever.
 *
 * PCI DSS SAQ-A posture rests on this application never touching card data, and
 * an error reporter that helpfully grabbed the POST body would quietly make
 * that untrue — as would one that captured a beneficiary's narrative from a
 * form submission that failed validation. Route parameters and a few flags are
 * enough to reproduce a fault; the payload is not needed and is not worth the
 * risk of holding.
 */
class ErrorReport extends Model
{
    use HasFactory;
    use HasUlids;

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_ERROR = 'error';

    public const SEVERITY_CRITICAL = 'critical';

    protected $fillable = [
        'fingerprint', 'exception_class', 'message', 'file', 'line', 'trace',
        'route', 'method', 'severity', 'context',
        'first_seen_at', 'last_seen_at', 'affected_visitor',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'severity' => self::SEVERITY_ERROR,
        'occurrences' => 1,
        'affected_users' => 0,
        'affected_visitor' => false,
        'is_muted' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'line' => 'integer',
            'occurrences' => 'integer',
            'affected_users' => 'integer',
            'affected_visitor' => 'boolean',
            'is_muted' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
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
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * What makes two occurrences the same fault.
     *
     * Class, file and line, plus the message with its variable parts removed —
     * numbers, quoted strings, hex ids. Without that normalisation
     * "No query results for model [Donation] 41" and the same for 42 would be
     * two problems, and a failing page would produce a new row per visitor.
     */
    public static function fingerprintFor(Throwable $e): string
    {
        $message = preg_replace(
            ['/\d+/', '/"[^"]*"/', "/'[^']*'/", '/0x[0-9a-f]+/i'],
            ['#', '"…"', "'…'", '0x…'],
            $e->getMessage(),
        );

        return hash('sha256', implode("\x1f", [
            $e::class,
            $e->getFile(),
            (string) $e->getLine(),
            (string) $message,
        ]));
    }

    /**
     * Record an occurrence, creating the group if this is the first.
     *
     * The upsert is a raw increment rather than read-modify-write: a fault on a
     * busy page produces many simultaneous occurrences, and a read-then-save
     * would lose most of them and could deadlock.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function recordOccurrence(string $fingerprint, array $attributes): self
    {
        $existing = static::query()->where('fingerprint', $fingerprint)->first();

        if ($existing !== null) {
            static::whereKey($existing->getKey())->update([
                'occurrences' => DB::raw('occurrences + 1'),
                'last_seen_at' => now(),
                // A fault that recurs after being marked resolved was not
                // resolved. Reopening it is more honest than leaving a green
                // tick on something that is still happening.
                'resolved_at' => null,
                'updated_at' => now(),
            ]);

            return $existing->refresh();
        }

        return static::create([
            ...$attributes,
            'fingerprint' => $fingerprint,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    public function noteAffectedUser(): void
    {
        static::whereKey($this->getKey())->update([
            'affected_users' => DB::raw('affected_users + 1'),
            'updated_at' => now(),
        ]);
    }

    public function resolve(User $actor, string $note = ''): void
    {
        $this->forceFill([
            'resolved_at' => now(),
            'resolved_by' => $actor->getKey(),
            'resolution_note' => $note !== '' ? $note : null,
        ])->save();
    }

    /**
     * Stop showing this one without claiming it is fixed.
     *
     * For known third-party noise that will not be fixed. Distinct from
     * resolved, because "we decided to ignore this" and "we fixed this" are
     * different statements and only one of them should be reversible without
     * comment.
     */
    public function mute(): void
    {
        $this->forceFill(['is_muted' => true])->save();
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /** A one-line summary for a list screen. */
    public function summary(): string
    {
        return sprintf(
            '%s · %s · %d occurrence%s since %s',
            class_basename($this->exception_class),
            Str::limit((string) $this->message, 80),
            $this->occurrences,
            $this->occurrences === 1 ? '' : 's',
            $this->first_seen_at?->format('j M') ?? '',
        );
    }

    /**
     * Trim the disk back when the table has grown past its ceiling.
     *
     * Resolved groups go first, then muted, then the oldest untouched ones.
     * A safety valve for the shared-hosting quota, not a retention policy —
     * which is why it prefers to delete the things somebody has already dealt
     * with.
     */
    public static function pruneToCeiling(): int
    {
        $max = (int) config('system.errors.max_groups', 500);
        $count = static::query()->count();

        if ($count <= $max) {
            return 0;
        }

        $ids = static::query()
            ->orderByRaw('CASE WHEN resolved_at IS NOT NULL THEN 0 WHEN is_muted = 1 THEN 1 ELSE 2 END')
            ->orderBy('last_seen_at')
            ->limit($count - $max)
            ->pluck('id');

        return static::query()->whereIn('id', $ids)->delete();
    }

    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull('resolved_at')->where('is_muted', false);
    }

    #[Scope]
    protected function critical(Builder $query): void
    {
        $query->where('severity', self::SEVERITY_CRITICAL);
    }
}
