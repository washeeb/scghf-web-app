<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Contracts\Retainable;
use App\Models\Concerns\BelongsToDivision;
use App\Models\Concerns\DeIdentifiable;
use App\Models\Concerns\HasConsents;
use App\Models\Concerns\RecordsAuthor;
use App\Support\Anonymiser;
use App\Support\RetentionRunner;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A person the foundation has helped, or been asked to help.
 *
 * The operational case record: identifiable, restricted, and destroyed when its
 * retention period expires. What survives is the anonymous
 * `BeneficiaryImpactRecord` projected when the case closed, which carries no
 * reversible link back here once this row is gone.
 *
 * ── Closure is not destruction ──────────────────────────────────────────────
 *
 * `close()` starts the retention clock and projects the analytics record. It
 * destroys nothing. The record stays lawfully identifiable for the whole
 * retention period — six years for an approved case — and is acted on only once
 * `closed_at + 72 months + grace` has passed, and only if no legal hold covers
 * it.
 *
 * @see RetentionRunner
 * @see Anonymiser
 */
class Beneficiary extends Model implements Retainable
{
    use BelongsToDivision;
    use DeIdentifiable;
    use HasConsents;
    use HasFactory;
    use HasUlids;
    use LogsActivity;
    use RecordsAuthor;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'case_reference', 'division_id', 'project_id', 'focus_area_id', 'status',
        'full_name', 'other_names', 'phone', 'email', 'ghana_card_number',
        'date_of_birth', 'address', 'community', 'latitude', 'longitude',
        'bank_account', 'momo_number', 'next_of_kin_name', 'next_of_kin_phone',
        'household_details', 'school_or_employer', 'religion', 'medical_notes',
        'application_narrative', 'case_notes', 'photo_id', 'signature_id',
        'id_document_id', 'intake_ip', 'gender', 'region', 'district',
        'assistance', 'currency', 'assisted_on', 'outcome',
        'case_worker_id', 'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'currency' => 'GHS',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'assisted_on' => 'date',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'closed_at' => 'datetime',
            'assistance' => MoneyCast::class.':assistance_minor,currency',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $beneficiary): void {
            $beneficiary->case_reference ??= self::generateReference();
            $beneficiary->last_activity_at ??= now();
        });

        static::updating(function (self $beneficiary): void {
            // The anchor for a withdrawn or incomplete application. Touched on
            // every change so an application somebody is still working on is
            // never swept up as abandoned.
            $beneficiary->last_activity_at = now();
        });
    }

    /**
     * A case reference quotable over the phone.
     *
     * Crockford base32 from a ULID, so it has no I/1 or O/0 to confuse when an
     * applicant reads it back — which they will, because this is the number
     * they are asked for when they call.
     *
     * Ten characters of the ULID's random tail rather than all twenty-six: the
     * column is VARCHAR(32) by convention, and 32^10 is roughly 10^15, which is
     * ample. The unique index is the backstop, and the retry below turns the
     * one-in-a-quadrillion collision into a second attempt rather than a failed
     * application.
     */
    public static function generateReference(): string
    {
        foreach (range(1, 5) as $ignored) {
            $reference = 'SCGHF-B-'.Str::upper(substr(Str::ulid()->toBase32(), -10));

            if (! static::withTrashed()->where('case_reference', $reference)->exists()) {
                return $reference;
            }
        }

        throw new RuntimeException('Could not generate a unique case reference after five attempts.');
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

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<FocusArea, $this> */
    public function focusArea(): BelongsTo
    {
        return $this->belongsTo(FocusArea::class);
    }

    /** @return HasMany<BeneficiaryDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(BeneficiaryDocument::class);
    }

    /** @return HasMany<Story, $this> */
    public function stories(): HasMany
    {
        return $this->hasMany(Story::class);
    }

    /** @return HasOne<BeneficiaryImpactRecord, $this> */
    public function impactRecord(): HasOne
    {
        return $this->hasOne(BeneficiaryImpactRecord::class, 'source_beneficiary_id');
    }

    /** @return BelongsTo<Media, $this> */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'photo_id');
    }

    // ── Case lifecycle ───────────────────────────────────────────────────────

    public function decline(string $reason = ''): void
    {
        $this->forceFill([
            'status' => self::STATUS_DECLINED,
            'decided_at' => now(),
            'last_activity_at' => now(),
            'outcome' => 'declined',
            'case_notes' => trim($this->case_notes."\n".$reason),
        ])->save();
    }

    public function approve(): void
    {
        $this->forceFill([
            'status' => self::STATUS_APPROVED,
            'decided_at' => now(),
            'last_activity_at' => now(),
        ])->save();
    }

    public function withdraw(): void
    {
        $this->forceFill([
            'status' => self::STATUS_WITHDRAWN,
            'last_activity_at' => now(),
        ])->save();
    }

    /**
     * Close the case: start the retention clock, project the analytics record.
     *
     * **Destroys nothing.** The projection happens here, at closure, rather
     * than at retention expiry, so that impact reporting reads from the
     * anonymous dataset from day one and never has to touch the case database
     * at all. It also means the statistical record survives even if the case
     * record is later erased on request.
     */
    public function close(?string $outcome = null): BeneficiaryImpactRecord
    {
        $this->forceFill([
            'status' => self::STATUS_CLOSED,
            'closed_at' => $this->closed_at ?? now(),
            'last_activity_at' => now(),
            'outcome' => $outcome ?? $this->outcome,
        ])->save();

        $this->documents()->whereNull('closed_at')->update(['closed_at' => $this->closed_at]);

        return $this->projectImpactRecord();
    }

    /**
     * Create or refresh the anonymous analytics row for this case.
     *
     * Every value is generalised on the way in — a band, a period, a coarse
     * geography. Nothing exact crosses this boundary, which is what makes the
     * analytics table safe to keep indefinitely under the
     * `anonymised_statistics` retention class.
     */
    public function projectImpactRecord(): BeneficiaryImpactRecord
    {
        $anonymiser = app(Anonymiser::class);

        $record = $this->impactRecord ?? new BeneficiaryImpactRecord;

        $record->forceFill([
            'source_beneficiary_id' => $this->getKey(),
            'division_id' => $this->division_id,
            'project_id' => $this->project_id,
            'focus_area_id' => $this->focus_area_id,
            'region' => $this->region,
            // District is the finest geography permitted. The community is
            // never carried across — it is a destroy element.
            'district' => $this->district,
            'gender' => $this->gender,
            'age_band' => $anonymiser->ageBand($this->date_of_birth, $this->assisted_on),
            'assistance_band' => $anonymiser->amountBand($this->assistance),
            'assistance_period' => $anonymiser->period($this->assisted_on ?? $this->closed_at),
            'outcome' => $this->outcome,
        ])->save();

        $this->setRelation('impactRecord', $record);

        return $record;
    }

    // ── Retention ────────────────────────────────────────────────────────────

    public function retentionClass(): string
    {
        return match ($this->status) {
            self::STATUS_DECLINED => 'beneficiary_application_declined',
            self::STATUS_WITHDRAWN, self::STATUS_DRAFT, self::STATUS_SUBMITTED,
            self::STATUS_UNDER_REVIEW => 'beneficiary_application_withdrawn',
            default => 'beneficiary_case_record',
        };
    }

    public function retentionAnchorDate(): ?Carbon
    {
        return match ($this->retentionClass()) {
            'beneficiary_application_declined' => $this->decided_at,
            'beneficiary_application_withdrawn' => $this->last_activity_at,
            // An OPEN case has no closure date, and null means the clock has
            // not started. That is what stops a live case being swept up.
            default => $this->closed_at,
        };
    }

    public function retentionScopeKey(): ?string
    {
        // Lets a legal hold cover "every beneficiary record for project 12"
        // without enumerating them, which is how such instructions arrive.
        return $this->project_id === null ? null : 'project:'.$this->project_id;
    }

    /**
     * Destroy the identifiable case record.
     *
     * The dataset-level de-identification the policy describes: the anonymous
     * projection is guaranteed to exist, its link back here is severed by the
     * foreign key as this row goes, and the identifiable record is HARD
     * deleted.
     *
     * Not stripped in place. A husk row keeping the exact amount and exact date
     * beside a division and a district still singles out one person, and
     * turning the operational table into the statistics table is precisely the
     * mistake the two-dataset design exists to avoid.
     *
     * A soft delete would be worse still: the row would keep every identifier
     * and satisfy Act 843 not at all.
     */
    public function deIdentify(): void
    {
        // Guarantees the statistics survive the record. A case closed before
        // this method existed, or one whose projection failed, still gets one.
        $this->projectImpactRecord();

        $this->documents()->get()->each(fn (BeneficiaryDocument $doc) => $doc->forceDelete());

        // ON DELETE SET NULL on beneficiary_impact_records.source_beneficiary_id
        // severs the last linkage in the same statement.
        $this->forceDelete();
    }

    /**
     * Every column holding data about this person, and its privacy element.
     *
     * A test asserts this covers every column on the table. The failure mode it
     * guards is not a wrong decision about a field; it is a field added in two
     * years that nobody classified, which would then survive untouched.
     *
     * @return array<string, string>
     */
    public static function privacyElements(): array
    {
        return [
            'case_reference' => 'case_reference',
            'full_name' => 'name',
            'other_names' => 'name',
            'phone' => 'phone',
            'email' => 'email',
            'ghana_card_number' => 'national_id',
            'date_of_birth' => 'date_of_birth',
            'address' => 'address',
            'community' => 'community',
            'latitude' => 'geolocation',
            'longitude' => 'geolocation',
            'bank_account' => 'bank_details',
            'momo_number' => 'bank_details',
            'next_of_kin_name' => 'next_of_kin',
            'next_of_kin_phone' => 'next_of_kin',
            'household_details' => 'household',
            'school_or_employer' => 'school_employer',
            'religion' => 'religion',
            'medical_notes' => 'medical',
            'application_narrative' => 'narrative',
            'case_notes' => 'case_notes',
            'photo_id' => 'likeness',
            'signature_id' => 'signature',
            'id_document_id' => 'id_document',
            'intake_ip' => 'device',

            'gender' => 'gender',
            'region' => 'region',
            'district' => 'district',
            'assistance_minor' => 'assistance_amount',
            'assisted_on' => 'assistance_date',
            'outcome' => 'outcome',
            'focus_area_id' => 'programme',
            'project_id' => 'programme',
            'division_id' => 'division',
        ];
    }

    /**
     * Structural columns that hold no data about the person.
     *
     * Keys, the case lifecycle timestamps that drive the retention clock, and
     * the staff who handled it — a case worker's id is data about an employee,
     * governed by the staff record, not by this person's retention class.
     *
     * @return array<int, string>
     */
    public static function privacyExempt(): array
    {
        return [
            'id', 'ulid', 'created_at', 'updated_at', 'deleted_at',
            'status', 'currency',
            'submitted_at', 'decided_at', 'last_activity_at', 'closed_at',
            'case_worker_id', 'created_by',
        ];
    }

    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNotIn('status', [self::STATUS_CLOSED, self::STATUS_DECLINED, self::STATUS_WITHDRAWN]);
    }

    /**
     * Records the retention runner may consider.
     *
     * A draft that was never submitted has no anchor and is filtered out
     * downstream anyway, but excluding it here keeps the sweep small.
     */
    #[Scope]
    protected function retentionCandidates(Builder $query): void
    {
        $query->whereIn('status', [
            self::STATUS_CLOSED, self::STATUS_DECLINED, self::STATUS_WITHDRAWN,
            self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW,
        ]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        /*
         * Deliberately narrow. The activity log records the CASE moving through
         * its states, never the personal data — an activity log holding a copy
         * of a Ghana Card number would survive the retention run that destroys
         * the record, which would defeat the entire exercise.
         */
        return LogOptions::defaults()
            ->logOnly(['status', 'project_id', 'division_id', 'decided_at', 'closed_at', 'outcome'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('beneficiary');
    }

    /** Guards against a status typo silently becoming a 72-month retention. */
    public function assertStatusIsKnown(): void
    {
        $known = [
            self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW,
            self::STATUS_APPROVED, self::STATUS_DECLINED, self::STATUS_WITHDRAWN,
            self::STATUS_CLOSED,
        ];

        if (! in_array($this->status, $known, true)) {
            throw new RuntimeException("Unknown beneficiary status [{$this->status}].");
        }
    }
}
