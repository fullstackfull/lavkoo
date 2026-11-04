<?php

namespace App\Http\Controllers\Admin\Shipping;

use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use App\Contracts\Repositories\CartShippingRepositoryInterface;
use App\Contracts\Repositories\CategoryRepositoryInterface;
use App\Contracts\Repositories\CategoryShippingCostRepositoryInterface;
use App\Contracts\Repositories\ShippingMethodRepositoryInterface;
use App\Contracts\Repositories\ShippingTypeRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\ShippingMethodRequest;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use App\Services\CategoryShippingCostService;
use App\Services\ShippingMethodService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator;

class ShippingMethodController extends BaseController
{
    /**
     * @param ShippingMethodRepositoryInterface $shippingMethodRepo
     * @param ShippingTypeRepositoryInterface $shippingTypeRepo
     * @param ShippingMethodService $shippingMethodService
     * @param CategoryRepositoryInterface $categoryRepo
     * @param CategoryShippingCostRepositoryInterface $categoryShippingCostRepo
     * @param CartShippingRepositoryInterface $categoryShippingRepo
     * @param CategoryShippingCostService $categoryShippingCostService
     * @param BusinessSettingRepositoryInterface $businessSettingRepo
     */
    public function __construct(
        private readonly ShippingMethodRepositoryInterface       $shippingMethodRepo,
        private readonly ShippingTypeRepositoryInterface         $shippingTypeRepo,
        private readonly ShippingMethodService                   $shippingMethodService,
        private readonly CategoryRepositoryInterface             $categoryRepo,
        private readonly CategoryShippingCostRepositoryInterface $categoryShippingCostRepo,
        private readonly CartShippingRepositoryInterface         $categoryShippingRepo,
        private readonly CategoryShippingCostService             $categoryShippingCostService,
        private readonly BusinessSettingRepositoryInterface      $businessSettingRepo,
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
        $shippingMethods = $this->shippingMethodRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            searchValue: requestString('order_search') ?: null,
            filters: ['creator_type' => 'admin'],
            dataLimit: getWebConfig(name: 'pagination_limit')
        );
        $allCategoryIds = $this->categoryRepo->getListWhere(filters: ['position' => 0], dataLimit: 'all')->pluck('id')->toArray();
        $allCategoryShippingCostArray = $this->categoryShippingCostRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            filters: ['seller_id' => 0],
            dataLimit: 'all',
        )->pluck('category_id')->toArray();
        foreach ($allCategoryIds as $id) {
            if (!in_array($id, $allCategoryShippingCostArray)) {
                $this->categoryShippingCostRepo->add(
                    data: $this->categoryShippingCostService->getAddCategoryWiseShippingCostData(
                        addedBy: 'admin',
                        id: $id
                    )
                );
            }
        }
        $adminShipping = $this->shippingTypeRepo->getFirstWhere(
            params: ['seller_id' => 0]
        );
        $allCategoryShippingCost = $this->categoryShippingCostRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            searchValue: requestString('category_search') ?: null,
            filters: ['seller_id' => 0],
            relations: ['category']
        )->filter(function ($item) use ($request) {
            return $item?->category != null;
        });
        return view('admin-views.shipping-method.index', compact('allCategoryShippingCost', 'shippingMethods', 'adminShipping'));
    }


    /**
     * @param ShippingMethodRequest $request
     * @return RedirectResponse
     */
    public function add(ShippingMethodRequest $request): RedirectResponse
    {
        $this->shippingMethodRepo->add($this->shippingMethodService->addShippingMethodData(request: $request, addedBy: 'admin'));

        updateSetupGuideCacheKey(key: 'shipping_method', panel: 'admin');
        ToastMagic::success(translate('successfully_added'));
        return redirect()->route('admin.business-settings.shipping-method.index');
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function updateStatus(Request $request): JsonResponse
    {
        $this->shippingMethodRepo->update(id: $request['id'], data: ['status' => $request['status']]);
        updateSetupGuideCacheKey(key: 'shipping_method', panel: 'admin');
        return response()->json(['success' => 1, 'message' => translate('Status_updated_successfully!')], status: 200);
    }

    /**
     * @param string|int $id
     * @return View|RedirectResponse
     */
    public function getUpdateView(string|int $id): View|RedirectResponse
    {
        if ($id != 1) {
            $method = $this->shippingMethodRepo->getFirstWhere(params: ['id' => $id]);
            return view('admin-views.shipping-method.update-view', compact('method'));
        }
        return back();
    }

    /**
     * @param ShippingMethodRequest $request
     * @param string|int $id
     * @return RedirectResponse
     * @throws Exception
     */
    public function update(ShippingMethodRequest $request, string|int $id): RedirectResponse
    {
        $this->shippingMethodRepo->update(id: $id, data: $this->shippingMethodService->addShippingMethodData(request: $request, addedBy: 'admin'));
        $this->categoryShippingRepo->updateWhere(params: ['shipping_method_id' => $id], data: ['shipping_cost' => currencyConverter($request['cost'])]);
        updateSetupGuideCacheKey(key: 'shipping_method', panel: 'admin');
        ToastMagic::success(translate('successfully_updated'));
        return redirect()->route('admin.business-settings.shipping-method.index');
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function delete(Request $request): RedirectResponse
    {
        $this->shippingMethodRepo->delete(params: ['id' => $request['id']]);
        return redirect()->back();
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function updateShippingResponsibility(Request $request): RedirectResponse
    {
        $this->businessSettingRepo->updateOrInsert(type: 'shipping_method', value: $request['shipping_method']);

        updateSetupGuideCacheKey(key: 'shipping_method', panel: 'admin');
        ToastMagic::success(translate('successfully_updated'));
        return redirect()->route('admin.business-settings.shipping-method.index');
    }

}
