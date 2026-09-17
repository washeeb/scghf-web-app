<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RecordsAuthor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * A navigation menu. Resolved by key: `menu('header')`.
 *
 * @property string $key
 * @property int $max_depth
 */
class Menu extends Model
{
    use RecordsAuthor;

    protected $fillable = ['key', 'name', 'description', 'is_locked', 'max_depth'];

    protected function casts(): array
    {
        return ['is_locked' => 'boolean', 'max_depth' => 'integer'];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $menu): void {
            if ($menu->is_locked) {
                throw new RuntimeException(
                    "The [{$menu->name}] menu is referenced by the site layout. "
                    .'Remove its items instead of deleting the menu.'
                );
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    /** @return HasMany<MenuItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class)->orderBy('sort_order');
    }

    /** @return HasMany<MenuItem, $this> */
    public function rootItems(): HasMany
    {
        return $this->items()->whereNull('parent_id');
    }

    /**
     * The renderable tree for a menu key, or an empty collection.
     *
     * ── Why this never throws ───────────────────────────────────────────────
     *
     * The header calls this on every page render. A missing menu — the seeder
     * has not run on a fresh environment, somebody renamed a key, the table
     * does not exist yet during `migrate:fresh` — must produce a header with no
     * navigation, not a 500 on the home page.
     *
     * A site with a bare header is visibly wrong and somebody fixes it. A site
     * that will not load is an outage.
     *
     * Items whose destination has gone are dropped by `isRenderable()`, so a
     * link into the site's own navigation can never 404.
     *
     * @return Collection<int, MenuItem>
     */
    public static function renderable(string $key, bool $authenticated = false): Collection
    {
        try {
            $menu = static::query()->where('key', $key)->first();

            if ($menu === null) {
                return new Collection;
            }

            return $menu->tree($authenticated)
                ->filter(fn (MenuItem $item): bool => $item->isRenderable())
                ->values();
        } catch (\Throwable) {
            return new Collection;
        }
    }

    /**
     * The renderable tree for a given auth state.
     *
     * Loads the whole menu in ONE query and assembles the tree in memory. The
     * obvious alternative — eager-loading `children.children` — issues a query
     * per level, which is how navigation quietly becomes the slowest part of a
     * layout. The header renders on every page, so this is the hot path.
     *
     * @return Collection<int, MenuItem>
     */
    public function tree(bool $authenticated = false): Collection
    {
        $audiences = ['all', $authenticated ? 'auth' : 'guest'];

        $items = $this->items()
            ->with('page:id,title,path,status,published_at')
            ->where('is_visible', true)
            ->whereIn('visible_to', $audiences)
            ->orderBy('sort_order')
            ->get();

        $byParent = $items->groupBy('parent_id');

        $attach = function (Collection $nodes) use (&$attach, $byParent): Collection {
            return $nodes->each(function (MenuItem $item) use ($attach, $byParent): void {
                $item->setRelation('children', $attach($byParent->get($item->id, new Collection)));
            });
        };

        return $attach($byParent->get(null, new Collection)->values());
    }
}
