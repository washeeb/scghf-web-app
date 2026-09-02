<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MenuItemLinkType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/**
 * @property MenuItemLinkType $link_type
 * @property string $label
 */
class MenuItem extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'menu_id', 'parent_id', 'label', 'link_type', 'page_id', 'route_name', 'url',
        'linkable_type', 'linkable_id', 'icon', 'is_highlighted', 'opens_in_new_tab',
        'visible_to', 'sort_order', 'is_visible',
    ];

    /**
     * Mirrors the migration's column defaults — see Page::$attributes for why
     * a model default and a column default must always be declared together.
     * `is_visible` being null in memory made isRenderable() return false for a
     * item that was perfectly valid.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'link_type' => 'page',
        'visible_to' => 'all',
        'sort_order' => 0,
        'is_visible' => true,
        'is_highlighted' => false,
        'opens_in_new_tab' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'link_type' => MenuItemLinkType::class,
            'is_highlighted' => 'boolean',
            'opens_in_new_tab' => 'boolean',
            'is_visible' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            if ($item->parent_id !== null) {
                $item->assertNestingIsValid();
            }

            // An external link opening in the same tab loses the visitor —
            // and on a donation page that is a real cost.
            if ($item->link_type === MenuItemLinkType::External && $item->isDirty('link_type')) {
                $item->opens_in_new_tab = true;
            }
        });
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /** @return BelongsTo<Menu, $this> */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /** @return BelongsTo<MenuItem, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<MenuItem, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    /** @return MorphTo<Model, $this> */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    // ── Resolution ───────────────────────────────────────────────────────────

    /**
     * Where this item points.
     *
     * A page link resolves THROUGH the relation, so renaming a page's slug
     * updates every menu pointing at it. Returns null when the destination is
     * gone, so the renderer can omit the item rather than emit a dead link.
     */
    public function resolveUrl(): ?string
    {
        return match ($this->link_type) {
            MenuItemLinkType::Page => $this->page?->path,
            MenuItemLinkType::External => $this->url,
            MenuItemLinkType::Route => $this->route_name !== null && Route::has($this->route_name)
                ? route($this->route_name)
                : null,
            MenuItemLinkType::Entity => $this->linkable?->path,
            MenuItemLinkType::Heading => null,
        };
    }

    /**
     * Whether to render this item at all.
     *
     * A heading with no children is noise. A link whose target has been deleted
     * or unpublished is worse than noise — it is a visitor hitting a 404 from
     * the site's own navigation.
     */
    public function isRenderable(): bool
    {
        if (! $this->is_visible) {
            return false;
        }

        if ($this->link_type === MenuItemLinkType::Heading) {
            return $this->children->isNotEmpty();
        }

        if ($this->link_type === MenuItemLinkType::Page) {
            return $this->page !== null && $this->page->isLive();
        }

        return $this->resolveUrl() !== null;
    }

    /**
     * Depth and cycle checks.
     *
     * Enforced on the model rather than in the admin form so it also holds for
     * seeders and imports. A menu nested deeper than the layout can render
     * would silently lose its deepest items — the worst kind of bug, because
     * the admin sees the item saved and the visitor never sees it at all.
     */
    private function assertNestingIsValid(): void
    {
        $depth = 1;
        $parent = static::find($this->parent_id);
        $guard = 0;

        while ($parent !== null && $guard++ < 20) {
            if ($this->exists && $parent->getKey() === $this->getKey()) {
                throw new RuntimeException('A menu item cannot be its own ancestor.');
            }

            $depth++;
            $parent = $parent->parent_id !== null ? static::find($parent->parent_id) : null;
        }

        $max = Menu::find($this->menu_id)?->max_depth ?? 1;

        // max_depth 1 means "root plus one level of children", i.e. depth 2.
        if ($depth > $max + 1) {
            throw new RuntimeException(
                "This menu supports {$max} level(s) of nesting; the layout cannot render deeper."
            );
        }
    }
}
