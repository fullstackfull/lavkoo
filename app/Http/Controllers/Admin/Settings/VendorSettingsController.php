<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use App\Http\Controllers\BaseController;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VendorSettingsController extends BaseController
{

    public function __construct(
        private readonly BusinessSettingRepositoryInterface $businessSettingRepo,
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
        $sales_commission = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'sales_commission']);
        if (!isset($sales_commission)) {
            $this->businessSettingRepo->add(data: ['type' => 'sales_commission', 'value' => 0]);
        }

        $seller_registration = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'seller_registration']);
        if (!isset($seller_registration)) {
            $this->businessSettingRepo->add(data: ['type' => 'seller_registration', 'value' => 1]);
        }
        return view('admin-views.business-settings.seller-settings');
    }

    public function update(Request $request): RedirectResponse
    {
        if ($request['active_auction_for_vendor'] && getWebConfig(name: 'auction_feature_status') != 1) {
            ToastMagic::warning(translate('Please enable the auction feature first to allow vendors to create auctions.'));
            return redirect()->back();
        }

        $this->businessSettingRepo->updateOrInsert(type: 'seller_pos', value: $request['seller_pos'] ?? 0);
        $this->businessSettingRepo->updateOrInsert(type: 'seller_registration', value: $request['seller_registration'] ?? 0);
        $this->businessSettingRepo->updateOrInsert(type: 'minimum_order_amount_by_seller', value: $request['minimum_order_amount_by_seller'] ?? 0);
        $this->businessSettingRepo->updateOrInsert(type: 'vendor_review_reply_status', value: $request['vendor_review_reply_status'] ?? 0);
        $this->businessSettingRepo->updateOrInsert(type: 'vendor_can_edit_order', value: $request['vendor_can_edit_order'] ?? 0);
        $this->businessSettingRepo->updateOrInsert(type: 'vendor_forgot_password_method', value: $request['vendor_forgot_password_method'] ?? 'phone');
        $this->businessSettingRepo->updateOrInsert(type: 'active_auction_for_vendor', value: $request['active_auction_for_vendor'] ?? 0);
        ToastMagic::success(translate('Updated_successfully'));
        return redirect()->back();
    }

}
