<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Services\PrioritySetupService;
use App\Traits\CacheManagerTrait;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class PrioritySetupController extends BaseController
{
    use CacheManagerTrait;

    public function __construct(
        private readonly BusinessSettingRepositoryInterface $businessSettingRepo,
        private readonly PrioritySetupService               $prioritySetupService,

    )
    {
    }

    public function index(?Request $request, ?string $type = null): View|Collection|LengthAwarePaginator|null|callable|RedirectResponse
    {
        $brandPriority = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'brand_list_priority'])['value']);
        $categoryPriority = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'category_list_priority'])['value']);
        $vendorProductListPriority = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'vendor_product_list_priority'])['value']);
        $featureProductPriority = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'featured_product_priority'])['value']);
        $newArrivalProductListPriority = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'new_arrival_product_list_priority'])['value']);
        $categoryWiseProductListPriority = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'category_wise_product_list_priority'])['value']);
        $bestSellingProductListPriority = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'best_selling_product_list_priority'])['value']);
        $topRatedProductListPriority = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'top_rated_product_list_priority'])['value']);
        $searchedProductListPriority = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'searched_product_list_priority'])['value']);
        $topVendorPriority = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'top_vendor_list_priority'])['value']);
        $vendorListPriority = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'vendor_list_priority'])['value']);

        return view('admin-views.business-settings.priority-setup.index', compact('featureProductPriority', 'topVendorPriority', 'brandPriority', 'categoryPriority', 'vendorListPriority', 'searchedProductListPriority', 'vendorProductListPriority', 'bestSellingProductListPriority', 'topRatedProductListPriority', 'categoryWiseProductListPriority', 'newArrivalProductListPriority'));
    }

    public function update(Request $request): RedirectResponse
    {

        $this->businessSettingRepo->updateOrInsert(type: 'brand_list_priority',
            value: $this->prioritySetupService->updateBrandAndCategoryPrioritySetupData(data: $request['brand_list_priority'])
        );

        $this->businessSettingRepo->updateOrInsert(type: 'category_list_priority',
            value: $this->prioritySetupService->updateBrandAndCategoryPrioritySetupData(data: $request['category_list_priority'])
        );

        $this->businessSettingRepo->updateOrInsert(type: 'vendor_list_priority',
            value: $this->prioritySetupService->updateVendorPrioritySetupData(data: $request['vendor_list_priority'])
        );

        $this->businessSettingRepo->updateOrInsert(
            type: 'featured_product_priority',
            value: $this->prioritySetupService->updateFeaturedProductPrioritySetupData(data: $request['featured_product_priority'])
        );

        $this->businessSettingRepo->updateOrInsert(type: 'top_vendor_list_priority',
            value: $this->prioritySetupService->updateTopVendorPrioritySetupData(data: $request['top_vendor_list_priority'])
        );

        $this->businessSettingRepo->updateOrInsert(
            type: 'category_wise_product_list_priority',
            value: $this->prioritySetupService->updateCategoryWiseProductListPrioritySetupData(data: $request['category_wise_product_list_priority'])
        );

        $this->businessSettingRepo->updateOrInsert(
            type: 'top_rated_product_list_priority',
            value: $this->prioritySetupService->updateTopRatedProductListPrioritySetupData(data: $request['top_rated_product_list_priority'])
        );

        $this->businessSettingRepo->updateOrInsert(
            type: 'best_selling_product_list_priority',
            value: $this->prioritySetupService->updateBestSellingProductListPrioritySetupData(data: $request['best_selling_product_list_priority'])
        );

        $this->businessSettingRepo->updateOrInsert(
            type: 'searched_product_list_priority',
            value: $this->prioritySetupService->updateProductListPrioritySetupData(data: $request['searched_product_list_priority'])
        );

        $this->businessSettingRepo->updateOrInsert(
            type: 'vendor_product_list_priority',
            value: $this->prioritySetupService->updateVendorProductListPrioritySetupData(data: $request['vendor_product_list_priority'])
        );

        if ($request['new_arrival_product_list_priority']) {
            $this->businessSettingRepo->updateOrInsert(
                type: 'new_arrival_product_list_priority',
                value: $this->prioritySetupService->updateNewArrivalProductListPrioritySetupData(data: $request['new_arrival_product_list_priority'])
            );
        }

        cacheRemoveByType(type: 'shops');
        cacheRemoveByType(type: 'brands');
        cacheRemoveByType(type: 'categories');

        ToastMagic::success(translate('Priority_setup_updated_successfully'));
        return redirect()->back();
    }

    public function updateByType(Request $request): RedirectResponse
    {
        if ($request['type'] == 'feature_deal_priority') {
            $this->businessSettingRepo->updateOrInsert(type: 'feature_deal_priority',
                value: $this->prioritySetupService->updateFeatureDealPrioritySetupData(request: $request)
            );
        }

        if ($request['type'] == 'flash_deal_priority') {
            $this->businessSettingRepo->updateOrInsert(type: 'flash_deal_priority',
                value: $this->prioritySetupService->updateFlashDealPrioritySetupData(request: $request)
            );
            cacheRemoveByType(type: 'flash_deals');
        }

        ToastMagic::success(translate('Priority_setup_updated_successfully'));
        return redirect()->back();
    }


}
