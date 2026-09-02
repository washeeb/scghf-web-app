<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * A legal, audit or investigation hold.
 *
 * A hold OVERRIDES every ordinary retention date. Destroying records subject to
 * a live dispute or investigation is a far more serious failure than keeping
 * them slightly too long, so the retention runner refuses to touch anything a
 * hold covers.
 *
 * @property string $reference
 * @property bool $is_active
 */
class LegalHold extends Model
{
    use HasUlids;

    protected $fillable = [
        'title', 'reason', 'hold_type', 'holdable_type', 'holdable_id',
        'retention_class', 'scope_key', 'placed_on', 'placed_by', 'review_on',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'hold_type' => 'legal',
        'is_active' => true,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'placed_on' => 'date',
            'review_on' => 'date',
            'released_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $hold): void {
            $hold->reference ??= 'HOLD-'.now()->format('Y').'-'.Str::upper(Str::random(6));
            $hold->placed_on ??= now()->toDateString();
        });
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /** @return MorphTo<Model, $this> */
    public function holdable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function placedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by');
    }

    /**
     * Release the hold.
     *
     * Records it covered become eligible for their ordinary retention dates
     * again — which for anything already past its date means the next run will
     * act on it. That is the intended behaviour, but it is why a release should
     * be a considered decision rather than a tidy-up.
     */
    public function release(string $reason, ?User $by = null): void
    {
        $this->forceFill([
            'is_active' => false,
            'released_on' => now()->toDateString(),
            'released_by' => $by?->getKey(),
            'release_reason' => $reason,
        ])->save();
    }

    public function isOverdueForReview(): bool
    {
        return $this->is_active
            && $this->review_on !== null
            && $this->review_on->isPast();
    }

    /**
     * Whether an active hold covers a given record.
     *
     * Three ways a hold can bite, checked cheapest first:
     *   1. it names this exact record
     *   2. it covers the record's whole retention class
     *   3. it covers that class narrowed to a scope the record belongs to
     */
    public static function covers(Model $record, string $retentionClass, ?string $scopeKey = null): ?self
    {
        return static::query()
            ->where('is_active', true)
            ->where(function (Builder $query) use ($record, $retentionClass, $scopeKey): void {
                $query
                    ->where(fn (Builder $q) => $q
                        ->where('holdable_type', $record->getMorphClass())
                        ->where('holdable_id', $record->getKey()))
                    ->orWhere(fn (Builder $q) => $q
                        ->where('retention_class', $retentionClass)
                        ->whereNull('scope_key'))
                    ->when($scopeKey !== null, fn (Builder $q) => $q
                        ->orWhere(fn (Builder $inner) => $inner
                            ->where('retention_class', $retentionClass)
                            ->where('scope_key', $scopeKey)));
            })
            ->first();
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    #[Scope]
    protected function needingReview(Builder $query): void
    {
        $query->where('is_active', true)
            ->whereNotNull('review_on')
            ->whereDate('review_on', '<=', now())
            ->orderBy('review_on');
    }
}
