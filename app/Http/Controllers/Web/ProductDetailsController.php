<?php

namespace App\Http\Controllers\Web;

use App\Services\Analytics\Analytics;
use App\Contracts\Repositories\OrderDetailRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\ReviewRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Repositories\DealOfTheDayRepository;
use App\Repositories\WishlistRepository;
use App\Services\ProductService;
use App\Traits\ProductTrait;
use App\Utils\ProductManager;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class ProductDetailsController extends Controller
{
    use ProductTrait;

    public function __construct(
        private readonly ProductRepositoryInterface        $productRepo,
        private readonly WishlistRepository                $wishlistRepo,
        private readonly ReviewRepositoryInterface         $reviewRepo,
        private readonly OrderDetailRepositoryInterface    $orderDetailRepo,
        private readonly DealOfTheDayRepository            $dealOfTheDayRepo,
        private readonly ProductService                    $productService,
    )
    {
    }

    /**
     * @param string $slug
     * @return View|RedirectResponse
     */
    public function index(string $slug): View|RedirectResponse
    {
        return self::getDefaultTheme(slug: $slug);
    }

    public function getDefaultTheme(string $slug): View|RedirectResponse
    {
        $product = $this->productRepo->getWebFirstWhereActive(
            params: ['slug' => $slug, 'customer_id' => Auth::guard('customer')->user()->id ?? 0],
            relations: ['seoInfo', 'digitalVariation' => 'digitalVariation', 'reviews', 'seller.shop', 'digitalProductAuthors.author',
                'digitalProductPublishingHouse.publishingHouse', 'clearanceSale' => 'clearanceSale']
        );

        if ($product) {
            // Analytics. Recorded before anything else on the page can fail: a product view is a
            // view whether or not the reviews block renders.
            app(Analytics::class)->productViewed(
                productId: (int) $product['id'],
                vendorId: $product['added_by'] === 'seller' ? (int) $product['user_id'] : null,
                price: (float) $product['unit_price'],
                properties: array_filter([
                    'category_id' => $product['category_id'] ?? null,
                    'brand_id' => $product['brand_id'] ?? null,
                    'product_type' => $product['product_type'] ?? null,
                    'in_stock' => (int) ($product['current_stock'] ?? 0) > 0,
                ], static fn ($value) => $value !== null),
            );

            $initialProductConfig = ProductManager::getInitialProductQuantity($product);
            $initialProductQuantity = $initialProductConfig['quantity'];
            $initialProductPrice = $initialProductConfig['price'];

            $productDetailsMeta = $product?->seoInfo;
            $productAuthorsInfo = $this->productService->getProductAuthorsInfo(product: $product);
            $productPublishingHouseInfo = $this->productService->getProductPublishingHouseInfo(product: $product);

            $overallRating = getOverallRating(reviews: $product?->reviews);
            $wishlistStatus = $this->wishlistRepo->getListWhereCount(filters: ['product_id' => $product['id'], 'customer_id' => auth('customer')->id()]);
            $productReviews = $this->reviewRepo->getListWhere(
                orderBy: ['id' => 'desc'],
                filters: ['product_id' => $product['id']],
                relations: ['reply'],
                dataLimit: 2, offset: 1
            );

            $firstVariationQuantity = $product['current_stock'];
            if (count(json_decode($product['variation'], true)) > 0) {
                $firstVariationQuantity = json_decode($product['variation'], true)[0]['qty'];
            }
            $firstVariationQuantity = $product['product_type'] == 'physical' ? $firstVariationQuantity : 999;

            $rating = getRating(reviews: $product->reviews);
            $decimalPointSettings = getWebConfig('decimal_point_settings');
            $moreProductFromSeller = $this->productRepo->getWebListWithScope(
                orderBy: ['id' => 'desc'],
                scope: 'active',
                filters: ['added_by' => $product['added_by'] == 'admin' ? 'in_house' : $product['added_by'], 'seller_id' => $product['user_id']],
                whereNotIn: ['id' => [$product['id']]],
                dataLimit: 5,
                offset: 1
            );

            if ($product['added_by'] == 'seller') {
                $productsForReview = $this->productRepo->getWebListWithScope(
                    scope: 'active',
                    filters: ['added_by' => $product['added_by'], 'seller_id' => $product['user_id']],
                    withCount: ['reviews' => 'reviews']
                );
            } else {
                $productsForReview = $this->productRepo->getWebListWithScope(
                    scope: 'active',
                    filters: ['added_by' => 'in_house', 'seller_id' => $product['user_id']],
                    withCount: ['reviews' => 'reviews']
                );
            }

            $totalReviews = 0;
            foreach ($productsForReview as $item) {
                $totalReviews += $item->reviews_count;
            }
            $countOrder = $this->orderDetailRepo->getListWhereCount(filters: ['product_id' => $product['id']]);
            $countWishlist = $this->wishlistRepo->getListWhereCount(filters: ['product_id' => $product['id']]);
            $relatedProducts = $this->productRepo->getWebListWithScope(
                scope: 'active',
                filters: ['category_id' => $product['category_id']],
                whereNotIn: ['id' => [$product['id']]],
                relations: ['reviews' => 'reviews'],
                dataLimit: 12,
                offset: 1
            );
            $dealOfTheDay = $this->dealOfTheDayRepo->getFirstWhere(['product_id' => $product['id'], 'status' => 1]);
            $currentDate = date('Y-m-d');

            $previewFileInfo = getFileInfoFromURL(url: $product?->preview_file_full_url['path']);

            return view(VIEW_FILE_NAMES['products_details'], compact('product', 'initialProductConfig', 'initialProductQuantity','initialProductPrice','countWishlist', 'countOrder', 'relatedProducts',
                'dealOfTheDay', 'currentDate', 'overallRating', 'wishlistStatus', 'productReviews', 'rating', 'totalReviews', 'productsForReview', 'moreProductFromSeller', 'decimalPointSettings', 'previewFileInfo', 'productAuthorsInfo', 'productPublishingHouseInfo', 'firstVariationQuantity', 'productDetailsMeta'));
        }

        Toastr::error(translate('not_found'));
        return back();
    }

}
