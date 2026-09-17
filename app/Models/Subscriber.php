<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A newsletter subscriber, with the consent record attached.
 *
 * Double opt-in is not optional here. Blueprint risk DEL-5: single opt-in is
 * the fastest way to destroy a sending domain's reputation, and a foundation
 * whose donation RECEIPTS stop arriving has a far worse problem than a smaller
 * mailing list.
 *
 * @property string $email
 * @property string $status
 */
class Subscriber extends Model
{
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    public const STATUS_BOUNCED = 'bounced';

    public const STATUS_COMPLAINED = 'complained';

    protected $fillable = ['email', 'name', 'source', 'topics'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'source' => 'footer',
        'bounce_count' => 0,
    ];

    protected $hidden = ['confirmation_token', 'unsubscribe_token'];

    protected function casts(): array
    {
        return [
            'topics' => 'array',
            'confirmed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
            'consent_at' => 'datetime',
            'last_emailed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $subscriber): void {
            $subscriber->email = mb_strtolower(trim($subscriber->email));

            // The unsubscribe token is generated up front, not on first send.
            // Every email must carry a working one-click unsubscribe, and
            // generating it lazily means the first email cannot.
            $subscriber->unsubscribe_token ??= Str::random(48);
            $subscriber->confirmation_token ??= Str::random(48);
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

    /**
     * Record what was agreed to, when, and from where.
     *
     * Act 843 requires consent to be freely given, specific and informed. That
     * means being able to show the EXACT text someone agreed to — not merely
     * that a row exists. Storing the wording is what makes the record evidence
     * rather than an assertion.
     */
    public function recordConsent(string $text, ?string $ip = null, ?string $sourceUrl = null): void
    {
        $this->forceFill([
            'consent_text' => $text,
            'consent_ip' => $ip,
            'consent_source_url' => $sourceUrl,
            'consent_at' => now(),
        ])->save();
    }

    public function confirm(): void
    {
        $this->forceFill([
            'status' => self::STATUS_CONFIRMED,
            'confirmed_at' => now(),
            // Burn the token. A confirmation link that works forever is a
            // link someone else can use later.
            'confirmation_token' => null,
        ])->save();
    }

    public function unsubscribe(?string $reason = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_UNSUBSCRIBED,
            'unsubscribed_at' => now(),
            'unsubscribe_reason' => $reason,
        ])->save();
    }

    /**
     * A hard bounce or a spam complaint.
     *
     * Both permanently stop sending. Continuing to mail an address that has
     * complained is the single fastest route to a blocked sending domain —
     * Blueprint risk DEL-4.
     */
    public function suppress(string $status = self::STATUS_BOUNCED): void
    {
        $this->forceFill([
            'status' => $status,
            'bounce_count' => $this->bounce_count + 1,
        ])->save();
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    /** Whether it is lawful and safe to email this address. */
    public function canBeEmailed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED && $this->deleted_at === null;
    }

    /** Pending sign-ups that were never confirmed and should be purged. */
    #[Scope]
    protected function stalePending(Builder $query, int $days = 30): void
    {
        $query->where('status', self::STATUS_PENDING)
            ->where('created_at', '<', now()->subDays($days));
    }

    #[Scope]
    protected function mailable(Builder $query): void
    {
        $query->where('status', self::STATUS_CONFIRMED);
    }
}
