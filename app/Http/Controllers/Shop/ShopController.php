<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Support\PageMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The shop, as a visitor sees it.
 *
 * ── The catalogue has existed since Phase 3 with nowhere to be seen ─────────
 *
 * Products, variants, a stock ledger, categories with a regulatory taxonomy —
 * all built and tested, and `FEATURE_SHOP=true` in front of none of it. This
 * is the page that makes the flag mean something.
 *
 * ── Only what is live is listed ─────────────────────────────────────────────
 *
 * `Product::live()` is publication AND the regulatory gate: a product flagged
 * as looking like a medicine is off the shelf until a review is recorded, and
 * that decision is made once, in the model, so a listing cannot forget it. A
 * product with every variant sold out is still listed — marked, honestly —
 * because a shelf that hides its gaps looks like a shop with three products.
 *
 * ── Every purchase says what it funds ───────────────────────────────────────
 *
 * A product tied to an appeal says so on the card and on its page. "Proceeds
 * fund the borehole" is the reason somebody buys a tote bag from a foundation
 * rather than a market stall, and leaving it off is leaving money on the table.
 */
class ShopController extends Controller
{
    private const PER_PAGE = 12;

    public function index(Request $request): View
    {
        $sort = $this->sort($request);

        return view('shop.index', [
            'products' => $this->sorted($this->catalogue(), $sort)->paginate(self::PER_PAGE)->withQueryString(),
            'categories' => $this->categories(),
            'category' => null,
            'sort' => $sort,
            'meta' => PageMeta::site(
                __('Shop'),
                (string) setting('shop.intro', __('Every purchase funds our work.')),
            ),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Shop'), 'url' => null],
            ],
        ]);
    }

    public function category(Request $request, ProductCategory $category): View
    {
        if (! $category->is_active) {
            throw new NotFoundHttpException;
        }

        $sort = $this->sort($request);

        /*
         * A parent category lists its children's products as well as its own.
         * "Clothing" with nothing in it because everything is in "T-shirts"
         * is a page that looks broken to a customer who does not know the
         * tree exists.
         */
        $ids = $category->children()->active()->pluck('id')->push($category->getKey())->all();

        return view('shop.index', [
            'products' => $this->sorted($this->catalogue()->whereIn('product_category_id', $ids), $sort)
                ->paginate(self::PER_PAGE)
                ->withQueryString(),
            'categories' => $this->categories(),
            'category' => $category,
            'sort' => $sort,
            'meta' => PageMeta::site(
                $category->name,
                $category->description ?: (string) setting('shop.intro', __('Every purchase funds our work.')),
            ),
            'crumbs' => array_values(array_filter([
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Shop'), 'url' => route('shop.index')],
                $category->parent ? ['label' => $category->parent->name, 'url' => route('shop.category', $category->parent)] : null,
                ['label' => $category->name, 'url' => null],
            ])),
        ]);
    }

    public function show(Product $product): View
    {
        if (! $product->isLive()) {
            throw new NotFoundHttpException;
        }

        $product->load([
            'variants' => fn ($q) => $q->where('is_active', true),
            'images.media',
            'featuredImage',
            'category',
            'cause',
        ]);

        return view('shop.show', [
            'product' => $product,
            'related' => $this->related($product),
            'meta' => PageMeta::for($product, route('shop.show', $product)),
            'crumbs' => array_values(array_filter([
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Shop'), 'url' => route('shop.index')],
                $product->category ? ['label' => $product->category->name, 'url' => route('shop.category', $product->category)] : null,
                ['label' => $product->name, 'url' => null],
            ])),
        ]);
    }

    /**
     * The listable products, with the "from" price alongside for sorting.
     *
     * A subquery rather than a column: the cheapest active variant is derived
     * data, and a cached copy is one more thing to keep right when a variant
     * is repriced.
     */
    private function catalogue(): Builder
    {
        return Product::query()
            ->live()
            ->with(['featuredImage', 'variants', 'cause', 'category'])
            ->addSelect([
                'from_price_minor' => ProductVariant::query()
                    ->selectRaw('MIN(price_minor)')
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ]);
    }

    private function sorted(Builder $query, string $sort): Builder
    {
        return match ($sort) {
            'price_asc' => $query->orderBy('from_price_minor')->orderBy('name'),
            'price_desc' => $query->orderByDesc('from_price_minor')->orderBy('name'),
            'newest' => $query->orderByDesc('published_at')->orderBy('name'),
            default => $query->orderByDesc('is_featured')->orderBy('sort_order')->orderBy('name'),
        };
    }

    /** @return Collection<int, ProductCategory> */
    private function categories(): Collection
    {
        return ProductCategory::query()
            ->active()
            ->roots()
            ->with(['children' => fn ($q) => $q->active()])
            ->withCount(['products' => fn ($q) => $q->live()])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->filter(fn (ProductCategory $c): bool => $c->products_count > 0 || $c->children->isNotEmpty())
            ->values();
    }

    /** @return Collection<int, Product> */
    private function related(Product $product): Collection
    {
        if ($product->product_category_id === null) {
            return collect();
        }

        return Product::query()
            ->live()
            ->where('product_category_id', $product->product_category_id)
            ->whereKeyNot($product->getKey())
            ->with(['featuredImage', 'variants', 'cause'])
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->limit(3)
            ->get();
    }

    private function sort(Request $request): string
    {
        $sort = $request->string('sort')->toString();

        return in_array($sort, ['price_asc', 'price_desc', 'newest'], true) ? $sort : 'featured';
    }
}
