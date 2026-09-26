<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A volunteer, a start, an end, a place.
 *
 * Completing a shift writes the hours row — once, whatever is pressed twice —
 * so the figure the foundation reports to a funder is made of shifts that
 * were scheduled and then confirmed, not of numbers typed from memory. A
 * shift can still be logged without one (`volunteers.log_hours`), because
 * not everything is planned.
 */
class VolunteerShift extends Model
{
    use HasFactory;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_MISSED = 'missed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'volunteer_id', 'volunteer_opportunity_id', 'project_id',
        'starts_at', 'ends_at', 'location', 'activity', 'notes', 'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::STATUS_SCHEDULED];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $shift): void {
            if ($shift->ends_at !== null && $shift->starts_at !== null && $shift->ends_at->lte($shift->starts_at)) {
                throw new RuntimeException('A shift has to end after it starts.');
            }

            if ($shift->starts_at !== null && $shift->ends_at !== null && $shift->starts_at->diffInMinutes($shift->ends_at) > 1440) {
                throw new RuntimeException('A shift cannot run longer than a day. Make it two.');
            }
        });
    }

    /** @return BelongsTo<Volunteer, $this> */
    public function volunteer(): BelongsTo
    {
        return $this->belongsTo(Volunteer::class);
    }

    /** @return BelongsTo<VolunteerOpportunity, $this> */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(VolunteerOpportunity::class, 'volunteer_opportunity_id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<VolunteerHour, $this> */
    public function hour(): BelongsTo
    {
        return $this->belongsTo(VolunteerHour::class, 'volunteer_hour_id');
    }

    public function minutes(): int
    {
        return (int) $this->starts_at->diffInMinutes($this->ends_at);
    }

    /**
     * The shift happened. Writes the hours entry as recorded by whoever
     * scheduled the shift and verified by whoever confirms it — a shift one
     * person planned and another confirmed is exactly what a verified entry
     * means. Where the same person did both, the entry waits for a second
     * person to verify it, as any hand-typed entry would.
     */
    public function complete(User $by, ?int $minutes = null): VolunteerHour
    {
        if ($this->status === self::STATUS_COMPLETED && $this->hour !== null) {
            return $this->hour;
        }

        if ($this->status === self::STATUS_CANCELLED) {
            throw new RuntimeException('A cancelled shift cannot be completed. Schedule it again.');
        }

        if ($this->starts_at->isFuture()) {
            throw new RuntimeException('This shift has not started yet.');
        }

        return DB::transaction(function () use ($by, $minutes): VolunteerHour {
            $hour = VolunteerHour::create([
                'volunteer_id' => $this->volunteer_id,
                'project_id' => $this->project_id,
                'volunteer_opportunity_id' => $this->volunteer_opportunity_id,
                'worked_on' => $this->starts_at->toDateString(),
                'minutes' => $minutes ?? $this->minutes(),
                'activity' => $this->activity,
                'notes' => $this->notes,
                'recorded_by' => $this->created_by ?? $by->getKey(),
            ]);

            if ($hour->recorded_by !== $by->getKey()) {
                $hour->verify($by);
            }

            $this->forceFill([
                'status' => self::STATUS_COMPLETED,
                'volunteer_hour_id' => $hour->getKey(),
            ])->save();

            $this->volunteer?->refreshTotalHours();

            return $hour;
        });
    }

    public function miss(): void
    {
        $this->forceFill(['status' => self::STATUS_MISSED])->save();
    }

    public function cancel(): void
    {
        if ($this->status === self::STATUS_COMPLETED) {
            throw new RuntimeException('A completed shift has hours against it; cancel the hours entry instead.');
        }

        $this->forceFill(['status' => self::STATUS_CANCELLED])->save();
    }

    /** Scheduled shifts starting on a given day that have not been reminded. */
    #[Scope]
    protected function needingReminderOn(Builder $query, DateTimeInterface $day): void
    {
        $query->where('status', self::STATUS_SCHEDULED)
            ->whereNull('reminder_sent_at')
            ->whereBetween('starts_at', [
                Carbon::instance($day)->startOfDay(),
                Carbon::instance($day)->endOfDay(),
            ]);
    }

    #[Scope]
    protected function upcoming(Builder $query): void
    {
        $query->where('status', self::STATUS_SCHEDULED)->where('starts_at', '>=', now());
    }
}
