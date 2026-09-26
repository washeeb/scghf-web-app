<?php

declare(strict_types=1);

namespace App\Models;

use App\Blocks\BlockDefinition;
use App\Blocks\BlockRegistry;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin-controlled availability for a block. The definition lives in PHP.
 *
 * @property string $key
 * @property bool $is_enabled
 */
class BlockType extends Model
{
    protected $fillable = ['key', 'is_enabled', 'sort_order', 'max_per_page'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    public function definition(): ?BlockDefinition
    {
        $registry = app(BlockRegistry::class);

        return $registry->has($this->key) ? $registry->get($this->key) : null;
    }

    public function name(): string
    {
        return $this->definition()?->name ?? $this->key;
    }

    /** Whether another instance may be placed on this page. */
    public function canBePlacedOn(Page $page): bool
    {
        if (! $this->is_enabled) {
            return false;
        }

        $limit = $this->max_per_page ?? $this->definition()?->maxPerPage;

        if ($limit === null) {
            return true;
        }

        return $page->sections()->where('block_type', $this->key)->count() < $limit;
    }

    #[Scope]
    protected function enabled(Builder $query): void
    {
        $query->where('is_enabled', true)->orderBy('sort_order');
    }
}
