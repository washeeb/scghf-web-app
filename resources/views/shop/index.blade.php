{{--
    The shop — the whole catalogue, or one category of it.

    Categories are a list of links rather than a dropdown, because a customer
    browsing on a phone is scanning for a word, and a list of six words is
    faster than a control that has to be opened. Sorting is a form that works
    without a script.
--}}
<x-site.page-shell
    :meta="$meta"
    :crumbs="$crumbs"
    :title="$category?->name ?? __('Shop')"
    :lead="$category?->description ?? setting('shop.intro', __('Every purchase funds our work.'))"
>
    @if ($proceeds = setting('shop.proceeds_statement'))
        <p class="mb-8 max-w-3xl rounded-lg border border-[var(--border)] bg-[var(--surface)] px-4 py-3 text-sm text-[var(--text-secondary)]">
            {{ $proceeds }}
        </p>
    @endif

    <div class="grid gap-10 lg:grid-cols-[14rem_1fr]">
        <aside>
            @if ($categories->isNotEmpty())
                <nav aria-label="{{ __('Categories') }}">
                    <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-[var(--text-muted)]">{{ __('Browse') }}</h2>

                    <ul role="list" class="flex flex-wrap gap-2 lg:flex-col lg:gap-1">
                        <li>
                            <a href="{{ route('shop.index') }}"
                               @class(['inline-block rounded-md px-3 py-1.5 text-sm', 'bg-[var(--brand-primary)] text-[var(--text-on-brand)]' => $category === null, 'text-[var(--text-primary)] hover:bg-[var(--surface)]' => $category !== null])
                               @if ($category === null) aria-current="page" @endif
                            >{{ __('Everything') }}</a>
                        </li>

                        @foreach ($categories as $root)
                            <li>
                                <a href="{{ route('shop.category', $root) }}"
                                   @class(['inline-block rounded-md px-3 py-1.5 text-sm', 'bg-[var(--brand-primary)] text-[var(--text-on-brand)]' => $category?->is($root), 'text-[var(--text-primary)] hover:bg-[var(--surface)]' => ! $category?->is($root)])
                                   @if ($category?->is($root)) aria-current="page" @endif
                                >{{ $root->name }}</a>

                                @if ($root->children->isNotEmpty())
                                    <ul role="list" class="ml-3 hidden lg:block">
                                        @foreach ($root->children as $child)
                                            <li>
                                                <a href="{{ route('shop.category', $child) }}"
                                                   @class(['inline-block rounded-md px-3 py-1 text-sm', 'font-semibold text-[var(--brand-primary)]' => $category?->is($child), 'text-[var(--text-secondary)] hover:text-[var(--text-primary)]' => ! $category?->is($child)])
                                                   @if ($category?->is($child)) aria-current="page" @endif
                                                >{{ $child->name }}</a>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endif
        </aside>

        <div>
            <form method="GET" action="{{ $category ? route('shop.category', $category) : route('shop.index') }}" class="mb-6 flex items-center justify-end gap-2">
                <label for="sort" class="text-sm text-[var(--text-secondary)]">{{ __('Sort by') }}</label>
                <select id="sort" name="sort" class="rounded-md border border-[var(--border)] bg-[var(--bg)] px-3 py-1.5 text-sm text-[var(--text-primary)]">
                    <option value="featured" @selected($sort === 'featured')>{{ __('Featured') }}</option>
                    <option value="newest" @selected($sort === 'newest')>{{ __('Newest') }}</option>
                    <option value="price_asc" @selected($sort === 'price_asc')>{{ __('Price: low to high') }}</option>
                    <option value="price_desc" @selected($sort === 'price_desc')>{{ __('Price: high to low') }}</option>
                </select>
                <button type="submit" class="rounded-md border border-[var(--border)] px-3 py-1.5 text-sm text-[var(--text-primary)]">{{ __('Go') }}</button>
            </form>

            @forelse ($products as $product)
                @if ($loop->first)
                    <ul role="list" class="grid gap-6 sm:grid-cols-2 xl:grid-cols-3">
                @endif

                <li><x-site.product-card :product="$product" :eager="$loop->index < 3" /></li>

                @if ($loop->last)
                    </ul>
                @endif
            @empty
                <p class="text-[var(--text-secondary)]">
                    {{ $category
                        ? __('Nothing in this category yet.')
                        : __('The shop is being stocked. Please check back soon.') }}
                </p>
            @endforelse

            @if ($products->hasPages())
                <div class="mt-10">{{ $products->onEachSide(1)->links() }}</div>
            @endif
        </div>
    </div>
</x-site.page-shell>
