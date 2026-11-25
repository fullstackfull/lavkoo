<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Repositories\CategoryShippingCostRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Http\Middleware\ScopeShippingToStaffCountries;
use App\Services\Storefront\StorefrontCategoryShippingCosts;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Devrabiul\ToastMagic\Facades\ToastMagic;

class CategoryShippingCostController extends BaseController
{
    public function __construct(
        private readonly CategoryShippingCostRepositoryInterface $categoryShippingCostRepo,
        private readonly StorefrontCategoryShippingCosts $costsOfTheMarket,
    )
    {
        // A per-category cost is a number typed in one market's money, so changing one is an act in that market (GCC-14c slice 6).
        $this->middleware(ScopeShippingToStaffCountries::class);
    }

    /**
     * @param Request|null $request
     * @param string|null $type
     * @return View|RedirectResponse Index function is the starting point of a controller
     * Index function is the starting point of a controller
     */
    public function index(Request|null $request, ?string $type = null): View|RedirectResponse
    {
        return $this->add($request);
    }

    public function add(Request $request): RedirectResponse
    {
        if (isset($request->ids)) {
            foreach ($request->ids as $key => $id) {
                $this->categoryShippingCostRepo->updateOrInsert(
                    params: ['seller_id' => 0, 'category_id' => $request['category_ids'][$key]] + $this->costsOfTheMarket->marketKeyOf(costId: $id),
                    data: [
                        'cost' => currencyConverter(amount: $request['cost'][$key]),
                        'multiply_qty' => isset($request->multiplyQTY) ? (in_array($id, $request->multiplyQTY) ? 1 : 0) : 0,
                        'updated_at' => now()
                    ],
                );
            }
        }

        ToastMagic::success(translate('Category_cost_successfully_updated'));
        return back();
    }
}
