<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Retainable;
use App\Models\Concerns\DeIdentifiable;
use App\Models\Concerns\HasConsents;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Somebody coming to an event.
 *
 * **`photography_consent` is nullable with no default, and that is deliberate.**
 * Consent has to be ASKED, and "we never asked" must be distinguishable from
 * "they said no". A boolean defaulting to false would silently record a refusal
 * nobody obtained; one defaulting to true would be worse.
 *
 * Three consents, three columns. Agreeing to be photographed is not agreeing to
 * be emailed about the event, and neither is agreeing to a newsletter. Ghana's
 * Act 843 treats them as distinct purposes and so does this table.
 */
class EventRegistration extends Model implements Retainable
{
    use DeIdentifiable;
    use HasConsents;
    use HasFactory;
    use HasUlids;

    public const STATUS_REGISTERED = 'registered';

    public const STATUS_WAITLISTED = 'waitlisted';

    public const STATUS_ATTENDED = 'attended';

    public const STATUS_NO_SHOW = 'no_show';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'event_id', 'user_id', 'name', 'email', 'phone', 'guests', 'status',
        'photography_consent', 'contact_consent', 'newsletter_consent',
        'consent_text', 'consent_ip', 'accessibility_needs', 'dietary_needs', 'notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_REGISTERED,
        'guests' => 0,
        'contact_consent' => false,
        'newsletter_consent' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'photography_consent' => 'boolean',
            'contact_consent' => 'boolean',
            'newsletter_consent' => 'boolean',
            'consent_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $registration): void {
            $registration->reference ??= 'SCGHF-E-'.Str::upper(substr(Str::ulid()->toBase32(), -10));
            $registration->email = $registration->email === null
                ? null
                : mb_strtolower(trim($registration->email));

            if ($registration->consent_text !== null) {
                $registration->consent_at ??= now();
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

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** People this booking accounts for — the registrant plus their guests. */
    public function headcount(): int
    {
        return 1 + (int) $this->guests;
    }

    // ── Consent ──────────────────────────────────────────────────────────────

    /**
     * Whether a photograph of this person may be published.
     *
     * NULL — never asked — is a no. An absent answer is not permission, and
     * treating it as one is exactly how a photograph of somebody who would have
     * objected ends up on a website.
     */
    public function mayBePhotographed(): bool
    {
        return $this->photography_consent === true;
    }

    /** Whether consent was actually put to them. */
    public function wasAskedAboutPhotography(): bool
    {
        return $this->photography_consent !== null;
    }

    public function mayBeEmailedAboutEvent(): bool
    {
        return $this->contact_consent && filled($this->email);
    }

    /**
     * Whether this person may be added to the newsletter.
     *
     * Its own consent. A registration is not a mailing list, and quietly
     * treating one as the other is the most common way a charity's list becomes
     * something nobody actually agreed to.
     */
    public function mayBeAddedToNewsletter(): bool
    {
        return $this->newsletter_consent && filled($this->email);
    }

    // ── Lifecycle ────────────────────────────────────────────────────────────

    /**
     * Register somebody, respecting capacity.
     *
     * Runs inside a transaction with the event row locked: two people booking
     * the last two places in the same second must not both succeed, and on a
     * popular event that is exactly when it happens.
     *
     * @param  array<string, mixed>  $details
     */
    public static function place(Event $event, array $details): self
    {
        $reason = $event->registrationRejectionReason();

        if ($reason !== null) {
            throw new RuntimeException($reason);
        }

        return DB::transaction(function () use ($event, $details): self {
            $locked = Event::query()->lockForUpdate()->findOrFail($event->getKey());

            $headcount = 1 + (int) ($details['guests'] ?? 0);
            $remaining = $locked->placesRemaining();

            /*
             * Over capacity goes to the WAITLIST rather than being refused. A
             * charity event that turns people away outright loses them; one
             * that waitlists them can call when somebody drops out.
             */
            $status = ($remaining !== null && $headcount > $remaining)
                ? self::STATUS_WAITLISTED
                : self::STATUS_REGISTERED;

            $registration = self::create([...$details, 'event_id' => $locked->getKey(), 'status' => $status]);

            if ($status === self::STATUS_REGISTERED) {
                $locked->adjustHeadcount($headcount);
            }

            return $registration;
        });
    }

    public function cancel(): void
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return;
        }

        $wasCounted = $this->status === self::STATUS_REGISTERED;

        $this->forceFill([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ])->save();

        if ($wasCounted) {
            $this->event?->adjustHeadcount(-$this->headcount());
        }
    }

    public function checkIn(): void
    {
        $this->forceFill([
            'status' => self::STATUS_ATTENDED,
            'checked_in_at' => now(),
        ])->save();
    }

    public function markNoShow(): void
    {
        $this->forceFill(['status' => self::STATUS_NO_SHOW])->save();
    }

    // ── Retention ────────────────────────────────────────────────────────────

    public function retentionClass(): string
    {
        return 'event_registration';
    }

    /**
     * The clock starts when the event finishes, not when somebody registered.
     *
     * A registration for an event that has not happened is live data; one for
     * an event two years ago is a record nobody needs.
     */
    public function retentionAnchorDate(): ?Carbon
    {
        $event = $this->event;

        if ($event === null) {
            return null;
        }

        $ended = $event->ends_at ?? $event->starts_at;

        return $ended->isPast() ? $ended : null;
    }

    public function retentionScopeKey(): ?string
    {
        return 'event:'.$this->event_id;
    }

    /** @return array<string, string> */
    public static function privacyElements(): array
    {
        return [
            'reference' => 'case_reference',
            'name' => 'name',
            'email' => 'email',
            'phone' => 'phone',
            'consent_text' => 'narrative',
            'consent_ip' => 'device',
            // Accessibility and dietary needs routinely reveal a disability or
            // a medical condition. They are health data, and treated as such.
            'accessibility_needs' => 'medical',
            'dietary_needs' => 'medical',
            'notes' => 'case_notes',
            'guests' => 'indicator',
        ];
    }

    /** @return array<int, string> */
    public static function privacyExempt(): array
    {
        return [
            'id', 'ulid', 'created_at', 'updated_at',
            'event_id', 'user_id', 'status',
            'photography_consent', 'contact_consent', 'newsletter_consent', 'consent_at',
            'checked_in_at', 'cancelled_at',
        ];
    }

    #[Scope]
    protected function attending(Builder $query): void
    {
        $query->whereIn('status', [self::STATUS_REGISTERED, self::STATUS_ATTENDED]);
    }

    /** People who agreed to be photographed — the door list for a photographer. */
    #[Scope]
    protected function photographable(Builder $query): void
    {
        $query->where('photography_consent', true);
    }

    #[Scope]
    protected function retentionCandidates(Builder $query): void
    {
        $query->whereHas('event', fn (Builder $q) => $q->where('starts_at', '<', now()));
    }
}
