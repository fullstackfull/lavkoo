<?php

namespace App\Http\Controllers\Web;

use App\Services\Analytics\Analytics;
use App\Http\Controllers\Controller;
use App\Models\Author;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\PublishingHouse;
use App\Models\Review;
use App\Models\Seller;
use App\Models\Shop;
use App\Models\StockClearanceProduct;
use App\Models\StockClearanceSetup;
use App\Utils\CartManager;
use App\Utils\CategoryManager;
use App\Utils\ProductManager;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Routing\Redirector;

class ShopViewController extends Controller
{
    public function getShopInfoArray($shop, $shopProducts, $sellerType, $sellerId): array
    {
        $totalOrder = Order::when($sellerType == 'admin', function ($query) {
            return $query->where(['seller_is' => 'admin']);
        })->when($sellerType == 'seller', function ($query) use ($sellerId) {
            return $query->where(['seller_is' => 'seller', 'seller_id' => $sellerId]);
        })->where('order_type', 'default_type')->count();
        $getProductIDs = $shopProducts->pluck('id')->toArray();
        return [
            'id' => $shop['id'],
            'name' => $shop['name'],
            'slug' => $shop['slug'],
            'author_type' => $shop['author_type'],
            'seller_id' => $shop['author_type'] == 'admin' ? 0 : $shop['seller_id'],
            'average_rating' => Review::active()->where('status', 1)->whereIn('product_id', $getProductIDs)->avg('rating'),
            'total_review' => Review::active()->where('status', 1)->whereIn('product_id', $getProductIDs)->count(),
            'total_order' => $totalOrder,
            'current_date' => date('Y-m-d'),
            'vacation_start_date' =>  date('Y-m-d', strtotime($shop->vacation_start_date)),
            'vacation_end_date' => date('Y-m-d', strtotime($shop->vacation_end_date)),
            'temporary_close' => checkVendorAbility(type: 'vendor', status: 'temporary_close', vendor: $shop),
            'vacation_status' => checkVendorAbility(type: 'vendor', status: 'vacation_status', vendor: $shop),
            'banner_full_url' =>  $shop->banner_full_url,
            'bottom_banner' =>$shop->bottom_banner,
            'bottom_banner_full_url' => $shop->bottom_banner_full_url,
            'image_full_url' => $shop->image_full_url,
            'minimum_order_amount' => $shop['author_type'] == "admin" ? getWebConfig(name: 'minimum_order_amount') : $shop->seller->minimum_order_amount,
        ];


    }

    public function seller_shop(Request $request, $slug): View|JsonResponse|Redirector|RedirectResponse
    {
        $shop = Shop::where('slug', $slug)->first();

        if (!$shop) {
            Toastr::error(translate('Shop_does_not_exist'));
            return redirect()->route('home');
        }

        if (getWebConfig(name: 'business_mode') == 'single' && $shop['author_type'] != 'admin') {
            Toastr::error(translate('access_denied!!'));
            return redirect()->route('home');
        }

        return self::default_theme($request, $shop);
    }

    public function default_theme($request, $shop): View|JsonResponse|Redirector|RedirectResponse
    {
        self::checkShopExistence($shop);

        // Analytics. A shop page view belongs to the vendor whose shop it is, which is what lets a
        // vendor be shown their own traffic without ever being shown anybody else's.
        app(Analytics::class)->shopViewed(
            shopId: (int) $shop['id'],
            vendorId: $shop['author_type'] === 'admin' ? null : (int) $shop['seller_id'],
        );

        $productAddedBy = $shop['author_type'] == 'admin' ? 'admin' : 'seller';
        $productUserID = $shop['seller_id'] == 0 ? 0 : $shop['seller_id'];
        $shopId = $shop['author_type'] == 'admin' ? 0 : $shop['id'];
        $shopAllProducts = ProductManager::getAllProductsData($request, $productUserID, $productAddedBy);
        $productListData = ProductManager::getProductListData($request, $productUserID, $productAddedBy);
        $categories = self::getShopCategoriesList(products: $shopAllProducts, shop: $shop);
        $brands = self::getShopBrandsList(request: $request, products: $shopAllProducts, sellerType: $productAddedBy, sellerId: $productUserID);
        $shopPublishingHouses = ProductManager::getPublishingHouseList(productIds: $shopAllProducts->pluck('id')->toArray(), vendorId: $productUserID);
        $digitalProductAuthors = ProductManager::getProductAuthorList(productIds: $shopAllProducts->pluck('id')->toArray(), vendorId: $productUserID);
        $shopInfoArray = self::getShopInfoArray(shop: $shop, shopProducts: $shopAllProducts, sellerType: $productAddedBy, sellerId: $productUserID);
        $products = $productListData->paginate(20)->appends($request->all());
        $stockClearanceProducts = StockClearanceProduct::active()->where(['shop_id'=> $shopId])->count();
        $stockClearanceSetup = StockClearanceSetup::where(['shop_id'=> $shopId])->first()?->is_active ?? 0;

        if ($request->ajax()) {
            return response()->json([
                'html_products' => view(VIEW_FILE_NAMES['products__ajax_partials'], compact('products', 'categories'))->render()
            ], 200);
        }
        $data = self::getProductListRequestData(request: $request);

        return view(VIEW_FILE_NAMES['shop_view_page'], [
            'products' => $products,
            'categories' => $categories,
            'seller_id' => $shop['seller_id'],
            'activeBrands'=> $brands,
            'shopInfoArray'=> $shopInfoArray,
            'shopPublishingHouses' => $shopPublishingHouses,
            'digitalProductAuthors' => $digitalProductAuthors,
            'stockClearanceProducts' => $stockClearanceProducts,
            'stockClearanceSetup' => $stockClearanceSetup,
            'data' => $data,
        ]);
    }

    public function checkShopExistence($shop): bool|Redirector|RedirectResponse
    {
        $businessMode = getWebConfig(name: 'business_mode');

        if (!$shop) {
            Toastr::error(translate('Shop_does_not_exist'));
            return back();
        }

        if ($shop['author_type'] != 'admin' && $businessMode == 'single') {
            Toastr::error(translate('access_denied!!'));
            return back();
        }

        if ($shop['author_type'] != 'admin') {
            if (!Seller::approved()->find($shop['seller_id'])) {
                Toastr::warning(translate('not_found'));
                return redirect('/');
            }
        }
        return true;
    }

    public function getShopCategoriesList($products, $shop)
    {
        $categoryInfoDecoded = [];
        foreach ($products->pluck('category_ids')->toArray() as $info) {
            $categoryInfoDecoded[] = json_decode($info, true);
        }

        $categoryIds = [];
        foreach ($categoryInfoDecoded as $decoded) {
            if ($decoded) {
                foreach ($decoded as $info) {
                    $categoryIds[] = $info['id'];
                }
            }
        }

        $productAuthor = $shop['author_type'] == 'admin' ? 'admin' : 'seller';

        $categories = Category::with(['product' => function ($query) use ($productAuthor, $shop) {
                return $query->active()->where('added_by', $productAuthor)
                    ->when($shop['author_type'] != 'admin', function ($query) use ($shop) {
                        return $query->where('user_id', $shop['seller_id']);
                    })->withCount(['orderDetails']);
            }])
            ->with(['childes.childes' => function ($query) use ($productAuthor, $shop) {
                return $query->withCount(['product' => function ($query) use ($productAuthor, $shop) {
                    return $query->active()->where('added_by', $productAuthor)
                        ->when($shop['author_type'] != 'admin', function ($query) use ($shop) {
                            return $query->where('user_id', $shop['seller_id']);
                        })->when(request('offer_type') == 'clearance_sale', function ($query) {
                        return $query->whereHas('clearanceSale', function ($query) {
                            return $query->active();
                        });
                    });
                }]);
            }])->where('position', 0)
            ->whereIn('id', $categoryIds)
            ->withCount(['product' => function ($query) use ($productAuthor, $shop) {
                $query->active()->where('added_by', $productAuthor)
                    ->when($shop['author_type'] != 'admin', function ($query) use ($shop) {
                        return $query->where('user_id', $shop['seller_id']);
                    })
                    ->when(request('offer_type') == 'clearance_sale', function ($query) {
                    return $query->whereHas('clearanceSale', function ($query) {
                        return $query->active();
                    });
                });
            }])
            ->get();
        return CategoryManager::getPriorityWiseCategorySortQuery(query: $categories);
    }

    public function getShopBrandsList($request, $products, $sellerType, $sellerId)
    {
        $brandIds = $products->pluck('brand_id')->toArray();
        $brands = Brand::active()->whereIn('id', $brandIds)->with(['brandProducts' => function ($query) use ($sellerType, $sellerId,  $request) {
            return $query->active()->when($sellerType == 'admin', function ($query) use ($sellerType) {
                return $query->where(['added_by' => $sellerType]);
            })
                ->when($sellerId && $sellerType == 'seller', function ($query) use ($sellerId, $sellerType) {
                    return $query->where(['added_by' => $sellerType, 'user_id' => $sellerId]);
                })->withCount(['orderDetails']);
        }])
            ->withCount(['brandProducts' => function ($query) use ($sellerType, $sellerId, $request) {
                    return $query->active()->when($sellerType == 'admin', function ($query) use ($sellerType) {
                        return $query->where(['added_by' => $sellerType]);
                    })
                    ->when($sellerId && $sellerType == 'seller', function ($query) use ($sellerId, $sellerType, $request) {
                        return $query->where(['added_by' => $sellerType, 'user_id' => $sellerId]);
                    })
                    ->when($request['offer_type'] == 'clearance_sale', function ($query) use ($sellerId, $sellerType) {
                        $stockClearanceProductIds = StockClearanceProduct::active()
                            ->when($sellerId && $sellerType == 'admin', function ($query) use ($sellerId, $sellerType) {
                                return $query->where(['added_by' => 'admin']);
                            })
                            ->when($sellerId && $sellerType == 'seller', function ($query) use ($sellerId, $sellerType) {
                                return $query->where(['added_by' => 'vendor', 'user_id' => $sellerId]);
                            })
                            ->pluck('product_id')->toArray();
                        return $query->whereIn('id', $stockClearanceProductIds);
                    });
            }])->get();

        $brandProductSortBy = getWebConfig(name: 'brand_list_priority');
        if ($brandProductSortBy && ($brandProductSortBy['custom_sorting_status'] == 1)) {
            if ($brandProductSortBy['sort_by'] == 'most_order') {
                $brands = $brands->map(function ($brand) {
                    $brand['order_count'] = $brand->brandProducts->sum('order_details_count');
                    return $brand;
                })->sortByDesc('order_count');
            } elseif ($brandProductSortBy['sort_by'] == 'latest_created') {
                $brands = $brands->sortByDesc('id');
            } elseif ($brandProductSortBy['sort_by'] == 'first_created') {
                $brands = $brands->sortBy('id');
            } elseif ($brandProductSortBy['sort_by'] == 'a_to_z') {
                $brands = $brands->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE);
            } elseif ($brandProductSortBy['sort_by'] == 'z_to_a') {
                $brands = $brands->sortByDesc('name', SORT_NATURAL | SORT_FLAG_CASE);
            }
        }
        return $brands;
    }

    public function filterProductsAjaxResponse(Request $request): JsonResponse
    {
        if ($request->has('min_price') && $request['min_price'] != '' && $request->has('max_price') && $request['max_price'] != '' && $request['min_price'] > $request['max_price']) {
            if ($request->ajax()) {
                return response()->json([
                    'success' => 0,
                    'message' => translate('Minimum_price_should_be_less_than_or_equal_to_maximum_price.'),
                ]);
            }
            Toastr::error(translate('Minimum_price_should_be_less_than_or_equal_to_maximum_price.'));
            redirect()->back();
        }

        $singlePageProductCount = $request['per_page_product'] ?? 25;
        if ($request->has('shop_id')) {
            $shopID = $request['shop_id'];
            self::checkShopExistence($shopID);
            $productAddedBy = $shopID == 0 ? 'admin' : 'seller';
            $productUserID = $shopID == 0 ? $shopID : Shop::where('id', $shopID)->first()->seller_id;
            $productListData = ProductManager::getProductListData($request, $productUserID, $productAddedBy);
        } else {
            $productListData = ProductManager::getProductListData($request);
        }

        $category = [];
        if ($request['category_ids']) {
            $category = Category::whereIn('id', $request['category_ids'])->get();
        }

        $brands = [];
        if ($request['brand_ids']) {
            $brands = Brand::whereIn('id', $request['brand_ids'])->get();
        }

        $publishingHouse = [];
        if ($request['publishing_house_ids']) {
            $publishingHouse = PublishingHouse::whereIn('id', $request['publishing_house_ids'])->select('id', 'name')->get();
        }

        $productAuthors = [];
        if ($request['author_ids']) {
            $productAuthors = Author::whereIn('id', $request['author_ids'])->select('id', 'name')->get();
        }

        $rating = $request->rating ?? [];
        $productsCount = $productListData->count();
        $paginateCount = ceil($productsCount / $singlePageProductCount);
        $currentPage = $offset ?? Paginator::resolveCurrentPage('page');
        $results = $productListData->forPage($currentPage, $singlePageProductCount);
        $products = new LengthAwarePaginator(items: $results, total: $productsCount, perPage: $singlePageProductCount, currentPage: $currentPage, options: [
            'path' => Paginator::resolveCurrentPath(),
            'appends' => $request->all(),
        ]);

        $data = self::getProductListRequestData(request: $request);

        return response()->json([
            'html_products' => view('theme-views.product._ajax-products', [
                'products' => $products,
                'paginate_count' => $paginateCount,
                'page' => ($request->page ?? 1),
                'request_data' => $request->all(),
                'singlePageProductCount' => $singlePageProductCount,
                'data' => $data,
            ])->render(),
            'html_tags' => view('theme-views.product._selected_filter_tags', [
                'tags_category' => $category,
                'tags_brands' => $brands,
                'rating' => $rating,
                'publishingHouse' => $publishingHouse,
                'productAuthors' => $productAuthors,
                'sort_by' => $request['sort_by'],
            ])->render(),
            'products_count' => $productsCount,
            'products' => $products,
            'singlePageProductCount' => $singlePageProductCount,
        ]);
    }

    public function ajax_shop_vacation_check(Request $request): JsonResponse
    {
        $current_date = date('Y-m-d');

        if ($request['added_by'] == "seller") {
            $shop = Shop::where('seller_id', $request['user_id'])->first();
            $vacation_start_date = $shop->vacation_start_date ? date('Y-m-d', strtotime($shop->vacation_start_date)) : null;
            $vacation_end_date = $shop->vacation_end_date ? date('Y-m-d', strtotime($shop->vacation_end_date)) : null;
            $temporary_close = $shop->temporary_close;
            $vacation_status = $shop->vacation_status;
        } else {
            $temporary_close = getWebConfig(name: 'temporary_close');
            $inhouse_vacation = getWebConfig(name: 'vacation_add');
            $vacation_start_date = $inhouse_vacation['vacation_start_date'];
            $vacation_end_date = $inhouse_vacation['vacation_end_date'];
            $vacation_status = $inhouse_vacation['status'];
            $temporary_close = $temporary_close['status'];
        }

        if ($temporary_close || ($vacation_status && $current_date >= $vacation_start_date && $current_date <= $vacation_end_date)) {
            return response()->json(['status' => 'inactive']);
        } else {
            $product_data = Product::find($request['id']);

            unset($request['added_by']);
            $request['quantity'] = $product_data->minimum_order_qty;

            $cart = CartManager::add_to_cart($request);
            session()->forget('coupon_code');
            session()->forget('coupon_type');
            session()->forget('coupon_bearer');
            session()->forget('coupon_discount');
            session()->forget('coupon_seller_id');
            return response()->json($cart);
        }
    }

    public static function getProductListRequestData($request): array
    {
        if ($request->has('product_view') && in_array($request['product_view'], ['grid-view', 'list-view'])) {
            session()->put('product_view_style', $request['product_view']);
        }

        $data = [
            'id' => $request['id'],
            'name' => $request['name'],
            'brand_id' => $request['brand_id'],
            'category_id' => $request['category_id'],
            'data_from' => $request['data_from'],
            'offer_type' => $request['offer_type'],
            'sort_by' => $request['sort_by'],
            'page_no' => $request['page'],
            'min_price' => $request['min_price'],
            'max_price' => $request['max_price'],
            'product_type' => $request['product_type'],
            'shop_id' => $request['shop_id'],
            'author_id' => $request['author_id'],
            'publishing_house_id' => $request['publishing_house_id'],
            'search_category_value' => $request['search_category_value'],
            'product_name' => $request['product_name'],
            'page' => $request['page'] ?? 1,
        ];

        if ($request->has('shop_id')) {
            $data['shop_id'] = $request['shop_id'];
        }

        return $data;
    }
}
