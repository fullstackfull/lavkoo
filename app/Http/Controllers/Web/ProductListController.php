<?php

namespace App\Http\Controllers\Web;

use App\Services\Analytics\Analytics;
use App\Models\Author;
use App\Models\BusinessSetting;
use App\Models\FlashDeal;
use App\Models\FlashDealProduct;
use App\Models\PublishingHouse;
use App\Models\RobotsMetaContent;
use App\Models\StockClearanceSetup;
use App\Utils\BrandManager;
use App\Utils\CategoryManager;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Services\BrandPageService;
use App\Services\CategoryPageService;
use App\Utils\ProductManager;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ProductListController extends Controller
{
    public function __construct(
        private readonly CategoryPageService $categoryPageService,
        private readonly BrandPageService    $brandPageService,
    )
    {
    }

    public function products(Request $request)
    {
        $pageTitle = translate('Products');
        if ($request->has('publishing_house_id')) {
            $pageTitle = PublishingHouse::firstWhere('id', $request['publishing_house_id'])?->name .' '.translate('Products');
        }
        if ($request->has('author_id')) {
            $pageTitle = Author::firstWhere('id', $request['author_id'])?->name .' '.translate('Products');
        }

        return self::default_theme(request: $request, pageType: 'default', pageTitle: $pageTitle);
    }

    public function getBrandProductsView(Request $request, $slug)
    {
        $dataForm = 'brand';
        $brand = Brand::active()->where('slug', $slug)->with(['seo'])->first();
        if (!$brand) {
            Toastr::warning(translate('brand_not_found'));
            return back();
        }

        $request->merge(['data_from' => $dataForm]);
        $request->merge(['brand_id' => $brand['id']]);
        return self::getProductsListPage(
            request: $request,
            pageType: $dataForm,
            pageTitle: ucwords(str_replace(['-', '_'], ' ', $brand['name'])) . ' ' . translate('products'),
            metaData: $brand?->seo,
            brand: $brand,
        );
    }

    public function getCategoryProductsView(Request $request, $slug)
    {
        $category = Category::where('slug', $slug)->with(['seo'])->first();
        if (!$category) {
            Toastr::warning(translate('category_not_found'));
            return back();
        }

        $dataForm = 'category';
        $request->merge(['data_from' => $dataForm]);

        if ($category['position'] == 0) {
            $request->merge(['category_id' => $category['id']]);
        } else if ($category['position'] == 1) {
            $request->merge(['sub_category_id' => $category['id']]);
        } else if ($category['position'] == 2) {
            $request->merge(['sub_sub_category_id' => $category['id']]);
        }
        return self::getProductsListPage(
            request: $request,
            pageType: $dataForm,
            pageTitle: ucwords(str_replace(['-', '_'], ' ', $category['name'])) . ' ' . translate('products'),
            metaData: $category?->seo
        );
    }

    public function getFeaturedProductsView(Request $request)
    {
        $request->merge(['data_from' => 'featured']);
        return self::getProductsListPage(
            request: $request,
            pageType: 'featured',
            pageTitle: translate('Featured_Products'),
            metaData: RobotsMetaContent::where('page_name', 'featured-products')->first()
        );
    }

    public function getFeaturedDealProductsView(Request $request)
    {
        $featuredDeal = FlashDeal::where(['deal_type' => 'feature_deal', 'status' => 1])->whereDate('start_date', '<=', date('Y-m-d'))
            ->whereDate('end_date', '>=', date('Y-m-d'))->with(['seo'])->first();
        $request->merge(['offer_type' => 'featured_deal']);
        return self::getProductsListPage(
            request: $request,
            offerType: 'featured_deal',
            pageTitle: translate('Featured_Deal_Products'),
            metaData: $featuredDeal?->seo
        );
    }

    public function getLatestProductsView(Request $request)
    {
        $request->merge(['data_from' => 'latest']);
        return self::getProductsListPage(
            request: $request,
            pageType: 'latest',
            pageTitle: translate('Latest_Products'),
            metaData: RobotsMetaContent::where('page_name', 'latest-products')->first()
        );
    }

    public function getBestSellingProductsView(Request $request)
    {
        $request->merge(['data_from' => 'best-selling']);
        return self::getProductsListPage(
            request: $request,
            pageType: 'best-selling',
            pageTitle: translate('Best_Selling_Products'),
            metaData: RobotsMetaContent::where('page_name', 'best-selling-products')->first()
        );
    }

    public function getTopRatedProductsView(Request $request)
    {
        $request->merge(['data_from' => 'top-rated']);
        return self::getProductsListPage(
            request: $request,
            pageType: 'top-rated',
            pageTitle: translate('Top_Rated_Products'),
            metaData: RobotsMetaContent::where('page_name', 'top-rated-products')->first()
        );
    }

    public function getMostFavoriteProductsView(Request $request)
    {
        $request->merge(['data_from' => 'most-favorite']);
        return self::getProductsListPage(
            request: $request,
            pageType: 'most-favorite',
            pageTitle: translate('Most_Favorite_Products'),
            metaData: RobotsMetaContent::where('page_name', 'most-favorite-products')->first()
        );
    }

    public function getDiscountedProductsView(Request $request)
    {
        $request->merge(['offer_type' => 'discounted']);
        return self::getProductsListPage(
            request: $request,
            pageType: 'discounted',
            pageTitle: translate('Discounted_Products'),
            metaData: RobotsMetaContent::where('page_name', 'discounted-products')->first()
        );
    }

    public function getClearanceSaleProductsView(Request $request)
    {
        $clearanceConfig = StockClearanceSetup::where(['setup_by' => 'admin'])->with(['seo'])->first();
        $request->merge(['offer_type' => 'clearance_sale']);
        return self::getProductsListPage(
            request: $request,
            pageType: 'clearance_sale',
            pageTitle: translate('Clearance_Sale_Products'),
            metaData: $clearanceConfig?->seo
        );
    }


    public function getProductsListPage(object|array $request, string $pageType = 'default', string $offerType = '', string $pageTitle = '', object|array|null $metaData = null, ?Brand $brand = null)
    {
        return self::default_theme(request: $request, pageType: $pageType, pageTitle: $pageTitle, metaData: $metaData, brand: $brand);
    }

    public function default_theme(object|array $request, string $pageType = 'default', string $pageTitle = '', object|array|null $metaData = null, ?Brand $brand = null): View|JsonResponse|Redirector|RedirectResponse
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

        $categories = CategoryManager::getCategoriesWithCountingAndPriorityWiseSorting();
        $activeBrands = BrandManager::getActiveBrandWithCountingAndPriorityWiseSorting();

        $data = self::getProductListRequestData(request: $request);
        $productListData = ProductManager::getProductListData(request: $request);
        $products = $productListData->paginate(20)->appends($data);

        // Analytics. Every product list in the storefront — search, category, brand, offers, the
        // ajax filter refresh — comes through here, which is also the only place the RESULT COUNT
        // is known. That count is the point: a search term with healthy volume and zero results is
        // a product the shop should be stocking, and it is invisible to any report that counts
        // searches without counting what they found.
        $this->recordListView(request: $request, pageType: $pageType, results: $products->total());

        if ($request->ajax()) {
            return response()->json([
                'total_product' => $products->total(),
                'html_products' => view('web-views.products._ajax-products', compact('products'))->render()
            ], 200);
        }

        $categoryPageHeader = $this->categoryPageService->getPageHeader(
            category: $this->categoryPageService->resolveCategory(request: $request)
        );
        $brandPageHeader = $this->brandPageService->getPageHeader(brand: $brand, request: $request);

        return view(VIEW_FILE_NAMES['products_view_page'], [
            'pageTitleContent' => $pageTitle ?? translate('products'),
            'products' => $products,
            'data' => $data,
            'activeBrands' => $activeBrands,
            'categories' => $categories,
            'robotsMetaContentData' => $metaData,
            'categoryPageHeader' => $categoryPageHeader,
            'brandPageHeader' => $brandPageHeader,
        ]);
    }


    public function getPageSelectedDataByType(Request $request, string $type)
    {
        $resultArray = [];
        if ($type == 'tag' && $request->has('category_ids') && !empty($request['category_ids'])) {
            $resultArray = Category::whereIn('id', $request['category_ids'])->select('id', 'name')->get();
        }

        if ($type == 'publishing_house' && $request->has('publishing_house_id') && !empty($request['publishing_house_id'])) {
            $resultArray = PublishingHouse::where('id', $request['publishing_house_id'])->select('id', 'name')->get();
        }

        if ($type == 'author' && $request->has('author_id') && !empty($request['author_id'])) {
            $resultArray = Author::where('id', $request['author_id'])->select('id', 'name')->get();
        }

        if ($type == 'brand' && $request['data_from'] == 'brand') {
            $resultArray = Brand::where('id', $request['brand_id'])->select('id', 'name')->get();
        }

        return $resultArray;
    }

    function getProductsRatingOneToFiveAsArray($productQuery): array
    {
        $rating_1 = 0;
        $rating_2 = 0;
        $rating_3 = 0;
        $rating_4 = 0;
        $rating_5 = 0;

        foreach ($productQuery as $rating) {
            if (isset($rating->rating[0]['average']) && ($rating->rating[0]['average'] > 0 && $rating->rating[0]['average'] < 2)) {
                $rating_1 += 1;
            } elseif (isset($rating->rating[0]['average']) && ($rating->rating[0]['average'] >= 2 && $rating->rating[0]['average'] < 3)) {
                $rating_2 += 1;
            } elseif (isset($rating->rating[0]['average']) && ($rating->rating[0]['average'] >= 3 && $rating->rating[0]['average'] < 4)) {
                $rating_3 += 1;
            } elseif (isset($rating->rating[0]['average']) && ($rating->rating[0]['average'] >= 4 && $rating->rating[0]['average'] < 5)) {
                $rating_4 += 1;
            } elseif (isset($rating->rating[0]['average']) && ($rating->rating[0]['average'] == 5)) {
                $rating_5 += 1;
            }
        }

        return [
            'rating_1' => $rating_1,
            'rating_2' => $rating_2,
            'rating_3' => $rating_3,
            'rating_4' => $rating_4,
            'rating_5' => $rating_5,
        ];
    }

    public static function getProductListRequestData($request): array
    {
        if ($request->has('product_view') && in_array($request['product_view'], ['grid-view', 'list-view'])) {
            session()->put('product_view_style', $request['product_view']);
        }

        return [
            'id' => $request['id'],
            'name' => $request['name'],
            'brand_id' => $request['brand_id'],
            'category_id' => $request['category_id'],
            'sub_category_id' => $request['sub_category_id'],
            'sub_sub_category_id' => $request['sub_sub_category_id'],
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
    }

    public function getFlashDealsView(Request $request, $id): View|RedirectResponse|JsonResponse
    {
        $request->merge(['offer_type' => 'flash-deals']);
        $request->merge(['flash_deals_id' => $id]);

        if ($request->has('product_name') && $request['product_name'] != '') {
            $request->merge(['data_from' => 'search']);
            $request->merge(['search' => $request['product_name']]);
        }

        $singlePageProductCount = 20;
        $userId = Auth::guard('customer')->user() ? Auth::guard('customer')->id() : 0;
        $flashDeal = ProductManager::getPriorityWiseFlashDealsProductsQuery(id: $id, userId: $userId);

        if (!isset($flashDeal['flashDeal']) || $flashDeal['flashDeal'] == null) {
            Toastr::warning(translate('not_found'));
            return back();
        }

        $data = self::getProductListRequestData(request: $request);
        $categories = CategoryManager::getCategoriesWithCountingAndPriorityWiseSorting(dataForm: 'flash-deals');
        $activeBrands = BrandManager::getActiveBrandWithCountingAndPriorityWiseSorting();

        $productListData = ProductManager::getProductListData(request: $request, type: 'flash-deals');
        $ratings = self::getProductsRatingOneToFiveAsArray(productQuery: $productListData);
        $products = $productListData->paginate(20)->appends($data);
        $getProductIds = $products->pluck('id')->toArray();

        if ($request['ratings'] != null) {
            $products = $products->map(function ($product) use ($request) {
                $product->rating = $product->rating->pluck('average')[0];
                return $product;
            });
            $products = $products->where('rating', '>=', $request['ratings'])
                ->where('rating', '<', $request['ratings'] + 1)
                ->paginate(20)->appends($data);
        }

        $allProductsColorList = ProductManager::getProductsColorsArray();
        $tagCategory = $this->getPageSelectedDataByType(request: $request, type: 'tag');
        $tagPublishingHouse = $this->getPageSelectedDataByType(request: $request, type: 'publishing_house');
        $tagProductAuthors = $this->getPageSelectedDataByType(request: $request, type: 'author');
        $tagBrand = $this->getPageSelectedDataByType(request: $request, type: 'brand');
        $paginateCount = ceil($products->count() / $singlePageProductCount);

        if ($request->ajax()) {
            return response()->json([
                'total_product' => $products->total(),
                'html_products' => view(VIEW_FILE_NAMES['products__ajax_partials'], ['products' => $products, 'product_ids' => $getProductIds])->render(),
            ], 200);
        }

        $selectedRatings = $request['rating'] ?? [];
        return view(VIEW_FILE_NAMES['flash_deals'], [
            'pageTitleContent' => translate('Flash_Deal_Products'),
            'products' => $products,
            'paginate_count' => $paginateCount,
            'data' => $data,
            'ratings' => $ratings,
            'selectedRatings' => $selectedRatings,
            'product_ids' => $getProductIds,
            'activeBrands' => $activeBrands,
            'productCategories' => $categories,
            'allProductsColorList' => $allProductsColorList,
            'deal' => $flashDeal['flashDeal'],
            'tag_category' => $tagCategory,
            'tagPublishingHouse' => $tagPublishingHouse,
            'tagProductAuthors' => $tagProductAuthors,
            'tag_brand' => $tagBrand,
            'singlePageProductCount' => $singlePageProductCount,
            'robotsMetaContentData' => $flashDeal['flashDeal']?->seo
        ]);
    }

    public function getFlashDealsProducts(Request $request): JsonResponse
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

        if ($request->has('product_name') && $request['product_name'] != '') {
            $request->merge(['data_from' => 'search']);
            $request->merge(['search' => $request['product_name']]);
        }

        $singlePageProductCount = 20;
        $productListData = ProductManager::getProductListData($request);

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

        $data = [
            'id' => $request['id'],
            'name' => $request['name'],
            'data_from' => $request['data_from'],
            'sort_by' => $request['sort_by'],
            'page_no' => $request['page'],
            'min_price' => $request['min_price'],
            'max_price' => $request['max_price'],
            'product_type' => $request['product_type'],
            'search_category_value' => $request['search_category_value'],
        ];
        if ($request->has('shop_id')) {
            $data['shop_id'] = $request['shop_id'];
        }

        return response()->json([
            'html_products' => view('theme-views.product._ajax-products', [
                'products' => $products,
                'paginate_count' => $paginateCount,
                'page' => $request['page'] ?? 1,
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

    /**
     * Record what this list page was: a search, a category, a brand, or a plain browse.
     *
     * @param  object|array  $request
     */
    private function recordListView(object|array $request, string $pageType, int $results): void
    {
        $analytics = app(Analytics::class);
        $term = trim((string) ($request['name'] ?? $request['product_name'] ?? $request['search'] ?? ''));

        if ($term !== '') {
            $analytics->searchPerformed(term: $term, results: $results, properties: [
                'category' => $request['search_category_value'] ?? null,
                'sort_by' => $request['sort_by'] ?? null,
            ]);

            return;
        }

        if ($pageType === 'category') {
            $categoryId = $request['sub_sub_category_id'] ?? $request['sub_category_id'] ?? $request['category_id'] ?? null;
            if ($categoryId) {
                $analytics->categoryViewed(categoryId: (int) $categoryId, properties: ['results' => $results]);

                return;
            }
        }

        if ($pageType === 'brand' && ($request['brand_id'] ?? null)) {
            $analytics->brandViewed(brandId: (int) $request['brand_id']);
        }
    }
}
