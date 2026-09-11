<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Str;

/**
 * Polymorphic from the start: projects, causes and products all want tags,
 * and retrofitting a pivot later means migrating data.
 */
class Tag extends Model
{
    protected $fillable = ['name', 'slug'];

    /** @var array<string, mixed> */
    protected $attributes = ['usage_count' => 0];

    protected static function booted(): void
    {
        static::saving(fn (self $t) => $t->slug = Str::slug($t->slug ?: $t->name));
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return MorphToMany<Post, $this> */
    public function posts(): MorphToMany
    {
        return $this->morphedByMany(Post::class, 'taggable');
    }

    public function donors(): MorphToMany
    {
        return $this->morphedByMany(Donor::class, 'taggable');
    }

    /** Find or create by name, so an editor typing a tag does not create duplicates. */
    public static function findOrCreateByName(string $name): self
    {
        return static::firstOrCreate(['slug' => Str::slug($name)], ['name' => trim($name)]);
    }
}
