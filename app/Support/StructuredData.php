<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Media;
use App\Models\Post;
use App\Models\Product;
use Illuminate\Support\HtmlString;

/**
 * JSON-LD, from the settings layer.
 *
 * ── Why a registered non-profit needs this specifically ─────────────────────
 *
 * `NGO` structured data carrying the legal name, the registration number, the
 * address and the contact points is what lets a search engine show the
 * foundation as an organisation rather than as a page — and, more usefully, it
 * is one of the signals that separates a real charity from a site impersonating
 * one. A Ghanaian donor deciding whether to trust a payment form is asking
 * exactly that question.
 *
 * ── Built from settings, never typed ────────────────────────────────────────
 *
 * Every value here comes from the CMS, which means the structured data cannot
 * drift from the footer, the receipts and the emails — all of which read the
 * same rows. A hand-written JSON-LD block is a second copy of the
 * organisation's identity that nobody updates.
 *
 * ── An unfilled placeholder is omitted, not emitted ─────────────────────────
 *
 * `setting()` returns null for a `{{PLACEHOLDER}}`, and every key here is
 * filtered before it is encoded. Publishing `"registrationNumber":
 * "{{REGISTRATION_NUMBER}}"` to a search engine is worse than publishing
 * nothing: the absence is invisible, the token is a claim that the site is
 * unfinished.
 */
class StructuredData
{
    /** The organisation itself, emitted on every page. */
    public function organisation(): HtmlString
    {
        $socials = collect([
            setting('social.facebook'),
            setting('social.instagram'),
            setting('social.x'),
            setting('social.linkedin'),
            setting('social.youtube'),
            setting('social.tiktok'),
        ])->filter()->values();

        return $this->encode(array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'NGO',
            'name' => setting('general.legal_name', setting('general.short_name')),
            'alternateName' => setting('general.short_name'),
            'url' => url('/'),
            'slogan' => setting('general.motto'),
            'description' => setting('seo.default_description'),
            'foundingDate' => setting('general.founded_on'),
            'founder' => ($founder = setting('general.founder_name'))
                ? ['@type' => 'Person', 'name' => $founder]
                : null,
            'identifier' => setting('general.registration_number'),
            'address' => $this->address(),
            'contactPoint' => $this->contactPoints(),
            'sameAs' => $socials->isEmpty() ? null : $socials->all(),
        ]));
    }

    /**
     * The site, with its search action.
     *
     * The `SearchAction` is what produces a search box under the result in
     * Google. It is only declared once `/search` exists — declaring a search
     * endpoint that 404s is the same class of mistake as a permission over a
     * table that was never built.
     */
    public function website(): HtmlString
    {
        return $this->encode([
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => setting('general.short_name', config('app.name')),
            'url' => url('/'),
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => route('search').'?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ]);
    }

    /**
     * The trail, as data.
     *
     * A visible breadcrumb tells the person on the page where they are; this
     * tells the search result, which is where most people meet an inner page of
     * a foundation's site for the first time.
     *
     * @param  array<int, array{label: string, url: string|null}>  $crumbs
     */
    public function breadcrumbs(array $crumbs): HtmlString
    {
        return $this->encode([
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => collect($crumbs)
                ->values()
                ->map(fn (array $crumb, int $index): array => array_filter([
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $crumb['label'],
                    'item' => $crumb['url'],
                ]))
                ->all(),
        ]);
    }

    /**
     * A news post as an Article. The publisher is the NGO; the author is
     * the staff member if one is named, otherwise the organisation.
     */
    public function article(Post $post): HtmlString
    {
        $image = $post->featuredImage?->isPublishable() ? $post->featuredImage->conversionUrl('hero') : null;
        $org = setting('general.legal_name', setting('general.short_name', config('app.name')));

        return $this->encode([
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => str($post->title)->limit(110)->toString(),
            'description' => $post->seoDescription(),
            'image' => $image,
            'datePublished' => $post->published_at?->toIso8601String(),
            'dateModified' => $post->updated_at?->toIso8601String(),
            'author' => $post->author
                ? ['@type' => 'Person', 'name' => $post->author->name]
                : ['@type' => 'Organization', 'name' => $org],
            'publisher' => array_filter([
                '@type' => 'Organization',
                'name' => $org,
                'logo' => $this->logoUrl() ? ['@type' => 'ImageObject', 'url' => $this->logoUrl()] : null,
            ]),
            'mainEntityOfPage' => route('news.show', $post),
        ]);
    }

    /**
     * A product with its offer in GHS. Price is the lowest sellable
     * variant's; availability is whether anything is in stock.
     */
    public function product(Product $product): HtmlString
    {
        $from = $product->fromPrice();
        $image = $product->featuredImage?->isPublishable() ? $product->featuredImage->conversionUrl('hero') : null;

        return $this->encode([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->name,
            'description' => $product->seoDescription(),
            'image' => $image,
            'sku' => $product->variants->first()?->sku,
            'brand' => ['@type' => 'Organization', 'name' => setting('general.short_name', config('app.name'))],
            'offers' => $from === null ? null : [
                '@type' => 'Offer',
                'url' => route('shop.show', $product),
                'priceCurrency' => 'GHS',
                'price' => number_format($from->toMinor() / 100, 2, '.', ''),
                'availability' => $product->isInStock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'seller' => ['@type' => 'Organization', 'name' => setting('general.legal_name', setting('general.short_name', config('app.name')))],
            ],
        ]);
    }

    /**
     * FAQPage: the questions and answers on the page, answers as plain
     * text — a rich snippet is not a place for markup.
     *
     * @param  iterable<int, object{question: string, answer: ?string}>  $faqs
     */
    public function faqPage(iterable $faqs): HtmlString
    {
        $entities = collect($faqs)
            ->filter(fn (object $faq): bool => filled($faq->question) && filled($faq->answer))
            ->map(fn (object $faq): array => [
                '@type' => 'Question',
                'name' => (string) $faq->question,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => str((string) $faq->answer)->stripTags()->squish()->toString(),
                ],
            ])
            ->values()
            ->all();

        return $this->encode([
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $entities,
        ]);
    }

    /**
     * The donate page: the NGO with a DonateAction, so a search result can
     * carry a "Donate" affordance and the amount is understood to be GHS.
     */
    public function donateAction(): HtmlString
    {
        return $this->encode([
            '@context' => 'https://schema.org',
            '@type' => 'NGO',
            'name' => setting('general.legal_name', setting('general.short_name', config('app.name'))),
            'url' => url('/'),
            'potentialAction' => [
                '@type' => 'DonateAction',
                'name' => __('Donate'),
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => route('donate'),
                    'actionPlatform' => ['https://schema.org/DesktopWebPlatform', 'https://schema.org/MobileWebPlatform'],
                ],
                'recipient' => ['@type' => 'NGO', 'name' => setting('general.legal_name', setting('general.short_name', config('app.name')))],
                'priceCurrency' => 'GHS',
            ],
        ]);
    }

    private function logoUrl(): ?string
    {
        $logo = Media::query()->find(setting('header.logo_light'));

        return $logo?->isPublishable() ? $logo->conversionUrl('card') : null;
    }

    /** @return array<string, mixed>|null */
    private function address(): ?array
    {
        $address = array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => setting('contact.address'),
            'addressLocality' => setting('contact.city'),
            'addressRegion' => setting('contact.region'),
            'postOfficeBoxNumber' => setting('contact.postal_address'),
            'addressCountry' => 'GH',
        ]);

        // Only the type and the country would survive if nothing is filled in,
        // and an address consisting of "Ghana" is not an address.
        return count($address) > 2 ? $address : null;
    }

    /**
     * How to reach the foundation, by purpose.
     *
     * ⚠ The safeguarding address is deliberately absent. It is marked
     * non-public in the settings layer for a reason: a confidential reporting
     * route published as structured data is a confidential reporting route
     * that is in every scraper's index by Friday.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function contactPoints(): ?array
    {
        $points = collect([
            ['contactType' => 'customer support', 'email' => setting('contact.email_general'), 'telephone' => setting('contact.phone_primary')],
            ['contactType' => 'donations', 'email' => setting('contact.email_donations')],
        ])
            ->map(fn (array $point): array => array_filter($point))
            // A contact point with only a label on it says nothing.
            ->filter(fn (array $point): bool => count($point) > 1)
            ->map(fn (array $point): array => ['@type' => 'ContactPoint', ...$point, 'areaServed' => 'GH'])
            ->values();

        return $points->isEmpty() ? null : $points->all();
    }

    /** @param  array<string, mixed>  $data */
    private function encode(array $data): HtmlString
    {
        /*
         * `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` so URLs and
         * Ghanaian place names read as themselves, and `JSON_HEX_TAG` so a
         * value containing `</script>` cannot close the block it sits inside —
         * this content comes from the CMS, and the CMS is edited by people.
         */
        return new HtmlString((string) json_encode(
            array_filter($data, fn (mixed $value): bool => $value !== null && $value !== []),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_THROW_ON_ERROR,
        ));
    }
}
