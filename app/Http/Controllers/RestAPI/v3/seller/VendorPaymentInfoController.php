<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Contracts\Repositories\VendorWithdrawMethodInfoRepositoryInterface;
use App\Contracts\Repositories\WithdrawalMethodRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v3\PaymentInfoApiRequest;
use App\Services\VendorPaymentInformationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VendorPaymentInfoController extends Controller
{

    public function __construct(
        private readonly VendorWithdrawMethodInfoRepositoryInterface $vendorWithdrawMethodInfoRepo,
        private readonly WithdrawalMethodRepositoryInterface         $withdrawalMethodRepo,
        private readonly VendorPaymentInformationService             $vendorPaymentInformationService,
    )
    {
    }

    public function index(Request $request): JsonResponse
    {
        $limit = $request['limit'] ?? 'all';
        $seller = $request['seller'];
        $withdrawMethods = $this->vendorWithdrawMethodInfoRepo->getListWhere(
            orderBy: ['created_at' => 'desc'],
            searchValue: requestString('search') ?: null,
            filters: ['user_id' => $seller['id']],
            relations: ['withdraw_method'],
            dataLimit: $limit,
            offset: $request['offset'] ?? 1
        );
        return response()->json([
            'data' => $withdrawMethods->values(),
            'total_size' => $limit == 'all' ? $withdrawMethods->count() : $withdrawMethods->total(),
            'limit' => (int)$request['limit'],
            'offset' => (int)$request['offset'],
        ]);
    }

    public function getWithdrawalMethods(Request $request): JsonResponse
    {
        $withdrawalMethods = $this->withdrawalMethodRepo->getListWhere(
            filters: ['is_active' => 1, 'vendor_status' => 1],
            dataLimit: $request['limit'] ?? 'all',
            offset: $request['offset'] ?? 1
        );

        return response()->json([
            'data' => $withdrawalMethods->values(),
            'total_size' => $withdrawalMethods->count(),
            'limit' => (int)$request['limit'],
            'offset' => (int)$request['offset'],
        ]);
    }


    public function add(PaymentInfoApiRequest $request): JsonResponse
    {
        $fields = $this->withdrawalMethodRepo->getFirstWhere(params: ['id' => $request['withdraw_method_id']]);
        $data = $this->vendorPaymentInformationService->getApiAddData(sellerID: $request['seller']?->id, request: $request, fields: $fields['method_fields']);
        $this->vendorWithdrawMethodInfoRepo->add(data: $data);
        return response()->json(['status' => true], 200);
    }

    public function update(PaymentInfoApiRequest $request): JsonResponse
    {
        $fields = $this->withdrawalMethodRepo->getFirstWhere(params: ['id' => $request['withdraw_method_id']]);
        $data = $this->vendorPaymentInformationService->getApiAddData(sellerID: $request['seller']?->id, request: $request, fields: $fields['method_fields']);
         $this->vendorWithdrawMethodInfoRepo->updateOrInsert(params: ['id' => $request['id']], data: $data);
        return response()->json(['status' => true], 200);
    }

    public function updateDefault(Request $request): JsonResponse
    {
        $this->vendorWithdrawMethodInfoRepo->updateWhere(params: ['user_id' => $request['seller']?->id], data: ['is_default' => 0]);
        $this->vendorWithdrawMethodInfoRepo->updateWhere(
            params: ['id' => $request['id']],
            data: ['is_default' => 1, 'is_active' => 1]
        );
        return response()->json(['status' => true], 200);
    }


    public function updateStatus(Request $request): JsonResponse
    {
        $this->vendorWithdrawMethodInfoRepo->updateWhere(params: ['id' => $request['id']], data: ['is_active' => $request['status'] ?? 0]);
        return response()->json(['status' => true], 200);
    }

    public function delete(Request $request): JsonResponse
    {
        $seller = $request['seller'];
        $this->vendorWithdrawMethodInfoRepo->delete(params: ['id' => $request['id'], 'user_id' => $seller['id']]);
        return response()->json(['status' => true], 200);
    }
}
