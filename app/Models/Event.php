<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\BelongsToDivision;
use App\Models\Concerns\HasSeo;
use App\Models\Concerns\RecordsAuthor;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Something happening, somewhere, at a time.
 *
 * Capacity counts PEOPLE, not bookings — a registration bringing three guests
 * takes four places. Getting that wrong is how a room built for eighty ends up
 * with a hundred and forty in it.
 *
 * Ticketing is behind a feature flag and off. The columns exist so switching it
 * on is not a migration, but a ticketed event would be priced through the
 * shop's payment path, not a second one.
 */
class Event extends Model
{
    use BelongsToDivision;
    use HasFactory;
    use HasSeo;
    use HasUlids;
    use LogsActivity;
    use RecordsAuthor;
    use SoftDeletes;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_POSTPONED = 'postponed';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'division_id', 'project_id', 'cause_id', 'title', 'slug', 'summary',
        'description', 'event_type', 'starts_at', 'ends_at',
        'venue_name', 'address', 'area', 'region', 'is_online', 'online_url',
        'accessibility_notes', 'outcomes', 'attendance_count', 'registration_required', 'capacity',
        'registration_opens_at', 'registration_closes_at',
        'is_ticketed', 'ticket_price', 'currency', 'status',
        'featured_image_id', 'gallery_id', 'is_featured', 'is_published', 'published_at', 'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'event_type' => 'outreach',
        'status' => self::STATUS_SCHEDULED,
        'currency' => 'GHS',
        'is_online' => false,
        'registration_required' => false,
        'registered_count' => 0,
        'is_ticketed' => false,
        'is_featured' => false,
        'is_published' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'registration_opens_at' => 'datetime',
            'registration_closes_at' => 'datetime',
            'published_at' => 'datetime',
            'is_online' => 'boolean',
            'registration_required' => 'boolean',
            'is_ticketed' => 'boolean',
            'is_featured' => 'boolean',
            'is_published' => 'boolean',
            'ticket_price' => MoneyCast::class.':ticket_price_minor,currency',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $event): void {
            if (blank($event->slug)) {
                $event->slug = Str::slug($event->title);
            }

            $event->slug = Str::slug($event->slug);

            if ($event->ends_at !== null && $event->ends_at->lt($event->starts_at)) {
                throw new RuntimeException('An event cannot end before it starts.');
            }

            /*
             * Ticketing is feature-flagged off. Publishing a ticketed event
             * while the flag is down would show a price nobody can pay.
             */
            if ($event->is_ticketed && ! config('features.event_ticketing', false)) {
                throw new RuntimeException(
                    'Event ticketing is switched off. Enable FEATURE_EVENT_TICKETING before '
                    .'marking an event as ticketed, or the price will show with no way to pay it.'
                );
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
        return 'slug';
    }

    /** @return HasMany<EventRegistration, $this> */
    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    /** @return HasMany<IssuedTicket, $this> */
    public function issuedTickets(): HasMany
    {
        return $this->hasMany(IssuedTicket::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Cause, $this> */
    public function cause(): BelongsTo
    {
        return $this->belongsTo(Cause::class);
    }

    /** @return BelongsTo<Gallery, $this> */
    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }

    /** @return BelongsTo<Media, $this> */
    public function featuredImage(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'featured_image_id');
    }

    // ── Capacity ─────────────────────────────────────────────────────────────

    /**
     * Places left, counting PEOPLE rather than bookings.
     *
     * A registration bringing three guests takes four places. Counting bookings
     * is how a room built for eighty ends up with a hundred and forty in it.
     */
    public function placesRemaining(): ?int
    {
        if ($this->capacity === null) {
            return null;
        }

        return max(0, $this->capacity - $this->registered_count);
    }

    public function isFull(): bool
    {
        return $this->capacity !== null && $this->registered_count >= $this->capacity;
    }

    /** Why registration is closed, or null if it is open. */
    public function registrationRejectionReason(): ?string
    {
        if (! $this->registration_required) {
            return 'This event does not need registration — just come along.';
        }

        if (! $this->isLive()) {
            return 'This event is not open for registration.';
        }

        if ($this->status === self::STATUS_CANCELLED) {
            return 'This event has been cancelled.';
        }

        if ($this->registration_opens_at !== null && $this->registration_opens_at->isFuture()) {
            return 'Registration opens on '.$this->registration_opens_at->format('j F Y').'.';
        }

        if ($this->registration_closes_at !== null && $this->registration_closes_at->isPast()) {
            return 'Registration closed on '.$this->registration_closes_at->format('j F Y').'.';
        }

        if ($this->starts_at->isPast()) {
            return 'This event has already taken place.';
        }

        return null;
    }

    public function isOpenForRegistration(): bool
    {
        return $this->registrationRejectionReason() === null;
    }

    /**
     * Adjust the headcount atomically.
     *
     * Never read-then-write: two people registering in the same second would
     * otherwise lose one, and on a popular event that is exactly when it
     * happens.
     */
    public function adjustHeadcount(int $by): void
    {
        static::whereKey($this->getKey())->update([
            'registered_count' => DB::raw('GREATEST(0, CAST(registered_count AS SIGNED) + '.$by.')'),
            'updated_at' => now(),
        ]);

        $this->refresh();
    }

    /** Recompute from the registrations, for reconciliation. */
    public function refreshHeadcount(): int
    {
        $count = (int) $this->registrations()
            ->whereIn('status', [EventRegistration::STATUS_REGISTERED, EventRegistration::STATUS_ATTENDED])
            ->sum(DB::raw('1 + guests'));

        static::whereKey($this->getKey())->update(['registered_count' => $count]);

        $this->refresh();

        return $count;
    }

    // ── Lifecycle ────────────────────────────────────────────────────────────

    public function cancel(string $reason): void
    {
        if (blank($reason)) {
            throw new RuntimeException(
                'Cancelling an event needs a reason. Everybody registered will be told it, and '
                .'"cancelled" on its own is not an explanation.'
            );
        }

        $this->forceFill([
            'status' => self::STATUS_CANCELLED,
            'cancellation_reason' => $reason,
        ])->save();
    }

    public function hasFinished(): bool
    {
        return ($this->ends_at ?? $this->starts_at)->isPast();
    }

    public function isLive(): bool
    {
        return $this->is_published
            && ($this->published_at === null || $this->published_at->isPast());
    }

    /** Published, and its publish date has passed. Cancelled events stay live: the page is where the cancellation is read. */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where('is_published', true)
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    #[Scope]
    protected function upcoming(Builder $query): void
    {
        $query->where('is_published', true)
            ->where('status', '!=', self::STATUS_CANCELLED)
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at');
    }

    #[Scope]
    protected function past(Builder $query): void
    {
        $query->where('is_published', true)
            ->where('starts_at', '<', now())
            ->orderByDesc('starts_at');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'starts_at', 'status', 'capacity', 'is_published'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('event');
    }
}
