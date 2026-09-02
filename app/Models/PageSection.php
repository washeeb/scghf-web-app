<?php

declare(strict_types=1);

namespace App\Models;

use App\Blocks\BlockDefinition;
use App\Blocks\BlockRegistry;
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
        'page_id', 'block_type', 'name', 'data',
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'is_visible' => 'boolean',
            'visible_from' => 'datetime',
            'visible_until' => 'datetime',
        ];
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
