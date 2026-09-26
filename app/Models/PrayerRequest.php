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
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Something somebody has asked to be prayed about.
 *
 * **Confidential by default, and publication is refused without explicit
 * consent.** A prayer request routinely carries the most sensitive thing
 * anybody volunteers to this foundation — a diagnosis, a bereavement, a
 * marriage in trouble — offered to people who will pray about it, not to a
 * website that needs content.
 *
 * Publishing "pray for Ama, who has been diagnosed with cancer" without asking
 * would be a serious breach of Act 843 and an unforgivable betrayal of the
 * person who asked. So `publish()` throws, and it throws by default.
 *
 * Even WITH consent, the default is to publish anonymously: somebody happy for
 * their situation to be prayed about publicly is not necessarily happy to be
 * named in it.
 */
class PrayerRequest extends Model implements Retainable
{
    use BelongsToDivision;
    use DeIdentifiable;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    public const STATUS_NEW = 'new';

    public const STATUS_PRAYING = 'praying';

    public const STATUS_ANSWERED = 'answered';

    public const STATUS_CLOSED = 'closed';

    public const CATEGORY_HEALTH = 'health';

    public const CATEGORY_FAMILY = 'family';

    public const CATEGORY_BEREAVEMENT = 'bereavement';

    public const CATEGORY_FINANCE = 'finance';

    public const CATEGORY_WORK = 'work';

    public const CATEGORY_SPIRITUAL = 'spiritual';

    public const CATEGORY_THANKSGIVING = 'thanksgiving';

    public const CATEGORY_OTHER = 'other';

    protected $fillable = [
        'user_id', 'division_id', 'name', 'email', 'phone', 'is_anonymous',
        'category', 'request', 'is_confidential', 'consent_to_publish',
        'consent_to_share_with_team', 'publish_anonymously',
        'consent_text', 'consent_ip', 'submitted_ip',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'category' => self::CATEGORY_OTHER,
        'status' => self::STATUS_NEW,
        'is_anonymous' => false,
        // The two defaults that matter.
        'is_confidential' => true,
        'consent_to_publish' => false,
        'consent_to_share_with_team' => true,
        'publish_anonymously' => true,
        'prayed_count' => 0,
        'is_published' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_anonymous' => 'boolean',
            'is_confidential' => 'boolean',
            'consent_to_publish' => 'boolean',
            'consent_to_share_with_team' => 'boolean',
            'publish_anonymously' => 'boolean',
            'is_published' => 'boolean',
            'consent_at' => 'datetime',
            'answered_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            $request->reference ??= 'SCGHF-P-'.Str::upper(substr(Str::ulid()->toBase32(), -10));

            if ($request->consent_text !== null) {
                $request->consent_at ??= now();
            }

            /*
             * An anonymous request must not carry contact details. Somebody
             * choosing not to be identified and then being emailed about it has
             * had their choice overridden by a form.
             */
            if ($request->is_anonymous) {
                $request->name = null;
                $request->email = null;
                $request->phone = null;
            }
        });

        static::saving(function (self $request): void {
            // The gate, on every save rather than only in publish(), so
            // `update(['is_published' => true])` cannot walk past it.
            if ($request->is_published) {
                $request->assertPublishable();
            }
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

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    // ── Publication ──────────────────────────────────────────────────────────

    /**
     * Why this request may not be published, or null if it may.
     *
     * A reason rather than a boolean, so an administrator is told what is
     * missing and asks for it — rather than looking for a way round.
     */
    public function publicationRejectionReason(): ?string
    {
        if (! $this->consent_to_publish) {
            return 'This request was submitted in confidence. It cannot be published without '
                .'explicit consent from the person who sent it.';
        }

        if ($this->is_confidential) {
            return 'This request is marked confidential. Clear that first, and only if the '
                .'person who sent it agreed to it being published.';
        }

        return null;
    }

    public function canBePublished(): bool
    {
        return $this->publicationRejectionReason() === null;
    }

    public function assertPublishable(): void
    {
        $reason = $this->publicationRejectionReason();

        if ($reason !== null) {
            throw new RuntimeException($reason);
        }
    }

    public function publish(): void
    {
        $this->assertPublishable();

        $this->forceFill([
            'is_published' => true,
            'published_at' => now(),
        ])->save();
    }

    public function unpublish(): void
    {
        $this->forceFill(['is_published' => false, 'published_at' => null])->save();
    }

    /**
     * The name to show publicly.
     *
     * Anonymous by default even where publication was agreed: somebody happy
     * for their situation to be prayed about publicly is not necessarily happy
     * to be named in it.
     */
    public function publicName(): string
    {
        if ($this->is_anonymous || $this->publish_anonymously || blank($this->name)) {
            return 'A member of our community';
        }

        return (string) $this->name;
    }

    /** Whether the prayer team may see the details at all. */
    public function mayBeSharedWithTeam(): bool
    {
        return $this->consent_to_share_with_team;
    }

    // ── Lifecycle ────────────────────────────────────────────────────────────

    /** Somebody prayed. Incremented atomically; it is a public counter. */
    public function recordPrayer(): void
    {
        static::whereKey($this->getKey())->update([
            'prayed_count' => DB::raw('prayed_count + 1'),
            'status' => $this->status === self::STATUS_NEW ? self::STATUS_PRAYING : $this->status,
            'updated_at' => now(),
        ]);

        $this->refresh();
    }

    public function markAnswered(string $note = ''): void
    {
        $this->forceFill([
            'status' => self::STATUS_ANSWERED,
            'answered_at' => now(),
            'answered_note' => $note !== '' ? $note : null,
        ])->save();
    }

    public function close(): void
    {
        $this->forceFill(['status' => self::STATUS_CLOSED])->save();
    }

    /** Whether anyone can be told the outcome. */
    public function canBeFollowedUp(): bool
    {
        return ! $this->is_anonymous && filled($this->email);
    }

    // ── Retention ────────────────────────────────────────────────────────────

    public function retentionClass(): string
    {
        return 'prayer_request';
    }

    public function retentionAnchorDate(): ?Carbon
    {
        // From submission, not from closure. Twelve months is long enough to
        // pray, to follow up and to report in aggregate; it is not long enough
        // to become an archive of a congregation's private difficulties.
        return $this->created_at;
    }

    public function retentionScopeKey(): ?string
    {
        return 'category:'.$this->category;
    }

    /** @return array<string, string> */
    public static function privacyElements(): array
    {
        return [
            'reference' => 'case_reference',
            'name' => 'name',
            'email' => 'email',
            'phone' => 'phone',
            // The request itself is the sensitive part — a diagnosis, a
            // bereavement, a marriage in trouble.
            'request' => 'medical',
            'answered_note' => 'case_notes',
            'consent_text' => 'narrative',
            'consent_ip' => 'device',
            'submitted_ip' => 'device',
            'category' => 'indicator',
        ];
    }

    /** @return array<int, string> */
    public static function privacyExempt(): array
    {
        return [
            'id', 'ulid', 'created_at', 'updated_at', 'deleted_at',
            'user_id', 'division_id', 'status', 'is_anonymous',
            'is_confidential', 'consent_to_publish', 'consent_to_share_with_team',
            'publish_anonymously', 'consent_at', 'prayed_count',
            'answered_at', 'assigned_to', 'is_published', 'published_at',
        ];
    }

    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereIn('status', [self::STATUS_NEW, self::STATUS_PRAYING]);
    }

    /** The public prayer wall — consented, non-confidential requests only. */
    #[Scope]
    protected function publishable(Builder $query): void
    {
        $query->where('is_published', true)
            ->where('consent_to_publish', true)
            ->where('is_confidential', false);
    }

    #[Scope]
    protected function retentionCandidates(Builder $query): void
    {
        // Everything. A prayer request has a fixed life from submission
        // whatever happened to it.
        $query->whereNotNull('created_at');
    }
}
