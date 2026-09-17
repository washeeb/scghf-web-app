<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasSeo;
use App\Shop\RegulatoryScreener;
use App\Support\Slug;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A branch of the shop's taxonomy.
 *
 * `policy_key` ties a category to the agreed list in
 * `config('compliance.shop.approved_categories')`. A category without one is
 * not forbidden — the trustees can add whatever they decide to sell — but it is
 * visibly outside what was agreed, which is exactly what somebody reviewing the
 * shop needs to see.
 */
class ProductCategory extends Model
{
    use HasFactory;
    use HasSeo;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'parent_id', 'name', 'slug', 'description', 'image_id',
        'policy_key', 'sort_order', 'is_active',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'sort_order' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $category): void {
            $category->slug = Slug::for($category->slug, $category->name);
        });
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsTo<ProductCategory, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<ProductCategory, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** Whether this category is part of the taxonomy the trustees agreed. */
    public function isApproved(): bool
    {
        return app(RegulatoryScreener::class)->isApprovedCategory($this->policy_key);
    }

    /** The example items recorded against this category in the policy. */
    public function policyItems(): array
    {
        return app(RegulatoryScreener::class)
            ->approvedCategories()[$this->policy_key]['items'] ?? [];
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }

    #[Scope]
    protected function roots(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /** Categories the trustees have not covered in the agreed taxonomy. */
    #[Scope]
    protected function outsidePolicy(Builder $query): void
    {
        $approved = array_keys(app(RegulatoryScreener::class)->approvedCategories());

        $query->where(fn (Builder $q) => $q->whereNull('policy_key')
            ->orWhereNotIn('policy_key', $approved));
    }
}
