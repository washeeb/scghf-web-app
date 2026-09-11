<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Somebody who has given.
 *
 * Separate from `User`. Most donors never create an account — they give once,
 * from a phone, and leave. Forcing a user row for every gift would fill the auth
 * table with records that can never log in, and make "how many people can sign
 * in to this site" unanswerable.
 *
 * Personal data under Act 843, and tied to financial records with a six-year
 * statutory minimum — so a donor record is soft-deletable for day-to-day
 * operations, and a genuine erasure request is a documented, logged path that
 * leaves the financial rows intact and anonymises the person.
 */
class Donor extends Model
{
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    public const TYPE_INDIVIDUAL = 'individual';

    public const TYPE_ORGANISATION = 'organisation';

    protected $fillable = [
        'user_id', 'name', 'email', 'phone', 'phone_raw', 'donor_type',
        'organisation_name', 'address', 'city', 'country',
        'consent_email', 'consent_sms', 'consent_text', 'consent_ip', 'consent_at',
        'is_anonymous_by_default', 'notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'donor_type' => self::TYPE_INDIVIDUAL,
        'consent_email' => false,
        'consent_sms' => false,
        'total_donated_minor' => 0,
        'donation_count' => 0,
        'is_anonymous_by_default' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'consent_email' => 'boolean',
            'consent_sms' => 'boolean',
            'is_anonymous_by_default' => 'boolean',
            'consent_at' => 'datetime',
            'first_donated_at' => 'datetime',
            'last_donated_at' => 'datetime',
            'total_donated' => MoneyCast::class.':total_donated_minor',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $donor): void {
            $donor->email = $donor->email === null ? null : mb_strtolower(trim($donor->email));

            if (filled($donor->phone)) {
                $donor->phone_raw ??= $donor->phone;
                $donor->phone = self::normalisePhone((string) $donor->phone);
            }
        });
    }

    /**
     * Ghanaian numbers to E.164, keeping the raw input alongside.
     *
     * `024 123 4567`, `+233241234567` and `233241234567` are the same person,
     * and a donor who gave under two of them should not appear twice on their
     * own giving history.
     */
    public static function normalisePhone(string $phone): string
    {
        $digits = preg_replace('/[^0-9+]/', '', $phone) ?? '';

        if (str_starts_with($digits, '+')) {
            return $digits;
        }

        if (str_starts_with($digits, '233')) {
            return '+'.$digits;
        }

        if (str_starts_with($digits, '0')) {
            return '+233'.substr($digits, 1);
        }

        return $digits;
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

    /** @return HasMany<Donation, $this> */
    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class);
    }

    /** @return HasMany<Subscription, $this> */
    /**
     * Tags are how Finance segments donors without a schema change per idea:
     * "church-network", "gala-2026", "major-donor". Shared with posts through
     * the same `taggables` table.
     *
     * @return MorphToMany<Tag, $this>
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function totalDonated(): Money
    {
        return $this->total_donated ?? Money::zero();
    }

    /**
     * Find or create a donor from the details given on a donation form.
     *
     * Matches on email first, then on a normalised phone. Deliberately does NOT
     * match on name: two people called Kwame Mensah are two people, and merging
     * them would put one donor's giving history in front of another.
     *
     * @param  array<string, mixed>  $details
     */
    public static function matchOrCreate(array $details): self
    {
        $email = isset($details['email']) ? mb_strtolower(trim((string) $details['email'])) : null;
        $phone = isset($details['phone']) ? self::normalisePhone((string) $details['phone']) : null;

        $donor = null;

        if ($email !== null && $email !== '') {
            $donor = static::where('email', $email)->first();
        }

        if ($donor === null && $phone !== null && $phone !== '') {
            $donor = static::where('phone', $phone)->first();
        }

        if ($donor !== null) {
            /*
             * An address given with a later gift fills a blank on the record.
             * It never overwrites one — a donor who moved and gave from the
             * old address by habit should not have the record changed by a
             * form, and a mismatch is something a person should look at.
             */
            $donor->fill(array_filter([
                'address' => blank($donor->address) ? ($details['address'] ?? null) : null,
                'city' => blank($donor->city) ? ($details['city'] ?? null) : null,
            ]));

            if ($donor->isDirty()) {
                $donor->save();
            }

            return $donor;
        }

        return static::create([
            'name' => $details['name'] ?? 'Anonymous donor',
            'email' => $email,
            'phone' => $phone,
            'address' => $details['address'] ?? null,
            'city' => $details['city'] ?? null,
            'country' => isset($details['address']) || isset($details['city']) ? 'GH' : null,
            'donor_type' => $details['donor_type'] ?? self::TYPE_INDIVIDUAL,
            'consent_email' => (bool) ($details['consent_email'] ?? false),
            'consent_sms' => (bool) ($details['consent_sms'] ?? false),
            'consent_text' => $details['consent_text'] ?? null,
            'consent_ip' => $details['consent_ip'] ?? null,
            'consent_at' => isset($details['consent_text']) ? now() : null,
        ]);
    }

    /**
     * Fold a completed gift into the lifetime figures.
     *
     * Incremented atomically in SQL, never read-then-written: two gifts landing
     * in the same second would otherwise lose one of them, and that is exactly
     * when it matters. Safe to increment rather than recompute because donations
     * are append-only and never change.
     */
    public function recordDonation(Donation $donation): void
    {
        $paidAt = $donation->paid_at ?? now();

        static::whereKey($this->getKey())->update([
            'total_donated_minor' => DB::raw('total_donated_minor + '.(int) $donation->amount->toMinor()),
            'donation_count' => DB::raw('donation_count + 1'),
            'last_donated_at' => $paidAt,
            'updated_at' => now(),
        ]);

        // A second statement rather than a COALESCE with a bound value, which
        // DB::raw cannot express safely. Only the first gift ever writes it.
        static::whereKey($this->getKey())
            ->whereNull('first_donated_at')
            ->update(['first_donated_at' => $paidAt]);
    }

    /**
     * Rebuild the lifetime figures from the donations table.
     *
     * The counters are incremented as gifts land; a refund, an amended
     * offline gift or a merge is corrected by recomputing rather than by
     * decrementing, because a decrement that runs twice is a wrong number and
     * a recount is not.
     */
    public function recalculateTotals(): void
    {
        $completed = Donation::query()->where('donor_id', $this->getKey())->completed();

        static::whereKey($this->getKey())->update([
            'total_donated_minor' => (int) (clone $completed)->sum('amount_minor'),
            'donation_count' => (clone $completed)->count(),
            'first_donated_at' => (clone $completed)->min('paid_at'),
            'last_donated_at' => (clone $completed)->max('paid_at'),
        ]);
    }

    /** Whether this donor may be emailed anything other than a receipt. */
    public function mayBeEmailed(): bool
    {
        return $this->consent_email && filled($this->email);
    }

    public function mayBeTexted(): bool
    {
        return $this->consent_sms && filled($this->phone);
    }

    public function displayName(): string
    {
        return $this->donor_type === self::TYPE_ORGANISATION && filled($this->organisation_name)
            ? (string) $this->organisation_name
            : (string) $this->name;
    }

    #[Scope]
    protected function emailable(Builder $query): void
    {
        $query->where('consent_email', true)->whereNotNull('email');
    }

    #[Scope]
    protected function recurringCandidates(Builder $query): void
    {
        $query->where('donation_count', '>=', 2);
    }
}
