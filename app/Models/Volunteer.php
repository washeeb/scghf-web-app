<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Retainable;
use App\Models\Concerns\BelongsToDivision;
use App\Models\Concerns\DeIdentifiable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Somebody currently helping.
 *
 * Two things this model does that a plain roster would not:
 *
 * **Clearances go stale.** `is_cleared` is recomputed from the application's
 * checks, never trusted as a stored flag, because a police certificate is a
 * statement about a point in time. A volunteer whose clearance lapsed in March
 * is not cleared in April, whether or not anybody ran a report.
 *
 * **A concern suspends immediately.** Before any investigation and without
 * implying a finding. That order of events is what any safeguarding policy
 * worth having insists on, and putting it in `raiseConcern()` means it happens
 * whether or not the person raising it remembers to do anything else.
 */
class Volunteer extends Model implements Retainable
{
    use BelongsToDivision;
    use DeIdentifiable;
    use HasFactory;
    use HasUlids;
    use LogsActivity;
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_LEFT = 'left';

    protected $fillable = [
        'volunteer_application_id', 'user_id', 'division_id',
        'full_name', 'email', 'phone', 'role', 'status', 'started_on',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'is_cleared' => false,
        'involves_vulnerable_contact' => true,
        'total_hours' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_cleared' => 'boolean',
            'involves_vulnerable_contact' => 'boolean',
            'started_on' => 'date',
            'ended_on' => 'date',
            'clearance_expires_on' => 'date',
            'concern_raised_at' => 'datetime',
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

    // ── Relationships ────────────────────────────────────────────────────────

    /** @return BelongsTo<VolunteerApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(VolunteerApplication::class, 'volunteer_application_id');
    }

    /** @return HasMany<VolunteerHour, $this> */
    public function hours(): HasMany
    {
        return $this->hasMany(VolunteerHour::class);
    }

    // ── Clearance ────────────────────────────────────────────────────────────

    /**
     * Whether this volunteer may currently work unsupervised.
     *
     * Computed, not read from the column. The column is a cache for list
     * screens; this is the answer.
     */
    public function isCurrentlyCleared(?\DateTimeInterface $on = null): bool
    {
        if (! $this->involves_vulnerable_contact) {
            return true;
        }

        $application = $this->application;

        if ($application === null) {
            // No application means no evidence of any check. For a role with
            // vulnerable contact that is a no, not an unknown.
            return false;
        }

        return $application->load('checks')->isSafeguardingComplete();
    }

    /** Refresh the cached flag from the checks behind it. */
    public function refreshClearance(): bool
    {
        $cleared = $this->isCurrentlyCleared();

        $clearance = $this->application?->checks->firstWhere('check_type', 'police_clearance');

        static::whereKey($this->getKey())->update([
            'is_cleared' => $cleared,
            'clearance_expires_on' => $clearance?->expires_on?->toDateString(),
            'updated_at' => now(),
        ]);

        $this->refresh();

        return $cleared;
    }

    public function clearanceHasLapsed(): bool
    {
        return $this->involves_vulnerable_contact
            && $this->clearance_expires_on !== null
            && $this->clearance_expires_on->endOfDay()->isPast();
    }

    // ── Safeguarding concerns ────────────────────────────────────────────────

    /**
     * Raise a concern, suspending the volunteer at once.
     *
     * Suspension here is a PRECAUTION, not a finding and not a punishment —
     * and it happens before anybody investigates anything. Reversing that
     * order, however reasonable it feels in the moment, is the failure every
     * safeguarding inquiry describes.
     */
    public function raiseConcern(User $by, string $note): void
    {
        if (blank($note)) {
            throw new RuntimeException(
                'A safeguarding concern needs to be written down. What was noticed, and when, '
                .'is the only thing anybody will have to work from later.'
            );
        }

        $suspend = (bool) config('compliance.safeguarding.suspend_on_concern', true);

        $this->forceFill([
            'concern_raised_at' => now(),
            'concern_note' => $note,
            'concern_raised_by' => $by->getKey(),
            'status' => $suspend ? self::STATUS_SUSPENDED : $this->status,
        ])->save();

        Log::warning('Safeguarding concern raised about a volunteer.', [
            'volunteer' => $this->ulid,
            'suspended' => $suspend,
        ]);
    }

    public function hasOpenConcern(): bool
    {
        return $this->concern_raised_at !== null;
    }

    /**
     * Close a concern and reinstate.
     *
     * Requires a written outcome. A concern that simply stops being mentioned
     * is the worst possible record of one.
     */
    public function resolveConcern(User $by, string $outcome): void
    {
        if (blank($outcome)) {
            throw new RuntimeException(
                'Closing a safeguarding concern needs a written outcome. A concern that simply '
                .'stops being mentioned is the worst possible record of one.'
            );
        }

        $this->forceFill([
            'concern_raised_at' => null,
            'concern_note' => trim((string) $this->concern_note."\n\nResolved: ".$outcome),
            'status' => self::STATUS_ACTIVE,
        ])->save();
    }

    public function leave(string $reason = ''): void
    {
        $this->forceFill([
            'status' => self::STATUS_LEFT,
            'ended_on' => now()->toDateString(),
            'leaving_reason' => $reason !== '' ? $reason : null,
        ])->save();
    }

    /** Whether this person may be rostered right now. */
    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && ! $this->hasOpenConcern()
            && $this->isCurrentlyCleared();
    }

    // ── Hours ────────────────────────────────────────────────────────────────

    /**
     * Recompute from verified hours only.
     *
     * A figure a funder is shown must have rows behind it that somebody
     * checked. Unverified entries are kept but do not count.
     */
    public function refreshTotalHours(): int
    {
        $minutes = (int) $this->hours()->whereNotNull('verified_at')->sum('minutes');
        $hours = intdiv($minutes, 60);

        static::whereKey($this->getKey())->update(['total_hours' => $hours]);

        $this->refresh();

        return $hours;
    }

    // ── Retention ────────────────────────────────────────────────────────────

    public function retentionClass(): string
    {
        return 'volunteer_record';
    }

    public function retentionAnchorDate(): ?Carbon
    {
        // Null while they are still volunteering: the clock starts when they
        // leave, and a serving volunteer is never swept up.
        return $this->ended_on;
    }

    public function retentionScopeKey(): ?string
    {
        return $this->division_id === null ? null : 'division:'.$this->division_id;
    }

    /** @return array<string, string> */
    public static function privacyElements(): array
    {
        return [
            'full_name' => 'name',
            'email' => 'email',
            'phone' => 'phone',
            'role' => 'programme',
            'leaving_reason' => 'case_notes',
            'concern_note' => 'case_notes',
        ];
    }

    /** @return array<int, string> */
    public static function privacyExempt(): array
    {
        return [
            'id', 'ulid', 'created_at', 'updated_at', 'deleted_at',
            'volunteer_application_id', 'user_id', 'division_id', 'status',
            'is_cleared', 'clearance_expires_on', 'involves_vulnerable_contact',
            'started_on', 'ended_on', 'total_hours',
            'concern_raised_at', 'concern_raised_by',
        ];
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    /** Serving volunteers whose clearance has lapsed or is about to. */
    #[Scope]
    protected function clearanceLapsing(Builder $query, int $days = 60): void
    {
        $query->where('status', self::STATUS_ACTIVE)
            ->where('involves_vulnerable_contact', true)
            ->whereNotNull('clearance_expires_on')
            ->whereDate('clearance_expires_on', '<=', now()->addDays($days));
    }

    #[Scope]
    protected function withOpenConcern(Builder $query): void
    {
        $query->whereNotNull('concern_raised_at');
    }

    #[Scope]
    protected function retentionCandidates(Builder $query): void
    {
        $query->whereNotNull('ended_on');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'is_cleared', 'clearance_expires_on', 'ended_on', 'concern_raised_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('volunteer');
    }
}
