<?php

namespace App\Http\Controllers\Vendor;

use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Contracts\Repositories\LoyaltyPointTransactionRepositoryInterface;
use App\Contracts\Repositories\OrderDetailRepositoryInterface;
use App\Contracts\Repositories\OrderDetailsRewardsRepositoryInterface;
use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Contracts\Repositories\RefundRequestRepositoryInterface;
use App\Contracts\Repositories\RefundStatusRepositoryInterface;
use App\Contracts\Repositories\VendorRepositoryInterface;
use App\Enums\ExportFileNames\Admin\RefundRequest as RefundRequestExportFile;
use App\Enums\ViewPaths\Vendor\Refund;
use App\Events\RefundEvent;
use App\Exports\RefundRequestExport;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Vendor\RefundStatusRequest;
use App\Repositories\VendorRepository;
use App\Services\Marketplace\ReturnLogisticsService;
use App\Services\RefundStatusService;
use App\Traits\CustomerTrait;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RefundController extends BaseController
{
    use CustomerTrait;

    public function __construct(
        private readonly RefundRequestRepositoryInterface       $refundRequestRepo,
        private readonly CustomerRepositoryInterface            $customerRepo,
        private readonly OrderDetailRepositoryInterface         $orderDetailRepo,
        private readonly RefundStatusRepositoryInterface        $refundStatusRepo,
        private readonly RefundStatusService                    $refundStatusService,
        private readonly OrderRepositoryInterface               $orderRepo,
        private readonly VendorRepositoryInterface              $vendorRepo,
        private readonly OrderDetailsRewardsRepositoryInterface $orderDetailsRewardsRepo,
        // Used when a refund settles an order that earned loyalty points. It was read without ever
        // being injected, so that path was a fatal rather than a refund.
        private readonly LoyaltyPointTransactionRepositoryInterface $loyaltyPointTransactionRepo,
        private readonly ReturnLogisticsService                 $returnLogistics,
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
        return $this->getList(request: $request, status: $type);
    }

    /**
     * @param object $request
     * @param string $status
     * @return View
     */
    public function getList(object $request, string $status): View
    {
        $vendorId = auth('seller')->id();
        $searchValue = $request['search'] ?? null;
        $fromDate = $request['from_date'];
        $toDate = $request['to_date'];
        $refundList = $this->refundRequestRepo->getListWhereHas(
            orderBy: ['id' => 'desc'],
            searchValue: $searchValue,
            filters: [
                'status'    => $status,
                'from_date' => $fromDate,
                'to_date'   => $toDate
            ],
            whereHas: 'order',
            whereHasFilters: ['seller_is' => 'seller', 'seller_id' => $vendorId],
            relations: ['customer'],
            dataLimit: getWebConfig('pagination_limit'),

        );
        return view('vendor-views.refund.index', compact('refundList', 'searchValue'));
    }

    /**
     * @param string|int $id
     * @return View|RedirectResponse
     */
    public function getDetailsView(string|int $id): View|RedirectResponse
    {
        $vendorId = auth('seller')->id();
        $refund = $this->refundRequestRepo->getFirstWhereHas(
            params: ['id' => $id],
            whereHas: 'order',
            whereHasFilters: ['seller_is' => 'seller', 'seller_id' => $vendorId],
            relations: ['order.details'],
        );
        if (!$refund || !$refund?->orderDetails || !$refund->order || (count($refund?->order?->details) <= 0)) {
            ToastMagic::error(translate('Refund Details not found'));
            return back();
        }
        $order = $refund->order;
        $totalProductPrice = 0;
        foreach ($order->details as $key => $orderDetails) {
            $totalProductPrice += ($orderDetails->qty * $orderDetails->price) + $orderDetails->tax - $orderDetails->discount;
        }
        $subtotal = $refund->orderDetails->price * $refund->orderDetails->qty - $refund->orderDetails->discount + $refund->orderDetails->tax;
        $couponDiscount = $order->discount_amount > 0 ? ($order->discount_amount * $subtotal) / $totalProductPrice : 0;

        $referralDiscount = $order?->refer_and_earn_discount ?? 0;
        $refundAmount = $subtotal - $couponDiscount - $referralDiscount;


        return view(Refund::DETAILS[VIEW], compact('refund', 'order', 'refundAmount', 'subtotal', 'couponDiscount', 'refundAmount', 'referralDiscount'));
    }

    /**
     * @param RefundStatusRequest $request
     * @return JsonResponse
     */
    public function updateStatus(RefundStatusRequest $request): JsonResponse
    {
        $vendorId = auth('seller')->id();
        $refund = $this->refundRequestRepo->getFirstWhereHas(
            params: ['id' => $request['id']],
            whereHas: 'order',
            whereHasFilters: ['seller_is' => 'seller', 'seller_id' => $vendorId],
        );
        if (($request['refund_status'] == 'approved' && $refund['approved_count'] >= 2) || $request['refund_status'] == 'rejected' && $refund['denied_count'] >= 2) {
            return response()->json(['error' => translate('you_already_changed_') . ($request['refund_status'] == 'approved' ? 'approve' : 'reject') . translate('_status_two_times') . '!!']);
        }
        $customer = $this->customerRepo->getFirstWhere(params: ['id' => $refund['customer_id']]);
        if (!isset($customer)) {
            return response()->json(['error' => translate('this_account_has_been_deleted') . ',' . translate('you_can_not_modify_the_status') . '!!']);
        }

        $orderDetails = $this->orderDetailRepo->getFirstWhere(['id' => $refund['order_details_id']]);
        $orderDetailsRewards = $this->orderDetailsRewardsRepo->getFirstWhere(params: ['order_details_id' => $refund['order_details_id'], 'reward_type' => 'loyalty_point']);
        $loyaltyPoint = $orderDetailsRewards['reward_amount'] ?? 0;
        if ($orderDetailsRewards && $customer['loyalty_point'] < $orderDetailsRewards['reward_amount'] && ($request['refund_status'] == 'refunded' || $request['refund_status'] == 'approved')) {
            return response()->json(['error' => translate('customer_has_not_sufficient_loyalty_point_to_take_refund_for_this_order') . '.']);
        }

        if ($refund['change_by'] == 'admin') {
            return response()->json([
                'error' => translate('refunded_status_can_not_be_changed'). '!! ' . translate('admin_already_changed_the_status') . ': ' . $refund['status'] . '!!'
            ]);
        }

        if ($refund['status'] != 'refunded') {
            if ($request['refund_status'] == 'refunded' && $loyaltyPoint > 0 && getWebConfig(name: 'loyalty_point_status') == 1) {
                $this->loyaltyPointTransactionRepo->addLoyaltyPointTransaction(userId: $refund['customer_id'], reference: $refund['order_id'], amount: $loyaltyPoint, transactionType: 'refund_order');
            }

            $statusMapping = [
                'pending' => 1,
                'approved' => 2,
                'rejected' => 3,
                'refunded' => 4,
            ];
            $this->orderDetailRepo->update(
                id: $orderDetails['id'],
                data: ['refund_request' => $statusMapping[$request['refund_status']]]
            );
            $this->refundStatusRepo->add($this->refundStatusService->getRefundStatusData(
                request: $request,
                refund: $refund,
                changeBy: 'seller'
            ));
            $this->refundRequestRepo->update(
                id: $refund['id'],
                data: [
                    'status' => $request['refund_status'],
                    'approved_count' => $request['refund_status'] == 'approved' ? ($refund['approved_count'] + 1) : $refund['approved_count'],
                    'denied_count' => $request['refund_status'] == 'rejected' ? ($refund['denied_count'] + 1) : $refund['denied_count'],
                    'rejected_note' => $request['refund_status'] == 'rejected' ? $request['rejected_note'] : null,
                    'approved_note' => $request['refund_status'] == 'approved' ? $request['approved_note'] : null,
                    'change_by' => 'seller',
                ]
            );
            // Approving a refund on a physical product means goods are coming back. The seller app
            // has opened the return for that since it shipped; the panel did not, so the same
            // decision taken at a desk gave the money back and quietly lost the units too.
            if ($request['refund_status'] == 'approved') {
                $this->returnLogistics->openForApprovedRefund(
                    refund: $refund,
                    orderDetails: $orderDetails,
                    sellerId: $vendorId,
                );
            }

            $order = $this->orderRepo->getFirstWhere(params: ['id' => $refund['order_id']]);
            event(new RefundEvent(status: $request['refund_status'], order: $order, refund: $refund, orderDetails: $orderDetails));
            return response()->json(['message' => translate('refund_status_updated') . '!!']);
        } else {
            return response()->json(['message' => translate('refunded_status_can_not_be_changed') . '!!']);
        }
    }

    public function exportList(Request $request, $status): BinaryFileResponse
    {
        $vendorId = auth('seller')->id();
        $vendor = $this->vendorRepo->getFirstWhere(params: ['id' => $vendorId]);
        $filter = [
          'status' => $request['status'],
          'from_date' => $request['from_date'],
          'to_date' => $request['to_date'],
        ];
        $refundList = $this->refundRequestRepo->getListWhereHas(
            orderBy: ['id' => 'desc'],
            searchValue: requestString('search') ?: null,
            filters: $filter,
            whereHas: 'order',
            whereHasFilters: ['seller_is' => 'seller', 'seller_id' => $vendorId],
            relations: ['order', 'order.seller', 'order.deliveryMan', 'product'],
            dataLimit: 'all',
        );
        return Excel::download(new RefundRequestExport([
            'data-from' => 'vendor',
            'vendor' => $vendor,
            'refundList' => $refundList,
            'search' => $request['search'],
            'status' => $status,
            'from' => $request['from_date'],
            'to' => $request['to_date'],
            'filter_By' => $request->get('type', 'all'),
        ]), RefundRequestExportFile::EXPORT_XLSX);
    }
}
