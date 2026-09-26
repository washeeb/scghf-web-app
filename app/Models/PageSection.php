<?php

declare(strict_types=1);

namespace App\Models;

use App\Blocks\BlockDefinition;
use App\Blocks\BlockRegistry;
use App\Blocks\SectionSettings;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One block placed on one page.
 *
 * @property string $block_type
 * @property array<string, mixed>|null $data
 */
class PageSection extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'page_id', 'block_type', 'name', 'data', 'settings',
        'sort_order', 'is_visible', 'visible_from', 'visible_until',
    ];

    /**
     * Mirrors the migration's column defaults — see Page::$attributes for why
     * a model default and a column default must always be declared together.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sort_order' => 0,
        'is_visible' => true,
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'settings' => 'array',
            'is_visible' => 'boolean',
            'visible_from' => 'datetime',
            'visible_until' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        /*
         * A block that changes type does not keep the old block's fields.
         *
         * `data` is shaped by whichever block definition the row carries, so a
         * row whose type moved from `hero` to `rich-text` while keeping its
         * data holds `overlay_opacity` and no `body` — every field silently
         * wrong, and nothing anywhere reporting it. The view would render an
         * empty block and the editor would see a bug rather than a mistake.
         *
         * Enforced in the model rather than by disabling the admin field,
         * because disabling it turned out to make adding a block impossible —
         * Filament omits disabled fields from the submitted state — and because
         * a guard here also holds for a seeder, an import and a console
         * command.
         *
         * Not destructive in practice: `EditPage` snapshots the page before
         * every save, so an editor who changes a type by accident restores it.
         */
        static::updating(function (self $section): void {
            if (! $section->isDirty('block_type')) {
                return;
            }

            $section->data = $section->definition()?->defaults() ?? [];
        });
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /** The PHP definition backing this section, or null if the block was removed from the registry. */
    public function definition(): ?BlockDefinition
    {
        $registry = app(BlockRegistry::class);

        return $registry->has($this->block_type) ? $registry->get($this->block_type) : null;
    }

    /**
     * A field from `data`, falling back to the block's declared default.
     *
     * Going through the definition means a block gaining a field does not break
     * pages saved before that field existed — they get the default rather than
     * null, and the view does not have to defend against every key.
     */
    public function field(string $name, mixed $default = null): mixed
    {
        $value = data_get($this->data, $name);

        if ($value !== null && $value !== '') {
            return $value;
        }

        return $this->definition()?->defaults()[$name] ?? $default;
    }

    /**
     * How this section looks, as a value object rather than a raw array.
     *
     * Named `presentation()` rather than `settings()` on purpose: a method with
     * the same name as a column is one Eloquent has to disambiguate, and the
     * rule it uses — attribute first, then relation — is not obvious to a
     * reader and would break the day somebody made it a relationship.
     *
     * Every block view asks this for its classes rather than reading the column
     * itself, so the closed vocabulary in `SectionSettings` is the only way a
     * stored value reaches a class attribute — and a `settings` column edited
     * by hand can produce nothing the file does not already contain.
     */
    public function presentation(): SectionSettings
    {
        return new SectionSettings($this->settings);
    }

    public function displayName(): string
    {
        return $this->name
            ?: $this->definition()?->name
            ?: ucfirst(str_replace('-', ' ', $this->block_type));
    }

    /**
     * Whether this section renders right now.
     *
     * A block whose type has vanished from the registry is treated as NOT
     * renderable rather than throwing: removing a block from the code must not
     * take down every page that still has one placed.
     */
    public function isRenderable(): bool
    {
        if (! $this->is_visible || $this->definition() === null) {
            return false;
        }

        if ($this->visible_from !== null && $this->visible_from->isFuture()) {
            return false;
        }

        return $this->visible_until === null || $this->visible_until->isFuture();
    }

    public function view(): ?string
    {
        return $this->definition()?->view();
    }

    #[Scope]
    protected function visible(Builder $query): void
    {
        $query->where('is_visible', true)
            ->where(fn (Builder $q) => $q->whereNull('visible_from')->orWhere('visible_from', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('visible_until')->orWhere('visible_until', '>', now()))
            ->orderBy('sort_order');
    }
}
