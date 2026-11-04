<?php

namespace App\Http\Controllers\Vendor;

use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\ReviewReplyRepositoryInterface;
use App\Contracts\Repositories\ReviewRepositoryInterface;
use App\Contracts\Repositories\VendorRepositoryInterface;
use App\Enums\ViewPaths\Vendor\Review;
use App\Exports\CustomerReviewListExport;
use App\Http\Controllers\BaseController;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReviewController extends BaseController
{
    /**
     * @param ReviewRepositoryInterface $reviewRepo
     * @param ProductRepositoryInterface $productRepo
     * @param CustomerRepositoryInterface $customerRepo
     * @param VendorRepositoryInterface $vendorRepo
     * @param ReviewReplyRepositoryInterface $reviewReplyRepo
     */
    public function __construct(
        private readonly ReviewRepositoryInterface      $reviewRepo,
        private readonly ProductRepositoryInterface     $productRepo,
        private readonly CustomerRepositoryInterface    $customerRepo,
        private readonly VendorRepositoryInterface      $vendorRepo,
        private readonly ReviewReplyRepositoryInterface $reviewReplyRepo,
    )
    {
    }

    /**
     * @param Request|null $request
     * @param string|null $type
     * @return View|Collection|LengthAwarePaginator|callable|RedirectResponse|null
     */
    public function index(?Request $request, ?string $type = null): View|Collection|LengthAwarePaginator|null|callable|RedirectResponse
    {
        $vendorId = auth('seller')->id();
        $filters = [
            'product_id' => $request['product_id'],
            'customer_id' => $request['customer_id'],
            'status' => $request['status'],
            'from' => $request['from'],
            'to' => $request['to'],

        ];
        $customers = $this->customerRepo->getCustomerNameList(request: $request)->filter(function ($customer) {
                return $customer['id'] != 0 && $customer['text'] !== 'Walk-In Customer';
            })->values()->toArray();
        if ($request->has('searchValue')) {
            $productId = $this->productRepo->getListWhere(
                searchValue: requestString('searchValue') ?: null,
                filters: ['added_by' => 'seller', 'seller_id' => $vendorId],
                dataLimit: 'all')->pluck('id')->toArray();
            $customerIds = $this->customerRepo->getListWhere(searchValue: requestString('searchValue') ?: null, dataLimit: 'all')->pluck('id')->toArray();
            $whereInFilters = [
                'product_id' => $productId,
                'customer_id' => $customerIds,
            ];
            $reviews = $this->reviewRepo->getListWhereIn(
                globalScope: false,
                orderBy: ['id' => 'desc'],
                searchValue: requestString('searchValue') ?: null,
                whereInFilters: $whereInFilters,
                relations: ['product', 'customer', 'reply'],
                nullFields: ['delivery_man_id'],
                dataLimit: getWebConfig('pagination_limit'));
        } else {
            $whereInFilters = [
                'product_id' => $request['product_id'] ? [$request['product_id']] : [],
            ];
            $reviews = $this->reviewRepo->getListWhereHas(
                whereHas: 'product',
                whereHasFilter: ['added_by' => 'seller', 'user_id' => $vendorId],
                orderBy: ['id' => 'desc'],
                filters: $filters,
                whereInFilters: $whereInFilters,
                relations: ['product', 'customer', 'reply'],
                globalScope: false,
                dataLimit: getWebConfig('pagination_limit'),
            );
        }

        $products = $this->productRepo->getListWithScope(
            searchValue: requestString('searchValue') ?: null,
            filters: ['seller_id' => $vendorId, 'added_by' => 'seller', 'has_reviews' => true],
            whereNotIn:['request_status' => [0,2]],
            dataLimit: getWebConfig('pagination_limit'),
        );



        $product = $this->productRepo->getFirstWhere(params: ['id' => $request['product_id']]);
        $customer = "all";
        if ($request['customer_id'] != 'all' && !is_null($request['customer_id']) && $request->has('customer_id')) {
            $customer = $this->customerRepo->getFirstWhere(params: ['id' => $request['customer_id']]);
        }
        return view(Review::INDEX[VIEW], [
            'reviews' => $reviews,
            'products' => $products,
            'product' => $product,
            'customer' => $customer,
            'from' => $request['from'],
            'to' => $request['to'],
            'customer_id' => $request['customer_id'],
            'product_id' => $request['product_id'],
            'status' => $request['status'],
            'searchValue' => $request['searchValue'],
            'customers' => $customers,
        ]);
    }

    /**
     * @param string|int $id
     * @param string|int $status
     * @return JsonResponse
     */
    public function updateStatus(string|int $id, string|int $status): JsonResponse
    {
        $this->reviewRepo->update(id: $id, data: ['status' => $status]);
        return response()->json([
            'status' => 1,
            'message' => translate('review_status_updated_successfully.')
        ]);
    }

    /**
     * @param Request $request
     * @return BinaryFileResponse|RedirectResponse
     */
    public function exportList(Request $request): BinaryFileResponse|RedirectResponse
    {
        $vendorId = auth('seller')->id();
        $vendor = $this->vendorRepo->getFirstWhere(params: ['id' => $vendorId]);
        $filters = [
            'product_id' => $request['product_id'],
            'customer_id' => $request['customer_id'],
            'status' => $request['status'],
            'from' => $request['from'],
            'to' => $request['to'],
        ];

        if ($request->has('searchValue')) {
            $productId = $this->productRepo->getListWhere(
                searchValue: requestString('searchValue') ?: null,
                filters: ['added_by' => 'seller', 'seller_id' => $vendorId],
                dataLimit: 'all')->pluck('id')->toArray();
            $customerIds = $this->customerRepo->getListWhere(searchValue: requestString('searchValue') ?: null, dataLimit: 'all')->pluck('id')->toArray();
            $whereInFilters = [
                'product_id' => $productId,
                'customer_id' => $customerIds,
            ];
            $reviews = $this->reviewRepo->getListWhereIn(
                globalScope: false,
                orderBy: ['id' => 'desc'],
                whereInFilters: $whereInFilters,
                relations: ['product', 'customer'],
                nullFields: ['delivery_man_id'],
                dataLimit: getWebConfig('pagination_limit'));
        } else {
            $whereInFilters = [
                'product_id' => $request['product_id'] ? [$request['product_id']] : [],
            ];
            $reviews = $this->reviewRepo->getListWhereHas(
                whereHas: 'product',
                whereHasFilter: ['added_by' => 'seller', 'user_id' => $vendorId],
                orderBy: ['id' => 'desc'],
                filters: $filters,
                whereInFilters: $whereInFilters,
                relations: ['product', 'customer'],
                globalScope: false,
                dataLimit: getWebConfig('pagination_limit'),

            );
        }
        $data = [
            'data-from' => 'vendor',
            'vendor' => $vendor,
            'reviews' => $reviews,
            'product_name' => $request->has('product_id') ? $this->productRepo->getFirstWhere(params: ['id' => $request['product_id']])['name'] : "all_products",
            'customer_name' => $request->has('customer_id') ? $this->customerRepo->getFirstWhere(params: ['id' => $request['customer_id']]) : "all_customers",
            'from' => $request['from'],
            'to' => $request['to'],
            'status' => $request['status'],
            'key' => $request['search'],
        ];
        return Excel::download(new CustomerReviewListExport($data), 'Product-Review-List.xlsx');
    }

    public function addReviewReply(Request $request): RedirectResponse
    {
        $this->reviewReplyRepo->updateOrInsert(params: [
            'review_id' => $request['review_id'],
            'added_by' => 'seller',
            'added_by_id' => auth('seller')->id()
        ], data: [
            'reply_text' => $request['reply_text'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return back();
    }
}
