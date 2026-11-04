<?php

namespace App\Services;

use App\Models\Banner;
use App\Traits\CacheManagerTrait;
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
        return $this->remember(type: 'Home Promo Banner', cacheKey: 'home_promo_banner');
    }

    /**
     * @param bool $newestFirst Single-banner placements take the first match, so they
     *                          want the newest; grids keep creation order within a priority.
     */
    private function remember(string $type, string $cacheKey, ?callable $build = null, bool $newestFirst = false): Collection
    {
        $themeName = theme_root_path() ?? 'default';
        $key = 'cache_banner_type_' . $cacheKey . '_' . $themeName;
        $this->cacheBannerAllTypeKeys(cacheKey: $key);

        return Cache::remember($key, CACHE_FOR_3_HOURS, function () use ($type, $themeName, $build, $newestFirst) {
            $query = Banner::with(['storage'])
                ->where(['banner_type' => $type, 'published' => 1, 'theme' => $themeName]);

            $query = ($build ? $build($query) : $query)->orderBy('priority');

            return ($newestFirst ? $query->orderByDesc('id') : $query->orderBy('id'))->get();
        });
    }
}
