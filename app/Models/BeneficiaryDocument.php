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
use Illuminate\Support\Carbon;

/**
 * A document supporting a beneficiary's case.
 *
 * **Sensitive documents have a far shorter life than the case they support.**
 * A medical report proving eligibility has served its purpose once the case
 * closes; keeping it for six years beside the financial record would be
 * retention without a purpose, which is exactly what Act 843 s.24 prohibits.
 *
 *   sensitive document   24 months from closure
 *   case record          72 months from closure
 *
 * So this model reports a different retention class depending on
 * `is_sensitive`, and the runner sweeps them on different schedules.
 */
class BeneficiaryDocument extends Model implements Retainable
{
    use DeIdentifiable;
    use HasFactory;
    use HasUlids;

    public const TYPE_MEDICAL = 'medical';

    public const TYPE_FINANCIAL = 'financial';

    public const TYPE_IDENTITY = 'identity';

    public const TYPE_SCHOOL = 'school';

    public const TYPE_REFERRAL = 'referral';

    public const TYPE_OTHER = 'other';

    /**
     * Document types that are sensitive whatever the flag says.
     *
     * A medical report uploaded with `is_sensitive` left false would otherwise
     * sit under the six-year case-record schedule. Deriving it from the type as
     * well as the flag means the shorter, safer period wins by default.
     *
     * @var array<int, string>
     */
    private const ALWAYS_SENSITIVE = [self::TYPE_MEDICAL, self::TYPE_IDENTITY];

    protected $fillable = [
        'beneficiary_id', 'media_id', 'title', 'document_type',
        'description', 'is_sensitive', 'closed_at', 'uploaded_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'document_type' => self::TYPE_OTHER,
        'is_sensitive' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_sensitive' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $document): void {
            if (in_array($document->document_type, self::ALWAYS_SENSITIVE, true)) {
                $document->is_sensitive = true;
            }
        });
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /** @return BelongsTo<Beneficiary, $this> */
    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    // ── Retention ────────────────────────────────────────────────────────────

    public function retentionClass(): string
    {
        return $this->is_sensitive
            ? 'beneficiary_sensitive_document'
            : 'beneficiary_case_record';
    }

    public function retentionAnchorDate(): ?Carbon
    {
        // Set when the case closes. An open case's documents are never due.
        return $this->closed_at;
    }

    public function retentionScopeKey(): ?string
    {
        return $this->beneficiary_id === null ? null : 'beneficiary:'.$this->beneficiary_id;
    }

    /**
     * Destroy the document and the file behind it.
     *
     * There is no statistical shell worth keeping in a scanned medical report,
     * so this is a deletion, not a strip. The media row goes too: leaving the
     * file on disk while removing the row that points at it is how a
     * "deleted" document stays readable to anyone with the URL.
     */
    public function deIdentify(): void
    {
        $this->media?->delete();

        $this->forceDelete();
    }

    /** @return array<string, string> */
    public static function privacyElements(): array
    {
        return [
            'title' => 'supporting_document',
            'description' => 'supporting_document',
            'media_id' => 'supporting_document',
            'document_type' => 'supporting_document',
        ];
    }

    /** @return array<int, string> */
    public static function privacyExempt(): array
    {
        return [
            'id', 'ulid', 'created_at', 'updated_at',
            'beneficiary_id', 'is_sensitive', 'closed_at', 'uploaded_by',
        ];
    }

    #[Scope]
    protected function sensitive(Builder $query): void
    {
        $query->where('is_sensitive', true);
    }

    #[Scope]
    protected function retentionCandidates(Builder $query): void
    {
        $query->whereNotNull('closed_at');
    }
}
