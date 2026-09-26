<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something the funder is owed by a date: a narrative report, an audited
 * statement, a receipt for the tranche, a site visit. The deadline that
 * loses the next grant if it is missed, which is why the scheduler
 * reminds the grant's owner before it falls due.
 *
 * @property Carbon $due_on
 * @property Carbon|null $completed_on
 * @property Carbon|null $reminded_at
 */
class GrantObligation extends Model
{
    public const KINDS = [
        'report' => 'Report',
        'audit' => 'Audited statement',
        'receipt' => 'Receipt or acknowledgement',
        'visit' => 'Visit or meeting',
        'other' => 'Other',
    ];

    protected $fillable = ['grant_id', 'title', 'kind', 'due_on', 'completed_on', 'completed_by', 'notes'];

    /** @var array<string, mixed> */
    protected $attributes = ['kind' => 'report'];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'completed_on' => 'date',
            'reminded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Grant, $this> */
    public function grant(): BelongsTo
    {
        return $this->belongsTo(Grant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function complete(?User $by = null): void
    {
        $this->forceFill(['completed_on' => now()->toDateString(), 'completed_by' => $by?->getKey()])->save();
    }

    public function isOverdue(): bool
    {
        return $this->completed_on === null && $this->due_on->isPast() && ! $this->due_on->isToday();
    }

    /** Not done, due within the window, and not reminded in the last week. */
    #[Scope]
    protected function needingReminder(Builder $query, int $days = 14): void
    {
        $query->whereNull('completed_on')
            ->whereDate('due_on', '<=', now()->addDays($days))
            ->where(fn (Builder $q) => $q->whereNull('reminded_at')->orWhere('reminded_at', '<', now()->subDays(7)));
    }
}
