<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

/**
 * Time given.
 *
 * Stored in MINUTES, not fractional hours. "2.5 hours" as a float is how a
 * total reported to a funder ends up at 3,399.9999999 — the same reason money
 * is integer pesewas everywhere else in this application.
 *
 * Only VERIFIED hours count towards a volunteer's total. An unverified entry is
 * kept, because somebody genuinely worked it and deleting it would be worse,
 * but a figure shown to a funder needs rows behind it that somebody checked.
 */
class VolunteerHour extends Model
{
    use HasFactory;

    protected $fillable = [
        'volunteer_id', 'project_id', 'volunteer_opportunity_id',
        'worked_on', 'minutes', 'activity', 'notes', 'recorded_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'worked_on' => 'date',
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $entry): void {
            if ($entry->minutes < 1) {
                throw new RuntimeException('An hours entry must record at least one minute.');
            }

            /*
             * A day has 1,440 minutes. An entry claiming more is a typo — most
             * often a start and end time entered as minutes — and letting it
             * through inflates a figure the foundation reports to a funder.
             */
            if ($entry->minutes > 1440) {
                throw new RuntimeException(
                    'An hours entry cannot exceed 24 hours in a day. Split it across the days '
                    .'actually worked.'
                );
            }

            if ($entry->worked_on !== null && $entry->worked_on->isFuture()) {
                throw new RuntimeException('Hours cannot be recorded for a day that has not happened.');
            }
        });
    }

    /** @return HasOne<VolunteerShift, $this> */
    public function shift(): HasOne
    {
        return $this->hasOne(VolunteerShift::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<User, $this> */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** @return BelongsTo<Volunteer, $this> */
    public function volunteer(): BelongsTo
    {
        return $this->belongsTo(Volunteer::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function hours(): float
    {
        return round($this->minutes / 60, 2);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Verify the entry.
     *
     * Deliberately not self-verifiable: the person who worked the hours is not
     * the person who confirms them, for the same reason a refund needs a second
     * approver.
     */
    public function verify(User $by): void
    {
        if ($this->recorded_by !== null && $this->recorded_by === $by->getKey()) {
            throw new RuntimeException(
                'Hours cannot be verified by the person who recorded them. Ask a supervisor.'
            );
        }

        $this->forceFill([
            'verified_at' => now(),
            'verified_by' => $by->getKey(),
        ])->save();

        $this->volunteer?->refreshTotalHours();
    }

    #[Scope]
    protected function verified(Builder $query): void
    {
        $query->whereNotNull('verified_at');
    }

    #[Scope]
    protected function awaitingVerification(Builder $query): void
    {
        $query->whereNull('verified_at');
    }
}
