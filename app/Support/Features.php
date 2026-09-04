<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\FeatureFlag;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Answers "is this feature on?" — config first, database override second.
 *
 * ── Why the order is that way round ─────────────────────────────────────────
 *
 * `config/features.php` decides which flags EXIST and what each one defaults
 * to. The database can only say "that one, currently, is different", for a
 * stated reason, by a named person, usually with an expiry.
 *
 * Config keeps the authority because flags are reviewed like code and their
 * history is in git where it can be produced. The database gets the override
 * because config cannot be changed at nine on a Saturday evening without a
 * deployment, and sometimes that is exactly what is needed.
 *
 * ── It answers from config when the database cannot be reached ──────────────
 *
 * A failed lookup returns the configured default rather than throwing or
 * returning false. During a database outage the honest answer to "is the shop
 * enabled?" is whatever was deployed — not "no", which would take working
 * parts of the site down on top of the outage.
 *
 * ── Cached per request, not across requests ─────────────────────────────────
 *
 * A flag is read many times in one page render and must not be many queries.
 * It is deliberately NOT cached beyond the request: an override switched on
 * during an incident has to take effect on the next page load, not whenever a
 * cache happens to expire.
 */
class Features
{
    /** @var array<string, bool>|null */
    private ?array $overrides = null;

    public function enabled(string $key): bool
    {
        $default = (bool) config("features.{$key}", false);

        return $this->overrides()[$key] ?? $default;
    }

    public function disabled(string $key): bool
    {
        return ! $this->enabled($key);
    }

    /**
     * Every flag with its effective value, for the admin screen.
     *
     * Built from CONFIG's key list, not the database's, so a flag that exists
     * in code but has never been overridden still appears — otherwise the
     * screen would only ever show the flags somebody had already touched.
     *
     * @return array<string, bool>
     */
    public function all(): array
    {
        $effective = [];

        foreach (array_keys((array) config('features', [])) as $key) {
            $effective[(string) $key] = $this->enabled((string) $key);
        }

        return $effective;
    }

    /**
     * Override a flag.
     *
     * The reason and the expiry are both first-class arguments rather than
     * options, so writing this call means thinking about both. `$expiresInDays`
     * defaults to null, which is a permanent override — allowed, but it has to
     * be written down as the choice it is.
     */
    public function override(
        string $key,
        bool $enabled,
        string $reason,
        User $actor,
        ?int $expiresInDays = null,
    ): FeatureFlag {
        FeatureFlag::assertOverridable($key);

        $flag = FeatureFlag::updateOrCreate(
            ['key' => $key],
            [
                'is_enabled' => $enabled,
                'reason' => $reason,
                'expires_at' => $expiresInDays === null ? null : now()->addDays($expiresInDays),
                'changed_by' => $actor->getKey(),
            ],
        );

        $this->overrides = null;

        app(AuditLogger::class)->record(
            event: 'feature_flag.changed',
            description: sprintf(
                '%s the [%s] feature: %s%s',
                $enabled ? 'Enabled' : 'Disabled',
                $key,
                $reason,
                $expiresInDays === null ? ' (no expiry).' : " (reverts in {$expiresInDays} days).",
            ),
            subject: $flag,
            causer: $actor,
        );

        return $flag;
    }

    /**
     * Remove an override so the configured default applies again.
     */
    public function clearOverride(string $key, User $actor): void
    {
        FeatureFlag::where('key', $key)->delete();
        $this->overrides = null;

        app(AuditLogger::class)->record(
            event: 'feature_flag.changed',
            description: "Removed the override on [{$key}]; the configured default applies again.",
            causer: $actor,
        );
    }

    /**
     * The overrides currently in force, keyed by flag.
     *
     * A lapsed override is simply not loaded, so nothing has to run to expire
     * one. That matters on a host where the scheduler is a cron line somebody
     * may not have set up yet — an expiry that depends on a job having run is
     * an expiry that silently does not happen.
     *
     * @return array<string, bool>
     */
    private function overrides(): array
    {
        if ($this->overrides !== null) {
            return $this->overrides;
        }

        try {
            return $this->overrides = FeatureFlag::query()
                ->inForce()
                ->pluck('is_enabled', 'key')
                ->map(fn ($value): bool => (bool) $value)
                ->all();
        } catch (Throwable $e) {
            /*
             * The table may not exist yet (a deploy mid-migration) or the
             * database may be unreachable. Fall back to config: during an
             * outage the honest answer is whatever was deployed, and answering
             * "everything is off" would take working parts of the site down on
             * top of the outage.
             */
            Log::warning('Feature flag overrides could not be read; using the configured defaults.', [
                'error' => $e->getMessage(),
            ]);

            return $this->overrides = [];
        }
    }

    /** Forget what was loaded. For tests, and after a change. */
    public function flush(): void
    {
        $this->overrides = null;
    }
}
