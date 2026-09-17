<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use RuntimeException;

/**
 * A temporary override of a flag declared in config/features.php.
 *
 * ── An override, never a new flag ───────────────────────────────────────────
 *
 * `config/features.php` remains the source of truth for which flags exist. This
 * table says only "that one, currently, is off". A row naming a flag the config
 * has never heard of is refused — a switch wired to nothing is worse than no
 * switch, because somebody will believe it did something.
 *
 * The reason config keeps that authority is the argument already written in
 * that file: flags are read with `config()`, reviewed like code, and their
 * history lives in git where it can be produced. What the database adds is the
 * thing config genuinely cannot do — turning something off at nine on a
 * Saturday evening, without a deployment, with a reason and a named person
 * attached.
 *
 * ── Every override expires, unless somebody chooses otherwise ───────────────
 *
 * `expires_at` is the column that stops "temporarily disable the shop while we
 * sort out the courier" from quietly becoming the permanent state of the site.
 * A null expiry is allowed, but it is the deliberate choice rather than the
 * easy one.
 */
class FeatureFlag extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = ['key', 'is_enabled', 'reason', 'expires_at', 'is_locked', 'changed_by'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_locked' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_locked' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $flag): void {
            if (! array_key_exists($flag->key, config('features', []))) {
                throw new InvalidArgumentException(
                    "There is no feature flag called [{$flag->key}]. Flags are declared in "
                    .'config/features.php; this table only overrides one that already exists. '
                    .'A switch wired to nothing is worse than no switch, because somebody will '
                    .'believe it did something.'
                );
            }

            if (trim((string) $flag->reason) === '') {
                throw new InvalidArgumentException(
                    'Changing a feature flag needs a reason — for turning one on as much as off. '
                    .'Three months later, "enabled the shop" with no reason is '
                    .'indistinguishable from a mis-click.'
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

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * Whether this override is still in force.
     *
     * Read from the dates on every call, never from a cached flag — the same
     * stance the GRA approval and the safeguarding clearances take, and for the
     * same reason: nothing about what the site does should depend on a cron job
     * having run last night.
     */
    public function isInForce(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    public function hasLapsed(): bool
    {
        return ! $this->isInForce();
    }

    /**
     * A sentence for the dashboard.
     *
     * Sentences rather than a toggle state, because the person reading this is
     * usually asking why part of the site is missing.
     */
    public function explanation(): string
    {
        $verb = $this->is_enabled ? 'switched on' : 'switched off';

        if ($this->hasLapsed()) {
            return sprintf(
                'Was %s (%s), but that override lapsed on %s — the configured default applies now.',
                $verb, $this->reason, $this->expires_at?->format('j M Y'),
            );
        }

        return sprintf(
            '%s: %s.%s',
            ucfirst($verb),
            $this->reason,
            $this->expires_at === null
                ? ' This override does not expire.'
                : ' Reverts on '.$this->expires_at->format('j M Y').'.',
        );
    }

    /**
     * Overrides that have lapsed and are doing nothing.
     *
     * Surfaced so somebody can delete them, because a list full of expired
     * rows is a list nobody reads — and the one that matters is in there.
     */
    #[Scope]
    protected function lapsed(Builder $query): void
    {
        $query->whereNotNull('expires_at')->where('expires_at', '<=', now());
    }

    #[Scope]
    protected function inForce(Builder $query): void
    {
        $query->where(function (Builder $q): void {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    /**
     * Locked flags. Never overridable from an admin screen.
     *
     * `donations` is the example worth stating: turning donations off has
     * financial and reputational consequences and should require a deployment
     * by somebody who has thought about it, not a toggle next to "dark mode".
     *
     * @return array<int, string>
     */
    public static function lockedKeys(): array
    {
        return ['donations'];
    }

    public static function assertOverridable(string $key): void
    {
        if (in_array($key, self::lockedKeys(), true)) {
            throw new RuntimeException(
                "The [{$key}] flag cannot be changed from the admin panel. Turning it off has "
                .'consequences that deserve a deployment and somebody who has thought about them.'
            );
        }
    }
}
