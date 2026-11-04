<?php

namespace App\Http\Controllers\RestAPI\v1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Product;
use App\Models\Review;
use App\Models\Seller;
use App\Models\Shop;
use App\Traits\CacheManagerTrait;
use App\Traits\InHouseTrait;
use App\Utils\Helpers;
use App\Utils\ProductManager;
use App\Services\VendorSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Arr;

class SellerController extends Controller
{
    use InHouseTrait;
    use CacheManagerTrait;

    public function __construct(
        private Seller $seller,
        private readonly VendorSummaryService $vendorSummary,
    )
    {
    }

    public function get_seller_info(Request $request): JsonResponse
    {
        $shop = Shop::where('slug', $request['slug'])->first();
        $data = [];
        $seller = $shop['author_type'] != 'admin' ? Seller::with(['shop'])->where(['id' => $shop['seller_id']])->first(['id', 'f_name', 'l_name', 'phone', 'image', 'minimum_order_amount']) : null;

        $productIds = Product::active()
            ->when($shop && $shop['author_type'] == 'admin', function ($query) {
                return $query->where(['added_by' => 'admin']);
            })
            ->when($shop && $shop['author_type'] != 'admin', function ($query) use ($shop) {
                return $query->where(['added_by' => 'seller'])
                    ->where('user_id', $shop['seller_id']);
            })
            ->withCount('reviews')
            ->pluck('id')->toArray();

        $avgRating = Review::active()->whereIn('product_id', $productIds)->avg('rating');
        $totalReview = Review::active()->whereIn('product_id', $productIds)->count();
        $totalOrder = Review::active()->whereIn('product_id', $productIds)->groupBy('order_id')->count();
        $totalProduct = Product::active()
            ->when($shop && $shop['author_type'] == 'admin', function ($query) {
                return $query->where(['added_by' => 'admin']);
            })
            ->when($shop && $shop['author_type'] != 'admin', function ($query) use ($shop) {
                return $query->where(['added_by' => 'seller'])
                    ->where('user_id', $shop['seller_id']);
            })->count();

        $minimumOrderAmount = 0;
        $minimumOrderAmountStatus = getWebConfig(name: 'minimum_order_amount_status');
        $minimumOrderAmountBySeller = getWebConfig(name: 'minimum_order_amount_by_seller');
        $ratingPercentage = round(($avgRating * 100) / 5);
        if ($shop['author_type'] != 'admin' && $minimumOrderAmountStatus && $minimumOrderAmountBySeller) {
            $minimumOrderAmount = $seller['minimum_order_amount'];
            unset($seller['minimum_order_amount']);
        }

        $data['seller'] = $seller;
        $data['avg_rating'] = (float)$avgRating;
        $data['positive_review'] = round(($avgRating * 100) / 5);
        $data['total_review'] = $totalReview;
        $data['total_order'] = $totalOrder;
        $data['total_product'] = $totalProduct;
        $data['minimum_order_amount'] = $minimumOrderAmount;
        $data['rating_percentage'] = $ratingPercentage;

        return response()->json($data, 200);
    }

    public function getVendorProducts($slug, Request $request): JsonResponse
    {
        $products = ProductManager::get_seller_products($slug, $request);
        $productsList = $products->total() > 0 ? Helpers::product_data_formatting($products->items(), true) : [];
        return response()->json([
            'total_size' => $products->total(),
            'limit' => (int)$request['limit'],
            'offset' => (int)$request['offset'],
            'products' => $productsList
        ]);
    }

    /**
     * Every active review on one seller's own products, correlated to the row
     * being selected. Fully qualified and free of global scopes: this is the one
     * query in the app where two tables each bring a `status` column.
     */
    private function sellerReviews(): \Illuminate\Database\Eloquent\Builder
    {
        return Review::query()
            ->withoutGlobalScopes()
            ->join('products', 'products.id', '=', 'reviews.product_id')
            ->whereColumn('products.user_id', 'sellers.id')
            ->where('products.added_by', 'seller')
            ->where('reviews.status', 1);
    }

    public function getSellerList(Request $request, $type): array
    {
        $sellers = $this->seller->when($type == 'top', function ($query) {
            return $query->whereHas('orders');
        })
            ->approved()->with(['shop'])
            ->select('sellers.*')
            ->withCount(['orders', 'product' => function ($query) {
                $query->active();
            }])
            // The card needs two numbers about this seller's reviews, so ask the
            // database for the two numbers. Loading every product and every
            // review of every seller to add them up in PHP — and then discarding
            // the rows — is the same answer at a fraction of the cost.
            //
            // Written as subqueries rather than relation aggregates on purpose.
            // Reaching reviews through products puts a `status` column from each
            // table in scope, and Review's global scope names that column
            // unqualified — ambiguous, and MySQL rejects the statement. A
            // relation aggregate merges that scope back in from the relation's
            // own builder however the closure asks it not to, so the only way to
            // be sure of every predicate is to write them all.
            ->addSelect([
                'rating_count' => $this->sellerReviews()->selectRaw('count(*)'),
                'total_rating' => $this->sellerReviews()->selectRaw('coalesce(sum(reviews.rating), 0)'),
            ])
            ->get()
            ->each(function ($seller) {
                $seller['temporary_close'] = (int)$seller?->shop?->temporary_close ?? 0;
                $seller['total_rating'] = (float)($seller->total_rating ?? 0);
                $seller['rating_count'] = (int)($seller->rating_count ?? 0);
                $seller['review_count'] = $seller['rating_count'];
                $seller['average_rating'] = $seller['total_rating'] / ($seller['rating_count'] == 0 ? 1 : $seller['rating_count']);
                $seller->is_vacation_mode_now = checkVendorAbility(type: 'vendor', status: 'vacation_status', vendor: $seller?->shop);
            });

        $inhouseProducts = Product::active()->with(['reviews', 'rating'])
            ->withCount(['reviews' => function ($query) {
                $query->active();
            }])
            ->where(['added_by' => 'admin'])->get();
        $inhouseProductCount = $inhouseProducts->count();

        $inhouseReviewData = Review::active()->whereIn('product_id', $inhouseProducts->pluck('id'));
        $inhouseReviewDataCount = $inhouseReviewData->count();
        $inhouseRattingStatusPositive = 0;
        foreach ($inhouseReviewData->pluck('rating') as $singleRating) {
            ($singleRating >= 4 ? ($inhouseRattingStatusPositive++) : '');
        }

        $inhouseShop = getInHouseShopConfig();

        $inhouseSeller = $this->getInHouseSellerObject();
        $inhouseSeller->total_rating = $inhouseReviewDataCount;
        $inhouseSeller->rating_count = $inhouseReviewDataCount;
        $inhouseSeller->review_count = $inhouseReviewDataCount;
        $inhouseSeller->product_count = $inhouseProductCount;
        $inhouseSeller->average_rating = $inhouseReviewData->avg('rating');
        $inhouseSeller->positive_review = $inhouseReviewDataCount != 0 ? ($inhouseRattingStatusPositive * 100) / $inhouseReviewDataCount : 0;
        $inhouseSeller->orders_count = Order::where(['seller_is' => 'admin'])->count();
        $inhouseSeller->temporary_close = (int)$inhouseShop->temporary_close ?? 0;
        $inhouseSeller->shop = $inhouseShop;
        $sellers->prepend($inhouseSeller);

        if ($type == 'top') {
            $sellers = ProductManager::getPriorityWiseTopVendorQuery(query: $sellers);
        } elseif ($type == 'new') {
            $sellers = $sellers->sortByDesc('id');
        } else {
            $sellers = ProductManager::getPriorityWiseVendorQuery(query: $sellers);
        }

        $currentPage = $request['offset'] ?? Paginator::resolveCurrentPage('page');
        $totalSize = $sellers->count();
        $sellers = $sellers->forPage($currentPage, $request->get('limit', DEFAULT_DATA_LIMIT));

        $sellers = new LengthAwarePaginator($sellers, $totalSize, $request->get('limit', DEFAULT_DATA_LIMIT), $currentPage, [
            'path' => Paginator::resolveCurrentPath(),
            'appends' => $request->all(),
        ]);

        return [
            'total_size' => $sellers->total(),
            'limit' => (int)$request['limit'],
            'offset' => (int)$request['offset'],
            // A shopper's view of each vendor: never the seller's contact,
            // banking or tax documents, which whole-model responses leaked.
            'sellers' => $this->vendorSummary->summarizeMany($sellers->values()),
        ];

    }

    public function more_sellers(): JsonResponse
    {
        return response()->json(array_values($this->cacheHomePageMoreVendorsList()->pluck('shop')->toArray()));
    }

    public function get_seller_best_selling_products($slug, Request $request)
    {
        $products = ProductManager::get_seller_best_selling_products($request, $slug, $request['limit'], $request['offset']);
        $products['products'] = isset($products['products'][0]) ? Helpers::product_data_formatting($products['products'], true) : [];

        return response()->json($products, 200);
    }

    public function get_sellers_featured_product($slug, Request $request)
    {
        $user = Helpers::getCustomerInformation($request);
        $shop = Shop::where('slug', $slug)->first();
        $featuredProducts = Product::active()->with(['reviews', 'rating', 'clearanceSale' => function ($query) {
            return $query->active();
        }])
            ->withCount(['wishList' => function ($query) use ($user) {
                $query->where('customer_id', $user != 'offline' ? $user->id : '0');
            }])
            ->where(['featured' => '1'])
            ->when($shop && $shop['author_type'] == 'admin', function ($query) {
                return $query->where(['added_by' => 'admin']);
            })
            ->when($shop && $shop['author_type'] != 'admin', function ($query) use ($shop) {
                return $query->where(['added_by' => 'seller', 'user_id' => $shop['seller_id']]);
            });

        $featuredProductsList = ProductManager::getPriorityWiseFeaturedProductsQuery(query: $featuredProducts, dataLimit: $request['limit'], offset: $request['offset']);
        $featuredProductsList?->map(function ($product) {
            $product['reviews_count'] = $product->reviews->count();
            $product['rating'] = isset($product?->rating[0]) ? $product->rating[0] : null;
        });

        return [
            'total_size' => $featuredProductsList->total(),
            'limit' => (int)$request['limit'],
            'offset' => (int)$request['offset'],
            'products' => $featuredProductsList->items() ? Helpers::product_data_formatting($featuredProductsList->items(), true) : []
        ];
    }

    public function get_sellers_recommended_products($slug, Request $request): array
    {
        $shop = Shop::where('slug', $slug)->first();
        $products = Product::active()->with(['category', 'reviews'])
            ->when($shop && $shop['author_type'] == 'admin', function ($query) {
                return $query->where(['added_by' => 'admin']);
            })
            ->when($shop && $shop['author_type'] != 'admin', function ($query) use ($shop) {
                return $query->where(['added_by' => 'seller', 'user_id' => $shop['seller_id']]);
            })
            ->withCount('orderDelivered')
            ->withSum('tags', 'visit_count')
            ->orderBy('order_delivered_count', 'desc')
            ->orderBy('tags_sum_visit_count', 'desc')
            ->paginate($request['limit'], ['*'], 'page', $request['offset']);

        $products?->map(function ($product) {
            $product['reviews_count'] = $product->reviews->count();
            $product['rating'] = isset($product?->rating[0]) ? $product->rating[0] : null;
        });


        return [
            'total_size' => $products->total(),
            'limit' => (int)$request['limit'],
            'offset' => (int)$request['offset'],
            'products' => $products ? Helpers::product_data_formatting($products, true) : []
        ];
    }
}
