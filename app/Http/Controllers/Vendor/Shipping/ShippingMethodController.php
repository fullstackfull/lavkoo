<?php

namespace App\Http\Controllers\Vendor\Shipping;

use App\Contracts\Repositories\CartShippingRepositoryInterface;
use App\Contracts\Repositories\CategoryRepositoryInterface;
use App\Contracts\Repositories\CategoryShippingCostRepositoryInterface;
use App\Contracts\Repositories\ShippingMethodRepositoryInterface;
use App\Contracts\Repositories\ShippingTypeRepositoryInterface;
use App\Enums\ViewPaths\Vendor\Dashboard;
use App\Enums\ViewPaths\Vendor\ShippingMethod;
use App\Http\Controllers\BaseController;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use App\Http\Requests\Vendor\ShippingMethodRequest;
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
     * @param ShippingMethodService $shippingMethodService
     * @param CategoryRepositoryInterface $categoryRepo
     * @param CategoryShippingCostRepositoryInterface $categoryShippingCostRepo
     * @param CartShippingRepositoryInterface $categoryShippingRepo
     * @param CategoryShippingCostService $categoryShippingCostService
     * @param ShippingTypeRepositoryInterface $shippingTypeRepo
     */
    public function __construct(
        private readonly ShippingMethodRepositoryInterface       $shippingMethodRepo,
        private readonly ShippingMethodService                   $shippingMethodService,
        private readonly CategoryRepositoryInterface             $categoryRepo,
        private readonly CategoryShippingCostRepositoryInterface $categoryShippingCostRepo,
        private readonly CartShippingRepositoryInterface         $categoryShippingRepo,
        private readonly CategoryShippingCostService             $categoryShippingCostService,
        private readonly ShippingTypeRepositoryInterface         $shippingTypeRepo,
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
        $shippingMethod = getWebConfig(name: 'shipping_method');
        $vendorId = auth('seller')->id();
        $allCategoryIds = $this->categoryRepo->getListWhere(filters: ['position' => 0])->pluck('id')->toArray();
        $allCategoryShippingCostArray = $this->categoryShippingCostRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            filters: ['seller_id' => $vendorId],
        )->pluck('category_id')->toArray();
        foreach ($allCategoryIds as $id) {
            if (!in_array($id, $allCategoryShippingCostArray)) {
                $this->categoryShippingCostRepo->add(
                    data: $this->categoryShippingCostService->getAddCategoryWiseShippingCostData(
                        addedBy: 'seller',
                        id: $id
                    )
                );
            }
        }
        $allCategoryShippingCost = $this->categoryShippingCostRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            searchValue: requestString('searchValue') ?: null,
            filters: ['seller_id' => $vendorId],
            relations: ['category']
        );
        $sellerShipping = $this->shippingTypeRepo->getFirstWhere(
            params: ['seller_id' => $vendorId]
        );
        $shippingType = isset($sellerShipping) ? $sellerShipping['shipping_type'] : 'order_wise';
        $shippingMethods = $this->shippingMethodRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            filters: ['creator_id' => $vendorId, 'creator_type' => 'seller'],
            dataLimit: getWebConfig(name: 'pagination_limit')
        );
        return view(ShippingMethod::INDEX[VIEW], compact('shippingMethods', 'allCategoryShippingCost', 'shippingType'));
    }
    /**
     * @param ShippingMethodRequest $request
     * @return RedirectResponse
     */
    public function add(ShippingMethodRequest $request): RedirectResponse
    {
        $this->shippingMethodRepo->add($this->shippingMethodService->addShippingMethodData(request: $request, addedBy: 'seller'));
        ToastMagic::success(translate('Order_wise_shipping_method_added_successfully'));
        return redirect()->back();
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function updateStatus(Request $request): JsonResponse
    {
        if (!$this->ownedByCurrentSeller($request['id'])) {
            return response()->json(['message' => translate('access_denied'), 'success' => 0], 403);
        }
        $this->shippingMethodRepo->update(id: $request['id'], data: ['status' => $request['status']]);
        return response()->json([
            'message' => translate('Shipping_method_status_updated_successfully'),
            'success' => 1,
        ], status: 200);
    }

    /**
     * @param string|int $id
     * @return View|RedirectResponse
     */
    public function getUpdateView(string|int $id): View|RedirectResponse
    {
        $shippingMethod = $this->shippingMethodRepo->getFirstWhere(params: ['id' => $id, 'creator_id' => auth('seller')->id(), 'creator_type' => 'seller']);
        if (!$shippingMethod) {
            ToastMagic::error(translate('access_denied'));
            return redirect()->route(ShippingMethod::INDEX[ROUTE]);
        }
        return view(ShippingMethod::UPDATE[VIEW], compact('shippingMethod'));
    }

    /**
     * @param ShippingMethodRequest $request
     * @param string|int $id
     * @return RedirectResponse
     */
    public function update(ShippingMethodRequest $request, string|int $id): RedirectResponse
    {
        if (!$this->ownedByCurrentSeller($id)) {
            ToastMagic::error(translate('access_denied'));
            return redirect()->route(ShippingMethod::INDEX[ROUTE]);
        }
        $this->shippingMethodRepo->update(id: $id, data: $this->shippingMethodService->addShippingMethodData(request: $request, addedBy: 'seller'));
        $this->categoryShippingRepo->updateWhere(params: ['shipping_method_id' => $id], data: ['shipping_cost' => currencyConverter($request['cost'])]);
        ToastMagic::success(translate('Shipping_method_status_updated_successfully'));
        return redirect()->route(ShippingMethod::INDEX[ROUTE]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function delete(Request $request): JsonResponse
    {
        // Scope the delete to the seller's own methods so one vendor cannot delete another's.
        $this->shippingMethodRepo->delete(params: ['id' => $request['id'], 'creator_id' => auth('seller')->id(), 'creator_type' => 'seller']);
        return response()->json([
            'status' => 1,
            'message' => translate('Shipping_method_deleted_successfully')
        ]);
    }

    /** True when the shipping method with $id belongs to the authenticated seller. */
    private function ownedByCurrentSeller(string|int $id): bool
    {
        return (bool) $this->shippingMethodRepo->getFirstWhere(
            params: ['id' => $id, 'creator_id' => auth('seller')->id(), 'creator_type' => 'seller']
        );
    }
}
