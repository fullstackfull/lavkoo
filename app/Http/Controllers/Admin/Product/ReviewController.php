<?php

namespace App\Http\Controllers\Admin\Product;

use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\ReviewReplyRepositoryInterface;
use App\Contracts\Repositories\ReviewRepositoryInterface;
use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Contracts\Repositories\ShopRepositoryInterface;
use App\Contracts\Repositories\VendorRepositoryInterface;
use App\Enums\ViewPaths\Admin\Review;
use App\Exports\CustomerReviewListExport;
use App\Http\Controllers\BaseController;
use App\Models\Order;
use App\Models\Product;
use App\Traits\InHouseTrait;
use App\Utils\Helpers;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReviewController extends BaseController
{
    use InHouseTrait;

    public function __construct(
        private readonly ReviewRepositoryInterface      $reviewRepo,
        private readonly ProductRepositoryInterface     $productRepo,
        private readonly CustomerRepositoryInterface    $customerRepo,
        private readonly ReviewReplyRepositoryInterface $reviewReplyRepo,
        private readonly VendorRepositoryInterface      $vendorRepo,
        private readonly ShopRepositoryInterface        $shopRepo,
    )
    {
    }

    /**
     * @param Request|null $request
     * @param string|null $type
     * @return View Index function is the starting point of a controller
     * Index function is the starting point of a controller
     */
    public function index(Request|null $request, ?string $type = null): View
    {

        $fromDateFinal = $request['from'];
        $toDateFinal = $request['to'];
        $customers = $this->customerRepo->getCustomerNameList(request: $request, dataLimit: 'all')
            ->filter(function ($customer) {
                return $customer['id'] != 0;
            })
            ->values()
            ->toArray();
        array_unshift($customers, ['id' => 'all', 'text' => translate('All_Customer')]);
        if ($request->filled('searchValue')) {
            $productIds = $this->productRepo->getListWhere(
                searchValue: requestString('searchValue') ?: null,
                filters: [
                    'added_by' => $request['vendor_id'] == 0 ? 'in_house' : '',
                    'user_id' => $request['vendor_id'],
                ],
                dataLimit: 'all'
            )->pluck('id')->toArray();
            $customerIds = $this->customerRepo->getListWhere(searchValue: requestString('searchValue') ?: null, dataLimit: 'all')->pluck('id')->toArray();
            $filtersBy = [
                'product_id' => $productIds,
                'customer_id' => $customerIds,
            ];
            $reviews = $this->reviewRepo->getListWhereIn(
                globalScope: false,
                orderBy: ['id' => 'desc'],
                searchValue: requestString('searchValue') ?: null,
                whereInFilters: $filtersBy,
                relations: ['product', 'customer', 'reply', 'order.details'],
                nullFields: ['delivery_man_id'],
                dataLimit: getWebConfig(name: 'pagination_limit')
            );
        } else {
            $filters = [
                'product_id' => $request['product_id'],
                'customer_id' => $request['customer_id'],
                'status' => $request['status'],
                'from' => $fromDateFinal,
                'to' => $toDateFinal,
                'delivery_man_id' => null,
            ];

            if ($request['vendor_id'] != null) {
                $filters['product_user_id'] = $request['vendor_id'];
                $filters['added_by'] = $request['vendor_id'] == 0 ? 'in_house' : 'seller';
            }

            $reviews = $this->reviewRepo->getListWhereIn(
                globalScope: false,
                orderBy: ['id' => 'desc'],
                filters: $filters,
                relations: ['product', 'customer'],
                dataLimit: getWebConfig(name: 'pagination_limit')
            );
        }

        $products = $this->productRepo->getListWithScope(
            searchValue: requestString('searchValue') ?: null,
            filters: ['has_reviews' => true],
            relations: ['category', 'brand', 'seller'],
            dataLimit: getWebConfig(name: 'pagination_limit')
        );

        $product = $this->productRepo->getFirstWhere(params: ['id' => $request['product_id']]);
        $vendor = $this->shopRepo->getFirstWhere(params: ['id' => $request['vendor_id']]);

        $customer = "all";
        if ($request['customer_id'] != 'all' && !is_null($request['customer_id']) && $request->has('customer_id')) {
            $customer = $this->customerRepo->getFirstWhere(params: ['id' => $request['customer_id']]);
        }

        $shopList =  $this->shopRepo->getListWhere(filters:['author_type'=>'vendor'], relations: ['products', 'products.reviews'], dataLimit: 'all')->each(function ($shopItem) {
            $productReviews = $shopItem->products->pluck('reviews')->collapse();
            $shopItem->average_rating = $productReviews->avg('rating');
            $shopItem->review_count = $productReviews->count();
            $shopItem->total_rating = $productReviews->sum('rating');
            return $shopItem;
        });

        $inhouseProducts = $this->productRepo->getListWithScope(scope: 'active', filters: ['added_by' => 'in_house'] , relations: ['reviews', 'rating'], withCount: ['reviews'], dataLimit: 'all');
        $inhouseProductCount = $inhouseProducts->count();
        $inhouseReviewData = $this->reviewRepo->getListWhereIn(globalScope: true, whereInFilters: ['product_id' => $inhouseProducts->pluck('id')->toArray()], dataLimit: 'all');
        $inhouseReviewDataCount = $inhouseReviewData->count();
        $inhouseRattingStatusPositive = 0;
        foreach ($inhouseReviewData->pluck('rating') as $singleRating) {
            ($singleRating >= 4 ? ($inhouseRattingStatusPositive++) : '');
        }
        $inhouseShop = getInHouseShopConfig();
        $inhouseShop->products_count = $inhouseProductCount;
        $inhouseShop->review_count = $inhouseReviewDataCount;
        $inhouseShop->average_rating = $inhouseReviewData->avg('rating');
        $inhouseShop->positive_review = $inhouseReviewDataCount != 0 ? ($inhouseRattingStatusPositive * 100) / $inhouseReviewDataCount : 0;
        $inhouseShop->orders_count = Order::where(['seller_is' => 'admin'])->count();
        $inhouseShop->is_vacation_mode_now = checkVendorAbility(type: 'inhouse', status: 'vacation_status') ? 1 : 0;
        $shopList = $shopList->reject(function ($shop) use ($inhouseShop) {
            return $shop->seller_id === $inhouseShop->seller_id && $shop->author_type === $inhouseShop->author_type;
        })->prepend($inhouseShop);

        return view('admin-views.reviews.list', [
            'reviews' => $reviews,
            'products' => $products,
            'shopList' => $shopList,
            'product' => $product,
            'vendor' => $vendor,
            'customer' => $customer,
            'from' => $request['from'],
            'to' => $request['to'],
            'customer_id' => $request['customer_id'],
            'vendor_id' => $request['vendor_id'],
            'product_id' => $request['product_id'],
            'status' => $request['status'],
            'searchValue' => $request['searchValue'],
            'customers' => $customers,
        ]);
    }

    public function searchVendor(Request $request): JsonResponse
    {
        $shopList = $this->shopRepo->getListWhere(searchValue: requestString('searchValue') ?: null, filters: ['author_type' => 'vendor'], relations: ['products', 'products.reviews'], dataLimit: 'all')->each(function ($shopItem) {
            $productReviews = $shopItem->products->pluck('reviews')->collapse();
            $productReviews = $productReviews->where('status', 1);
            $shopItem->average_rating = $productReviews->avg('rating');
            $shopItem->review_count = $productReviews->count();
            $shopItem->total_rating = $productReviews->sum('rating');
            return $shopItem;
        });

        if (!(isset($request['searchValue']) && !str_contains(strtolower(getInHouseShopConfig(key: 'name')), strtolower($request['searchValue'])))) {
            $shopList = $shopList->prepend(getInHouseShopConfig());
        }

        return response()->json([
            'result' => view('admin-views.reviews._review-vendors', [
                'shopList' => $shopList,
            ])->render(),
            'count' => count($shopList),
        ]);
    }


    public function updateStatus(Request $request): RedirectResponse|JsonResponse
    {
        $status = $request['status'] ?? 0;
        $this->reviewRepo->update(id: $request['id'], data: ['status' => $status]);

        if ($request->ajax()) {
            return response()->json([
                'status' => 1,
                'message' => translate('review_status_updated.')
            ]);
        }
        ToastMagic::success(translate('review_status_updated'));
        return back();
    }

    public function exportList(Request $request): BinaryFileResponse|RedirectResponse
    {
        $filters = [
            'product_id' => $request['product_id'],
            'customer_id' => $request['customer_id'],
            'vendor_id' => $request['vendor_id'],
            'status' => $request['status'],
            'from' => $request['from'],
            'to' => $request['to'],
            'searchValue' => $request['searchValue'],
            'delivery_man_id' => null,
        ];

        if ($request->has('searchValue')) {
            $productIds = $this->productRepo->getListWhere(
                searchValue: requestString('searchValue') ?: null,
                dataLimit: 'all')->pluck('id')->toArray();
            $customerIds = $this->customerRepo->getListWhere(searchValue: requestString('searchValue') ?: null, dataLimit: 'all')->pluck('id')->toArray();
            $filtersBy = [
                'product_id' => $productIds,
                'customer_id' => $customerIds,
            ];
            $reviews = $this->reviewRepo->getListWhereIn(
                globalScope: false,
                orderBy: ['id' => 'desc'],
                whereInFilters: $filtersBy,
                relations: ['product', 'customer'],
                nullFields: ['delivery_man_id'],
                dataLimit: getWebConfig(name: 'pagination_limit'));
        } else {
            if ($request['vendor_id'] != null) {
                $filters['product_user_id'] = $request['vendor_id'];
                $filters['added_by'] = $request['vendor_id'] == 0 ? 'in_house' : 'seller';
            }
            $reviews = $this->reviewRepo->getListWhereIn(
                globalScope: false,
                orderBy: ['id' => 'desc'],
                filters: $filters,
                relations: ['product', 'customer'],
                dataLimit: getWebConfig(name: 'pagination_limit'));
        }
        $data = [
            'data-from' => 'admin',
            'reviews' => $reviews,
            'product_name' => $request->has('product_id') ? $this->productRepo->getFirstWhere(params: ['id' => $request['product_id']])['name'] : "all_products",
            'customer_name' => $request->has('customer_id') && $request->has('customer_id') != 'all' ? $this->customerRepo->getFirstWhere(params: ['id' => $request['customer_id']]) : "all_customers",
            'from' => $request['from'],
            'to' => $request['to'],
            'status' => $request['status'],
            'key' => $request['search'],
        ];
        return Excel::download(new CustomerReviewListExport($data), 'Customer-Review-List.xlsx');
    }

    public function getCustomerList(Request $request): JsonResponse
    {
        // The repository method is getCustomerNameList(); getCustomerList() has never existed on it,
        // so the customer filter on the reviews page answered 500 on every keystroke. Same call the
        // working Admin\Customer\CustomerController makes.
        $data = $this->customerRepo->getCustomerNameList(request: $request);
        return response()->json($data);
    }

    public function search(Request $request): JsonResponse
    {
        $products = $this->productRepo->getListWithScope(
            searchValue: requestString('name') ?: null,
            scope: 'active',
            relations: ['category', 'brand', 'seller.shop'],
            dataLimit: 'all');
        return response()->json([
            'result' => view('admin-views.partials._search-product', compact('products'))->render(),
        ]);
    }

    public function addReviewReply(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'reply_text' => 'required_without_all:attachments',
        ], [
            'required_without_all' => translate('reply_text_is_required') . '!',
        ]);

        if ($validator->fails()) {
            $errors = Helpers::validationErrorProcessor($validator);
            foreach ($errors as $value) {
                ToastMagic::error(translate($value['message']));
            }
            return back();
        }

        $this->reviewReplyRepo->updateOrInsert(
            params: [
                'review_id' => $request['review_id'],
                'added_by' => 'admin',
                'added_by_id' => auth('admin')->id()
            ], data: [
            'reply_text' => $request['reply_text'],
            'created_at' => now(),
            'updated_at' => now(),
        ]
        );
        return back();
    }
}
