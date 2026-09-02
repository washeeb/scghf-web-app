<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A written GRA approval under Act 896.
 *
 * Section 97 approves the organisation; section 100 covers a particular
 * worthwhile cause. Either can authorise deductibility messaging — neither is
 * implied by the Foundation merely being registered.
 *
 * @property string $approval_type
 * @property string $reference
 * @property string $status
 */
class TaxApproval extends Model
{
    use HasUlids;

    public const TYPE_SECTION_97 = 'section_97';

    public const TYPE_SECTION_100 = 'section_100';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'approval_type', 'reference', 'tin', 'issued_on', 'expires_on',
        'status', 'document_id', 'covers_scope', 'notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'approval_type' => self::TYPE_SECTION_97,
        'status' => self::STATUS_DRAFT,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'expires_on' => 'date',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $approval): void {
            if ($approval->expires_on !== null && $approval->expires_on->lte($approval->issued_on)) {
                throw new RuntimeException('An approval cannot expire on or before the day it was issued.');
            }

            /*
             * An approval cannot be made active without the letter attached.
             * The whole point of this record is that the Foundation holds
             * WRITTEN approval; an active row with no document is an assertion,
             * and it is exactly the state an auditor would ask about.
             */
            if ($approval->status === self::STATUS_ACTIVE && $approval->document_id === null) {
                throw new RuntimeException(
                    'Attach the GRA approval document before marking this approval active. '
                    .'Deductibility messaging depends on the Foundation actually holding it.'
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
        return 'ulid';
    }

    /** @return BelongsTo<Media, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'document_id');
    }

    /**
     * Whether this approval authorises deductibility messaging today.
     *
     * Deliberately evaluated from the dates on every call rather than trusting
     * the stored status. A status column can go stale between scheduler runs;
     * the date cannot. Nothing about what a donor is told should depend on a
     * cron job having run last night.
     */
    public function isCurrentlyValid(?\DateTimeInterface $on = null): bool
    {
        $on = $on ? Carbon::instance($on) : now();

        if (in_array($this->status, [self::STATUS_REVOKED, self::STATUS_SUPERSEDED, self::STATUS_DRAFT], true)) {
            return false;
        }

        if ($this->issued_on->startOfDay()->gt($on)) {
            return false;
        }

        // Valid through the whole of the expiry day.
        return $this->expires_on === null || $this->expires_on->endOfDay()->gte($on);
    }

    public function isExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->endOfDay()->isPast();
    }

    public function daysUntilExpiry(): ?int
    {
        return $this->expires_on === null
            ? null
            : (int) now()->startOfDay()->diffInDays($this->expires_on->startOfDay(), false);
    }

    public function revoke(string $reason, ?User $by = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_REVOKED,
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ])->save();
    }

    /** How this approval is cited on a receipt. */
    public function citation(): string
    {
        $section = $this->approval_type === self::TYPE_SECTION_100 ? '100' : '97';

        return sprintf(
            'GRA approval under section %s of the Income Tax Act, 2015 (Act 896): %s%s',
            $section,
            $this->reference,
            $this->expires_on !== null ? ', valid to '.$this->expires_on->format('j F Y') : '',
        );
    }

    #[Scope]
    protected function current(Builder $query): void
    {
        $query->whereNotIn('status', [self::STATUS_REVOKED, self::STATUS_SUPERSEDED, self::STATUS_DRAFT])
            ->whereDate('issued_on', '<=', now())
            // orWhere, not where: an approval is current if it has NO expiry
            // OR its expiry is in the future. Chaining these with AND makes the
            // condition unsatisfiable and the scope returns nothing.
            ->where(fn (Builder $q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', now()));
    }

    #[Scope]
    protected function expiringWithin(Builder $query, int $days): void
    {
        $query->where('status', self::STATUS_ACTIVE)
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '>=', now())
            ->whereDate('expires_on', '<=', now()->addDays($days));
    }
}
