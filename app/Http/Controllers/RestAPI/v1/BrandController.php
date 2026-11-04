<?php

namespace App\Http\Controllers\RestAPI\v1;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Product;
use App\Models\Shop;
use App\Utils\BrandManager;
use App\Utils\Helpers;
use App\Utils\ProductManager;
use Illuminate\Http\JsonResponse;
use App\Services\BrandPageService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

class BrandController extends Controller
{
    public function __construct(private readonly BrandPageService $brandPageService)
    {
    }

    /**
     * The brand landing header the app renders above the product grid: the
     * brand's banner and the categories its products actually live in, each with
     * a count. Filter the product list with the same category id to reproduce
     * what the web page does.
     */
    public function getPageHeader(Request $request, $brandId): JsonResponse
    {
        $brand = Brand::active()->find($brandId);
        if (!$brand) {
            return response()->json(['errors' => [['code' => 'brand', 'message' => translate('brand_not_found')]]], 404);
        }

        $header = $this->brandPageService->getPageHeader(brand: $brand, request: $request);
        $banner = $header['banner'];

        return response()->json([
            'brand' => [
                'id' => $brand['id'],
                'name' => $brand['name'],
                'slug' => $brand['slug'],
                'image_full_url' => $brand->image_full_url,
                'mobile_image_full_url' => $brand->mobile_image_full_url,
            ],
            'banner' => $banner ? [
                'id' => $banner['id'],
                'title' => $banner['title'],
                'sub_title' => $banner['sub_title'],
                'url' => $banner['url'],
                'photo_full_url' => $banner->photo_full_url,
                'mobile_photo_full_url' => $banner->mobile_photo_full_url,
            ] : null,
            'categories' => $header['categories']->map(fn ($category) => [
                'id' => $category['id'],
                'name' => $category['name'],
                'slug' => $category['slug'],
                'products_count' => $category['products_count'],
                'icon_full_url' => $category->icon_full_url,
                'mobile_icon_full_url' => $category->mobile_icon_full_url,
            ])->values(),
        ], 200);
    }

    public function get_brands(Request $request): array
    {
        $shop = $request->has('shop_slug') && !empty($request['shop_slug']) ? Shop::where('slug', $request['shop_slug'])->first() : null;

        if ($shop) {
            $brand_ids = Product::active()
                ->when($shop['author_type'] == 'admin', function ($query) use ($request) {
                    return $query->where(['added_by' => 'admin']);
                })
                ->when($shop['author_type'] != 'admin', function ($query) use ($request, $shop) {
                    return $query->where(['added_by' => 'seller'])->where('user_id', $shop['seller_id']);
                })->pluck('brand_id');
            $brands = Brand::active()->whereIn('id', $brand_ids)->withCount('brandProducts');
        } else {
            $brands = Brand::active()->withCount('brandProducts');
        }

        $brands = self::getPriorityWiseBrandProductsQuery(query: $brands);
        $currentPage = $request['offset'] ?? Paginator::resolveCurrentPage('page');
        $totalSize = $brands->count();
        $brands = $brands->forPage($currentPage, $request->get('limit', DEFAULT_DATA_LIMIT));

        $brands = new LengthAwarePaginator($brands, $totalSize, $request->get('limit', DEFAULT_DATA_LIMIT), $currentPage, [
            'path' => Paginator::resolveCurrentPath(),
            'appends' => $request->all(),
        ]);
        return [
            'total_size' => $brands->total(),
            'limit' => (int)$request['limit'],
            'offset' => (int)$request['offset'],
            'brands' => $brands->values()
        ];
    }

    function getPriorityWiseBrandProductsQuery($query): Collection
    {
        $brandProductSortBy = getWebConfig(name: 'brand_list_priority');
        if ($brandProductSortBy && ($brandProductSortBy['custom_sorting_status'] == 1)) {
            if ($brandProductSortBy['sort_by'] == 'most_order') {
                return $query->with(['brandProducts' => function ($query) {
                    return $query->withCount('orderDetails');
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
            } else {
                return $query->get();
            }
        }

        return $query->latest()->get();
    }

    public function get_products(Request $request, $brand_id): JsonResponse
    {
        $dataLimit = $request['limit'] ?? 'all';
        $user = Helpers::getCustomerInformation($request);
        $searchKey = $request['search'];

        $products = Product::active()
            ->with(['clearanceSale' => function ($query) {
                return $query->active();
            }])
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
            ->withCount(['reviews', 'wishList' => function ($query) use ($user) {
                $query->where('customer_id', $user != 'offline' ? $user->id : '0');
            }])
            ->where(['brand_id' => $brand_id]);

        if ($dataLimit == 'all') {
            return response()->json(Helpers::product_data_formatting($products->get(), true), 200);
        }

        $products = $products->paginate(($request['limit'] ?? 20), ['*'], 'page', request()->get('page', ($request['offset'] ?? 1)));
        $productFinal = Helpers::product_data_formatting($products, true);

        return response()->json([
            'total_size' => $products->total(),
            'limit' => (int)$request['limit'],
            'offset' => (int)$request['offset'],
            'products' => $productFinal,
        ], 200);
    }
}
