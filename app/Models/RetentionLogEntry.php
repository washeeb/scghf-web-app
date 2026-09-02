<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One entry in the retention audit log.
 *
 * Append-only, and it outlives the data it describes. It holds no personal
 * data itself, only a one-way digest, so it can be kept far longer than the
 * records it accounts for.
 */
class RetentionLogEntry extends Model
{
    protected $table = 'retention_log';

    public const UPDATED_AT = null;

    protected $fillable = [
        'retention_class', 'subject_type', 'subject_id', 'subject_digest',
        'action', 'detail', 'legal_hold_id', 'run_id', 'performed_by', 'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // A deletion log an administrator can edit proves nothing. Blocking
        // this in the model is what makes the log evidence rather than notes.
        static::updating(function (): void {
            throw new RuntimeException('The retention log is append-only and cannot be edited.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('The retention log is append-only and cannot be deleted.');
        });
    }

    /** @return BelongsTo<LegalHold, $this> */
    public function legalHold(): BelongsTo
    {
        return $this->belongsTo(LegalHold::class);
    }

    /** @return BelongsTo<User, $this> */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function wasDestructive(): bool
    {
        return in_array($this->action, ['delete', 'de_identify'], true);
    }

    #[Scope]
    protected function forRun(Builder $query, string $runId): void
    {
        $query->where('run_id', $runId)->orderBy('id');
    }

    #[Scope]
    protected function destructive(Builder $query): void
    {
        $query->whereIn('action', ['delete', 'de_identify']);
    }
}
