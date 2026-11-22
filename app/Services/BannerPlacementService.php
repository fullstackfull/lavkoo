<?php

namespace App\Services;

use App\Models\Banner;
use App\Models\Product;
use App\Services\Storefront\StorefrontCatalog;
use App\Traits\CacheManagerTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Where the screen-scoped banners appear.
 *
 * These types are not part of the generic banner feed: each belongs to one
 * placement and is read by the surface that renders it — the web through the
 * blades, the apps through their own endpoints — so both always see the same
 * published records. Every query here is cached under the banner cache group,
 * which every banner save and delete already invalidates.
 */
class BannerPlacementService
{
    use CacheManagerTrait;

    /** The table the country of a banner's product is read from. */
    private const PRODUCTS = 'products';

    /**
     * Every published category-page banner, for resolving a page's own or its
     * ancestor's. One page shows one banner, so when a merchant has created several
     * for the same category the newest wins — the convention every other single-banner
     * slot in this codebase follows.
     */
    public function getCategoryPageBanners(): Collection
    {
        return $this->remember(
            type: 'Category Banner',
            cacheKey: 'category_banner',
            build: fn ($query) => $query->where('resource_type', 'category'),
            newestFirst: true,
        );
    }

    /** Brand-page banners, each naming the brand whose page it heads; newest wins. */
    public function getBrandPageBanners(): Collection
    {
        return $this->remember(
            type: 'Brand Banner',
            cacheKey: 'brand_banner',
            build: fn ($query) => $query->where('resource_type', 'brand'),
            newestFirst: true,
        );
    }

    /**
     * Category-section banners grouped by the category whose product row they
     * sit above; a category may carry several, laid out by each one's layout.
     *
     * @return Collection<int, Collection<int, Banner>>
     */
    public function getCategorySectionBanners(): Collection
    {
        return $this->remember(
            type: 'Category Section Banner',
            cacheKey: 'category_section_banner',
            build: fn ($query) => $query->where('resource_type', 'category'),
        )->groupBy('resource_id');
    }

    /**
     * The home page's promo grid, which belongs to no category. Banners marked
     * `slider` are pooled into one rotating slot; the rest take a full or half
     * row, in the admin's priority order.
     */
    public function getHomePromoBanners(): Collection
    {
        return $this->offeredByTheStorefront(banners: $this->remember(type: 'Home Promo Banner', cacheKey: 'home_promo_banner'));
    }

    /**
     * The same rule in the read itself, for a list of banners that is still a query: a banner that points at a product the storefront of
     * this request may not sell is left out before any cap on the number of rows counts, and a read that cannot be answered throws rather
     * than handing back a list with nothing left out. A banner that points at nothing, at a category or a brand, or at a product that is
     * gone, belongs to no country and stays, as it does above.
     */
    public function narrowToTheStorefront(Builder $banners): Builder
    {
        if (StorefrontCatalog::country() === null) {
            return $banners;
        }

        $productOfTheBanner = fn (QueryBuilder $product): QueryBuilder => $product
            ->from(self::PRODUCTS)
            ->whereColumn(self::PRODUCTS . '.id', $banners->qualifyColumn('resource_id'));

        return $banners->where(fn (Builder $banner) => $banner
            ->where('resource_type', '!=', 'product')
            ->orWhereNull('resource_type')
            ->orWhereNotExists($productOfTheBanner)
            ->orWhereExists(fn (QueryBuilder $product) => StorefrontCatalog::restrictJoinedProducts(query: $productOfTheBanner($product))));
    }

    /**
     * A banner belongs to no country, but the product it points at does: another storefront's product is neither shown nor opened. The
     * cache holds every banner, whatever the storefront, so this runs after it.
     */
    private function offeredByTheStorefront(Collection $banners): Collection
    {
        $productIds = $banners->where('resource_type', 'product')->pluck('resource_id')->unique();

        if (StorefrontCatalog::country() === null || $productIds->isEmpty()) {
            return $banners;
        }

        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

        return $banners
            ->filter(fn (Banner $banner) => $banner['resource_type'] !== 'product' || StorefrontCatalog::offers(product: $products->get($banner['resource_id'])))
            ->values();
    }

    /**
     * @param bool $newestFirst Single-banner placements take the first match, so they
     *                          want the newest; grids keep creation order within a priority.
     */
    private function remember(string $type, string $cacheKey, ?callable $build = null, bool $newestFirst = false): Collection
    {
        $themeName = theme_root_path() ?? 'default';
        $key = storefrontCacheKey(key: 'cache_banner_type_' . $cacheKey . '_' . $themeName);
        $this->cacheBannerAllTypeKeys(cacheKey: $key);

        return Cache::remember($key, CACHE_FOR_3_HOURS, function () use ($type, $themeName, $build, $newestFirst) {
            $query = Banner::with(['storage'])
                ->where(['banner_type' => $type, 'published' => 1, 'theme' => $themeName]);

            $query = ($build ? $build($query) : $query)->orderBy('priority');

            return ($newestFirst ? $query->orderByDesc('id') : $query->orderBy('id'))->get();
        });
    }
}
