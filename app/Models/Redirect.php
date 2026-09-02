<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A redirect, hand-written or captured from a 404.
 *
 * @property string $from_path
 * @property string|null $to_path
 * @property int $status_code
 */
class Redirect extends Model
{
    protected $fillable = [
        'from_path', 'to_path', 'status_code', 'source',
        'is_active', 'preserve_query', 'notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status_code' => 301,
        'source' => 'manual',
        'is_active' => true,
        'preserve_query' => false,
        'hits' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'preserve_query' => 'boolean',
            'status_code' => 'integer',
            'last_hit_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $redirect): void {
            $redirect->from_path = self::normalise($redirect->from_path);

            if ($redirect->to_path !== null && ! str_starts_with($redirect->to_path, 'http')) {
                $redirect->to_path = self::normalise($redirect->to_path);
            }

            if ($redirect->from_path === $redirect->to_path) {
                throw new RuntimeException('A redirect cannot point at itself.');
            }

            // 410 Gone says "this is deliberately removed" and needs no target.
            // Anything else without a target would send a visitor nowhere.
            if ($redirect->status_code !== 410 && blank($redirect->to_path)) {
                throw new RuntimeException('A redirect needs a destination unless it returns 410 Gone.');
            }

            $redirect->assertNoLoop();
        });
    }

    /**
     * Trailing slashes stripped, leading slash guaranteed, case preserved.
     *
     * Without this, '/about', '/about/' and 'about' are three different rows,
     * and whichever the visitor happens to type decides whether the redirect
     * fires at all.
     */
    public static function normalise(string $path): string
    {
        $path = trim($path);

        if (str_starts_with($path, 'http')) {
            return $path;
        }

        $path = '/'.ltrim($path, '/');
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    /**
     * Resolve a path, following chains to their destination.
     *
     * A → B → C returns C rather than issuing two round trips. Two redirects in
     * a row costs a mobile visitor a full extra request, and search engines
     * dilute the signal at each hop.
     */
    public static function resolve(string $path): ?self
    {
        $path = self::normalise($path);
        $seen = [];
        $redirect = static::query()->active()->where('from_path', $path)->first();

        if ($redirect === null) {
            return null;
        }

        $final = $redirect;

        while ($final->to_path !== null && ! in_array($final->to_path, $seen, true)) {
            $seen[] = $final->to_path;

            $next = static::query()->active()->where('from_path', $final->to_path)->first();

            if ($next === null) {
                break;
            }

            $final = $next;
        }

        // Report the original row's status code but the chain's destination.
        return $redirect->status_code === $final->status_code
            ? $final
            : tap($redirect)->setAttribute('to_path', $final->to_path);
    }

    public function recordHit(?string $referrer = null): void
    {
        // Atomic increment. A read-modify-write here loses hits under any real
        // traffic, and the count is the only signal for which redirects matter.
        static::whereKey($this->getKey())->update([
            'hits' => \DB::raw('hits + 1'),
            'last_hit_at' => now(),
            'last_referrer' => $referrer,
        ]);
    }

    /**
     * Record a 404 so an editor can turn it into a redirect.
     *
     * Stored inactive with no destination: it is a REPORT of a broken path, not
     * a redirect, and must never fire until someone says where it should go.
     */
    public static function record404(string $path, ?string $referrer = null): ?self
    {
        $path = self::normalise($path);

        // Ignore the noise. Scanners probe thousands of paths a day and would
        // otherwise fill this table with WordPress URLs this site never had.
        if (preg_match('#^/(wp-|xmlrpc|\.env|\.git|vendor/|admin\.php)#i', $path)) {
            return null;
        }

        $existing = static::where('from_path', $path)->first();

        if ($existing !== null) {
            $existing->recordHit($referrer);

            return $existing;
        }

        $redirect = new self;
        $redirect->forceFill([
            'from_path' => $path,
            'to_path' => null,
            'status_code' => 410,
            'source' => 'auto_404',
            'is_active' => false,
            'hits' => 1,
            'last_hit_at' => now(),
            'last_referrer' => $referrer,
        ]);
        $redirect->saveQuietly();

        return $redirect;
    }

    /**
     * A → B → A would bounce a browser until it gives up.
     */
    private function assertNoLoop(): void
    {
        if ($this->to_path === null) {
            return;
        }

        $seen = [$this->from_path];
        $next = $this->to_path;
        $guard = 0;

        while ($next !== null && $guard++ < 20) {
            if (in_array($next, $seen, true)) {
                throw new RuntimeException("This redirect creates a loop via [{$next}].");
            }

            $seen[] = $next;

            $row = static::query()
                ->when($this->exists, fn (Builder $q) => $q->whereKeyNot($this->getKey()))
                ->where('from_path', $next)
                ->first();

            $next = $row?->to_path;
        }
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** Captured 404s awaiting a decision — the editor's work queue. */
    #[Scope]
    protected function unresolved404s(Builder $query): void
    {
        $query->where('source', 'auto_404')
            ->where('is_active', false)
            ->orderByDesc('hits');
    }
}
