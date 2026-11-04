<?php

namespace App\Services;

use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The brand landing header: the brand's banner and the categories its products
 * actually live in.
 *
 * The category strip is derived from the catalogue rather than listed by hand —
 * only categories that currently hold an active product of this brand appear,
 * each with its count — so it can never offer a filter that returns nothing.
 */
class BrandPageService
{
    public function __construct(private readonly BannerPlacementService $bannerPlacement)
    {
    }

    /** @return array{brand: ?Brand, banner: ?Banner, categories: Collection, activeCategoryId: ?int} */
    public function getPageHeader(?Brand $brand, object|array $request = []): array
    {
        if (!$brand) {
            return ['brand' => null, 'banner' => null, 'categories' => collect(), 'activeCategoryId' => null];
        }

        $selected = data_get($request, 'category_ids');
        $activeCategoryId = is_array($selected) && !empty($selected) ? (int)reset($selected) : null;

        return [
            'brand' => $brand,
            'banner' => $this->getBanner(brand: $brand),
            'categories' => $this->getCategories(brand: $brand),
            'activeCategoryId' => $activeCategoryId,
        ];
    }

    private function getBanner(Brand $brand): ?Banner
    {
        return $this->bannerPlacement->getBrandPageBanners()->first(function ($banner) use ($brand) {
            return $banner['resource_type'] == 'brand' && (int)$banner['resource_id'] === (int)$brand['id'];
        });
    }

    /**
     * The categories holding active products of this brand, most stocked first.
     * Cached per brand and language, and invalidated with the category cache group.
     */
    private function getCategories(Brand $brand): Collection
    {
        $cacheKey = 'brand_page_categories_' . $brand['id'] . '_' . app()->getLocale();
        $cacheKeys = Cache::get(CACHE_CONTAINER_FOR_LANGUAGE_WISE_CACHE_KEYS, []);
        if (!in_array($cacheKey, $cacheKeys)) {
            $cacheKeys[] = $cacheKey;
            Cache::put(CACHE_CONTAINER_FOR_LANGUAGE_WISE_CACHE_KEYS, $cacheKeys, CACHE_FOR_3_HOURS);
        }

        return Cache::remember($cacheKey, CACHE_FOR_3_HOURS, function () use ($brand) {
            $counts = Product::active()
                ->where('brand_id', $brand['id'])
                ->whereNotNull('category_id')
                ->selectRaw('category_id, COUNT(*) as products_count')
                ->groupBy('category_id')
                ->pluck('products_count', 'category_id');

            if ($counts->isEmpty()) {
                return collect();
            }

            return Category::whereIn('id', $counts->keys())->get()
                ->map(function ($category) use ($counts) {
                    $category->products_count = (int)$counts->get($category['id'], 0);
                    return $category;
                })
                ->sortByDesc('products_count')
                ->values();
        });
    }
}
