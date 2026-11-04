<?php

namespace App\Http\Controllers\Admin\Promotion;

use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use App\Contracts\Repositories\StockClearanceSetupRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Services\PrioritySetupService;
use App\Traits\InHouseTrait;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class ClearanceSalePrioritySetupController extends BaseController
{
    use InHouseTrait;

    public function __construct(
        private readonly BusinessSettingRepositoryInterface     $businessSettingRepo,
        private readonly StockClearanceSetupRepositoryInterface $stockClearanceSetupRepo,
        private readonly PrioritySetupService                   $prioritySetupService,
    )
    {
    }

    public function index(?Request $request, ?string $type = null): View|Collection|LengthAwarePaginator|null|callable|RedirectResponse
    {
        $stockClearancePriority = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'stock_clearance_product_list_priority'])?->value ?? '';
        $stockClearanceVendors = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'stock_clearance_vendor_priority'])?->value ?? '';
        $availableVendors = $this->stockClearanceSetupRepo->getListWhere(filters: ['is_active' => 1], relations: ['shop'], dataLimit: 'all')->pluck('shop');

        $availableVendors = $availableVendors->map(function($vendor) {
            if (is_null($vendor)) {
                $vendor = $this->getInHouseShopObject();
            }
            return $vendor;
        });

        return view('admin-views.deal.clearance-sale.priority-setup', [
            'stockClearancePriority' => json_decode($stockClearancePriority, true),
            'stockClearanceVendors' => json_decode($stockClearanceVendors, true) ?? [],
            'availableVendors' => $availableVendors,
        ]);
    }

    public function updateConfig(Request $request): RedirectResponse
    {
        $this->businessSettingRepo->updateOrInsert(
            type: 'stock_clearance_vendor_priority',
            value: json_encode($request['vendor_priorities_id'] ?? []),
        );
        $this->businessSettingRepo->updateOrInsert(
            type: 'stock_clearance_product_list_priority',
            value: json_encode($this->prioritySetupService->updateStockClearanceProductPrioritySetupData(request: $request))
        );
        ToastMagic::success(translate('Priority_setup_updated_successfully'));
        return redirect()->back();
    }


}
