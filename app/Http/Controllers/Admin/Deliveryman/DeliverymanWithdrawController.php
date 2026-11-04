<?php

namespace App\Http\Controllers\Admin\Deliveryman;

use App\Contracts\Repositories\DeliveryManWalletRepositoryInterface;
use App\Contracts\Repositories\WithdrawRequestRepositoryInterface;
use App\Enums\ExportFileNames\Admin\DeliverymanWithdraw as DeliverymanWithdrawExport;
use App\Events\WithdrawStatusUpdateEvent;
use App\Exports\DeliveryManWithdrawRequestExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vendor\DeliveryManWithdrawRequest;
use App\Services\DeliveryManWalletService;
use App\Services\DeliveryManWithdrawService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DeliverymanWithdrawController extends Controller
{

    /**
     * @param WithdrawRequestRepositoryInterface $withdrawRequestRepo
     * @param DeliveryManWalletRepositoryInterface $deliveryManWalletRepo
     */
    public function __construct(
        private readonly WithdrawRequestRepositoryInterface   $withdrawRequestRepo,
        private readonly DeliveryManWalletRepositoryInterface $deliveryManWalletRepo,
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
        $withdrawRequests = $this->withdrawRequestRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            searchValue: requestString('searchValue') ?: null,
            filters: ['admin_id' => 0, 'whereNotNull' => 'delivery_man_id', 'status' => $request['status']],
            relations: ['deliveryMan'],
            dataLimit: getWebConfig('pagination_limit')
        );
        return view('admin-views.delivery-man.withdraw.index', compact('withdrawRequests'));
    }

    public function getFiltered(Request $request): JsonResponse
    {
        $withdrawRequests = $this->withdrawRequestRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            searchValue: requestString('searchValue') ?: null,
            filters: ['admin_id' => 0, 'whereNotNull' => 'delivery_man_id', 'status' => $request['status']],
            relations: ['deliveryMan'],
            dataLimit: getWebConfig('pagination_limit')
        );
        return response()->json([
            'view' => view('admin-views.delivery-man.withdraw._table', compact('withdrawRequests'))->render(),
            'count' => $withdrawRequests->count(),
        ], 200);
    }

    public function getView($withdraw_id): JsonResponse
    {
        $details = $this->withdrawRequestRepo->getFirstWhereNotNull(
            params: ['id' => $withdraw_id],
            filters: ['whereNotNull' => 'delivery_man_id'],
            relations: ['deliveryMan'],
        );
        return response()->json(['view' => view('admin-views.delivery-man.withdraw._details', compact('details'))->render()]);
    }


    public function updateStatus(DeliveryManWithdrawRequest $request, string|int $withdrawId, DeliveryManWithdrawService $deliveryManWithdrawService, DeliveryManWalletService $deliveryManWalletService): JsonResponse
    {
        $withdraw = $this->withdrawRequestRepo->getFirstWhere(params: ['id' => $withdrawId], relations: ['deliveryMan']);
        if (!$withdraw) {
            return response()->json(['error' => translate('Invalid_withdraw')]);
        }
        $wallet = $this->deliveryManWalletRepo->getFirstWhere(params: ['delivery_man_id' => $withdraw['delivery_man_id']]);
        if (!$wallet) {
            $deliverymanWalletData = $deliveryManWalletService->getDeliveryManData(id: $withdraw['delivery_man_id'], deliverymanCharge: 0, cashInHand: 0);
            $this->deliveryManWalletRepo->add(data: $deliverymanWalletData);
            $wallet = $this->deliveryManWalletRepo->getFirstWhere(params: ['delivery_man_id' => $withdraw['delivery_man_id']]);
        }
        $formatData = $deliveryManWithdrawService->getUpdateData(request: $request, wallet: $wallet, withdraw: $withdraw);
        $walletData = $formatData['wallet'];
        $withdrawData = $formatData['withdraw'];
        $this->deliveryManWalletRepo->update(id: $wallet->id, data: $walletData);
        $this->withdrawRequestRepo->update(id: $withdrawId, data: $withdrawData);
        if (!empty($withdraw->deliveryMan?->fcm_token)) {
            WithdrawStatusUpdateEvent::dispatch('withdraw_request_status_message', 'delivery_man', $withdraw->deliveryMan?->app_language ?? getDefaultLanguage(), $request['approved'], $withdraw->deliveryMan?->fcm_token);
        }
        if ($request['approved'] == 1) {
            return response()->json(['message' => translate('Delivery_man_payment_has_been_approved_successfully')]);
        } else {
            return response()->json(['message' => translate('Delivery_man_payment_request_has_been_Denied_successfully')]);
        }
    }

    public function exportList(Request $request): BinaryFileResponse
    {
        $withdrawRequests = $this->withdrawRequestRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            searchValue: requestString('searchValue') ?: null,
            filters: ['admin_id' => 0, 'whereNotNull' => 'delivery_man_id', 'status' => $request['status']],
            relations: ['deliveryMan'],
            dataLimit: 'all'
        );
        return Excel::download(new DeliveryManWithdrawRequestExport([
            'withdraw_request' => $withdrawRequests,
            'filter' => $request['status'],
            'searchValue' => $request['searchValue'],
            'pending_request' => $withdrawRequests->where('approved', 0)->count(),
            'approved_request' => $withdrawRequests->where('approved', 1)->count(),
            'denied_request' => $withdrawRequests->where('approved', 2)->count(),
        ]), DeliverymanWithdrawExport::EXPORT_XLSX
        );
    }
}
