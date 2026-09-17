<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasSeo;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class BlogCategory extends Model
{
    use HasSeo;
    use SoftDeletes;

    protected $fillable = ['name', 'slug', 'description', 'colour', 'sort_order', 'is_published'];

    /** @var array<string, mixed> */
    protected $attributes = ['sort_order' => 0, 'is_published' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(fn (self $c) => $c->slug = Str::slug($c->slug ?: $c->name));
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return HasMany<Post, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('is_published', true)->orderBy('sort_order');
    }
}
