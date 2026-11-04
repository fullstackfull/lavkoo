<?php

namespace App\Http\Controllers\Admin\Payment;

use App\Contracts\Repositories\OfflinePaymentMethodRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\OfflinePaymentMethodRequest;
use App\Services\OfflinePaymentMethodService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class OfflinePaymentMethodController extends BaseController
{
    /**
     * @param OfflinePaymentMethodRepositoryInterface $offlinePaymentMethodRepo
     * @param OfflinePaymentMethodService $offlinePaymentMethodService
     */
    public function __construct(
        private readonly OfflinePaymentMethodRepositoryInterface $offlinePaymentMethodRepo,
        private readonly OfflinePaymentMethodService             $offlinePaymentMethodService,
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
        $methods = $this->offlinePaymentMethodRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            searchValue: requestString('searchValue') ?: null,
            filters: ['status' => $request['status']],
            dataLimit: 10,
        );
        return view('admin-views.third-party.offline-payment-method.index', compact('methods'));
    }

    /**
     * @param $request
     * @return View
     */


    /**
     * @return View
     */
    public function getAddView(): View
    {
        return view('admin-views.third-party.offline-payment-method.add-view');
    }

    /**
     * @param OfflinePaymentMethodRequest $request
     * @return JsonResponse
     */
    public function add(OfflinePaymentMethodRequest $request): JsonResponse
    {
        $methodInformation = $this->offlinePaymentMethodService->getMethodInformationData(request: $request);
        if (!is_array($methodInformation) && $methodInformation === false) {
            return response()->json([
                'status' => 0,
                'message' => translate('information_Input_Field_Name_must_be_unique'),
                'redirect_url' => '',
            ]);
        }
        $methodFields = $this->offlinePaymentMethodService->getMethodFieldsData(request: $request);
        $this->offlinePaymentMethodRepo->add($this->offlinePaymentMethodService->getOfflinePaymentMethodData(
            methodName: $request['method_name'],
            methodFields: $methodFields,
            methodInformation: $methodInformation, addOrUpdate: 'add'
        ));

        updateSetupGuideCacheKey(key: 'offline_payment_setup', panel: 'admin');
        return response()->json([
            'status' => 1,
            'message' => translate('offline_payment_method_added_successfully'),
            'redirect_url' => route('admin.third-party.offline-payment-method.index'),
        ]);
    }

    /**
     * @param string|int $id
     * @return View|RedirectResponse
     */
    public function getUpdateView(string|int $id): View|RedirectResponse
    {
        $method = $this->offlinePaymentMethodRepo->getFirstWhere(params: ['id' => $id]);
        if ($method) {
            return view('admin-views.third-party.offline-payment-method.update-view', compact('method'));
        } else {
            ToastMagic::error(translate('offline_payment_method_not_found'));
            return redirect()->route('admin.third-party.offline-payment-method.index');
        }
    }

    /**
     * @param OfflinePaymentMethodRequest $request
     * @param string|int $id
     * @return JsonResponse
     */
    public function update(OfflinePaymentMethodRequest $request, string|int $id): JsonResponse
    {
        $method = $this->offlinePaymentMethodRepo->getFirstWhere(params: ['id' => $id]);
        $methodInformation = $this->offlinePaymentMethodService->getMethodInformationData(request: $request);
        if (!$methodInformation) {
            return response()->json([
                'status' => 0,
                'message' => translate('information_Input_Field_Name_must_be_unique'),
                'redirect_url' => '',
            ]);
        }
        $methodFields = $this->offlinePaymentMethodService->getMethodFieldsData(request: $request);
        $this->offlinePaymentMethodRepo->update(id: $method['id'], data: $this->offlinePaymentMethodService->getOfflinePaymentMethodData(
            methodName: $request['method_name'],
            methodFields: $methodFields,
            methodInformation: $methodInformation, addOrUpdate: 'update'
        ));

        updateSetupGuideCacheKey(key: 'offline_payment_setup', panel: 'admin');
        return response()->json([
            'status' => 1,
            'message' => translate('offline_payment_method_update_successfully'),
            'redirect_url' => route('admin.third-party.offline-payment-method.index'),
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function updateStatus(Request $request): JsonResponse
    {
        $method = $this->offlinePaymentMethodRepo->getFirstWhere(params: ['id' => $request['id']]);
        if ($method) {
            $this->offlinePaymentMethodRepo->update(id: $method['id'], data: ['status' => $method['status'] == 1 ? 0 : 1]);
            updateSetupGuideCacheKey(key: 'offline_payment_setup', panel: 'admin');
            return response()->json([
                'success_status' => 1,
                'message' => translate('status_updated_successfully'),
            ]);
        } else {
            return response()->json([
                'success_status' => 0,
                'message' => translate('status_update_failed'),
            ]);
        }
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function delete(Request $request): RedirectResponse
    {
        $method = $this->offlinePaymentMethodRepo->getFirstWhere(params: ['id' => $request['id']]);
        $this->offlinePaymentMethodRepo->delete(params: ['id' => $method['id']]);
        ToastMagic::success(translate('offline_payment_method_delete_successfully'));
        return redirect()->route('admin.third-party.offline-payment-method.index');
    }
}
