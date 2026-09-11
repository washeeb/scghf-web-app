<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Retainable;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Somebody offering to help.
 *
 * **`approve()` is the important method in this class, and it refuses.** A role
 * involving unsupervised contact with children or other vulnerable people
 * cannot be approved until every check in
 * `config('compliance.safeguarding.required_checks')` is recorded and current.
 *
 * That refusal is the whole point of building this in code rather than writing
 * it in a policy. Two of the foundation's four divisions exist to work with
 * orphans and widows; a well-meaning administrator approving a keen volunteer
 * "and doing the police check next week" is not a hypothetical failure, it is
 * the ordinary one.
 */
class VolunteerApplication extends Model implements Retainable
{
    use DeIdentifiable;
    use HasFactory;
    use HasUlids;
    use LogsActivity;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'volunteer_opportunity_id', 'user_id', 'status',
        'full_name', 'email', 'phone', 'date_of_birth', 'address', 'region',
        'occupation', 'motivation', 'experience', 'availability',
        'next_of_kin_name', 'next_of_kin_phone', 'cv_media_id',
        'declaration_agreed', 'declaration_text', 'declaration_ip',
        'disclosed_convictions', 'assessor_notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'declaration_agreed' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'declaration_agreed' => 'boolean',
            'declaration_at' => 'datetime',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $application): void {
            $application->reference ??= 'SCGHF-V-'.Str::upper(substr(Str::ulid()->toBase32(), -10));
            $application->last_activity_at ??= now();
        });

        static::updating(function (self $application): void {
            $application->last_activity_at = now();
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

    // ── Relationships ────────────────────────────────────────────────────────

    /** @return BelongsTo<VolunteerOpportunity, $this> */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(VolunteerOpportunity::class, 'volunteer_opportunity_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    /** @return HasMany<SafeguardingCheck, $this> */
    public function checks(): HasMany
    {
        return $this->hasMany(SafeguardingCheck::class);
    }

    /** @return BelongsTo<Media, $this> */
    public function cv(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cv_media_id');
    }

    // ── Safeguarding ─────────────────────────────────────────────────────────

    /**
     * The checks this application must satisfy.
     *
     * Driven by the ROLE, not by the applicant. A role flagged as involving
     * vulnerable contact gets the full set; anything else gets the basic one.
     * An opportunity with no record at all is treated as involving contact,
     * because for this foundation that is the safe assumption.
     *
     * @return array<int, string>
     */
    public function requiredChecks(): array
    {
        $involvesContact = $this->opportunity?->involves_vulnerable_contact ?? true;

        if ($involvesContact) {
            // A keyed map of check type => label and description.
            return array_keys((array) config('compliance.safeguarding.required_checks', []));
        }

        // A flat list of the few that apply to a role with no contact.
        return array_values((array) config('compliance.safeguarding.basic_checks', []));
    }

    /**
     * Checks that are required but not currently satisfied.
     *
     * Returns the LIST, not a boolean, so an administrator is told exactly what
     * is outstanding. "Cannot approve" with no explanation is how a requirement
     * gets worked around instead of met.
     *
     * @return array<int, string>
     */
    public function outstandingChecks(): array
    {
        $satisfied = $this->checks
            ->filter(fn (SafeguardingCheck $check): bool => $check->isSatisfied())
            ->pluck('check_type')
            ->all();

        return array_values(array_diff($this->requiredChecks(), $satisfied));
    }

    public function isSafeguardingComplete(): bool
    {
        return $this->outstandingChecks() === [];
    }

    /** Whether any check has been recorded as failed. */
    public function hasFailedCheck(): bool
    {
        return $this->checks->contains('outcome', SafeguardingCheck::OUTCOME_FAILED);
    }

    /** Create the pending check rows this application needs. */
    public function openRequiredChecks(): void
    {
        foreach ($this->requiredChecks() as $type) {
            SafeguardingCheck::firstOrCreate([
                'volunteer_application_id' => $this->getKey(),
                'check_type' => $type,
            ]);
        }

        $this->load('checks');
    }

    // ── Lifecycle ────────────────────────────────────────────────────────────

    public function submit(): void
    {
        if (! $this->declaration_agreed) {
            throw new RuntimeException(
                'The safeguarding declaration must be agreed before an application can be '
                .'submitted. It is what the applicant is later held to.'
            );
        }

        $this->forceFill([
            'status' => self::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'declaration_at' => $this->declaration_at ?? now(),
            'last_activity_at' => now(),
        ])->save();

        $this->openRequiredChecks();
    }

    /**
     * Approve, and create the volunteer record.
     *
     * **Refuses** while any required check is outstanding or any has failed.
     * The error names what is missing, so the answer is to go and do it rather
     * than to look for a way round.
     */
    public function approve(User $by, string $role = ''): Volunteer
    {
        $this->load('checks', 'opportunity');

        if ($this->hasFailedCheck()) {
            throw new RuntimeException(sprintf(
                'Application %s cannot be approved: a safeguarding check was recorded as '
                .'FAILED. That decision has to be revisited explicitly, not overridden here.',
                $this->reference,
            ));
        }

        $outstanding = $this->outstandingChecks();

        if ($outstanding !== []) {
            throw new RuntimeException(sprintf(
                'Application %s cannot be approved. Outstanding safeguarding checks: %s. '
                .'This role involves contact with vulnerable people, and every check must be '
                .'recorded and current before anybody starts.',
                $this->reference,
                implode(', ', array_map(
                    fn (string $type): string => (string) config(
                        "compliance.safeguarding.required_checks.{$type}.label",
                        $type,
                    ),
                    $outstanding,
                )),
            ));
        }

        return DB::transaction(function () use ($by, $role): Volunteer {
            $this->forceFill([
                'status' => self::STATUS_APPROVED,
                'decided_at' => now(),
                'last_activity_at' => now(),
                'assessed_by' => $by->getKey(),
            ])->save();

            $clearance = $this->checks->firstWhere('check_type', 'police_clearance');

            $volunteer = Volunteer::create([
                'volunteer_application_id' => $this->getKey(),
                'user_id' => $this->user_id,
                'division_id' => $this->opportunity?->division_id,
                'full_name' => $this->full_name,
                'email' => $this->email,
                'phone' => $this->phone,
                'role' => $role !== '' ? $role : $this->opportunity?->title,
                'started_on' => now()->toDateString(),
            ]);

            $volunteer->forceFill([
                'is_cleared' => true,
                'clearance_expires_on' => $clearance?->expires_on?->toDateString(),
                'involves_vulnerable_contact' => $this->opportunity?->involves_vulnerable_contact ?? true,
            ])->save();

            $this->opportunity?->increment('positions_filled');

            return $volunteer->refresh();
        });
    }

    public function decline(User $by, string $reason): void
    {
        $this->forceFill([
            'status' => self::STATUS_DECLINED,
            'decided_at' => now(),
            'last_activity_at' => now(),
            'decline_reason' => $reason,
            'assessed_by' => $by->getKey(),
        ])->save();
    }

    public function withdraw(): void
    {
        $this->forceFill([
            'status' => self::STATUS_WITHDRAWN,
            'last_activity_at' => now(),
        ])->save();
    }

    // ── Retention ────────────────────────────────────────────────────────────

    public function retentionClass(): string
    {
        return match ($this->status) {
            self::STATUS_DECLINED => 'volunteer_application_declined',
            self::STATUS_APPROVED => 'volunteer_record',
            default => 'volunteer_application_withdrawn',
        };
    }

    public function retentionAnchorDate(): ?Carbon
    {
        return match ($this->retentionClass()) {
            'volunteer_application_declined' => $this->decided_at,
            // An approved application's life is governed by the volunteer
            // record it produced, which is anchored on when they left. While
            // they are still volunteering there is no anchor at all.
            'volunteer_record' => $this->volunteerEndedAt(),
            default => $this->last_activity_at,
        };
    }

    public function retentionScopeKey(): ?string
    {
        return $this->volunteer_opportunity_id === null
            ? null
            : 'opportunity:'.$this->volunteer_opportunity_id;
    }

    private function volunteerEndedAt(): ?Carbon
    {
        $ended = Volunteer::where('volunteer_application_id', $this->getKey())->value('ended_on');

        return $ended === null ? null : Carbon::parse($ended);
    }

    /** @return array<string, string> */
    public static function privacyElements(): array
    {
        return [
            'reference' => 'case_reference',
            'full_name' => 'name',
            'email' => 'email',
            'phone' => 'phone',
            'date_of_birth' => 'date_of_birth',
            'address' => 'address',
            'occupation' => 'school_employer',
            'motivation' => 'narrative',
            'experience' => 'narrative',
            'next_of_kin_name' => 'next_of_kin',
            'next_of_kin_phone' => 'next_of_kin',
            'cv_media_id' => 'supporting_document',
            'declaration_text' => 'narrative',
            'declaration_ip' => 'device',
            // A disclosed conviction is among the most sensitive things anybody
            // tells this foundation about themselves.
            'disclosed_convictions' => 'medical',
            'assessor_notes' => 'case_notes',
            'region' => 'region',
            'availability' => 'programme',
        ];
    }

    /** @return array<int, string> */
    public static function privacyExempt(): array
    {
        return [
            'id', 'ulid', 'created_at', 'updated_at', 'deleted_at',
            'status', 'volunteer_opportunity_id', 'user_id',
            'declaration_agreed', 'declaration_at',
            'submitted_at', 'decided_at', 'last_activity_at', 'decline_reason',
            'assessed_by',
        ];
    }

    #[Scope]
    protected function awaitingDecision(Builder $query): void
    {
        $query->whereIn('status', [self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW]);
    }

    #[Scope]
    protected function retentionCandidates(Builder $query): void
    {
        $query->whereIn('status', [
            self::STATUS_DECLINED, self::STATUS_WITHDRAWN,
            self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW, self::STATUS_APPROVED,
        ]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        /*
         * Narrow on purpose. The log records the application moving through its
         * states, never the personal data — an activity log holding a copy of a
         * disclosed conviction would outlive the retention run that destroys the
         * application, which defeats the exercise.
         */
        return LogOptions::defaults()
            ->logOnly(['status', 'decided_at', 'assessed_by', 'volunteer_opportunity_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('volunteer_application');
    }
}
