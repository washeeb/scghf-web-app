<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A daily count. There is no visitor record anywhere, and that is the design.
 *
 * No IP address, no fingerprint, no cross-site identifier, no per-visitor row —
 * not as a policy somebody could relax next year, but because this schema has
 * nowhere to put one. Privacy-respecting by construction rather than by
 * intention.
 *
 * ── What that costs, stated plainly ─────────────────────────────────────────
 *
 * This application cannot report unique visitors, and somebody will eventually
 * ask for that number. It reports VIEWS, and SESSIONS counted from the session
 * the application already creates for its own reasons. Nothing here can tell
 * one person on two devices from two people, and making it able to would mean
 * minting an identifier for people who did not ask to be counted — which is a
 * larger cost than the number is worth to a foundation.
 *
 * ── No ULID ─────────────────────────────────────────────────────────────────
 *
 * These rows are aggregates, never addressed individually and never in a URL.
 * A public identifier for "views of /about on 3 September" would be twenty-six
 * bytes and a unique index protecting nothing.
 */
class VisitorStat extends Model
{
    use HasFactory;

    public const DIMENSION_TOTAL = 'total';

    public const DIMENSION_PATH = 'path';

    public const DIMENSION_REFERRER = 'referrer_host';

    public const DIMENSION_DEVICE = 'device_type';

    protected $fillable = ['date', 'dimension', 'value', 'views', 'sessions'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'dimension' => self::DIMENSION_TOTAL,
        'value' => '',
        'views' => 0,
        'sessions' => 0,
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'views' => 'integer',
            'sessions' => 'integer',
        ];
    }

    /**
     * Add one to a counter, creating the row if it is the first today.
     *
     * An upsert with a raw increment: many requests land in the same second and
     * a read-modify-write would lose most of them. `$newSession` is passed in
     * rather than inferred, because only the caller knows whether this request
     * is the first of its session.
     */
    public static function increment_(string $dimension, string $value, bool $newSession, ?Carbon $date = null): void
    {
        $date ??= now();

        DB::table('visitor_stats')->upsert(
            [[
                'date' => $date->toDateString(),
                'dimension' => $dimension,
                'value' => mb_substr($value, 0, 191),
                'views' => 1,
                'sessions' => $newSession ? 1 : 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['date', 'dimension', 'value'],
            [
                'views' => DB::raw('views + 1'),
                'sessions' => DB::raw('sessions + '.($newSession ? 1 : 0)),
                'updated_at' => DB::raw('VALUES(updated_at)'),
            ],
        );
    }

    /**
     * Whether this dimension has already collected as many distinct values as
     * it is allowed to today.
     *
     * A site being scraped by a misbehaving crawler can produce ten thousand
     * distinct paths in an afternoon, and this table is not the place to absorb
     * that. Past the ceiling, further values are folded into `(other)` rather
     * than dropped — the total still adds up, which matters more than knowing
     * which of ten thousand junk URLs was hit.
     */
    public static function dimensionIsFull(string $dimension, ?Carbon $date = null): bool
    {
        $max = (int) config('system.visitors.max_values_per_dimension', 200);
        $day = ($date ?? now())->toDateString();

        // A COUNT per dimension per page view was three queries on every
        // request to answer a question whose answer changes once a day at
        // most. Five minutes stale at the cap costs at worst a few extra rows.
        $count = Cache::remember("visitors:count:{$dimension}:{$day}", 300, fn (): int => static::query()
            ->whereDate('date', $day)
            ->where('dimension', $dimension)
            ->count());

        return $count >= $max;
    }

    // ── Reading ──────────────────────────────────────────────────────────────

    public static function viewsBetween(Carbon $from, Carbon $to): int
    {
        return (int) static::query()
            ->where('dimension', self::DIMENSION_TOTAL)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->sum('views');
    }

    public static function sessionsBetween(Carbon $from, Carbon $to): int
    {
        return (int) static::query()
            ->where('dimension', self::DIMENSION_TOTAL)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->sum('sessions');
    }

    /**
     * The most-viewed values for a dimension over a period.
     *
     * @return Collection<int, object{value: string, views: int}>
     */
    public static function top(string $dimension, Carbon $from, Carbon $to, int $limit = 10): Collection
    {
        return static::query()
            ->selectRaw('value, SUM(views) as views')
            ->where('dimension', $dimension)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('value')
            ->orderByDesc('views')
            ->limit($limit)
            ->get()
            ->map(fn (self $row): object => (object) [
                'value' => (string) $row->value,
                'views' => (int) $row->views,
            ]);
    }

    /**
     * The share of views from mobile devices, as a percentage.
     *
     * The one number in this table that changes engineering decisions. This
     * foundation's readers are disproportionately on low-end Android handsets
     * on slow connections, and that is what justifies the performance budget in
     * `CLAUDE.md` — an LCP target measured on simulated 3G is a strange thing
     * to defend without a figure behind it.
     */
    public static function mobileSharePercent(Carbon $from, Carbon $to): ?int
    {
        $rows = static::query()
            ->selectRaw('value, SUM(views) as views')
            ->where('dimension', self::DIMENSION_DEVICE)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('value')
            ->pluck('views', 'value');

        $total = (int) $rows->sum();

        return $total === 0 ? null : (int) round(((int) $rows->get('mobile', 0)) / $total * 100);
    }

    #[Scope]
    protected function forDimension(Builder $query, string $dimension): void
    {
        $query->where('dimension', $dimension);
    }
}
