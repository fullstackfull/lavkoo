<?php

namespace App\Http\Controllers\Admin\Order;

use App\Contracts\Repositories\AdminWalletRepositoryInterface;
use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Contracts\Repositories\LoyaltyPointTransactionRepositoryInterface;
use App\Contracts\Repositories\OrderDetailRepositoryInterface;
use App\Contracts\Repositories\OrderDetailsRewardsRepositoryInterface;
use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Contracts\Repositories\RefundRequestRepositoryInterface;
use App\Contracts\Repositories\RefundStatusRepositoryInterface;
use App\Contracts\Repositories\RefundTransactionRepositoryInterface;
use App\Contracts\Repositories\VendorWalletRepositoryInterface;
use App\Enums\ExportFileNames\Admin\RefundRequest as RefundRequestExportFile;
use App\Events\RefundEvent;
use App\Exports\RefundRequestExport;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\RefundStatusRequest;
use App\Services\RefundStatusService;
use App\Services\RefundTransactionService;
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
        private readonly RefundRequestRepositoryInterface           $refundRequestRepo,
        private readonly CustomerRepositoryInterface                $customerRepo,
        private readonly OrderRepositoryInterface                   $orderRepo,
        private readonly OrderDetailRepositoryInterface             $orderDetailRepo,
        private readonly AdminWalletRepositoryInterface             $adminWalletRepo,
        private readonly VendorWalletRepositoryInterface            $vendorWalletRepo,
        private readonly RefundStatusRepositoryInterface            $refundStatusRepos,
        private readonly RefundTransactionRepositoryInterface       $refundTransactionRepo,
        private readonly LoyaltyPointTransactionRepositoryInterface $loyaltyPointTransactionRepo,
        private readonly OrderDetailsRewardsRepositoryInterface     $orderDetailsRewardsRepo,
    ) {}

    public function index(?Request $request, ?string $type = null): View|Collection|LengthAwarePaginator|null|callable|RedirectResponse
    {
        $status = $type;
        $fromDate = $request['from_date'];
        $toDate = $request['to_date'];
        $walletStatus = getWebConfig(name: 'wallet_status');
        $walletAddRefund = getWebConfig(name: 'wallet_add_refund');
        $refundList = $this->refundRequestRepo->getListWhereHas(
            orderBy: ['id' => 'desc'],
            searchValue: requestString('searchValue') ?: null,
            filters: [
                'status'    => $status,
                'from_date' => $fromDate,
                'to_date'   => $toDate
            ],
            whereHas: 'order',
            whereHasFilters: ['seller_is' => $request['type']],
            relations: ['order', 'order.seller', 'order.deliveryMan', 'product', 'customer', 'orderDetails'],
            dataLimit: getWebConfig('pagination_limit'),
        );
        return view('admin-views.refund.list', compact('refundList', 'status', 'walletStatus', 'walletAddRefund'));
    }

    public function getDetailsView($id): View|RedirectResponse
    {
        $refund = $this->refundRequestRepo->getFirstWhere(params: ['id' => $id], relations: ['order.details']);
        if (!$refund || !$refund?->orderDetails) {
            ToastMagic::error(translate('Refund Details not found'));
            return back();
        }
        $order = $refund->order;
        $totalProductPrice = 0;
        foreach ($order->details as $or_d) {
            $totalProductPrice += ($or_d->qty * $or_d->price) + $or_d->tax - $or_d->discount;
        }
        $subtotal = ($refund?->orderDetails?->price * $refund?->orderDetails?->qty) - $refund?->orderDetails?->discount + $refund?->orderDetails?->tax;
        $couponDiscount = $order->discount_amount > 0 ? ($order->discount_amount * $subtotal) / $totalProductPrice : 0;
        $referralDiscount = $order?->refer_and_earn_discount ?? 0;
        $refundAmount = $subtotal - $couponDiscount - $referralDiscount;

        $walletStatus = getWebConfig(name: 'wallet_status');
        $walletAddRefund = getWebConfig(name: 'wallet_add_refund');

        return view(
            'admin-views.refund.details',
            compact(
                'refund',
                'order',
                'totalProductPrice',
                'subtotal',
                'couponDiscount',
                'referralDiscount',
                'refundAmount',
                'walletStatus',
                'walletAddRefund'
            )
        );
    }

    public function updateRefundStatus(RefundStatusRequest $request, RefundStatusService $refundStatusService, RefundTransactionService $refundTransactionService): JsonResponse
    {
        $refund = $this->refundRequestRepo->getFirstWhere(params: ['id' => $request['id']]);
        if ($refund['status'] == 'refunded') {
            return response()->json(['error' => translate('when_refund_status_refunded') . ',' . translate('then_you_can`t_change_refund_status') . '.']);
        }

        if (!empty($request['payment_method']) && empty($request['payment_info'])) {
            return response()->json(['error' => translate('The_payment_info_field_is_required')]);
        }

        $user = $this->customerRepo->getFirstWhere(params: ['id' => $refund['customer_id']]);

        if (!isset($user)) {
            return response()->json(['error' => translate('this_account_has_been_deleted_you_can_not_modify_the_status') . '.']);
        }

        $orderDetailsRewards = $this->orderDetailsRewardsRepo->getFirstWhere(params: ['order_details_id' => $refund['order_details_id'], 'reward_type' => 'loyalty_point']);
        $loyaltyPoint = $orderDetailsRewards['reward_amount'] ?? 0;
        if ($orderDetailsRewards && $user['loyalty_point'] < $orderDetailsRewards['reward_amount'] && ($request['refund_status'] == 'refunded' || $request['refund_status'] == 'approved')) {
            return response()->json(['error' => translate('customer_has_not_sufficient_loyalty_point_to_take_refund_for_this_order') . '.']);
        }

        $order = $this->orderRepo->getFirstWhere(params: ['id' => $refund['order_id']]);
        if ($request['refund_status'] == 'refunded' && $refund['status'] != 'refunded') {
            if ($order['seller_is'] == 'admin') {
                $adminWallet = $this->adminWalletRepo->getFirstWhere(params: ['admin_id' => $order['seller_id']]);
                $this->adminWalletRepo->updateWhere(params: ['admin_id' => $order['seller_id']], data: ['inhouse_earning' => $adminWallet['inhouse_earning'] - $refund['amount']]);
            } else {
                $sellerWallet = $this->vendorWalletRepo->getFirstWhere(params: ['seller_id' => $order['seller_id']]);
                $this->vendorWalletRepo->updateWhere(params: ['seller_id' => $order['seller_id']], data: ['total_earning' => $sellerWallet['total_earning'] - $refund['amount']]);
            }
            $this->refundTransactionRepo->add(data: $refundTransactionService->getData(request: $request, refund: $refund, order: $order));

            // Reverse the marketplace's side of the refund on the vendor ledger.
            //
            // The wallet update just above subtracts the refunded amount from the seller's earnings
            // and says nothing about the commission — so the marketplace kept its cut of a sale it
            // gave back. This posts the refund as a debit and credits the commission on the
            // refunded portion back, proportionally, as new ledger entries. Nothing is edited: the
            // original earning and commission stay as they were.
            app(\App\Services\Marketplace\RefundReversalService::class)->reverseForOrderLine(
                orderDetailsId: $refund['order_details_id'],
                refundedAmount: (float) $refund['amount'],
                refundReference: $refund['id'],
            );
        }

        if ($refund['status'] != 'refunded') {
            $orderDetails = $this->orderDetailRepo->getFirstWhere(params: ['id' => $refund['order_details_id']]);
            $dataArray = $refundStatusService->getRefundStatusProcessData(request: $request, orderDetails: $orderDetails, refund: $refund, loyaltyPoint: $loyaltyPoint);

            if ($request['refund_status'] == 'refunded' && $loyaltyPoint > 0 && getWebConfig(name: 'loyalty_point_status') == 1) {
                $this->loyaltyPointTransactionRepo->addLoyaltyPointTransaction(userId: $refund['customer_id'], reference: $refund['order_id'], amount: $loyaltyPoint, transactionType: 'refund_order');
            }
            $this->orderDetailRepo->update(id: $refund['order_details_id'], data: ['refund_request' => $dataArray['orderDetails']['refund_request']]);
            $this->refundRequestRepo->update(id: $request['id'], data: $dataArray['refund']);
            foreach ($dataArray['refundStatus'] as $status) {
                $this->refundStatusRepos->add(data: $status);
            }

            event(new RefundEvent(status: $request['refund_status'], order: $order, refund: $refund, orderDetails: $orderDetails));
            return response()->json(['message' => translate('refund_status_updated') . '.']);
        } else {
            return response()->json(['error' => translate('refunded_status_can_not_be_changed') . '.']);
        }
    }

    public function exportList(Request $request, $status): BinaryFileResponse
    {
        $fromDate = $request['from_date'];
        $toDate = $request['to_date'];
        $refundList = $this->refundRequestRepo->getListWhereHas(
            orderBy: ['id' => 'desc'],
            searchValue: requestString('searchValue') ?: null,
            filters: [
                'status'    => $status,
                'from_date' => $fromDate,
                'to_date'   => $toDate
            ],
            whereHas: 'order',
            whereHasFilters: ['seller_is' => $request['type']],
            relations: ['order', 'order.seller', 'order.deliveryMan', 'product'],
            dataLimit: 'all',
        );

        return Excel::download(new RefundRequestExport([
            'data-from' => 'admin',
            'refundList' => $refundList,
            'search' => $request['searchValue'],
            'status' => $status,
            'from' => $fromDate,
            'to'   => $toDate,
            'filter_By' => $request->get('type', 'all'),
        ]), RefundRequestExportFile::EXPORT_XLSX);
    }
}
