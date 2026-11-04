<?php

namespace App\Services;

use App\Models\Banner;
use App\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The category landing header: the merchandising banner for the category being
 * browsed, plus that category's own sub-categories as an entry strip.
 *
 * The banner is an ordinary banner record of type "Category Banner" whose
 * category resource names the page it belongs to, so it is created, published
 * and translated through the existing banner CRUD. A sub-category page with no
 * banner of its own inherits its nearest ancestor's, which lets one banner
 * cover a whole branch of the tree.
 */
class CategoryPageService
{
    public function __construct(private readonly BannerPlacementService $bannerPlacement)
    {
    }

    /** Categories are three levels deep; the bound also stops a cyclic parent chain. */
    private const MAX_TREE_DEPTH = 4;

    /**
     * The category a product-list request is browsing, deepest level first —
     * the same precedence the product query itself filters by.
     */
    public function resolveCategory(object|array $request): ?Category
    {
        foreach (['sub_sub_category_id', 'sub_category_id', 'category_id'] as $key) {
            $categoryId = data_get($request, $key);
            if (!empty($categoryId) && is_numeric($categoryId)) {
                return Category::find($categoryId);
            }
        }

        return null;
    }

    /** @return array{category: ?Category, banner: ?Banner, subCategories: Collection} */
    public function getPageHeader(?Category $category): array
    {
        if (!$category) {
            return ['category' => null, 'banner' => null, 'subCategories' => collect()];
        }

        return [
            'category' => $category,
            'banner' => $this->getBanner(category: $category),
            'subCategories' => $this->getSubCategories(category: $category),
        ];
    }

    /** The category's own banner, else the nearest ancestor's. */
    private function getBanner(Category $category): ?Banner
    {
        $banners = $this->bannerPlacement->getCategoryPageBanners();
        if ($banners->isEmpty()) {
            return null;
        }

        $current = $category;
        for ($depth = 0; $current && $depth < self::MAX_TREE_DEPTH; $depth++) {
            $banner = $banners->first(function ($banner) use ($current) {
                return $banner['resource_type'] == 'category' && (int)$banner['resource_id'] === (int)$current['id'];
            });

            if ($banner) {
                return $banner;
            }

            $current = $current['parent_id'] ? Category::find($current['parent_id']) : null;
        }

        return null;
    }

    /**
     * The open category's direct children in the admin's priority order, each
     * carrying its own product count so a caller can surface or skip empties.
     */
    private function getSubCategories(Category $category): Collection
    {
        $cacheKey = 'category_page_sub_categories_' . $category['id'] . '_' . app()->getLocale();
        $cacheKeys = Cache::get(CACHE_CONTAINER_FOR_LANGUAGE_WISE_CACHE_KEYS, []);
        if (!in_array($cacheKey, $cacheKeys)) {
            $cacheKeys[] = $cacheKey;
            Cache::put(CACHE_CONTAINER_FOR_LANGUAGE_WISE_CACHE_KEYS, $cacheKeys, CACHE_FOR_3_HOURS);
        }

        return Cache::remember($cacheKey, CACHE_FOR_3_HOURS, function () use ($category) {
            // Products carry a column per level, so the count relation follows the child's own position.
            $countRelation = $category['position'] == 0 ? 'subCategoryProduct' : 'subSubCategoryProduct';

            return Category::where('parent_id', $category['id'])
                ->withCount([$countRelation => function ($query) {
                    return $query->active();
                }])
                ->orderBy('priority')->orderBy('name')->get()
                ->map(function ($subCategory) use ($countRelation) {
                    $subCategory->products_count = $subCategory[$countRelation . '_count'] ?? 0;
                    return $subCategory;
                });
        });
    }
}
