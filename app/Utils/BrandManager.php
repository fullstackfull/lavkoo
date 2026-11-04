<?php

namespace App\Utils;

use App\Models\Brand;
use App\Traits\GeneratesUniqueSlug;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class BrandManager
{
    use GeneratesUniqueSlug;

    public static function getActiveBrandWithCountingAndPriorityWiseSorting()
    {
        $segment = str_replace('-', '_', Str::slug(request()->segment(1)));
        $cacheKey = 'cache_priority_wise_brands_list_' . (getDefaultLanguage() ?? 'en') . '_' . (request('offer_type') ?? 'default') . (request('data_form') ?? 'default'). $segment;
        $cacheKeys = Cache::get(CACHE_CONTAINER_FOR_LANGUAGE_WISE_CACHE_KEYS, []);

        if (!in_array($cacheKey, $cacheKeys)) {
            $cacheKeys[] = $cacheKey;
            Cache::put(CACHE_CONTAINER_FOR_LANGUAGE_WISE_CACHE_KEYS, $cacheKeys, CACHE_FOR_3_HOURS);
        }

        return Cache::remember($cacheKey, CACHE_FOR_3_HOURS, function () {
            $brandList = Brand::active()->withCount(['brandProducts' => function ($query) {
                return $query->active()->when(request('offer_type') == 'clearance_sale', function ($query) {
                    return $query->whereHas('clearanceSale', function ($query) {
                        return $query->active();
                    });
                })->when(request('offer_type') == 'flash-deals', function ($query) {
                    return $query->whereHas('flashDealProducts.flashDeal');
                });
            }])->when(request('offer_type') == 'flash-deals', function ($query) {
                return $query->whereHas('brandProducts.flashDealProducts.flashDeal');
            });
            return self::getPriorityWiseBrandProductsQuery(query: $brandList);
        });
    }

    public static function getPriorityWiseBrandProductsQuery($query)
    {
        $brandProductSortBy = getWebConfig(name: 'brand_list_priority');
        if ($brandProductSortBy && ($brandProductSortBy['custom_sorting_status'] == 1)) {
            if ($brandProductSortBy['sort_by'] == 'most_order') {
                return $query->with(['brandProducts' => function ($query) {
                    return $query->active()->withCount('orderDetails');
                }])->get()->map(function ($brand) {
                    $brand['order_count'] = $brand?->brandProducts?->sum('order_details_count') ?? 0;
                    return $brand;
                })->sortByDesc('order_count');
            } elseif ($brandProductSortBy['sort_by'] == 'latest_created') {
                return $query->latest()->get();
            } elseif ($brandProductSortBy['sort_by'] == 'first_created') {
                return $query->orderBy('id', 'asc')->get();
            } elseif ($brandProductSortBy['sort_by'] == 'a_to_z') {
                return $query->orderBy('name', 'asc')->get();
            } elseif ($brandProductSortBy['sort_by'] == 'z_to_a') {
                return $query->orderBy('name', 'desc')->get();
            }
        }

        $hasMissingSlug = (clone $query)->whereNull('slug')->orWhere('slug', '')->exists();
        if (!$hasMissingSlug) {
            return $query->latest()->get();
        }
        return $query->latest()->get()->map(function ($brand) {
            if (empty($brand->slug)) {
                $brand->update(['slug' => Str::slug($brand?->name) . '-' . Str::random(4) . '-' . time()]);
            }
            return $brand;
        });

    }
}
