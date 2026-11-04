<?php

namespace App\View\Composers;

use App\Models\Product;
use App\Services\Seo\SeoMetaResolver;
use App\Services\Seo\SeoTagRenderer;
use App\Services\Seo\StructuredDataBuilder;
use Illuminate\View\View;

/**
 * Supplies the product page with the SEO tags its own template does not emit (Phase 2.2).
 *
 * Phase 1.6 built the resolver, the tag renderer and the schema builder, and tested all three — but
 * nothing on the storefront included them, so a live product page carried NO canonical, NO hreflang
 * and NO structured data. Verified by fetching a real product page and finding zero
 * `application/ld+json` blocks. Built and tested is not the same as working.
 *
 * Scope is deliberately narrow: the theme already writes title, description, og:*, twitter:* and
 * robots itself, and this adds only what is missing. Emitting the full block alongside would
 * duplicate half the head, and a duplicated canonical is worse than none — conflicting signals are
 * treated as untrustworthy rather than averaged.
 *
 * Every failure path degrades to an empty string. A product page must render even if SEO data is
 * missing, malformed, or its tables have not been migrated.
 */
class ProductSeoSupplementComposer
{
    public function __construct(
        private readonly SeoMetaResolver       $resolver,
        private readonly SeoTagRenderer        $tags,
        private readonly StructuredDataBuilder $schema,
    )
    {
    }

    public function compose(View $view): void
    {
        $product = $view->getData()['productDetails'] ?? null;

        if (!$product instanceof Product) {
            $view->with(['seoSupplementTags' => '', 'seoSupplementSchema' => '']);
            return;
        }

        $view->with([
            'seoSupplementTags'   => $this->renderTags($product),
            'seoSupplementSchema' => $this->renderSchema($product),
        ]);
    }

    private function renderTags(Product $product): string
    {
        try {
            $language = $this->currentLanguage();
            $meta = $this->resolver->resolve(Product::class, (int) $product->id, $language, 'product');

            // Fall back to the page's own URL: a product with no explicit canonical still needs one,
            // since the same product is reachable through category, search and share URLs carrying
            // different query strings.
            $canonical = $meta['canonical_url'] ?? null;
            if (empty($canonical)) {
                $canonical = route('product', [$product->slug]);
            }

            return $this->tags->renderCanonicalAndAlternates($canonical, $this->alternates($product));
        } catch (\Throwable) {
            return '';
        }
    }

    private function renderSchema(Product $product): string
    {
        try {
            return $this->tags->renderJsonLd($this->schema->product($this->schemaDataFor($product)));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Map a Product model onto the plain array StructuredDataBuilder expects.
     *
     * The builder takes data rather than a model on purpose — it is testable without the database
     * and reusable for entities that are not Eloquent. That contract needs an adapter, and its
     * absence is why the schema silently produced nothing: passing the model raised a TypeError
     * that the catch below turned into an empty string. Loud in a test, invisible in production.
     *
     * @return array<string, mixed>
     */
    private function schemaDataFor(Product $product): array
    {
        $reviews = $product->relationLoaded('reviews') ? $product->reviews : collect();

        return [
            'name'        => (string) $product->name,
            'description' => trim(strip_tags((string) ($product->details ?? ''))) ?: null,
            'sku'         => $product->code ?? null,
            // Google reads a real GTIN (EAN/UPC) differently from a shop's own SKU: it matches the
            // product across the web, so it travels beside the SKU rather than replacing it.
            'gtin'        => $product->barcode ?: null,
            'url'         => route('product', [$product->slug]),
            'images'      => $this->imageUrls($product),
            'brand'       => $product->brand?->name,
            'price'       => $product->unit_price,
            'currency'    => getCurrencyCode() ?: 'USD',
            // A product with no stock field (a digital one) is available; only an explicit zero
            // means out of stock, so physical stock is not inferred where it does not apply.
            'in_stock'     => $product->product_type === 'digital' || (int) ($product->current_stock ?? 0) > 0,
            'rating'       => $product->reviews_avg_rating ?? null,
            'review_count' => (int) ($product->reviews_count ?? $reviews->count()),
        ];
    }

    /** @return array<int, string> */
    private function imageUrls(Product $product): array
    {
        try {
            $thumbnail = $product->thumbnail_full_url;
            $url = is_array($thumbnail) ? ($thumbnail['path'] ?? null) : $thumbnail;

            return is_string($url) && $url !== '' ? [$url] : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * hreflang targets for the languages the store actually publishes.
     *
     * The storefront serves one URL per product and switches language by session, so the alternates
     * point at the same URL with an explicit language parameter rather than at separate paths.
     *
     * @return array<string,string>
     */
    private function alternates(Product $product): array
    {
        try {
            $languages = getWebConfig('language');
            if (!is_iterable($languages)) {
                return [];
            }

            $url = route('product', [$product->slug]);
            $alternates = [];
            foreach ($languages as $language) {
                $code = $language['code'] ?? null;
                if ($code) {
                    $alternates[$code] = $url . '?language=' . $code;
                }
            }

            // One language is not an alternate of anything — emitting hreflang for a single locale
            // is noise that tells a crawler nothing.
            return count($alternates) > 1 ? $alternates : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function currentLanguage(): string
    {
        try {
            return function_exists('getDefaultLanguage') ? (string) getDefaultLanguage() : 'en';
        } catch (\Throwable) {
            return 'en';
        }
    }
}
