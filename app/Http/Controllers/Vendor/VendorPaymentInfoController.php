<?php

namespace App\Http\Controllers\Vendor;

use App\Models\VendorWithdrawMethodInfo;
use App\Services\VendorPaymentInformationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\View\View;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vendor\PaymentInfoRequest;
use App\Contracts\Repositories\VendorWithdrawMethodInfoRepositoryInterface;
use App\Contracts\Repositories\WithdrawalMethodRepositoryInterface;
use Devrabiul\ToastMagic\Facades\ToastMagic;

class VendorPaymentInfoController extends Controller
{
    public function __construct(
        private readonly VendorWithdrawMethodInfoRepositoryInterface $vendorWithdrawMethodInfoRepo,
        private readonly WithdrawalMethodRepositoryInterface         $withdrawalMethodRepo,
        private readonly VendorPaymentInformationService             $vendorPaymentInformationService,
    )
    {
    }

    public function index(Request $request): View
    {
        $withdrawalMethods = $this->withdrawalMethodRepo->getListWhere(filters: ['is_active' => 1], dataLimit: 10);
        $vendorWithdrawMethods = $this->vendorWithdrawMethodInfoRepo->getListWhere(
            orderBy: ['created_at' => 'desc'],
            searchValue: requestString('search') ?: null,
            filters: ['user_id' => auth('seller')->id()],
            relations: ['withdraw_method'],
            dataLimit: 10
        )->appends($request->all());
        return view('vendor-views.shop.payment-information', [
            'withdrawalMethods' => $withdrawalMethods,
            'vendorWithdrawMethods' => $vendorWithdrawMethods,
        ]);
    }

    public function add(PaymentInfoRequest $request): JsonResponse
    {
        $fields = $this->withdrawalMethodRepo->getFirstWhere(params: ['id' => $request['withdraw_method_id']]);
        $data = $this->vendorPaymentInformationService->getAddData(request: $request, fields: $fields['method_fields']);
        $this->vendorWithdrawMethodInfoRepo->add(data: $data);
        if ($this->vendorWithdrawMethodInfoRepo->getListWhere(
                filters: ['user_id' => auth('seller')->id()],
                dataLimit: 'all'
            )->count() == 1) {
            $this->vendorWithdrawMethodInfoRepo->updateWhere(params: ['user_id' => auth('seller')->id()], data: ['is_default' => 1, 'is_active' => 1]);
        }
        updateSetupGuideCacheKey(key: 'payment_information', panel: 'vendor');

        return response()->json([
            'status' => true,
            'message' => translate('payment_info_add_successfully')
        ]);
    }

    public function update(PaymentInfoRequest $request): JsonResponse
    {
        // Confirm the payment method belongs to the signed-in seller — an unscoped update let a seller
        // overwrite (redirect the funds of) another vendor's withdraw method.
        $owned = $this->vendorWithdrawMethodInfoRepo->getFirstWhere(params: ['id' => $request['id'], 'user_id' => auth('seller')->id()]);
        if (!$owned) {
            return response()->json(['status' => false, 'message' => translate('payment_method_not_found')], 403);
        }
        $fields = $this->withdrawalMethodRepo->getFirstWhere(params: ['id' => $request['withdraw_method_id']]);
        $data = $this->vendorPaymentInformationService->getAddData(request: $request, fields: $fields['method_fields']);
        $this->vendorWithdrawMethodInfoRepo->updateOrInsert(params: ['id' => $request['id'], 'user_id' => auth('seller')->id()], data: $data);
        updateSetupGuideCacheKey(key: 'payment_information', panel: 'vendor');
        return response()->json([
            'status' => true,
            'message' => translate('payment_info_updated_successfully')
        ]);
    }

    public function getDynamicPaymentInformationView(Request $request): JsonResponse
    {
        $id = $request->get('id');
        $data = [];

        if (!empty($id)) {
            $method = $this->withdrawalMethodRepo->getFirstWhere(params: ['is_active' => 1, 'id' => $id]);
            if ($method) {
                $data = $method['method_fields'];
                return response()->json([
                    'status' => true,
                    'message' => translate('Withdrawal_method_found'),
                    'htmlView' => view('vendor-views.shop.dynamic-payment-information', compact('data'))->render(),
                ], 200);
            }
        }

        return response()->json([
            'status' => false,
            'message' => translate('Withdrawal_method_not_found'),
            'htmlView' => view('vendor-views.shop.dynamic-payment-information', compact('data'))->render(),
        ], 200);
    }

    public function getUpdateView($id): JsonResponse
    {
        // Scope to the signed-in seller — an unscoped lookup disclosed another vendor's bank details.
        $vendorWithdrawMethods = $this->vendorWithdrawMethodInfoRepo->getListWhere(filters: ['id' => $id, 'user_id' => auth('seller')->id()], relations: ['withdraw_method']);
        return response()->json([
            'status' => count($vendorWithdrawMethods) > 0,
            'data' => $vendorWithdrawMethods
        ]);
    }

    public function delete($id): RedirectResponse
    {
        // Scope to the signed-in seller — an unscoped delete removed another vendor's withdraw method.
        $this->vendorWithdrawMethodInfoRepo->delete(params: ['id' => $id, 'user_id' => auth('seller')->id()]);
        ToastMagic::success(translate("Payment_method_has_been_deleted"));
        return redirect()->back();
    }

    public function updateDefault(Request $request): RedirectResponse
    {
        $this->vendorWithdrawMethodInfoRepo->updateWhere(params: ['user_id' => auth('seller')->id()], data: ['is_default' => 0]);
        // Scope by user_id — an unscoped id update let a seller toggle another vendor's method default.
        $this->vendorWithdrawMethodInfoRepo->updateWhere(
            params: ['id' => $request['id'], 'user_id' => auth('seller')->id()],
            data: ['is_default' => 1, 'is_active' => 1]
        );
        ToastMagic::success(translate("Payment_method_set_as_default"));
        return redirect()->back();
    }


    public function updateStatus(Request $request): RedirectResponse
    {
        // Scope by user_id — an unscoped id update let a seller toggle another vendor's method status.
        $this->vendorWithdrawMethodInfoRepo->updateWhere(params: ['id' => $request['id'], 'user_id' => auth('seller')->id()], data: ['is_active' => $request['status'] ?? 0]);
        ToastMagic::success(translate("status_updated_successfully"));
        return redirect()->back();
    }
}
