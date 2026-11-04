<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Contracts\Repositories\AuthorRepositoryInterface;
use App\Contracts\Repositories\BrandRepositoryInterface;
use App\Contracts\Repositories\DigitalProductAuthorRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\PublishingHouseRepositoryInterface;
use App\Contracts\Repositories\RestockProductCustomerRepositoryInterface;
use App\Contracts\Repositories\RestockProductRepositoryInterface;
use App\Contracts\Repositories\StockClearanceProductRepositoryInterface;
use App\Contracts\Repositories\StockClearanceSetupRepositoryInterface;
use App\Enums\WebConfigKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v3\DigitalProductUploadRequest;
use App\Http\Requests\API\v3\ProductAddRequest;
use App\Http\Requests\API\v3\ProductUpdateRequest;
use App\Http\Requests\API\v3\ProductUploadImageRequest;
use App\Models\Author;
use App\Models\BusinessSetting;
use App\Models\Category;
use App\Models\Color;
use App\Models\DealOfTheDay;
use App\Models\DeliveryMan;
use App\Models\DigitalProductVariation;
use App\Models\FlashDealProduct;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Product;
use App\Models\ProductSeo;
use App\Models\PublishingHouse;
use App\Models\Review;
use App\Models\Shop;
use App\Models\StockClearanceProduct;
use App\Models\Tag;
use App\Models\Translation;
use App\Repositories\DigitalProductPublishingHouseRepository;
use App\Services\Marketplace\BrandRegistryService;
use App\Services\ProductService;
use App\Traits\CacheManagerTrait;
use App\Traits\FileManagerTrait;
use App\Traits\ProductTrait;
use App\Utils\Convert;
use App\Utils\Helpers;
use App\Utils\ProductManager;
use Barryvdh\DomPDF\PDF;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\TaxModule\app\Traits\VatTaxManagement;

class ProductController extends Controller
{
    use FileManagerTrait {
        delete as deleteFile;
        update as updateFile;
    }
    use CacheManagerTrait, ProductTrait;
    use VatTaxManagement;

    public function __construct(
        private readonly AuthorRepositoryInterface                 $authorRepo,
        private readonly PublishingHouseRepositoryInterface        $publishingHouseRepo,
        private readonly DigitalProductAuthorRepositoryInterface   $digitalProductAuthorRepo,
        private readonly DigitalProductPublishingHouseRepository   $digitalProductPublishingHouseRepo,
        private readonly StockClearanceProductRepositoryInterface  $stockClearanceProductRepo,
        private readonly StockClearanceSetupRepositoryInterface    $stockClearanceSetupRepo,
        private readonly ProductRepositoryInterface                $productRepo,
        private readonly BrandRepositoryInterface                  $brandRepo,
        private readonly ProductService                            $productService,
        private readonly RestockProductRepositoryInterface         $restockProductRepo,
        private readonly RestockProductCustomerRepositoryInterface $restockProductCustomerRepo,
    )
    {
    }

    public function getProductList(Request $request): JsonResponse
    {
        $seller = $request->seller;
        $products = Product::with(['clearanceSale' => function ($query) {
                return $query->active();
            }])
            ->withCount('reviews')
            ->where(['added_by' => 'seller', 'user_id' => $seller['id']])
            ->when(isset($request['filter_category_ids']) && !empty($request['filter_category_ids']) && is_array($request['filter_category_ids']) && count($request['filter_category_ids']) > 0,
                function ($query) use ($request) {
                    return \App\Utils\ProductManager::filterQueryForCategoryWithSubCategories(
                        request: $request,
                        query: $query,
                        productAddedBy: isset($request['added_by']) && $this->isAddedByInHouse(addedBy: $request['added_by']) ? 'admin' : 'seller',
                        productUserID: isset($request['added_by']) && !$this->isAddedByInHouse(addedBy: $request['added_by']) && isset($request['seller_id']) ? $request['seller_id'] : 0
                    );
                }
            )
            ->when(isset($request['status']), function ($query) use ($request) {
                return $query->where('status', $request['status']);
            })
            ->when(isset($request['request_status']), function ($query) use ($request) {
                return $query->where('request_status', $request['request_status']);
            })
            ->when(isset($request['filter_sort_by']) && $request['filter_sort_by'] == 'latest', function ($query) use ($request) {
                return $query->orderBy('id', 'desc');
            })
            ->when(isset($request['filter_sort_by']) && $request['filter_sort_by'] == 'oldest', function ($query) use ($request) {
                return $query->orderBy('id', 'asc');
            })
            ->when(isset($request['filter_sort_by']) && $request['filter_sort_by'] == 'best-selling', function ($query) use ($request) {
                return $query->withCount([
                    'orderDetails' => function ($query) {
                        return $query->where('delivery_status', 'delivered')->whereDoesntHave('refundStatus', function ($query) {
                            return $query->where(['status' => 'approved']);
                        });
                    },
                    'orderDetails as totalSoldAmount' => function ($query) {
                        return $query->select(DB::raw('SUM(qty * price)'))
                            ->where('delivery_status', 'delivered')
                            ->whereDoesntHave('refundStatus', function ($query) {
                                return $query->where(['status' => 'approved']);
                            });
                    }
                ])->orderBy('order_details_count', 'desc');
            })
            ->when(isset($request['filter_sort_by']) && $request['filter_sort_by'] == 'most-favorite', function ($query) use ($request) {
                return $query->with('reviews', function ($query) {
                    return $query->whereHas('product', function ($query) {
                        $query->active();
                    });
                })
                    ->withCount(['reviews' => function ($query) {
                        return $query->whereNull('delivery_man_id');
                    }])
                    ->withAvg('rating as ratings_average', 'rating')
                    ->orderByDesc('reviews_count');
            })
            ->orderBy('id', 'DESC')->get();
        return response()->json($products, 200);
    }

    public function getVendorAllProducts($seller_id, Request $request): JsonResponse
    {
        // Ownership check: seller_api_auth proves WHO is calling, not WHICH shop they may read.
        // Without this an authenticated seller could still iterate {seller_id} and read a
        // competitor's full catalogue (IDOR).
        $authSellerId = $request['seller']->id ?? null;
        if ($authSellerId === null || (string) $authSellerId !== (string) $seller_id) {
            return response()->json(['message' => translate('unauthorized')], 403);
        }

        $brandIds = json_decode($request['brand_ids'] ?? '', true);
        $categoryIds = json_decode($request['category_ids'] ?? '', true);
        $publishingHouseIds = json_decode($request['publishing_house_ids'] ?? '', true);
        $authorIds = json_decode($request['author_ids'] ?? '', true);

        $filterCategoryIds = json_decode($request['filter_category_ids'] ?? '', true);
        $filterSubCategoryIds = json_decode($request['filter_sub_category_ids'] ?? '', true);
        $filterSubSubCategoryIds = json_decode($request['filter_sub_sub_category_ids'] ?? '', true);
        $filterProductTypes = json_decode($request['product_types'] ?? '', true);
        $filterProductStatus = json_decode($request['product_status'] ?? '', true);

        $request->merge(['filter_category_ids' => $filterCategoryIds]);
        $request->merge(['filter_sub_category_ids' => $filterSubCategoryIds]);
        $request->merge(['filter_sub_sub_category_ids' => $filterSubSubCategoryIds]);

        $publishingHouseList = PublishingHouse::with(['publishingHouseProducts'])
            ->whereHas('publishingHouseProducts.product', function ($query) {
                return $query->active();
            })
            ->withCount(['publishingHouseProducts' => function ($query) {
                return $query->whereHas('product', function ($query) {
                    return $query->active();
                });
            }])->get();

        $productIdsForPublisher = [];

        foreach ($publishingHouseList as $publishingHouseGroup) {
            if (!empty($publishingHouseGroup?->publishingHouseProducts)) {
                foreach ($publishingHouseGroup->publishingHouseProducts as $publishingHouse) {
                    $productIdsForPublisher[] = $publishingHouse->product_id;
                }
            }
        }

        $productIdsForUnknownPublisher = Product::active()->where(['product_type' => 'digital'])->whereNotIn('id', $productIdsForPublisher)->pluck('id')->toArray();

        $authorList = Author::withCount(['digitalProductAuthor' => function ($query) {
            return $query->whereHas('product', function ($query) {
                return $query->active();
            });
        }])->get();

        $productIdsForAuthor = [];

        foreach ($authorList as $authorGroup) {
            if (!empty($authorGroup?->digitalProductAuthor)) {
                foreach ($authorGroup->digitalProductAuthor as $authorItem) {
                    $productIdsForAuthor[] = $authorItem->product_id;
                }
            }
        }

        $productIdsForUnknownAuthor = Product::active()->where(['product_type' => 'digital'])->whereNotIn('id', $productIdsForAuthor)->pluck('id')->toArray();

        $products = Product::when($request['offer_type'] == 'clearance_sale', function ($query) {
                return $query->active();
            })->with(['brand', 'category', 'rating', 'tags', 'reviews', 'seoInfo', 'digitalVariation', 'digitalProductAuthors' => function ($query) {
                return $query->with(['author']);
            }, 'digitalProductPublishingHouse' => function ($query) {
                return $query->with(['publishingHouse']);
            }, 'clearanceSale' => function ($query) {
                return $query->active();
            }, 'taxVats' => function ($query) {
                return $query->with(['tax'])->wherehas('tax', function ($query) {
                    return $query->where('is_active', 1);
                });
            }])
            ->withCount('reviews')
            ->where(['user_id' => $seller_id, 'added_by' => 'seller'])
            ->when($request['search'], function ($query) use ($request) {
                $key = searchTerms($request['search']);
                foreach ($key as $value) {
                    $query->where('name', 'like', "%{$value}%");
                }
            })->when($request['search'] && $request['offer_type'] == 'clearance_sale', function ($query) {
                $clearanceSaleProductIds = StockClearanceProduct::pluck('product_id')->toArray();
                return $query->whereNotIn('id', $clearanceSaleProductIds);
            })
            ->when(in_array($request['product_type'], ['physical', 'digital']), function ($query) use ($request) {
                return $query->where(['product_type' => $request['product_type']]);
            })
            ->when(isset($request['product_types']) && is_array($filterProductTypes), function ($query) use ($request, $filterProductTypes) {
                return $query->whereIn('product_type', $filterProductTypes);
            })
            ->when($request->has('brand_ids') && json_decode($request['brand_ids'] ?? '', true), function ($query) use ($request) {
                $query->whereIn('brand_id', json_decode($request['brand_ids'] ?? '', true));
            })
            ->when($request->has('category_ids') && $categoryIds, function ($query) use ($categoryIds) {
                $query->where(function ($query) use ($categoryIds) {
                    return $query->whereIn('category_id', $categoryIds)
                        ->orWhereIn('sub_category_id', $categoryIds)
                        ->orWhereIn('sub_sub_category_id', $categoryIds);
                });
            })
            ->when($request->has('filter_category_ids') && !empty($filterCategoryIds) && is_array($filterCategoryIds) && count($filterCategoryIds) > 0,
                function ($query) use ($request) {
                    return \App\Utils\ProductManager::filterQueryForCategoryWithSubCategories(
                        request: $request,
                        query: $query,
                        productAddedBy: isset($request['added_by']) && $this->isAddedByInHouse(addedBy: $request['added_by']) ? 'admin' : 'seller',
                        productUserID: isset($request['added_by']) && !$this->isAddedByInHouse(addedBy: $request['added_by']) && isset($request['seller_id']) ? $request['seller_id'] : 0
                    );
                }
            )
            ->when(isset($request['status']), function ($query) use ($request) {
                return $query->where('status', $request['status']);
            })
            ->when(isset($request['product_status']) && is_array($filterProductStatus), function ($query) use ($request, $filterProductStatus) {
                return $query->whereIn('status', $filterProductStatus);
            })
            ->when(isset($request['request_status']), function ($query) use ($request) {
                return $query->where('request_status', $request['request_status']);
            })
            ->when(isset($request['filter_sort_by']) && $request['filter_sort_by'] == 'latest', function ($query) use ($request) {
                return $query->orderBy('id', 'desc');
            })
            ->when(isset($request['filter_sort_by']) && $request['filter_sort_by'] == 'oldest', function ($query) use ($request) {
                return $query->orderBy('id', 'asc');
            })
            ->when(isset($request['filter_sort_by']) && $request['filter_sort_by'] == 'best-selling', function ($query) use ($request) {
                return $query->withCount([
                    'orderDetails' => function ($query) {
                        return $query->where('delivery_status', 'delivered')->whereDoesntHave('refundStatus', function ($query) {
                            return $query->where(['status' => 'approved']);
                        });
                    },
                    'orderDetails as totalSoldAmount' => function ($query) {
                        return $query->select(DB::raw('SUM(qty * price)'))
                            ->where('delivery_status', 'delivered')
                            ->whereDoesntHave('refundStatus', function ($query) {
                                return $query->where(['status' => 'approved']);
                            });
                    }
                ])->orderBy('order_details_count', 'desc');
            })
            ->when(isset($request['filter_sort_by']) && $request['filter_sort_by'] == 'most-favorite', function ($query) use ($request) {
                return $query->with('reviews', function ($query) {
                    return $query->whereHas('product', function ($query) {
                        $query->active();
                    });
                })
                    ->withCount(['reviews' => function ($query) {
                        return $query->whereNull('delivery_man_id');
                    }])
                    ->withAvg('rating as ratings_average', 'rating')
                    ->orderByDesc('reviews_count');
            })
            ->when(($request['min_price'] != null && $request['min_price'] > 0), function ($query) use ($request) {
                $minPrice = Convert::usdPaymentModule($request['min_price'] ?? 0, \request('currency_code'));
                return $query->where('unit_price', '>=', $minPrice);
            })
            ->when(($request['max_price'] != null), function ($query) use ($request) {
                $maxPrice = Convert::usdPaymentModule($request['max_price'] ?? 0, \request('currency_code'));
                return $query->where('unit_price', '<=', $maxPrice);
            })
            ->when(isset($request['start_date']) && checkDateFormatInMDYAndTime(dateTime: $request['start_date']), function ($query) use ($request) {
                return $query->whereDate('created_at', '>=', Carbon::createFromFormat('m/d/Y h:i:s A', $request['start_date']));
            })
            ->when(isset($request['end_date']) && checkDateFormatInMDYAndTime(dateTime: $request['end_date']), function ($query) use ($request) {
                return $query->whereDate('created_at', '<=', Carbon::createFromFormat('m/d/Y h:i:s A', $request['end_date']));
            })
            ->when($request->has('publishing_house_ids') && !empty($publishingHouseIds), function ($query) use ($request, $productIdsForUnknownPublisher, $publishingHouseIds) {
                $publishingHouseList = PublishingHouse::whereIn('id', $publishingHouseIds)->with(['publishingHouseProducts'])->withCount(['publishingHouseProducts' => function ($query) {
                    return $query->whereHas('product', function ($query) {
                        return $query->active();
                    });
                }])->get();

                $publishingHouseProductIds = [];

                foreach ($publishingHouseList as $publishingHouseGroup) {
                    if (!empty($publishingHouseGroup?->publishingHouseProducts)) {
                        foreach ($publishingHouseGroup->publishingHouseProducts as $publishingHouse) {
                            $publishingHouseProductIds[] = $publishingHouse->product_id;
                        }
                    }
                }

                if (in_array(0, $publishingHouseIds)) {
                    $publishingHouseProductIds = array_merge($publishingHouseProductIds, $productIdsForUnknownPublisher);
                }

                return $query->where(['product_type' => 'digital'])->whereIn('id', $publishingHouseProductIds);
            })
            ->when($request->has('author_ids') && !empty($authorIds) && is_array($authorIds), function ($query) use ($request, $productIdsForUnknownAuthor, $authorIds) {
                $authorList = Author::whereIn('id', $authorIds)->withCount(['digitalProductAuthor' => function ($query) {
                    return $query->whereHas('product', function ($query) {
                        return $query->active();
                    });
                }])->get();


                $authorProductIds = [];
                foreach ($authorList as $authorGroup) {
                    if (!empty($authorGroup?->digitalProductAuthor)) {
                        foreach ($authorGroup->digitalProductAuthor as $authorItem) {
                            $authorProductIds[] = $authorItem->product_id;
                        }
                    }
                }
                if (in_array(0, $authorIds)) {
                    $authorProductIds = array_merge($authorProductIds, $productIdsForUnknownAuthor);
                }
                return $query->where(['product_type' => 'digital'])->whereIn('id', $authorProductIds);
            })
            ->latest()
            ->paginate($request['limit'], ['*'], 'page', $request['offset']);

        $products->map(function ($product) {
            $product->digital_product_authors_names = $this->productService->getProductAuthorsInfo(product: $product)['names'];
            $product->digital_product_publishing_house_names = $this->productService->getProductPublishingHouseInfo(product: $product)['names'];
            return $product;
        });

        $productsFinal = Helpers::product_data_formatting($products->items(), true);

        return response()->json([
            'total_size' => $products->total(),
            'limit' => (int)$request['limit'],
            'offset' => (int)$request['offset'],
            'products' => $productsFinal,
            'brand_ids' => $request->has('brand_ids') ? $brandIds : null,
            'category_ids' => $request->has('category_ids') ? $categoryIds : null,
            'product_type' => $request['product_type'] ?? null,
            'search' => $request['search'] ?? null,
            'max_price' => $request['max_price'] ?? null,
            'min_price' => $request['min_price'] ?? null,
            'start_date' => $request['start_date'] ?? null,
            'end_date' => $request['end_date'] ?? null,
            'offer_type' => $request['offer_type'] ?? null,
            'publishing_house_ids' => $request->has('publishing_house_ids') ? $publishingHouseIds : null,
            'author_ids' => $request->has('author_ids') ? $authorIds : null,
            'filter_category_ids' => $request->has('filter_category_ids') ? $filterCategoryIds : null,
            'filter_sub_category_ids' => $request->has('filter_sub_category_ids') ? $filterSubCategoryIds : null,
            'filter_sub_sub_category_ids' => $request->has('filter_sub_sub_category_ids') ? $filterSubSubCategoryIds : null,
            'status' => $request['status'] ?? null,
            'request_status' => $request['request_status'] ?? null,
            'filter_sort_by' => $request['filter_sort_by'] ?? null,
        ], 200);
    }


    public function editOrderVendorAllProducts($seller_id, Request $request): JsonResponse
    {
        // Ownership check: seller_api_auth proves WHO is calling, not WHICH shop they may read.
        // Without this an authenticated seller could still iterate {seller_id} and read a
        // competitor's full catalogue (IDOR).
        $authSellerId = $request['seller']->id ?? null;
        if ($authSellerId === null || (string) $authSellerId !== (string) $seller_id) {
            return response()->json(['message' => translate('unauthorized')], 403);
        }

        $brandIds = json_decode($request['brand_ids'] ?? '', true);
        $categoryIds = json_decode($request['category_ids'] ?? '', true);
        $publishingHouseIds = json_decode($request['publishing_house_ids'] ?? '', true);
        $authorIds = json_decode($request['author_ids'] ?? '', true);

        $filterCategoryIds = json_decode($request['filter_category_ids'] ?? '', true);
        $filterSubCategoryIds = json_decode($request['filter_sub_category_ids'] ?? '', true);
        $filterSubSubCategoryIds = json_decode($request['filter_sub_sub_category_ids'] ?? '', true);
        $filterProductTypes = json_decode($request['product_types'] ?? '', true);
        $filterProductStatus = json_decode($request['product_status'] ?? '', true);

        $request->merge(['filter_category_ids' => $filterCategoryIds]);
        $request->merge(['filter_sub_category_ids' => $filterSubCategoryIds]);
        $request->merge(['filter_sub_sub_category_ids' => $filterSubSubCategoryIds]);

        $publishingHouseList = PublishingHouse::with(['publishingHouseProducts'])
            ->whereHas('publishingHouseProducts.product', function ($query) {
                return $query->active();
            })
            ->withCount(['publishingHouseProducts' => function ($query) {
                return $query->whereHas('product', function ($query) {
                    return $query->active();
                });
            }])->get();

        $productIdsForPublisher = [];

        foreach ($publishingHouseList as $publishingHouseGroup) {
            if (!empty($publishingHouseGroup?->publishingHouseProducts)) {
                foreach ($publishingHouseGroup->publishingHouseProducts as $publishingHouse) {
                    $productIdsForPublisher[] = $publishingHouse->product_id;
                }
            }
        }

        $productIdsForUnknownPublisher = Product::active()->where(['product_type' => 'digital'])->whereNotIn('id', $productIdsForPublisher)->pluck('id')->toArray();

        $authorList = Author::withCount(['digitalProductAuthor' => function ($query) {
            return $query->whereHas('product', function ($query) {
                return $query->active();
            });
        }])->get();

        $productIdsForAuthor = [];

        foreach ($authorList as $authorGroup) {
            if (!empty($authorGroup?->digitalProductAuthor)) {
                foreach ($authorGroup->digitalProductAuthor as $authorItem) {
                    $productIdsForAuthor[] = $authorItem->product_id;
                }
            }
        }

        $productIdsForUnknownAuthor = Product::active()->where(['product_type' => 'digital'])->whereNotIn('id', $productIdsForAuthor)->pluck('id')->toArray();

        $products = Product::when($request['offer_type'] == 'clearance_sale', function ($query) {
            return $query->active();
        })->with(['brand', 'category', 'rating', 'tags', 'reviews', 'seoInfo', 'digitalVariation', 'digitalProductAuthors' => function ($query) {
            return $query->with(['author']);
        }, 'digitalProductPublishingHouse' => function ($query) {
            return $query->with(['publishingHouse']);
        }, 'clearanceSale' => function ($query) {
            return $query->active();
        }, 'taxVats' => function ($query) {
            return $query->with(['tax'])->wherehas('tax', function ($query) {
                return $query->where('is_active', 1);
            });
        }])
            ->withCount('reviews')
            ->where(['user_id' => $seller_id, 'added_by' => 'seller'])
            ->when($request['search'], function ($query) use ($request) {
                $key = searchTerms($request['search']);
                foreach ($key as $value) {
                    $query->where('name', 'like', "%{$value}%");
                }
            })->when($request['search'] && $request['offer_type'] == 'clearance_sale', function ($query) {
                $clearanceSaleProductIds = StockClearanceProduct::pluck('product_id')->toArray();
                return $query->whereNotIn('id', $clearanceSaleProductIds);
            })
            ->when(in_array($request['product_type'], ['physical', 'digital']), function ($query) use ($request) {
                return $query->where(['product_type' => $request['product_type']]);
            })
            ->when(isset($request['product_types']) && is_array($filterProductTypes), function ($query) use ($request, $filterProductTypes) {
                return $query->whereIn('product_type', $filterProductTypes);
            })
            ->when($request->has('brand_ids') && json_decode($request['brand_ids'] ?? '', true), function ($query) use ($request) {
                $query->whereIn('brand_id', json_decode($request['brand_ids'] ?? '', true));
            })
            ->when($request->has('category_ids') && $categoryIds, function ($query) use ($categoryIds) {
                $query->where(function ($query) use ($categoryIds) {
                    return $query->whereIn('category_id', $categoryIds)
                        ->orWhereIn('sub_category_id', $categoryIds)
                        ->orWhereIn('sub_sub_category_id', $categoryIds);
                });
            })
            ->when($request->has('filter_category_ids') && !empty($filterCategoryIds) && is_array($filterCategoryIds) && count($filterCategoryIds) > 0,
                function ($query) use ($request) {
                    return \App\Utils\ProductManager::filterQueryForCategoryWithSubCategories(
                        request: $request,
                        query: $query,
                        productAddedBy: isset($request['added_by']) && $this->isAddedByInHouse(addedBy: $request['added_by']) ? 'admin' : 'seller',
                        productUserID: isset($request['added_by']) && !$this->isAddedByInHouse(addedBy: $request['added_by']) && isset($request['seller_id']) ? $request['seller_id'] : 0
                    );
                }
            )
            ->when(isset($request['status']), function ($query) use ($request) {
                return $query->where('status', $request['status']);
            })
            ->when(isset($request['product_status']) && is_array($filterProductStatus), function ($query) use ($request, $filterProductStatus) {
                return $query->whereIn('status', $filterProductStatus);
            })
            ->when(isset($request['request_status']), function ($query) use ($request) {
                return $query->where('request_status', $request['request_status']);
            })
            ->when(isset($request['filter_sort_by']) && $request['filter_sort_by'] == 'latest', function ($query) use ($request) {
                return $query->orderBy('id', 'desc');
            })
            ->when(isset($request['filter_sort_by']) && $request['filter_sort_by'] == 'oldest', function ($query) use ($request) {
                return $query->orderBy('id', 'asc');
            })
            ->when(isset($request['filter_sort_by']) && $request['filter_sort_by'] == 'best-selling', function ($query) use ($request) {
                return $query->withCount([
                    'orderDetails' => function ($query) {
                        return $query->where('delivery_status', 'delivered')->whereDoesntHave('refundStatus', function ($query) {
                            return $query->where(['status' => 'approved']);
                        });
                    },
                    'orderDetails as totalSoldAmount' => function ($query) {
                        return $query->select(DB::raw('SUM(qty * price)'))
                            ->where('delivery_status', 'delivered')
                            ->whereDoesntHave('refundStatus', function ($query) {
                                return $query->where(['status' => 'approved']);
                            });
                    }
                ])->orderBy('order_details_count', 'desc');
            })
            ->when(isset($request['filter_sort_by']) && $request['filter_sort_by'] == 'most-favorite', function ($query) use ($request) {
                return $query->with('reviews', function ($query) {
                    return $query->whereHas('product', function ($query) {
                        $query->active();
                    });
                })
                    ->withCount(['reviews' => function ($query) {
                        return $query->whereNull('delivery_man_id');
                    }])
                    ->withAvg('rating as ratings_average', 'rating')
                    ->orderByDesc('reviews_count');
            })
            ->when(($request['min_price'] != null && $request['min_price'] > 0), function ($query) use ($request) {
                $minPrice = Convert::usdPaymentModule($request['min_price'] ?? 0, \request('currency_code'));
                return $query->where('unit_price', '>=', $minPrice);
            })
            ->when(($request['max_price'] != null), function ($query) use ($request) {
                $maxPrice = Convert::usdPaymentModule($request['max_price'] ?? 0, \request('currency_code'));
                return $query->where('unit_price', '<=', $maxPrice);
            })
            ->when(isset($request['start_date']) && checkDateFormatInMDYAndTime(dateTime: $request['start_date']), function ($query) use ($request) {
                return $query->whereDate('created_at', '>=', Carbon::createFromFormat('m/d/Y h:i:s A', $request['start_date']));
            })
            ->when(isset($request['end_date']) && checkDateFormatInMDYAndTime(dateTime: $request['end_date']), function ($query) use ($request) {
                return $query->whereDate('created_at', '<=', Carbon::createFromFormat('m/d/Y h:i:s A', $request['end_date']));
            })
            ->when($request->has('publishing_house_ids') && !empty($publishingHouseIds), function ($query) use ($request, $productIdsForUnknownPublisher, $publishingHouseIds) {
                $publishingHouseList = PublishingHouse::whereIn('id', $publishingHouseIds)->with(['publishingHouseProducts'])->withCount(['publishingHouseProducts' => function ($query) {
                    return $query->whereHas('product', function ($query) {
                        return $query->active();
                    });
                }])->get();

                $publishingHouseProductIds = [];

                foreach ($publishingHouseList as $publishingHouseGroup) {
                    if (!empty($publishingHouseGroup?->publishingHouseProducts)) {
                        foreach ($publishingHouseGroup->publishingHouseProducts as $publishingHouse) {
                            $publishingHouseProductIds[] = $publishingHouse->product_id;
                        }
                    }
                }

                if (in_array(0, $publishingHouseIds)) {
                    $publishingHouseProductIds = array_merge($publishingHouseProductIds, $productIdsForUnknownPublisher);
                }

                return $query->where(['product_type' => 'digital'])->whereIn('id', $publishingHouseProductIds);
            })
            ->when($request->has('author_ids') && !empty($authorIds) && is_array($authorIds), function ($query) use ($request, $productIdsForUnknownAuthor, $authorIds) {
                $authorList = Author::whereIn('id', $authorIds)->withCount(['digitalProductAuthor' => function ($query) {
                    return $query->whereHas('product', function ($query) {
                        return $query->active();
                    });
                }])->get();


                $authorProductIds = [];
                foreach ($authorList as $authorGroup) {
                    if (!empty($authorGroup?->digitalProductAuthor)) {
                        foreach ($authorGroup->digitalProductAuthor as $authorItem) {
                            $authorProductIds[] = $authorItem->product_id;
                        }
                    }
                }
                if (in_array(0, $authorIds)) {
                    $authorProductIds = array_merge($authorProductIds, $productIdsForUnknownAuthor);
                }
                return $query->where(['product_type' => 'digital'])->whereIn('id', $authorProductIds);
            })
            ->latest()
            ->paginate($request['limit'], ['*'], 'page', $request['offset']);

        $order = Order::where('id', $request['order_id'])->first();
        $products->map(function ($product) use ($order) {
            $product->digital_product_authors_names = $this->productService->getProductAuthorsInfo(product: $product)['names'];
            $product->digital_product_publishing_house_names = $this->productService->getProductPublishingHouseInfo(product: $product)['names'];
            if ($order) {
                $currentStock = max(0, $product->current_stock);
                $checkDetailsQty = collect($order?->details)?->where('product_id', $product['id'])?->sum('qty') ?? 0;
                if ($checkDetailsQty > 0) {
                    $currentStock += $checkDetailsQty;
                }
                $product->current_stock = $currentStock;
            }
            return $product;
        });

        $productsFinal = Helpers::product_data_formatting($products->items(), true);

        return response()->json([
            'total_size' => $products->total(),
            'limit' => (int)$request['limit'],
            'offset' => (int)$request['offset'],
            'products' => $productsFinal,
            'brand_ids' => $request->has('brand_ids') ? $brandIds : null,
            'category_ids' => $request->has('category_ids') ? $categoryIds : null,
            'product_type' => $request['product_type'] ?? null,
            'search' => $request['search'] ?? null,
            'max_price' => $request['max_price'] ?? null,
            'min_price' => $request['min_price'] ?? null,
            'start_date' => $request['start_date'] ?? null,
            'end_date' => $request['end_date'] ?? null,
            'offer_type' => $request['offer_type'] ?? null,
            'publishing_house_ids' => $request->has('publishing_house_ids') ? $publishingHouseIds : null,
            'author_ids' => $request->has('author_ids') ? $authorIds : null,
            'filter_category_ids' => $request->has('filter_category_ids') ? $filterCategoryIds : null,
            'filter_sub_category_ids' => $request->has('filter_sub_category_ids') ? $filterSubCategoryIds : null,
            'filter_sub_sub_category_ids' => $request->has('filter_sub_sub_category_ids') ? $filterSubSubCategoryIds : null,
            'status' => $request['status'] ?? null,
            'request_status' => $request['request_status'] ?? null,
            'filter_sort_by' => $request['filter_sort_by'] ?? null,
        ], 200);
    }

    public function details(Request $request, $id): JsonResponse
    {
        $seller = $request->seller;
        $product = Product::withoutGlobalScopes()->with(['seoInfo', 'digitalProductAuthors', 'digitalProductPublishingHouse', 'clearanceSale' => function ($query) {
            return $query->active();
        }, 'taxVats' => function ($query) {
            return $query->with(['tax'])->wherehas('tax', function ($query) {
                return $query->where('is_active', 1);
            });
        }, 'translations'])
        ->withCount('reviews')->where(['added_by' => 'seller', 'user_id' => $seller->id])
        ->find($id);

        if (isset($product)) {
            $product = Helpers::product_data_formatting($product, false);
        }
        return response()->json($product, 200);
    }

    public function getProductImages(Request $request, $id):JsonResponse
    {
        $seller = $request->seller;
        $product = Product::where(['added_by' => 'seller', 'user_id' => $seller->id])->find($id);
        $productImage = [];
        if (isset($product)) {
            $productImage = [
                'images' => json_decode($product->images),
                'color_image' => json_decode($product->color_image),
                'images_full_url' => $product->images_full_url,
                'color_images_full_url' => $product->color_images_full_url,
            ];
        }
        return response()->json($productImage, 200);
    }

    public function stock_out_list(Request $request):JsonResponse
    {
        $seller = $request->seller;
        $stockLimit = $seller['stock_limit'] <= 0 ? getWebConfig(name: 'stock_limit') : $seller['stock_limit'];

        $products = Product::withCount('reviews')
            ->with(['seoInfo', 'digitalProductAuthors', 'digitalProductPublishingHouse', 'clearanceSale' => function ($query) {
                return $query->active();
            }])
            ->where(['added_by' => 'seller', 'user_id' => $seller->id, 'product_type' => 'physical', 'request_status' => 1])
            ->when($stockLimit <= 0, function ($query) {
                return $query->where('current_stock', 0);
            })
            ->when($stockLimit > 0, function ($query) use ($stockLimit) {
                return $query->where('current_stock', '<', $stockLimit);
            })
            ->paginate($request['limit'], ['*'], 'page', $request['offset']);

        $products->map(function ($data) {
            return Helpers::product_data_formatting($data);
        });

        return response()->json([
            'total_size' => $products->total(),
            'limit' => (int)$request['limit'],
            'offset' => (int)$request['offset'],
            'products' => $products->items()
        ], 200);
    }

    public function upload_images(ProductUploadImageRequest $request):JsonResponse
    {
        $path = $request['type'] == 'product' ? '' : $request['type'] . '/';
        $image = $this->upload('product/' . $path, 'webp', $request->file('image'));
        if ($request['colors_active'] == "true") {
            $color_image = array(
                "color" => !empty($request['color']) ? str_replace('#', '', $request['color']) : null,
                "image_name" => $image,
            );
        } else {
            $color_image = null;
        }

        return response()->json([
            'image_name' => $image,
            'type' => $request['type'],
            'color_image' => $color_image,
            'storage' => config('filesystems.disks.default') ?? 'public',
        ], 200);
    }

    // Digital product file upload
    public function upload_digital_product(DigitalProductUploadRequest $request):JsonResponse
    {
        $seller = $request->seller;
        try {
            $file = $this->fileUpload('product/digital-product/', $request->digital_file_ready->getClientOriginalExtension(), $request->file('digital_file_ready'));

            return response()->json(['digital_file_ready_name' => $file], 200);
        } catch (\Exception $e) {
            return response()->json(['errors' => $e], 403);
        }
    }


    public function deleteDigitalProduct(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required',
            'variant_key' => 'required',
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $variation = DigitalProductVariation::where(['product_id' => $request['product_id'], 'variant_key' => $request['variant_key']])->first();
        if ($variation) {
            DigitalProductVariation::where(['id' => $variation['id']])->update(['file' => null]);
            return response()->json([
                'status' => 1,
                'message' => translate('delete_successful')
            ]);
        }
        return response()->json([
            'status' => 0,
            'message' => translate('delete_unsuccessful')
        ]);
    }

    public function add_new(Request $request): JsonResponse
    {
        $seller = $request->seller;

        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'category_id' => 'required',
            'product_type' => 'required',
            'unit' => 'required_if:product_type,==,physical',
            'images' => 'required',
            'thumbnail' => 'required',
            'discount_type' => 'required|in:percent,flat',
            'lang' => 'required',
            'unit_price' => 'required|min:1',
            'discount' => 'required|gt:-1',
            'shipping_cost' => 'required_if:product_type,==,physical|gt:-1',
            'code' => 'required|min:6|max:20|regex:/^[a-zA-Z0-9]+$/|unique:products',
            'minimum_order_qty' => 'required|numeric|min:1',
        ], [
            'name.required' => translate('Product name is required!'),
            'unit.required_if' => translate('Unit is required!'),
            'category_id.required' => translate('category is required!'),
            'shipping_cost.required_if' => translate('Shipping Cost is required!'),
            'images.required' => translate('Product images is required!'),
            'image.required' => translate('Product thumbnail is required!'),
            'code.required' => translate('Code is required!'),
            'minimum_order_qty.required' => translate('The minimum order quantity is required!'),
            'minimum_order_qty.min' => translate('The minimum order quantity must be positive!'),
        ]);

        $validator->after($this->brandClaimGuard($request, $seller));

        $taxData = $this->getTaxSystemType();
        $productWiseTax = $taxData['productWiseTax'] && !$taxData['is_included'];

        if ($productWiseTax && (!isset($request['tax_ids']) || empty(json_decode($request['tax_ids'], true)))) {
            $validator->after(function ($validator) {
                $validator->errors()->add('tax', translate('Please_add_your_product_tax') . '!');
            });
        }
        $disallowedExtensions = getDisallowedExtensionsListArray();
        if ($request['preview_file']) {
            $extension = '';
            if (is_string($request['preview_file'])) {
                $extension = strtolower(pathinfo($request['preview_file'], PATHINFO_EXTENSION));
            } elseif ($request['preview_file'] instanceof \Illuminate\Http\UploadedFile) {
                $extension = strtolower($request['preview_file']->getClientOriginalExtension());
            }
            if (in_array($extension, $disallowedExtensions) &&  env('APP_MODE', 'dev') == 'demo') {
                $validator->after(function ($validator) {
                    $validator->errors()->add('files', translate('Uploading_ZIP_files_is_currently_unavailable_in_demo_mode') . '!');
                });
            }elseif (in_array($extension, $disallowedExtensions)) {
                $validator->after(function ($validator) {
                    $validator->errors()->add('files', translate('The_uploaded_file_type_is_not_supported') . '!');
                });
            }
        }
        if ($request['product_type'] == 'digital') {
            $digitalFileOptions = self::getDigitalVariationOptions(request: $request);
            $digitalFileCombinations = self::getDigitalVariationCombinations(arrays: $digitalFileOptions);
            foreach ($digitalFileCombinations as $combinationKey => $combination) {
                foreach ($combination as $item) {
                    $string = $combinationKey . '-' . str_replace(' ', '', $item);
                    $uniqueKey = strtolower(str_replace('-', '_', $string));
                    $fileItem = $request->file('digital_files_' . $uniqueKey);

                    if ($fileItem) {
                        $extension = '';
                        if (is_string($fileItem)) {
                            $extension = strtolower(pathinfo($fileItem, PATHINFO_EXTENSION));
                        } elseif ($fileItem instanceof \Illuminate\Http\UploadedFile) {
                            $extension = strtolower($fileItem->getClientOriginalExtension());
                        }
                        if (in_array($extension, $disallowedExtensions) &&  env('APP_MODE', 'dev') == 'demo') {
                            $validator->after(function ($validator) {
                                $validator->errors()->add('digital_files', translate('Uploading_ZIP_files_is_currently_unavailable_in_demo_mode') . '!');
                            });
                        } elseif (in_array($extension, $disallowedExtensions)) {
                            $validator->after(function ($validator) {
                                $validator->errors()->add('digital_files', translate('The_uploaded_file_type_is_not_supported'). '!');
                            });
                        }
                    }
                }
            }
        }
        $discount = $request['discount_type'] == 'percent' ? (($request['unit_price'] / 100) * $request['discount']) : $request['discount'];

        if ($request['unit_price'] <= $discount) {
            $validator->after(function ($validator) {
                $validator->errors()->add('unit_price', translate('Discount can not be more or equal to the price!'));
            });
        }

        $category = [];
        if ($request['category_id'] != null) {
            $category[] = ['id' => $request['category_id'], 'position' => 1];
        }
        if ($request['sub_category_id'] != null) {
            $category[] = ['id' => $request['sub_category_id'], 'position' => 2];
        }
        if ($request['sub_sub_category_id'] != null) {
            $category[] = ['id' => $request['sub_sub_category_id'], 'position' => 3];
        }

        $requestLanguage = json_decode($request['lang'], true);
        $requestName = json_decode($request['name'], true);
        $requestDescription = json_decode($request['description'], true);
        $requestColors = json_decode($request['colors'], true);
        $requestImages = json_decode($request['images'], true);
        $requestColorImages = json_decode($request['color_image'], true);
        $requestTags = json_decode($request['tags'], true);
        $requestChoiceArray = json_decode($request['choice'], true);
        $requestChoiceNo = json_decode($request['choice_no'], true);
        $requestChoiceAttributes = json_decode($request['choice_attributes'], true);
        $storage = config('filesystems.disks.default') ?? 'public';
        $productArray = [
            'user_id' => $seller->id,
            'shop_id' => Shop::where('seller_id', $seller->id)->first()->id ?? null,
            'added_by' => "seller",
            'name' => $requestName[array_search(Helpers::default_lang(), $requestLanguage)],
            'slug' => Str::slug($requestName[array_search(Helpers::default_lang(), $requestLanguage)], '-') . '-' . Str::random(6),
            'category_ids' => json_encode($category),
            'category_id' => $request['category_id'],
            'sub_category_id' => $request['sub_category_id'],
            'sub_sub_category_id' => $request['sub_sub_category_id'],
            'brand_id' => $request['product_type'] == "physical" ? ( $request['brand_id'] ?? null) : null,
            'unit' => $request['product_type'] == 'physical' ? $request['unit'] : null,
            'product_type' => $request['product_type'],
            'digital_product_type' => $request['product_type'] == 'digital' ? $request['digital_product_type'] : null,
            'code' => $request['code'],
            'minimum_order_qty' => $request['minimum_order_qty'],
            'details' => $requestDescription[array_search(Helpers::default_lang(), $requestLanguage)],
            'images' => json_encode($requestImages),
            'color_image' => json_encode($requestColorImages),
            'thumbnail' => $request['thumbnail'],
            'thumbnail_storage_type' => $request['thumbnail'] ? $storage : null,
        ];

        if ($request['product_type'] == 'digital' && $request['digital_product_type'] == 'ready_product' && $request['digital_file_ready']) {
            $digitalFileExtension = '';
            if (is_string($request['digital_file_ready'])) {
                $digitalFileExtension = strtolower(pathinfo($request['digital_file_ready'], PATHINFO_EXTENSION));
            } elseif ($request['digital_file_ready'] instanceof \Illuminate\Http\UploadedFile) {
                $digitalFileExtension = strtolower($request['digital_file_ready']->getClientOriginalExtension());
            }
            if (in_array($digitalFileExtension, $disallowedExtensions) && env('APP_MODE', 'dev') == 'demo') {
                $validator->after(function ($validator) {
                    $validator->errors()->add('files', translate('Uploading_ZIP_files_is_currently_unavailable_in_demo_mode') . '!');
                });
            }
            if(in_array($digitalFileExtension, $disallowedExtensions)) {
                $validator->after(function ($validator) {
                    $validator->errors()->add('files', translate('The_uploaded_file_type_is_not_supported') . '!');
                });
            }
            $productArray['digital_file_ready'] = $request['digital_file_ready'];
            $productArray['digital_file_ready_storage_type'] = $storage;
        }

        if ($request->has('colors_active') && $request->has('colors') && count($requestColors) > 0) {
            $productArray['colors'] = $request['product_type'] == 'physical' ? json_encode($requestColors) : json_encode([]);
        } else {
            $colors = [];
            $productArray['colors'] = $request['product_type'] == 'physical' ? json_encode($colors) : json_encode([]);
        }

        $choiceOptions = [];
        $requestChoiceNoIndex = 0;
        if ($request->has('choice')) {
            foreach ($requestChoiceNo as $key => $no) {
                $str = 'choice_options_' . $no;
                $optionValues = $request[$str];
                // An attribute whose options were removed before saving must not persist an empty,
                // unusable variation attribute (keeps parity with the web ProductService fix).
                if (empty($optionValues)) {
                    $requestChoiceNoIndex++;
                    continue;
                }
                $item = [];
                $item['name'] = 'choice_' . $no;
                $item['title'] = $requestChoiceArray[$requestChoiceNoIndex] ?? ('choice_' . $no);
                $item['options'] = $optionValues;
                $choiceOptions[] = $item;
                $requestChoiceNoIndex++;
            }
        }
        $productArray['choice_options'] = $request['product_type'] == 'physical' ? json_encode($choiceOptions) : json_encode([]);

        //combinations start
        $options = [];
        if ($request->has('colors_active') && $request->has('colors') && count($requestColors) > 0) {
            $colors_active = 1;
            $options[] = $requestColors;
        }
        if ($request->has('choice_no')) {
            foreach ($requestChoiceNo as $key => $no) {
                $name = 'choice_options_' . $no;
                $optionValues = $request[$name];
                // Skip an attribute emptied by removal so it doesn't collapse the whole
                // variation set (an empty factor zeroes the cartesian product).
                if (empty($optionValues)) {
                    continue;
                }
                $options[] = $optionValues;
            }
        }

        //Generates the combinations of customer choice options
        $combinations = Helpers::combinations($options);
        $variations = [];
        $stock_count = 0;
        if (count($combinations[0]) > 0) {

            foreach ($combinations as $combination) {
                $str = '';
                foreach ($combination as $k => $item) {
                    if ($k > 0) {
                        $str .= '-' . str_replace(' ', '', $item);
                    } else {
                        if ($request->has('colors_active') && $request->has('colors') && count($requestColors) > 0) {
                            $color_name = Color::where('code', $item)->first()->name ?? '';
                            $str .= $color_name;
                        } else {
                            $str .= str_replace(' ', '', $item);
                        }
                    }
                }
                $item = [];
                $item['type'] = $str;
                $item['price'] = Convert::usd(abs($request['price_' . str_replace('.', '_', $str)]));
                $item['sku'] = $request['sku_' . str_replace('.', '_', $str)];
                $item['qty'] = $request['qty_' . str_replace('.', '_', $str)];

                $variations[] = $item;
                $stock_count += $item['qty'];
            }
        } else {
            $stock_count = (int)$request['current_stock'];
        }

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $digitalFileOptions = self::getDigitalVariationOptions(request: $request);
        $digitalFileCombinations = self::getDigitalVariationCombinations(arrays: $digitalFileOptions);

        $previewFile = '';
        if ($request['product_type'] == 'digital' && $request->has('preview_file')) {
            $previewFile = $this->fileUpload(dir: 'product/preview/', format: $request['preview_file']->getClientOriginalExtension(), file: $request['preview_file']);
        }

        //combinations end
        $productArray += [
            'variation' => $request['product_type'] == 'physical' ? json_encode($variations) : json_encode([]),
            'unit_price' => Convert::usd($request['unit_price']),
            'purchase_price' => 0,
            'discount' => $request['discount_type'] == 'flat' ? Convert::usd($request['discount']) : $request['discount'],
            'discount_type' => $request['discount_type'],

            'attributes' => $request['product_type'] == 'physical' ? json_encode($requestChoiceAttributes) : json_encode([]),
            'current_stock' => $request['product_type'] == 'physical' ? abs($stock_count) : 999999999,

            'video_provider' => 'youtube',
            'video_url' => $request['video_url'],
            'request_status' => getWebConfig(name: 'new_product_approval') == 1 ? 0 : 1,
            'status' => 0,
            'shipping_cost' => $request['product_type'] == 'physical' ? Convert::usd($request['shipping_cost']) : 0,
            'multiply_qty' => ($request['product_type'] == 'physical') ? ($request['multiplyQTY'] == 1 ? 1 : 0) : 0,
            'digital_product_file_types' => $request->has('extensions_type') ? json_decode($request['extensions_type'], true) : [],
            'digital_product_extensions' => $digitalFileCombinations,
            'preview_file' => $previewFile,
            'preview_file_storage_type' => $request->has('preview_file') ? $storage : null,
        ];

        $product = Product::create($productArray);

        $this->updateProductAuthorAndPublishingHouse(request: $request, product: $product);
        $digitalFileArray = self::getAddProductDigitalVariationData(request: $request, product: $product);
        foreach ($digitalFileArray as $digitalFile) {
            DigitalProductVariation::create($digitalFile);
        }

        ProductSeo::create(self::getProductSEOData(request: $request, product: $product));

        $productTagIds = [];
        if ($request['tags'] && count($requestTags) > 0) {
            foreach ($requestTags as $key => $value) {
                $tag = Tag::firstOrNew(['tag' => trim($value)]);
                $tag->save();
                $productTagIds[] = $tag->id;
            }
        }
        $product->tags()->sync($productTagIds);

        $data = [];
        foreach ($requestLanguage as $index => $key) {
            if ($requestName[$index] && $key != Helpers::default_lang()) {
                $data[] = [
                    'translationable_type' => 'App\Models\Product',
                    'translationable_id' => $product->id,
                    'locale' => $key,
                    'key' => 'name',
                    'value' => $requestName[$index],
                ];
            }
            if ($requestDescription[$index] && $key != Helpers::default_lang()) {
                $data[] = [
                    'translationable_type' => 'App\Models\Product',
                    'translationable_id' => $product->id,
                    'locale' => $key,
                    'key' => 'description',
                    'value' => $requestDescription[$index],
                ];
            }
        }
        Translation::insert($data);

        $this->getAddTaxData(
            taxableType: \App\Models\Product::class,
            taxableId: $product->id,
            taxIds: json_decode($request['tax_ids'], true)
        );

        return response()->json(['message' => translate('successfully product added!'), 'request' => $request->all()], 200);
    }

    public function updateProductAuthorAndPublishingHouse(object|array $request, object|array $product): void
    {
        if ($request['product_type'] == 'digital') {
            if ($request->has('authors')) {
                $authorIds = [];
                foreach (json_decode($request['authors'], true) as $author) {
                    $authorId = $this->authorRepo->updateOrCreate(params: ['name' => $author], value: ['name' => $author]);
                    $authorIds[] = $authorId?->id;
                }

                foreach ($authorIds as $author) {
                    $productAuthorData = ['author_id' => $author, 'product_id' => $product->id];
                    $this->digitalProductAuthorRepo->updateOrCreate(params: $productAuthorData, value: $productAuthorData);
                }

                $this->digitalProductAuthorRepo->deleteWhereNotIn(filters: ['product_id' => $product->id], whereNotIn: ['author_id' => $authorIds]);
            } else {
                $this->digitalProductAuthorRepo->delete(params: ['product_id' => $product->id]);
            }

            if ($request->has('publishing_house')) {
                $publishingHouseIds = [];
                foreach (json_decode($request['publishing_house'], true) as $publishingHouse) {
                    $publishingHouseId = $this->publishingHouseRepo->updateOrCreate(params: ['name' => $publishingHouse], value: ['name' => $publishingHouse]);
                    $publishingHouseIds[] = $publishingHouseId?->id;
                }

                foreach ($publishingHouseIds as $publishingHouse) {
                    $publishingHouseData = ['publishing_house_id' => $publishingHouse, 'product_id' => $product->id];
                    $this->digitalProductPublishingHouseRepo->updateOrCreate(params: $publishingHouseData, value: $publishingHouseData);
                }
                $this->digitalProductPublishingHouseRepo->deleteWhereNotIn(filters: ['product_id' => $product->id], whereNotIn: ['publishing_house_id' => $publishingHouseIds]);
            } else {
                $this->digitalProductPublishingHouseRepo->delete(params: ['product_id' => $product->id]);
            }
        } else {
            $this->digitalProductAuthorRepo->delete(params: ['product_id' => $product->id]);
            $this->digitalProductPublishingHouseRepo->delete(params: ['product_id' => $product->id]);
        }
    }

    public function getAddProductDigitalVariationData(object $request, object|array $product)
    {
        $digitalFileOptions = self::getDigitalVariationOptions(request: $request);
        $digitalFileCombinations = self::getDigitalVariationCombinations(arrays: $digitalFileOptions);

        $digitalFiles = [];
        foreach ($digitalFileCombinations as $combinationKey => $combination) {
            foreach ($combination as $item) {
                $string = $combinationKey . '-' . str_replace(' ', '', $item);
                $uniqueKey = strtolower(str_replace('-', '_', $string));
                $fileItem = $request->file('digital_files_' . $uniqueKey);
                $uploadedFile = '';
                if ($fileItem) {
                    $uploadedFile = $this->fileUpload(dir: 'product/digital-product/', format: $fileItem->getClientOriginalExtension(), file: $fileItem);
                }
                $digitalFiles[] = [
                    'product_id' => $product->id,
                    'variant_key' => json_decode($request['digital_product_variant_key'], true)[$uniqueKey],
                    'sku' => json_decode($request['digital_product_sku'], true)[$uniqueKey],
                    'price' => json_decode($request['digital_product_price'], true)[$uniqueKey],
                    'file' => $uploadedFile,
                ];
            }
        }
        return $digitalFiles;
    }

    public function getDigitalVariationOptions(object $request): array
    {
        $options = [];
        if ($request->has('extensions_type')) {
            foreach ((json_decode($request['extensions_type'], true) ?? []) as $type) {
                $type = str_replace(' ', '_', $type);
                $name = 'extensions_options_' . $type;
                $decoded = json_decode($request[$name] ?? '[]', true);
                $options[$type] = is_array($decoded) ? $decoded : [];
            }
        }
        return $options;
    }

    public function getDigitalVariationCombinations(array $arrays = []): array
    {
        $result = [];
        if (count($arrays) > 0) {
            foreach ($arrays as $arrayKey => $array) {
                foreach ($array as $key => $value) {
                    if ($value) {
                        $result[$arrayKey][] = $value;
                    }
                }
            }
        }
        return $result;
    }

    public function edit(Request $request, $id)
    {
        $product = Product::withoutGlobalScopes()->with('translations', 'tags', 'digitalVariation', 'seoInfo')->withCount('reviews')->find($id);
        $product = Helpers::product_data_formatting($product);

        return response()->json($product, 200);
    }

    public function updateProduct(Request $request, $id): JsonResponse
    {
        $storage = config('filesystems.disks.default') ?? 'public';
        $seller = $request->seller;
        $product = Product::with(['digitalVariation', 'seoInfo'])->withCount('reviews')->find($id);
        $oldProductData = $product;
        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'category_id' => 'required',
            'product_type' => 'required',
            'unit' => 'required_if:product_type,==,physical',
            'discount_type' => 'required|in:percent,flat',
            'lang' => 'required',
            'unit_price' => 'required|min:1',
            'discount' => 'required|gt:-1',
            'shipping_cost' => 'required_if:product_type,==,physical|gt:-1',
            'minimum_order_qty' => 'required|numeric|min:1',
            'code' => 'required|min:6|max:20|regex:/^[a-zA-Z0-9]+$/|unique:products,code,' . $product->id,
        ], [
            'name.required' => 'Product name is required!',
            'category_id.required' => 'category  is required!',
            'unit.required_if' => 'Unit is required!',
            'code.min' => 'The code must be positive!',
            'code.digits_between' => 'The code must be minimum 6 digits!',
            'code.required' => 'Product code sku is required!',
            'minimum_order_qty.required' => 'The minimum order quantity is required!',
            'minimum_order_qty.min' => 'The minimum order quantity must be positive!',
        ]);

        $validator->after($this->brandClaimGuard($request, $seller));

        $taxData = $this->getTaxSystemType();
        $productWiseTax = $taxData['productWiseTax'] && !$taxData['is_included'];
        if ($productWiseTax && (!isset($request['tax_ids']) || empty(json_decode($request['tax_ids'], true)))) {
            $validator->after(function ($validator) {
                $validator->errors()->add('tax', translate('Please_add_your_product_tax') . '!');
            });
        }

        $disallowedExtensions = getDisallowedExtensionsListArray();
        if ($request['preview_file']) {
            $extension = '';
            if (is_string($request['preview_file'])) {
                $extension = strtolower(pathinfo($request['preview_file'], PATHINFO_EXTENSION));
            } elseif ($request['preview_file'] instanceof \Illuminate\Http\UploadedFile) {
                $extension = strtolower($request['preview_file']->getClientOriginalExtension());
            }

            if (in_array($extension, $disallowedExtensions) && env('APP_MODE', 'dev') == 'demo') {
                $validator->after(function ($validator) {
                    $validator->errors()->add('files', translate('Uploading_ZIP_files_is_currently_unavailable_in_demo_mode') . '!');
                });
            }
            if (in_array($extension, $disallowedExtensions)) {
                $validator->after(function ($validator) {
                    $validator->errors()->add('files', translate('The_uploaded_file_type_is_not_supported') . '!');
                });
            }
        }

        // Digital files validation
        if ($request['product_type'] == 'digital') {
            $digitalFileOptions = self::getDigitalVariationOptions(request: $request);
            $digitalFileCombinations = self::getDigitalVariationCombinations(arrays: $digitalFileOptions);
            foreach ($digitalFileCombinations as $combinationKey => $combination) {
                foreach ($combination as $item) {
                    $string = $combinationKey . '-' . str_replace(' ', '', $item);
                    $uniqueKey = strtolower(str_replace('-', '_', $string));
                    $fileItem = $request->file('digital_files_' . $uniqueKey);
                    if ($fileItem) {
                        $extension = '';
                        if (is_string($fileItem)) {
                            $extension = strtolower(pathinfo($fileItem, PATHINFO_EXTENSION));
                        } elseif ($fileItem instanceof \Illuminate\Http\UploadedFile) {
                            $extension = strtolower($fileItem->getClientOriginalExtension());
                        }
                        if (in_array($extension, $disallowedExtensions) && env('APP_MODE', 'dev') == 'demo') {
                            $validator->after(function ($validator) {
                                $validator->errors()->add('digital_files', translate('Uploading_ZIP_files_is_currently_unavailable_in_demo_mode') . '!');
                            });
                        } elseif (in_array($extension, $disallowedExtensions)) {
                            $validator->after(function ($validator) {
                                $validator->errors()->add('digital_files', translate('The_uploaded_file_type_is_not_supported') . '!');
                            });
                        }
                    }
                }
            }
        }

        if ($request['discount_type'] == 'percent') {
            $discount = ($request['unit_price'] / 100) * $request['discount'];
        } else {
            $discount = $request['discount'];
        }

        if ($request['unit_price'] <= $discount) {
            $validator->after(function ($validator) {
                $validator->errors()->add(
                    'unit_price',
                    translate('Discount can not be more or equal to the price!')
                );
            });
        }

        $requestLanguage = json_decode($request['lang'], true);
        $requestName = json_decode($request['name'], true);
        $requestDescription = json_decode($request['description'], true);
        $requestColors = json_decode($request['colors'], true);
        $requestImages = json_decode($request['images'], true);
        $requestColorImages = json_decode($request['color_image'], true);
        $requestTags = json_decode($request['tags'], true);
        $requestChoiceArray = json_decode($request['choice'], true);
        $requestChoiceNo = json_decode($request['choice_no'], true);
        $requestChoiceAttributes = json_decode($request['choice_attributes'], true);

        $modifiedColors = [];
        foreach ($requestColors as $color) {
            $modifiedColors[] = str_replace('#', '', $color);
        }

        $modifiedColorImages = [];
        $modifiedColorImagePath = [];
        foreach ($requestColorImages as $colorImage) {
            if ($colorImage['color'] !== null && !in_array($colorImage['color'], $modifiedColors)) {
                $colorImage['color'] = null;
            }
            $modifiedColorImages[] = $colorImage;
            $modifiedColorImagePath[] = $colorImage['image_name'];
        }

        foreach ($requestImages as $requestImage) {
            if ($requestImage['image_name'] !== null && !in_array($requestImage['image_name'], $modifiedColorImagePath)) {
                $modifiedColorImages[] = [
                    'color' => null,
                    'image_name' => $requestImage['image_name'],
                    'storage' => $requestImage['storage'] ?? 'public',
                ];
            }
        }

        $allImagesData = [];
        foreach ($modifiedColorImages as $image) {
            $allImagesData[] = [
                'image_name' => $image['image_name'],
                'storage' => $image['storage'],
            ];
        }

        $productArray = [
            'user_id' => $seller->id,
            'added_by' => 'seller',
            'name' => $requestName[array_search(Helpers::default_lang(), $requestLanguage)]
        ];
        $category = [];

        if ($request->category_id != null) {
            $category[] = [
                'id' => $request['category_id'],
                'position' => 1,
            ];
        }
        if ($request->sub_category_id != null) {
            $category[] = [
                'id' => $request->sub_category_id,
                'position' => 2,
            ];
        }
        if ($request->sub_sub_category_id != null) {
            $category[] = [
                'id' => $request->sub_sub_category_id,
                'position' => 3,
            ];
        }

        $productArray += [
            'category_ids' => json_encode($category),
            'category_id' => $request['category_id'],
            'sub_category_id' => $request['sub_category_id'],
            'sub_sub_category_id' => $request['sub_sub_category_id'],
            'brand_id' => $request['product_type'] == "physical" ? ($request['brand_id'] ?? null) : null,
            'unit' => $request['product_type'] == 'physical' ? $request['unit'] : null,
            'product_type' => $request['product_type'],
            'digital_product_type' => $request['product_type'] == 'digital' ? $request['digital_product_type'] : null,
            'code' => $request->code,
            'minimum_order_qty' => $request['minimum_order_qty'],
            'details' => $requestDescription[array_search(Helpers::default_lang(), $requestLanguage)],
            'images' => json_encode($allImagesData),
            'color_image' => json_encode($modifiedColorImages),
            'thumbnail' => $request->thumbnail,
            'thumbnail_storage_type' => $product->thumbnail == $request->thumbnail ? $product->thumbnail_storage_type : $storage,
        ];

        if ($request->product_type == 'digital') {
            if ($request->digital_product_type == 'ready_product' && $request->digital_file_ready) {
                $digitalFileExtension = '';
                if (is_string($request['digital_file_ready'])) {
                    $digitalFileExtension = strtolower(pathinfo($request['digital_file_ready'], PATHINFO_EXTENSION));
                } elseif ($request['digital_file_ready'] instanceof \Illuminate\Http\UploadedFile) {
                    $digitalFileExtension = strtolower($request['digital_file_ready']->getClientOriginalExtension());
                }

                if (in_array($digitalFileExtension, $disallowedExtensions) && env('APP_MODE', 'dev') == 'demo') {
                    $validator->after(function ($validator) {
                        $validator->errors()->add('files', translate('Uploading_ZIP_files_is_currently_unavailable_in_demo_mode') . '!');
                    });
                }
                if (in_array($digitalFileExtension, $disallowedExtensions)) {
                    $validator->after(function ($validator) {
                        $validator->errors()->add('files', translate('The_uploaded_file_type_is_not_supported'). '!');
                    });
                }
                $productArray += [
                    'digital_file_ready' => $request->digital_file_ready,
                    'digital_file_ready_storage_type' => $storage,
                ];
            } elseif (($request->digital_product_type == 'ready_after_sell') && $product->digital_file_ready) {
                $productArray += [
                    'digital_file_ready' => null,
                ];
            }

            if ($request->has('extensions_type') && $request->has('digital_product_variant_key')) {
                $productArray += [
                    'digital_file_ready' => null,
                ];
            }
        } elseif ($request->product_type == 'physical' && $product->digital_file_ready) {
            $productArray += [
                'digital_file_ready' => null,
            ];
        }

        if ($request->has('colors_active') && $request->has('colors') && count($requestColors) > 0) {
            $productArray += [
                'colors' => $request->product_type == 'physical' ? json_encode($requestColors) : json_encode([]),
            ];
        } else {
            $colors = [];
            $productArray += [
                'colors' => $request->product_type == 'physical' ? json_encode($colors) : json_encode([]),
            ];
        }

        $choice_options = [];
        $requestChoiceNoIndex = 0;
        if ($request->has('choice')) {
            foreach ($requestChoiceNo as $key => $no) {
                $str = 'choice_options_' . $no;
                $optionValues = $request[$str];
                // An attribute whose options were removed before saving must not persist an empty,
                // unusable variation attribute (keeps parity with the web ProductService fix).
                if (empty($optionValues)) {
                    $requestChoiceNoIndex++;
                    continue;
                }
                $item = [];
                $item['name'] = 'choice_' . $no;
                $item['title'] = $requestChoiceArray[$requestChoiceNoIndex] ?? ('choice_' . $no);
                $item['options'] = $optionValues;
                $choice_options[] = $item;
                $requestChoiceNoIndex++;
            }
        }
        $productArray += [
            'choice_options' => $request->product_type == 'physical' ? json_encode($choice_options) : json_encode([]),
        ];

        //combinations start
        $options = [];
        if ($request->has('colors_active') && $request->has('colors') && count($requestColors) > 0) {
            $colors_active = 1;
            $options[] = $requestColors;
        }
        if ($request->has('choice_no')) {
            foreach ($requestChoiceNo as $key => $no) {
                $name = 'choice_options_' . $no;
                $optionValues = $request[$name];
                // Skip an attribute emptied by removal so it doesn't collapse the whole
                // variation set (an empty factor zeroes the cartesian product).
                if (empty($optionValues)) {
                    continue;
                }
                $options[] = $optionValues;
            }
        }

        //Generates the combinations of customer choice options
        $combinations = Helpers::combinations($options);

        $variations = [];
        $stock_count = 0;
        if (count($combinations[0]) > 0) {

            foreach ($combinations as $combination) {
                $str = '';
                foreach ($combination as $k => $item) {
                    if ($k > 0) {
                        $str .= '-' . str_replace(' ', '', $item);
                    } else {
                        if ($request->has('colors_active') && $request->has('colors') && count($requestColors) > 0) {
                            $color_name = Color::where('code', $item)->first()->name ?? '';
                            $str .= $color_name;
                        } else {
                            $str .= str_replace(' ', '', $item);
                        }
                    }
                }
                $item = [];
                $item['type'] = $str;
                $item['price'] = Convert::usd(abs($request['price_' . str_replace('.', '_', $str)]));
                $item['sku'] = $request['sku_' . str_replace('.', '_', $str)];
                $item['qty'] = $request['qty_' . str_replace('.', '_', $str)];

                array_push($variations, $item);
                $stock_count += $item['qty'];
            }
        } else {
            $stock_count = (int)$request['current_stock'];
        }

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $digitalFileOptions = self::getDigitalVariationOptions(request: $request);
        $digitalFileCombinations = self::getDigitalVariationCombinations(arrays: $digitalFileOptions);

        $productArray += [
            'variation' => $request->product_type == 'physical' ? json_encode($variations) : json_encode([]),
            'unit_price' => Convert::usd($request->unit_price),
            'purchase_price' => 0,
            'discount' => $request->discount_type == 'flat' ? Convert::usd($request->discount) : $request->discount,
            'discount_type' => $request->discount_type,
            'attributes' => $request->product_type == 'physical' ? json_encode($requestChoiceAttributes) : json_encode([]),
            'current_stock' => $request->product_type == 'physical' ? $request->current_stock : 999999999,
            'meta_title' => '',
            'meta_description' => '',
            'shipping_cost' => $request->product_type == 'physical' ? (getWebConfig(name: 'product_wise_shipping_cost_approval') == 1 ? $product->shipping_cost : Convert::usd($request->shipping_cost)) : 0,
            'multiply_qty' => ($request->product_type == 'physical') ? ($request->multiplyQTY == 1 ? 1 : 0) : 0,

            'digital_product_file_types' => $request->has('extensions_type') ? json_decode($request['extensions_type'], true) : [],
            'digital_product_extensions' => $digitalFileCombinations,
        ];

        if (getWebConfig(name: 'product_wise_shipping_cost_approval') == 1 && ($product->shipping_cost != Convert::usd($request->shipping_cost)) && ($request->product_type == 'physical')) {
            $productArray += [
                'temp_shipping_cost' => Convert::usd($request->shipping_cost),
                'is_shipping_cost_updated' => 0,
            ];
        }

        if ($request->has('meta_image')) {
            $productArray += [
                'meta_image' => null,
            ];
        }

        if ($request->file('preview_file')) {
            $productArray += [
                'preview_file' => $this->updateFile(dir: 'product/preview/', oldImage: $product['preview_file'], format: $request['preview_file']->getClientOriginalExtension(), image: $request['preview_file'], fileType: 'file'),
                'preview_file_storage_type' => $storage,
            ];
        }

        $productArray += [
            'video_provider' => 'youtube',
            'video_url' => $request['video_url'] ?? '',
        ];

        if ($product['request_status'] == 2) {
            $productArray += [
                'request_status' => 0,
            ];
        }

        Product::where('id', $id)->update($productArray);

        $this->updateProductAuthorAndPublishingHouse(request: $request, product: $product);
        self::getDigitalProductUpdateProcess($request, $product);

        $ProductSeo = ProductSeo::where(['product_id' => $product['id']])->first();
        if ($ProductSeo) {
            ProductSeo::find($ProductSeo['id'])->update(self::getProductSEOData(request: $request, product: $product));
        } else {
            ProductSeo::create(self::getProductSEOData(request: $request, product: $product));
        }

        $tag_ids = [];
        if ($requestTags) {
            foreach ($requestTags as $key => $value) {
                $tag = Tag::firstOrNew(
                    ['tag' => trim($value)]
                );
                $tag->save();
                $tag_ids[] = $tag->id;
            }
        }
        $product->tags()->sync($tag_ids);

        foreach ($requestLanguage as $index => $key) {
            if ($requestName[$index] && $key != 'en') {
                Translation::updateOrInsert(
                    [
                        'translationable_type' => 'App\Models\Product',
                        'translationable_id' => $product->id,
                        'locale' => $key,
                        'key' => 'name'
                    ],
                    ['value' => $requestName[$index]]
                );
            }
            if ($requestDescription[$index] && $key != 'en') {
                Translation::updateOrInsert(
                    [
                        'translationable_type' => 'App\Models\Product',
                        'translationable_id' => $product->id,
                        'locale' => $key,
                        'key' => 'description'
                    ],
                    ['value' => $requestDescription[$index]]
                );
            }
        }

        $updatedProduct = $this->productRepo->getFirstWhere(params: ['id' => $product['id']]);
        $this->updateRestockRequestListAndNotify(product: $oldProductData, updatedProduct: $updatedProduct);
        $this->updateStockClearanceProduct(product: $updatedProduct);

        $taxVatIds = $product?->taxVats?->pluck('tax_id')->toArray() ?? [];
        $this->getUpdateTaxData(
            taxableType: \App\Models\Product::class,
            taxableId: $product['id'],
            taxIds: json_decode($request['tax_ids'], true),
            oldTaxIds: $taxVatIds
        );

        return response()->json(['message' => translate('Successfully_product_updated!')], 200);
    }
    public function updateStockClearanceProduct($product): void
    {
        $config = $this->stockClearanceSetupRepo->getFirstWhere(params: [
            'setup_by' => $product['added_by'] == 'admin' ? $product['added_by'] : 'vendor',
            'shop_id' => $product['added_by'] == 'admin' ? 0 : $product?->seller?->shop?->id,
        ]);
        $stockClearanceProduct = $this->stockClearanceProductRepo->getFirstWhere(params: ['product_id' => $product['id']]);

        if ($config && $config['discount_type'] == 'product_wise' && $stockClearanceProduct && $stockClearanceProduct['discount_type'] == 'flat') {
            $minimumPrice = $product['unit_price'];
            foreach ((json_decode($product['variation'], true) ?? []) as $variation) {
                if ($variation['price'] < $minimumPrice) {
                    $minimumPrice = $variation['price'];
                }
            }

            if ($minimumPrice < $stockClearanceProduct['discount_amount']) {
                $this->stockClearanceProductRepo->updateByParams(params: ['product_id' => $product['id']], data: ['is_active' => 0]);
            }
        }
    }

    public function getProductSEOData(object $request, object|null $product = null): array
    {
        return [
            "product_id" => $product['id'],
            "title" => $request['meta_title'] ?? ($product ? $product['meta_title'] : null),
            "description" => $request['meta_description'] ?? ($product ? $product['meta_description'] : null),
            "index" => $request['meta_index'] == 'index' ? '' : 'noindex',
            "no_follow" => $request['meta_no_follow'] == 'nofollow' ? 'nofollow' : '',
            "no_image_index" => $request['meta_no_image_index'] ? 'noimageindex' : '',
            "no_archive" => $request['meta_no_archive'] ? 'noarchive' : '',
            "no_snippet" => $request['meta_no_snippet'] ?? 0,
            "max_snippet" => $request['meta_max_snippet'] ?? 0,
            "max_snippet_value" => $request['meta_max_snippet_value'] ?? 0,
            "max_video_preview" => $request['meta_max_video_preview'] ?? 0,
            "max_video_preview_value" => $request['meta_max_video_preview_value'] ?? 0,
            "max_image_preview" => $request['meta_max_image_preview'] ?? 0,
            "max_image_preview_value" => $request['meta_max_image_preview_value'] ?? 0,
            "image" => $request->meta_image ?? ($product ? ($product->seoInfo->image ?? $product['meta_image']) : null),
            "created_at" => now(),
            "updated_at" => now(),
        ];
    }

    public function getDigitalProductUpdateProcess($request, $product): void
    {
        if ($request->has('digital_product_variant_key') && !$request->hasFile('digital_file_ready')) {
            $getAllVariation = DigitalProductVariation::where(['product_id' => $product['id']])->get();
            $getAllVariationKey = $getAllVariation->pluck('variant_key')->toArray();
            $getRequestVariationKey = json_decode($request['digital_product_variant_key'], true);
            $differenceFromDB = array_diff($getAllVariationKey, $getRequestVariationKey);
            $differenceFromRequest = array_diff($getRequestVariationKey, $getAllVariationKey);
            $newCombinations = array_merge($differenceFromDB, $differenceFromRequest);

            foreach ($newCombinations as $newCombination) {
                if (in_array($newCombination, $getRequestVariationKey)) {
                    $uniqueKey = strtolower(str_replace('-', '_', $newCombination));
                    $fileItem = $request->file('digital_files_' . $uniqueKey);
                    $uploadedFile = '';
                    if ($fileItem) {
                        $uploadedFile = $this->fileUpload(dir: 'product/digital-product/', format: $fileItem->getClientOriginalExtension(), file: $fileItem);
                    }
                    DigitalProductVariation::insert([
                        'product_id' => $product['id'],
                        'variant_key' => $getRequestVariationKey[$uniqueKey],
                        'sku' => json_decode($request['digital_product_sku'], true)[$uniqueKey],
                        'price' => json_decode($request['digital_product_price'], true)[$uniqueKey],
                        'file' => $uploadedFile,
                    ]);
                }
            }

            foreach ($differenceFromDB as $variation) {
                $variation = DigitalProductVariation::where(['product_id' => $product['id'], 'variant_key' => $variation])->first();
                if ($variation) {
                    DigitalProductVariation::where(['id' => $variation['id']])->delete();
                }
            }

            foreach ($getAllVariation as $variation) {
                if (in_array($variation['variant_key'], $getRequestVariationKey)) {
                    $uniqueKey = strtolower(str_replace('-', '_', $variation['variant_key']));
                    $fileItem = $request->file('digital_files_' . $uniqueKey);
                    $uploadedFile = $variation['file'] ?? '';
                    $variation = DigitalProductVariation::where(['product_id' => $product['id'], 'variant_key' => $variation['variant_key']])->first();
                    if ($fileItem) {
                        $uploadedFile = $this->fileUpload(dir: 'product/digital-product/', format: $fileItem->getClientOriginalExtension(), file: $fileItem);
                    }
                    DigitalProductVariation::where(['product_id' => $product['id'], 'variant_key' => $variation['variant_key']])->update([
                        'variant_key' => $getRequestVariationKey[$uniqueKey],
                        'sku' => json_decode($request['digital_product_sku'], true)[$uniqueKey],
                        'price' => json_decode($request['digital_product_price'], true)[$uniqueKey],
                        'file' => $uploadedFile,
                    ]);
                }

                if ($request['product_type'] == 'physical' || $request['digital_product_type'] == 'ready_after_sell') {
                    $variation = DigitalProductVariation::where(['product_id' => $product['id'], 'variant_key' => $variation['variant_key']])->first();
                    if ($variation && $variation['file']) {
                        DigitalProductVariation::where(['id' => $variation['id']])->update(['file' => '']);
                    }
                    if ($request['product_type'] == 'physical') {
                        $variation->delete();
                    }
                }
            }
        } else {
            DigitalProductVariation::where(['product_id' => $product['id']])->delete();
        }
    }

    public function updateProductQuantity(Request $request): JsonResponse
    {
        // Scoped to the caller's own shop. Looked up by id alone, this accepted any product id in
        // the marketplace: a seller could set a rival's stock to zero and take their listings out of
        // the search results. Ownership is proved by the WHERE clause, never by the id in the body.
        $product = $this->productRepo->getFirstWhere(params: [
            'id' => $request['product_id'],
            'added_by' => 'seller',
            'user_id' => $request->seller->id,
        ]);
        if ($product) {
            $this->productRepo->updateByParams(params: ['id' => $request['product_id']], data: [
                'current_stock' => $request['current_stock'],
                'variation' => $request['variation'],
            ]);
            $updatedProduct = $this->productRepo->getFirstWhere(params: ['id' => $request['product_id']]);
            $this->updateRestockRequestListAndNotify(product: $product, updatedProduct: $updatedProduct);

            return response()->json(['message' => translate('successfully_product_updated')], 200);
        }
        return response()->json(['message' => translate('update_fail')], 403);
    }

    public function status_update(Request $request):JsonResponse
    {
        $seller = $request->seller;
        $product = Product::withCount('reviews')->where(['added_by' => 'seller', 'user_id' => $seller->id])->find($request->id);
        if (!$product) {
            return response()->json(['message' => translate('invalid_prodcut')], 403);
        }
        $product->status = $request->status;
        $product->save();

        return response()->json([
            'success' => translate('status_update_successfully'),
        ], 200);
    }

    public function delete(Request $request, $id):JsonResponse
    {
        $product = Product::withCount('reviews')->find($id);
        foreach (json_decode($product['images'], true) as $image) {
            $imageName = is_string($image) ? $image : $image['image_name'];
            $this->deleteFile('/product/' . $imageName);
        }
        $this->deleteFile('/product/thumbnail/' . $product['thumbnail']);
        $this->productService->deletePreviewFile(product: $product);
        $product->delete();
        FlashDealProduct::where(['product_id' => $id])->delete();
        DealOfTheDay::where(['product_id' => $id])->delete();
        cacheRemoveByType(type: 'products');
        return response()->json(['message' => translate('successfully product deleted!')], 200);
    }

    public function barcode_generate(Request $request): JsonResponse
    {
        $request->validate([
            'id' => 'required',
            'quantity' => 'required',
        ], [
            'id.required' => 'Product ID is required',
            'quantity.required' => 'Barcode quantity is required',
        ]);

        if ($request['limit'] > 270) {
            return response()->json(['code' => 403, 'message' => 'You can not generate more than 270 barcode']);
        }
        $product = Product::withCount('reviews')->where('id', $request->id)->first();
        $quantity = $request->quantity ?? 30;

        if (isset($product->code)) {
            $pdf = app()->make(PDF::class);
            $pdf->loadView('vendor-views.product.barcode-pdf', compact('product', 'quantity'));
            $pdf->save(storage_path('app/public/product/barcode.pdf'));
            return response()->json(asset('storage/app/public/product/barcode.pdf'));
        } else {
            return response()->json(['message' => translate('Please update product code!')], 203);
        }

    }

    public function top_selling_products(Request $request): JsonResponse
    {
        $seller = $request->seller;

        $topSellProducts = $this->productRepo->getTopSellList(
            filters: [
                'added_by' => 'seller',
                'seller_id' => $seller['id'],
                'request_status' => 1
            ],
            relations: ['rating', 'orderDetails', 'refundRequest'],
            withCount: ['reviews' => 'reviews'],
            dataLimit: (int)$request['limit'],
            offset: (int)$request['offset'],
        );

        $collection = [];
        foreach ($topSellProducts as $topSellProduct) {
            $product = [
                'product_id' => $topSellProduct['id'],
                'count' => (string)($topSellProduct['order_details_count'] ?? 0),
                'product' => Helpers::product_data_formatting($topSellProduct, false),
            ];
            $collection[] = $product;
        }

        return response()->json([
            'total_size' => count($collection),
            'limit' => $request['limit'],
            'offset' => $request['offset'],
            'products' => $collection
        ], 200);
    }

    public function most_popular_products(Request $request): JsonResponse
    {
        $seller = $request->seller;

        $products = $this->productRepo->getTopRatedList(
            filters: [
                'user_id' => $seller['id'],
                'added_by' => 'seller',
                'request_status' => 1
            ],
            relations: ['rating', 'tags', 'clearanceSale' => function ($query) {
                return $query->active();
            }],
            dataLimit: (int)($request['limit'] ?? getWebConfig(name: 'pagination_limit')),
            offset: (int)$request['offset'] ?? 1,
        );

        $productsFinal = Helpers::product_data_formatting($products, true);

        return response()->json([
            'total_size' => $products->total(),
            'limit' => $request['limit'],
            'offset' => $request['offset'],
            'products' => $productsFinal
        ], 200);
    }

    public function top_delivery_man(Request $request):JsonResponse
    {
        $seller = $request->seller;
        $delivery_men = DeliveryMan::with(['rating', 'orders' => function ($query) {
            $query->select('delivery_man_id', DB::raw('COUNT(delivery_man_id) as count'));
        }])
            ->withCount(['deliveredOrders'])
            ->whereHas('deliveredOrders', function ($query) use ($seller) {
                $query->where(['seller_is' => 'seller', 'seller_id' => $seller['id']])->whereNotNull('delivery_man_id');
            })
            ->where(['seller_id' => $seller['id']])
            ->when(!empty($request['search']), function ($query) use ($request) {
                $key = searchTerms($request['search']);
                foreach ($key as $value) {
                    $query->where('f_name', 'like', "%{$value}%")
                        ->orWhere('l_name', 'like', "%{$value}%");
                }
            })
            ->paginate($request['limit'], ['*'], 'page', $request['offset']);

        $data = array();
        $data['total_size'] = $delivery_men->total();
        $data['limit'] = $request['limit'];
        $data['offset'] = $request['offset'];
        $data['delivery_man'] = $delivery_men->items();
        return response()->json($data, 200);
    }

    public function review_list(Request $request, $product_id):JsonResponse
    {
        $product = Product::withCount('reviews')->find($product_id);
        $average_rating = count($product->rating) > 0 ? number_format($product->rating[0]->average, 2, '.', ' ') : 0;
        $reviews = Review::with(['customer', 'product', 'reply'])->where(['product_id' => $product_id])
            ->latest('updated_at')
            ->paginate($request['limit'], ['*'], 'page', $request['offset']);

        $rating_group_count = Review::where(['product_id' => $product_id])
            ->select('rating', DB::raw('count(*) as total'))
            ->groupBy('rating')
            ->get();

        $data = array();
        $data['total_size'] = $reviews->total();
        $data['limit'] = $request['limit'];
        $data['offset'] = $request['offset'];
        $data['group-wise-rating'] = $rating_group_count;
        $data['average_rating'] = $average_rating;
        $data['reviews'] = $reviews->items();

        return response()->json($data, 200);
    }

    public function get_categories(Request $request):JsonResponse
    {
        $categories = Category::with(['childes.childes', 'childes' => function ($query) {
            $query->with(['childes' => function ($query) {
                $query->withCount(['subSubCategoryProduct'])->where('position', 2);
            }])->withCount(['subCategoryProduct'])->where('position', 1);
        }])
            ->where('position', 0)
            ->priority()
            ->get();

        return response()->json($categories, 200);

    }

    public function deleteImage(Request $request):JsonResponse
    {
        // Scoped to the seller asking. Found by id alone, this deleted images from any seller's
        // product — and a product that did not exist fatalled on the next line instead of answering.
        $product = Product::withCount('reviews')
            ->where(['id' => $request['id'], 'added_by' => 'seller', 'user_id' => $request->seller->id])
            ->first();

        if (!$product) {
            return response()->json(['errors' => [
                ['code' => 'product', 'message' => translate('product_not_found')],
            ]], 404);
        }

        $array = [];
        if (count(json_decode($product['images']) ?? []) < 2) {
            return response()->json(['message' => translate('you_can_not_delete_all_images')], 403);
        }
        $colors = json_decode($product['colors']);
        $color_image = json_decode($product['color_image']);
        $color_image_arr = [];
        if ($colors && $color_image) {
            foreach ($color_image as $img) {
                if ($img->color != $request['color'] && $img->image_name != $request['name']) {
                    $color_image_arr[] = [
                        'color' => $img->color != null ? $img->color : null,
                        'image_name' => $img->image_name,
                        'storage' => $img?->storage ?? 'public',
                    ];
                } else {
                    $this->deleteFile('/product/' . $request['name']);
                    if ($img->color != null) {
                        $color_image_arr[] = [
                            'color' => $img->color,
                            'image_name' => null,
                        ];
                    }
                }
            }
        }

        foreach (json_decode($product['images']) as $image) {
            $imageName = $image->image_name ?? $image;
            if ($imageName != $request['name']) {
                array_push($array, $image);
            } else {
                $this->deleteFile('/product/' . $request['name']);
            }
        }
        $product->update([
            'images' => json_encode($array),
            'color_image' => json_encode($color_image_arr),
        ]);

        return response()->json(translate('product_image_removed_successfully'), 200);
    }

    public function getStockLimitStatus(Request $request):JsonResponse
    {
        $seller = $request->seller;
        $filters = [
            'added_by' => 'seller',
            'product_type' => 'physical',
            'request_status' => 1,
            'user_id' => $seller->id,
        ];

        $stockLimit = ($seller['stock_limit'] ?? 0) <= 0 ? getWebConfig(name: 'stock_limit') : $seller['stock_limit'];
        $products = Product::where($filters)->where('current_stock', '<', $stockLimit)->get();
        if ($products->count() == 1) {
            return response()->json(['status' => 'one_product', 'product_count' => 1, 'product' => $products->first()], 200);
        } else {
            return response()->json(['status' => 'multiple_product', 'product_count' => $products->count()], 200);
        }
    }

    public function deletePreviewFile(Request $request): JsonResponse
    {
        // Scoped to the seller asking. Found by id alone, this cleared the preview file on any
        // seller's product.
        $product = $this->productRepo->getFirstWhereWithoutGlobalScope(params: [
            'id' => $request['product_id'],
            'added_by' => 'seller',
            'user_id' => $request->seller->id,
        ]);

        if (!$product) {
            return response()->json(['errors' => [
                ['code' => 'product', 'message' => translate('product_not_found')],
            ]], 404);
        }

        $this->productService->deletePreviewFile(product: $product);
        $this->productRepo->update(id: $request['product_id'], data: ['preview_file' => null]);
        return response()->json([
            'status' => 1,
            'message' => translate('Preview_file_deleted')
        ]);
    }

    public function getDigitalProductsAuthorList(Request $request): JsonResponse
    {
        $authors = ProductManager::getProductAuthorList();
        return response()->json($authors->values());
    }

    public function getDigitalPublishingHouseList(Request $request): JsonResponse
    {
        $publishingHouseList = ProductManager::getPublishingHouseList();
        return response()->json($publishingHouseList->values());
    }

    public function getRestockRequestList(Request $request): JsonResponse
    {
        $seller = Helpers::getSellerByToken($request);

        if (!$seller) {
            return response()->json([
                // The same wording the middleware uses. Two spellings of one refusal
                // meant a client matching on the message saw two different errors.
                'auth-001' => translate('Your existing session token does not authorize you any more')
            ], 401);
        }

        $filters = [
            'added_by' => 'seller',
            'seller_id' => $seller->id,
            'category_id' => $request['category_id'],
            'brand_ids' => $request['brand_ids'] ? json_decode($request['brand_ids']) : [],
        ];

        $startDate = !empty($request['restock_start_date']) ? Carbon::parse($request['restock_start_date'])->startOfDay() : null;
        $endDate = !empty($request['restock_end_date']) ? Carbon::parse($request['restock_end_date'])->endOfDay() : null;

        $restockProducts = $this->restockProductRepo->getListWhereBetween(
            orderBy: ['updated_at' => 'desc'],
            searchValue: requestString('search') ?: null,
            filters: $filters,
            relations: ['product'],
            whereBetween: 'created_at',
            whereBetweenFilters: $startDate && $endDate ? [$startDate, $endDate] : [],
            dataLimit: $request['limit'] ?? getWebConfig(name: WebConfigKey::PAGINATION_LIMIT),
            offset: $request['offset'] ?? '1',
        );

        $restockProducts->map(function ($data) {
            $data->variant_keys = $this->restockProductRepo->getListWhere(filters: ['product_id' => $data['product_id']], dataLimit: 'all')?->pluck('variant')->toArray() ?? [];
            $data->product = Helpers::product_data_formatting($data->product, false);
            return $data;
        });

        return response()->json([
            'total_size' => $restockProducts->total(),
            'limit' => (int)($request['limit'] ?? getWebConfig(name: WebConfigKey::PAGINATION_LIMIT)),
            'offset' => (int)$request['offset'],
            'data' => $restockProducts->items(),
        ], 200);
    }

    public function deleteRestockRequest(Request $request): JsonResponse
    {
        $this->restockProductRepo->delete(params: ['id' => $request['id']]);
        $this->restockProductCustomerRepo->delete(params: ['restock_product_id' => $request['id']]);
        return response()->json([
            'message' => translate('product_restock_removed_successfully')
        ], 200);
    }

    public function updateRestockQuantity(Request $request): JsonResponse
    {
        // Same scoping as updateProductQuantity, and for the same reason: the restock screen is a
        // second door onto the same write.
        $product = $this->productRepo->getFirstWhere(params: [
            'id' => $request['product_id'],
            'added_by' => 'seller',
            'user_id' => $request->seller->id,
        ]);

        if ($product && $request['current_stock'] >= 0) {
            $this->productRepo->updateByParams(params: ['id' => $request['product_id']], data: [
                'current_stock' => $request['current_stock'],
                'variation' => $request['variation'],
            ]);

            $updatedProduct = $this->productRepo->getFirstWhere(params: ['id' => $request['product_id']]);
            $this->updateRestockRequestListAndNotify(product: $product, updatedProduct: $updatedProduct);

            return response()->json(['message' => translate('successfully_product_updated!')], 200);
        }
        return response()->json(['message' => translate('update_fail!')], 403);
    }

    public function getRestockRequestBrands(Request $request): JsonResponse
    {
        $seller = Helpers::getSellerByToken($request);

        if (!$seller) {
            return response()->json([
                // The same wording the middleware uses. Two spellings of one refusal
                // meant a client matching on the message saw two different errors.
                'auth-001' => translate('Your existing session token does not authorize you any more')
            ], 401);
        }

        $filters = [
            'added_by' => 'seller',
            'seller_id' => $seller->id
        ];

        $restockRequests = $this->restockProductRepo->getListWhereBetween(
            orderBy: ['updated_at' => 'desc'],
            filters: $filters,
            relations: ['product'],
            dataLimit: 'all',
        );

        $products = $this->productRepo->getListWithScope(whereIn: ['id' => $restockRequests->pluck('product_id')->toArray()], dataLimit: 'all');
        $brands = $this->brandRepo->getListWhereIn(whereIn: ['id' => $products->pluck('brand_id')->toArray()], dataLimit: 'all');

        $brands = $brands->map(function ($brand) use ($restockRequests) {
            $brand->product_count = $restockRequests->filter(function ($restockRequest) use ($brand) {
                return $restockRequest?->product?->brand_id === $brand->id;
            })->count();
            return $brand;
        });

        return response()->json([
            'brands' => $brands,
        ], 200);
    }

    /**
     * Refuse a listing under a brand this shop is not entitled to sell — but only once the
     * marketplace has switched enforcement on.
     *
     * Written as a validator clause rather than an early return so the refusal arrives in the same
     * shape as every other one the form already handles, and lands on the brand field where the
     * seller is looking.
     *
     * While enforcement is off this does nothing at all. The mismatches are still found and still
     * reported — the brand detector raises them, with the affected listings counted — which is how a
     * legitimate reseller learns they need paperwork before the day their listings stop working
     * rather than on it.
     */
    private function brandClaimGuard(object $request, object $seller): callable
    {
        return function ($validator) use ($request, $seller): void {
            $brandId = $request['product_type'] === 'physical' ? ($request['brand_id'] ?? null) : null;

            if (!$brandId) {
                return;
            }

            $registry = app(BrandRegistryService::class);

            if (!$registry->isEnforcing() || $registry->mayList($seller->id, $brandId)) {
                return;
            }

            $validator->errors()->add('brand_id', translate('brand_claim_required_to_list_under_this_brand') . '!');
        };
    }
}
