<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * The fragment cache, and the one number that empties it.
 *
 * ── Why a generation, not keys ─────────────────────────────────────────────
 *
 * The obvious design is a key per thing — `menu:header`, `announcement`,
 * `home:blocks` — and code on every save that forgets the right ones. It
 * fails the first time somebody adds a fragment and forgets the forget, and
 * the failure is a stale menu that nobody reports for a week because it
 * looks like the site.
 *
 * So every key here carries a generation number, and any content save bumps
 * the number. A bump costs one cache write; the old entries become
 * unreachable and age out. Over-invalidation is the deliberate trade: an
 * editor saving a post clears the menus too, which costs one extra render
 * of each on the next request and nothing else. On shared hosting with no
 * Redis, a cache that is sometimes cold is fine; a cache that is sometimes
 * wrong is a support ticket.
 *
 * The same number keys the full-page cache (CachePublicPage), so a publish
 * reaches the whole site in one write.
 */
final class SiteCache
{
    private const GENERATION_KEY = 'site:generation';

    private static ?int $generation = null;

    private static bool $bumped = false;

    private static bool $consultedSinceBump = false;

    /**
     * Remember a fragment under the current generation.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function remember(string $key, Closure $callback, ?int $ttl = null): mixed
    {
        return self::store()->remember(
            self::key($key),
            $ttl ?? (int) config('performance.fragments.ttl', 3600),
            $callback,
        );
    }

    public static function key(string $key): string
    {
        return 'site:'.self::generation().':'.$key;
    }

    /** The current generation, for anything that builds its own keys on it. */
    public static function generation(): int
    {
        self::$consultedSinceBump = true;

        return self::current();
    }

    /**
     * Something the public site shows has changed: start a new generation.
     *
     * Clock-based (milliseconds) rather than incremented, so two processes
     * bumping at once cannot land on the same number, and so a cache store
     * that lost the key (a `cache:clear`, a file store wiped by a deploy)
     * still moves forward rather than back to 1 and onto keys that may
     * still exist. The `+ 1` floor keeps it strictly increasing when a
     * seeder bumps a thousand times in a second.
     *
     * An import saving five hundred rows is one change to the site, not
     * five hundred cache writes: within an HTTP request a second bump is
     * skipped while nothing in the request has read the generation since
     * the first — no public page renders inside an admin request, so the
     * number it set is already newer than anything cached. A console
     * process (the scheduler, a worker, a seeder) bumps every time: other
     * processes render between its saves, and a write is cheaper than a
     * stale page for ten minutes.
     */
    public static function bump(): void
    {
        if (self::$bumped && ! self::$consultedSinceBump && ! app()->runningInConsole()) {
            return;
        }

        $next = max((int) floor(microtime(true) * 1000), self::current() + 1);

        self::store()->forever(self::GENERATION_KEY, $next);
        self::$generation = $next;
        self::$bumped = true;
        self::$consultedSinceBump = false;
    }

    /** Forget the in-process state — tests and long-running workers. */
    public static function flush(): void
    {
        self::$generation = null;
        self::$bumped = false;
        self::$consultedSinceBump = false;
    }

    private static function current(): int
    {
        return self::$generation ??= (int) self::store()->rememberForever(self::GENERATION_KEY, fn (): int => 1);
    }

    /**
     * Files by default, whatever the application's default store: a menu
     * fragment read from the `cache` table is a database round trip, which
     * is the cost the fragment exists to avoid. Arrays and scalars only —
     * the store never unserializes an object (`cache.serializable_classes`).
     */
    private static function store(): Repository
    {
        return Cache::store((string) config('performance.fragments.store', 'file'));
    }
}
