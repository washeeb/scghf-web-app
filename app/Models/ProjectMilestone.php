<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A checkpoint on a project.
 *
 * `is_public` exists because not every milestone should be published. Internal
 * ones — a grant report due, an audit — are tracked here alongside the public
 * ones rather than in somebody's spreadsheet, and simply do not appear on the
 * project page.
 */
class ProjectMilestone extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_ACHIEVED = 'achieved';

    public const STATUS_MISSED = 'missed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'project_id', 'title', 'description', 'status',
        'due_on', 'achieved_on', 'sort_order', 'is_public',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'sort_order' => 0,
        'is_public' => true,
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'achieved_on' => 'date',
            'is_public' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $milestone): void {
            // Achieving a milestone without recording when is how a project
            // timeline becomes unreadable a year later.
            if ($milestone->status === self::STATUS_ACHIEVED && $milestone->achieved_on === null) {
                $milestone->achieved_on = now()->toDateString();
            }
        });
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Past its due date and not yet achieved.
     *
     * A cancelled milestone is never overdue — it was called off, which is a
     * decision, not a failure to deliver.
     */
    public function isOverdue(): bool
    {
        if ($this->due_on === null) {
            return false;
        }

        if (in_array($this->status, [self::STATUS_ACHIEVED, self::STATUS_CANCELLED], true)) {
            return false;
        }

        return $this->due_on->isPast();
    }

    /** Named `visible`, not `public` — a reserved word makes a poor scope name. */
    #[Scope]
    protected function visible(Builder $query): void
    {
        $query->where('is_public', true);
    }
}
