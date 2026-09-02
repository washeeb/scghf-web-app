<?php

declare(strict_types=1);

namespace App\Models;

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
    protected $fillable = ['key', 'name', 'description', 'is_locked', 'max_depth'];

    /** @return array<string, string> */
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
