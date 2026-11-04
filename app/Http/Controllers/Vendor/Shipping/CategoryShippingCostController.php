<?php

namespace App\Http\Controllers\Vendor\Shipping;

use App\Contracts\Repositories\CategoryShippingCostRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Services\CategoryShippingCostService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class CategoryShippingCostController extends BaseController
{
    /**
     * @param CategoryShippingCostRepositoryInterface $categoryShippingCostRepo
     * @param CategoryShippingCostService $categoryShippingCostService
     */
    public function __construct(
        private readonly CategoryShippingCostRepositoryInterface $categoryShippingCostRepo,
        private readonly CategoryShippingCostService             $categoryShippingCostService,
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
       return $this->update(request:$request);
    }

    /**
     * @param object $request
     * @return RedirectResponse
     */
    public function update(object $request): RedirectResponse
    {
        if ($request['ids']) {
            foreach ($request['ids'] as $key => $id) {
                $this->categoryShippingCostRepo->update(
                    id: $id,
                    data: $this->categoryShippingCostService->getUpdateCategoryWiseShippingData(
                        key: $key,
                        id: $id,
                        request: $request
                    )
                );
            }
        }
        ToastMagic::success(translate('category_wise_shipping_cost_updated_successfully'));
        return redirect()->back();
    }


}
