<?php

namespace App\Http\Controllers\Web;

use App\Traits\CacheManagerTrait;
use App\Traits\EmailTemplateTrait;
use App\Traits\InHouseTrait;
use App\Utils\CategoryManager;
use App\Http\Controllers\Controller;
use App\Models\DealOfTheDay;
use App\Models\Product;
use App\Services\BannerPlacementService;
use App\Services\Theme\StorefrontThemeRenderer;
use App\Utils\ProductManager;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Modules\Auction\app\Enums\AuctionStatus;

class HomeController extends Controller
{
    use InHouseTrait, EmailTemplateTrait;
    use CacheManagerTrait;

    public function __construct(
        private readonly Product $product,
    )
    {
    }


    public function index(): View
    {
        $brands = $this->cachePriorityWiseBrandList();
        $homeCategories = $this->cacheHomeCategoriesList();
        $topRatedProducts = $this->cacheTopRatedProductList();
        $latestProductsList = $this->cacheHomePageLatestProductList()->take(8);
        $bestSellProduct = $this->cacheBestSellProductList();
        $recommendedProduct = $this->cacheHomePageRandomSingleProductItem();
        $bannerTypeMainBanner = $this->cacheBannerForTypeMainBanner();
        $bannerTypeMainSectionBanner = $this->cacheBannerTable(bannerType: 'Main Section Banner');
        $topVendorsList = ProductManager::getPriorityWiseTopVendorQuery($this->cacheHomePageTopVendorsList());
        $bannerTypeFooterBanner = $this->cacheBannerTable(bannerType: 'Footer Banner', dataLimit: 10);
        $clearanceSaleProducts = $this->cacheHomePageClearanceSaleProducts();

        $categories = CategoryManager::getCategoriesWithCountingAndPriorityWiseSorting(dataForm: 'home_page');
        $userId = Auth::guard('customer')->user() ? Auth::guard('customer')->id() : 0;
        $flashDeal = ProductManager::getPriorityWiseFlashDealsProductsQuery(userId: $userId);
        $current_date = date('Y-m-d H:i:s');

        $bestSellProduct = $bestSellProduct->count() == 0 ? $latestProductsList : $bestSellProduct;
        $topRatedProducts = $topRatedProducts->count() == 0 ? $bestSellProduct : $topRatedProducts;

        $featuredProductsList = ProductManager::getPriorityWiseFeaturedProductsQuery(query: $this->product->active()->with(['clearanceSale' => function ($query) {
            return $query->active();
        }]), dataLimit: 12);
        $newArrivalProducts = ProductManager::getPriorityWiseNewArrivalProductsQuery(query: $this->product->active()->with(['clearanceSale' => function ($query) {
            return $query->active();
        }]), dataLimit: 8);

        $dealOfTheDay = DealOfTheDay::with(['product' => function ($query) {
            return $query->active()->with(['clearanceSale' => function ($query) {
                return $query->active();
            }]);
        }])
            ->join('products', 'products.id', '=', 'deal_of_the_days.product_id')
            ->select('deal_of_the_days.*', 'products.unit_price')
            ->where('products.status', 1)
            ->where('deal_of_the_days.status', 1)
            ->first();

        $robotsMetaContentData = \App\Models\RobotsMetaContent::where('page_name', 'default')->first();

        $auctionProducts = getWebConfig(name: 'auction_feature_status') ? $this->getHomePageAuctionProductsList() : null;
        if ($auctionProducts && auth('customer')->check()) {
            $auctionProducts->load(['myBid', 'myParticipation']);
        }

        $bannerPlacement = app(BannerPlacementService::class);
        $themeRenderer = app(StorefrontThemeRenderer::class);
        // A merchant can instead place these banners as a theme-builder section, to control where
        // in the page order they sit; the built-in slots stand down so they never render twice.
        $categorySectionBanners = $themeRenderer->pageSectionsRenderBannerType(page: 'home', bannerType: 'Category Section Banner')
            ? collect()
            : $bannerPlacement->getCategorySectionBanners();
        $homePromoBanners = $themeRenderer->pageSectionsRenderBannerType(page: 'home', bannerType: 'Home Promo Banner')
            ? collect()
            : $bannerPlacement->getHomePromoBanners();

        return view(VIEW_FILE_NAMES['home'],
            compact(
                'flashDeal', 'featuredProductsList', 'topRatedProducts', 'bestSellProduct', 'latestProductsList', 'categories', 'brands',
                'dealOfTheDay', 'topVendorsList', 'homeCategories', 'bannerTypeMainBanner', 'bannerTypeMainSectionBanner',
                'current_date', 'recommendedProduct', 'bannerTypeFooterBanner', 'newArrivalProducts', 'clearanceSaleProducts', 'robotsMetaContentData', 'auctionProducts',
                'categorySectionBanners', 'homePromoBanners'
            )
        );
    }

    public function getHomePageAuctionProductsList()
    {
        if (!getCheckAddonPublishedStatus(moduleName: 'Auction')) {
            return null;
        }
        return Cache::remember(CACHE_HOME_PAGE_AUCTION_PRODUCTS_LIST, CACHE_FOR_3_HOURS, function () {
            return \Modules\Auction\app\Models\AuctionProduct::active()
                ->whereAuctionCurrentStatus([AuctionStatus::LIVE, AuctionStatus::UPCOMING])
                ->orderByDesc('id')
                ->take(8)
                ->get();
        });
    }

}
