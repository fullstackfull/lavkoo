<?php

namespace App\Utils;

use App\Models\Author;
use App\Models\Category;
use App\Models\DigitalProductAuthor;
use App\Models\DigitalProductPublishingHouse;
use App\Models\FlashDeal;
use App\Models\FlashDealProduct;
use App\Models\Product;
use App\Models\PublishingHouse;
use Illuminate\Support\Facades\Cache;

class CategoryManager
{
    public static function products($category_id, $request = null, $dataLimit = null)
    {
        $user = Helpers::getCustomerInformation($request);
        $id = '"' . $category_id . '"';

        $brandIds         = $request->has('brand_ids')         ? (is_array($request['brand_ids'])         ? $request['brand_ids']         : (json_decode($request['brand_ids'], true)         ?? [])) : [];
        $publishingHouses = $request->has('publishing_houses') ? (is_array($request['publishing_houses']) ? $request['publishing_houses'] : (json_decode($request['publishing_houses'], true) ?? [])) : [];
        $productAuthors   = $request->has('product_authors')   ? (is_array($request['product_authors'])   ? $request['product_authors']   : (json_decode($request['product_authors'], true)   ?? [])) : [];

        $productIdsForUnknownPublisher = [];
        $productIdsForUnknownAuthor    = [];

        if (!empty($publishingHouses) && in_array(0, $publishingHouses)) {
            $knownPublisherProductIds      = DigitalProductPublishingHouse::pluck('product_id')->toArray();
            $productIdsForUnknownPublisher = Product::active()->where('product_type', 'digital')
                ->whereNotIn('id', $knownPublisherProductIds)->pluck('id')->toArray();
        }

        if (!empty($productAuthors) && in_array(0, $productAuthors)) {
            $knownAuthorProductIds      = DigitalProductAuthor::pluck('product_id')->toArray();
            $productIdsForUnknownAuthor = Product::active()->where('product_type', 'digital')
                ->whereNotIn('id', $knownAuthorProductIds)->pluck('id')->toArray();
        }

        $products = Product::with(['flashDealProducts.flashDeal', 'rating', 'seller.shop', 'tags', 'clearanceSale' => function ($query) {
                return $query->active();
            }])
            ->withCount(['reviews', 'wishList' => function ($query) use ($user) {
                $query->where('customer_id', $user != 'offline' ? $user['id'] : '0');
            }])
            ->active()
            ->where('category_ids', 'like', "%{$id}%")
            ->when($request->has('search') && !empty($request['search']), function ($query) use ($request) {
                $searchKey = $request['search'];
                $productsIDArray = [];
                $searchProducts = ProductManager::search_products($request, $searchKey);
                if ($searchProducts['products'] == null || getDefaultLanguage() != 'en') {
                    $searchProducts = ProductManager::translated_product_search(base64_encode($searchKey));
                }
                if ($searchProducts['products']) {
                    foreach ($searchProducts['products'] as $product) {
                        $productsIDArray[] = $product->id;
                    }
                }

                $searchName = str_ireplace(['\'', '"', ',', ';', '<', '>', '?', '\\'], ' ', preg_replace('/\s\s+/', ' ', $searchKey));
                return $query->when(!empty($productsIDArray), function ($query) use ($productsIDArray) {
                    return $query->whereIn('id', $productsIDArray);
                })->when(empty($productsIDArray), function ($query) use ($productsIDArray) {
                    return $query->whereIn('id', [0]);
                })->orderByRaw("CASE WHEN name LIKE ? THEN 1 ELSE 2 END, LOCATE(?, name), name", ["%{$searchName}%", $searchName]);
            })
            ->when(in_array($request['product_type'] ?? '', ['physical', 'digital']), function ($query) use ($request) {
                return $query->where('product_type', $request['product_type']);
            })
            ->when(!empty($brandIds), function ($query) use ($brandIds) {
                return $query->whereIn('brand_id', $brandIds);
            })
            ->when(!empty($publishingHouses), function ($query) use ($publishingHouses, $productIdsForUnknownPublisher) {
                $list = PublishingHouse::whereIn('id', $publishingHouses)
                    ->with(['publishingHouseProducts'])
                    ->withCount(['publishingHouseProducts' => function ($q) {
                        return $q->whereHas('product', fn($q) => $q->active());
                    }])->get();

                $ids = [];
                foreach ($list as $group) {
                    foreach ($group->publishingHouseProducts ?? [] as $ph) {
                        $ids[] = $ph->product_id;
                    }
                }
                if (in_array(0, $publishingHouses)) {
                    $ids = array_merge($ids, $productIdsForUnknownPublisher);
                }
                return $query->where('product_type', 'digital')->whereIn('id', $ids);
            })
            ->when(!empty($productAuthors), function ($query) use ($productAuthors, $productIdsForUnknownAuthor) {
                $list = Author::whereIn('id', $productAuthors)
                    ->withCount(['digitalProductAuthor' => function ($q) {
                        return $q->whereHas('product', fn($q) => $q->active());
                    }])->get();

                $ids = [];
                foreach ($list as $group) {
                    foreach ($group->digitalProductAuthor ?? [] as $item) {
                        $ids[] = $item->product_id;
                    }
                }
                if (in_array(0, $productAuthors)) {
                    $ids = array_merge($ids, $productIdsForUnknownAuthor);
                }
                return $query->where('product_type', 'digital')->whereIn('id', $ids);
            });

        $products = ProductManager::getPriorityWiseCategoryWiseProductsQuery(query: $products, dataLimit: $dataLimit ?? 'all', offset: $request['offset'] ?? 1);

        $currentDate = date('Y-m-d H:i:s');
        $products?->map(function ($product) use ($currentDate) {
            $flashDealStatus = 0;
            $flashDealEndDate = 0;
            if (count($product->flashDealProducts) > 0) {
                $flashDeal = null;
                foreach ($product->flashDealProducts as $flashDealData) {
                    if ($flashDealData->flashDeal) {
                        $flashDeal = $flashDealData->flashDeal;
                    }
                }
                if ($flashDeal) {
                    $startDate = date('Y-m-d H:i:s', strtotime($flashDeal->start_date));
                    $endDate = date('Y-m-d H:i:s', strtotime($flashDeal->end_date));
                    $flashDealStatus = $flashDeal->status == 1 && (($currentDate >= $startDate) && ($currentDate <= $endDate)) ? 1 : 0;
                    $flashDealEndDate = $flashDeal->end_date;
                }
            }
            $product['flash_deal_status'] = $flashDealStatus;
            $product['flash_deal_end_date'] = $flashDealEndDate;
            return $product;
        });

        return $products;
    }

    public static function getCategoriesWithCountingAndPriorityWiseSorting($dataLimit = null, $dataForm = null)
    {
        // The payload depends on language, offer_type, dataForm and data_from —
        // nothing else. The old key also appended the first URL segment, minting
        // 20+ identical heavy entries (one per section of the site, per language),
        // and glued the last two parts with no delimiter so distinct inputs could
        // collide.
        $cacheKey = 'cache_main_categories_list_' . implode('_', [
            getDefaultLanguage() ?? 'en',
            request('offer_type') ?? 'default',
            $dataForm ?? 'default',
            request('data_from', 'default'),
        ]);
        $cacheKeys = Cache::get(CACHE_CONTAINER_FOR_LANGUAGE_WISE_CACHE_KEYS, []);

        if (!in_array($cacheKey, $cacheKeys)) {
            $cacheKeys[] = $cacheKey;
            Cache::put(CACHE_CONTAINER_FOR_LANGUAGE_WISE_CACHE_KEYS, $cacheKeys, CACHE_FOR_3_HOURS);
        }

        $categories = Cache::remember($cacheKey, CACHE_FOR_3_HOURS, function () use ($dataForm) {
                // Inside the cache on purpose: these used to run on every call —
                // even on cache hits — and hydrated full products only to read ids.
                $featuredDealProductIDs = [];
                if (request('offer_type') == 'featured_deal') {
                    $featuredDealID = FlashDeal::where(['deal_type' => 'feature_deal', 'status' => 1])->whereDate('start_date', '<=', date('Y-m-d'))
                        ->whereDate('end_date', '>=', date('Y-m-d'))->pluck('id')->first();
                    $featuredDealProductIDs = $featuredDealID ? FlashDealProduct::where('flash_deal_id', $featuredDealID)->pluck('product_id')->toArray() : [];
                }

                $categories = Category::with(['product' => function ($query) {
                    return $query->active()->withCount(['orderDetails'])->with(['clearanceSale' => function ($query) {
                        return $query->active();
                    }]);
                }])
                ->when($dataForm == 'flash-deals', function ($query) {
                    return $query->whereHas('product.flashDealProducts.flashDeal');
                })
                ->when($dataForm == 'home_page', function ($query) {
                    return $query->whereHas('product', function ($query) {
                        return $query->active();
                    });
                })
                ->withCount(['product' => function ($query) use ($dataForm, $featuredDealProductIDs) {
                    return $query->active()->when(request('offer_type') == 'clearance_sale', function ($query) {
                        return $query->whereHas('clearanceSale', function ($query) {
                            return $query->active();
                        });
                    })
                    ->when(request('offer_type') == 'discounted', function ($query) {
                        return $query->where('discount', '>', 0);
                    })
                    ->when(request('data_from') == 'publishing_house', function ($query) {
                        return $query->where(['product_type' => 'digital']);
                    })
                    ->when(request('offer_type') == 'featured_deal', function ($query) use ($featuredDealProductIDs) {
                        return $query->whereIn('id', $featuredDealProductIDs ?: [0]);
                    })
                    ->when($dataForm == 'flash-deals', function ($query) {
                        return $query->whereHas('flashDealProducts.flashDeal');
                    });
                }])
                ->with(['childes' => function ($query) use ($dataForm, $featuredDealProductIDs) {
                    return $query->with(['childes' => function ($query) use ($dataForm, $featuredDealProductIDs) {
                        return $query->withCount(['subSubCategoryProduct' => function ($query) use ($featuredDealProductIDs) {
                            return $query->active()->when(request('offer_type') == 'clearance_sale', function ($query) {
                                return $query->whereHas('clearanceSale', function ($query) {
                                    return $query->active();
                                });
                            })
                            ->when(request('offer_type') == 'discounted', function ($query) {
                                return $query->where('discount', '>', 0);
                            })
                            ->when(request('data_from') == 'publishing_house', function ($query) {
                                return $query->where(['product_type' => 'digital']);
                            })
                            ->when(request('offer_type') == 'featured_deal', function ($query) use ($featuredDealProductIDs) {
                                return $query->whereIn('id', $featuredDealProductIDs ?: [0]);
                            });
                        }])->where('position', 2);
                    }])->withCount(['subCategoryProduct' => function ($query) use ($dataForm, $featuredDealProductIDs) {
                        return $query->active()->when(request('offer_type') == 'clearance_sale', function ($query) {
                            return $query->whereHas('clearanceSale', function ($query) {
                                return $query->active();
                            });
                        })
                        ->when(request('offer_type') == 'discounted', function ($query) {
                            return $query->where('discount', '>', 0);
                        })
                        ->when(request('data_from') == 'publishing_house', function ($query) {
                            return $query->where(['product_type' => 'digital']);
                        })
                        ->when(request('offer_type') == 'featured_deal', function ($query) use ($featuredDealProductIDs) {
                            return $query->whereIn('id', $featuredDealProductIDs ?: [0]);
                        })
                        ->when($dataForm == 'flash-deals', function ($query) {
                            return $query->whereHas('flashDealProducts.flashDeal');
                        });
                    }])
                    ->where('position', 1);
                }])->where('position', 0)->get();

                // The eager-loaded products exist for exactly one consumer: the
                // most_order sort's sum of order_details_count. Store that sum and
                // drop the products — the cached tree used to serialize EVERY
                // active product of every root category on every page view.
                $categories->each(function ($category) {
                    $category->order_count = (int) ($category->product?->sum('order_details_count') ?? 0);
                    unset($category->product);
                });

                return $categories;
        });

        $categoriesProcessed = self::getPriorityWiseCategorySortQuery(query: $categories);
        if ($dataLimit) {
            $categoriesProcessed = $categoriesProcessed->paginate($dataLimit);
        }
        return $categoriesProcessed;
    }

    public static function getPriorityWiseCategorySortQuery($query)
    {
        $categoryProductSortBy = getWebConfig(name: 'category_list_priority');
        if ($categoryProductSortBy && ($categoryProductSortBy['custom_sorting_status'] == 1)) {
            if ($categoryProductSortBy['sort_by'] == 'most_order') {
                return $query->map(function ($category) {
                    // Cached trees carry the sum precomputed (products dropped);
                    // ad-hoc queries that still eager-load products keep working.
                    if (!isset($category->order_count)) {
                        $category->order_count = $category?->product?->sum('order_details_count') ?? 0;
                    }
                    return $category;
                })->sortByDesc('order_count');
            } elseif ($categoryProductSortBy['sort_by'] == 'latest_created') {
                return $query->sortByDesc('id');
            } elseif ($categoryProductSortBy['sort_by'] == 'first_created') {
                return $query->sortBy('id');
            } elseif ($categoryProductSortBy['sort_by'] == 'a_to_z') {
                return $query->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE);
            } elseif ($categoryProductSortBy['sort_by'] == 'z_to_a') {
                return $query->sortByDesc('name', SORT_NATURAL | SORT_FLAG_CASE);
            }
        }
        return $query->sortByDesc('priority');
    }
}
