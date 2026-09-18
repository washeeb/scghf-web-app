<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\SettingType;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Read/write access to the settings table, cached as a single array.
 *
 * Registered as a singleton and reached through the `setting()` helper.
 *
 * **Why one cache entry for the whole table rather than one per key.** A page
 * render touches the header, footer, contact block, socials, donation presets
 * and SEO defaults — twenty-plus keys. With per-key caching that is twenty-plus
 * round trips to the `cache` table on every request, and on shared hosting the
 * cache store IS the database (no Redis). One query, one array, one hydration.
 * The table is a few hundred rows at most, so the memory cost is trivial.
 *
 * The in-process `$loaded` array means repeated reads inside one request do not
 * even hit the cache driver.
 */
class Settings
{
    public const CACHE_KEY = 'scghf.settings.all';

    /** @var array<string, mixed>|null */
    private ?array $loaded = null;

    /**
     * A setting's value, cast to its declared type.
     *
     * `$key` is `group.key`. An unknown key returns `$default` rather than
     * throwing — a missing setting must not take a page down, and the preflight
     * command is what surfaces gaps.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        if (! array_key_exists($key, $all)) {
            return $default;
        }

        $value = $all[$key];

        // An unfilled placeholder is treated as absent. Rendering
        // "{{PHONE_PRIMARY}}" to a donor is worse than rendering nothing.
        if (is_string($value) && preg_match('/\{\{[A-Z_]+\}\}/', $value)) {
            return $default;
        }

        return $value ?? $default;
    }

    /** True when the setting is present, non-null and not an unfilled placeholder. */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Write a setting and bust the cache.
     *
     * Creates the row if it does not exist, so a new setting can be introduced
     * by a seeder line without a migration.
     */
    public function set(string $key, mixed $value, ?SettingType $type = null): Setting
    {
        [$group, $name] = $this->split($key);

        $setting = Setting::firstOrNew(['group' => $group, 'key' => $name]);

        if (! $setting->exists) {
            $setting->type = $type ?? SettingType::String;
            $setting->label = str($name)->replace('_', ' ')->title()->toString();
        } elseif ($type !== null) {
            $setting->type = $type;
        }

        $setting->setTypedValue($value);
        $setting->save();

        $this->flush();

        return $setting;
    }

    /**
     * Every setting in a group, keyed by its short name.
     *
     * @return array<string, mixed>
     */
    public function group(string $group): array
    {
        $prefix = $group.'.';

        return collect($this->all())
            ->filter(fn (mixed $v, string $k): bool => str_starts_with($k, $prefix))
            ->mapWithKeys(fn (mixed $v, string $k): array => [substr($k, strlen($prefix)) => $v])
            ->all();
    }

    /**
     * Only the settings marked safe for the browser.
     *
     * Used to build the payload shared with Blade and Alpine. Anything not
     * explicitly public never leaves the server — the default is closed.
     *
     * @return array<string, mixed>
     */
    public function publicValues(): array
    {
        return Cache::rememberForever(self::CACHE_KEY.'.public', fn (): array => Setting::query()
            ->public()
            ->get()
            ->mapWithKeys(fn (Setting $s): array => [$s->qualifiedKey() => $s->typedValue()])
            ->all());
    }

    /**
     * The whole table, cast, keyed `group.key`.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->loaded ??= Cache::rememberForever(
            self::CACHE_KEY,
            fn (): array => Setting::query()
                ->get()
                ->mapWithKeys(fn (Setting $s): array => [$s->qualifiedKey() => $s->typedValue()])
                ->all(),
        );
    }

    /**
     * Settings still holding a placeholder or nothing.
     *
     * Feeds `php artisan scghf:preflight` — see PHASE-1-BLUEPRINT.md §11.6.4.
     * Being able to answer "what is still fake?" mechanically is what makes
     * "fill it in later" a safe strategy rather than a way to launch a
     * half-configured donation site.
     *
     * @return Collection<int, Setting>
     */
    public function unfilled(): Collection
    {
        return Setting::query()
            ->orderBy('group')
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (Setting $s): bool => $s->isUnfilled())
            ->values();
    }

    public function flush(): void
    {
        $this->loaded = null;
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::CACHE_KEY.'.public');

        // A setting is on every page: the phone number in the footer, the
        // theme, the presets. Every cached fragment and page is now stale.
        SiteCache::bump();
    }

    /** @return array{0: string, 1: string} */
    private function split(string $key): array
    {
        if (! str_contains($key, '.')) {
            throw new \InvalidArgumentException(
                "Setting key must be 'group.key', got '{$key}'."
            );
        }

        [$group, $name] = explode('.', $key, 2);

        return [$group, $name];
    }
}
