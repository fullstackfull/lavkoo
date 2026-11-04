<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Requests\Vendor\VendorOtherSetupRequest;
use Illuminate\Http\Request;
use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\ReviewRepositoryInterface;
use App\Contracts\Repositories\VendorRepositoryInterface;
use App\Contracts\Repositories\ShopRepositoryInterface;
use App\Http\Requests\Vendor\ShopRequest;
use App\Http\Requests\Vendor\ShopVacationRequest;
use App\Http\Controllers\BaseController;
use App\Services\ShopService;
use App\Services\VendorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class ShopController extends BaseController
{
    public function __construct(
        private readonly VendorRepositoryInterface  $vendorRepo,
        private readonly OrderRepositoryInterface   $orderRepo,
        private readonly ProductRepositoryInterface $productRepo,
        private readonly ReviewRepositoryInterface  $reviewRepo,
        private readonly ShopRepositoryInterface    $shopRepo,
        private readonly ShopService                $shopService,
        private readonly VendorService              $vendorService,
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
        $shop = $this->shopRepo->getFirstWhere(['seller_id' => auth('seller')->id()]);
        $vendor = $this->vendorRepo->getFirstWhere(params: ['id' => auth('seller')->id()]);
        if (!isset($shop)) {
            $this->shopRepo->add($this->shopService->getShopDataForAdd(vendor: $vendor));
            $shop = $this->shopRepo->getFirstWhere(['seller_id' => auth('seller')->id()]);
        }

        $minimumOrderAmountStatus = getWebConfig(name: 'minimum_order_amount_status');
        $minimumOrderAmountByVendor = getWebConfig(name: 'minimum_order_amount_by_seller');
        $freeDeliveryStatus = getWebConfig(name: 'free_delivery_status');
        $freeDeliveryResponsibility = getWebConfig(name: 'free_delivery_responsibility');

        $allProductids = $this->productRepo->getListWithScope(filters: ['added_by' => 'seller', 'seller_id' => auth('seller')->id()], dataLimit: 'all')->pluck('id')->toArray();

        $totalProducts = count($allProductids);

        $filters = [
            'seller_id' => auth('seller')->id(),
            'seller_is' => 'seller',
        ];

        $totalOrders = $this->orderRepo->getListWhere(filters: $filters, dataLimit: 'all')->count();
        $totalReviews = $this->reviewRepo->getListWhereIn(filters: [
            'seller_is' => 'seller',
            'seller_id' => auth('seller')->id(),
        ], whereInFilters: [
            'product_id' => !empty($allProductids) ? $allProductids : [null],
        ], dataLimit: 'all')->count();

        return view('vendor-views.shop.index', [
            'shop' => $shop,
            'vendor' => $vendor,
            'minimumOrderAmountStatus' => $minimumOrderAmountStatus,
            'minimumOrderAmountByVendor' => $minimumOrderAmountByVendor,
            'freeDeliveryStatus' => $freeDeliveryStatus,
            'freeDeliveryResponsibility' => $freeDeliveryResponsibility,
            'totalProducts' => $totalProducts,
            'totalOrders' => $totalOrders,
            'totalReviews' => $totalReviews,
        ]);
    }

    /**
     * @param string|int $id
     * @return View
     */
    public function getUpdateView(string|int $id): View
    {
        $shop = $this->shopRepo->getFirstWhere(['id' => $id]);
        return view('vendor-views.shop.update-view', compact('shop'));
    }

    /**
     * @param ShopRequest $request
     * @param string|int $id
     * @return RedirectResponse
     */
    public function update(ShopRequest $request, string|int $id): RedirectResponse
    {
        $shop = $this->shopRepo->getFirstWhere(['id' => $id]);
        $this->shopRepo->update(id: $id, data: $this->shopService->getShopDataForUpdate(request: $request, shop: $shop));
        updateSetupGuideCacheKey(key: 'shop_setup', panel: 'vendor');
        ToastMagic::info(translate('Shop_updated_successfully'));
        return redirect()->route('vendor.shop.index');
    }

    /**
     * @param ShopVacationRequest $request
     * @return RedirectResponse
     */
    public function updateVacation(ShopVacationRequest $request): RedirectResponse
    {
        $this->shopRepo->update(id: $request['id'], data: $this->shopService->getVacationData(request: $request));
        updateSetupGuideCacheKey(key: 'shop_setup', panel: 'vendor');
        ToastMagic::success(translate('Vacation_mode_updated_successfully'));
        return back();
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function closeShopTemporary(Request $request): RedirectResponse
    {
        $this->shopRepo->update(id: $request['id'], data: ['temporary_close' => !$request->get(key: 'status', default: 0)]);
        Cache::clear();
        updateSetupGuideCacheKey(key: 'shop_setup', panel: 'vendor');
        ToastMagic::success(translate('Status_updated_successfully'));
        return back();
    }

    public function getPaymentInformationView(Request $request): View
    {
        return view('vendor-views.shop.payment-information');
    }

    public function getOtherSetupView(Request $request): View
    {
        $vendor = $this->vendorRepo->getFirstWhere(params: ['id' => auth('seller')->id()]);
        $shop = $this->shopRepo->getFirstWhere(params: ['seller_id' => auth('seller')->id()]);
        return view('vendor-views.shop.other-setup', [
            'shop' => $shop,
            'vendor' => $vendor,
        ]);
    }


    public function updateOtherSettings(VendorOtherSetupRequest $request): RedirectResponse
    {
        if ($request->has('minimum_order_amount')) {
            $this->vendorRepo->update(
                id: auth('seller')->id(),
                data: $this->vendorService->getMinimumOrderAmount(request: $request)
            );
        }
        if ($request->has('stock_limit')) {
            $this->vendorRepo->update(
                id: auth('seller')->id(),
                data: $this->vendorService->getVendorStockLimit(request: $request)
            );
        }
        if ($request->has('free_delivery_over_amount')) {
            $this->vendorRepo->update(
                id: auth('seller')->id(),
                data: $this->vendorService->getFreeDeliveryOverAmountData(request: $request)
            );
        }
        $this->shopRepo->updateWhere(
            params: ['seller_id' => auth('seller')->id()],
            data: $this->vendorService->getUpdateBusinessTIN(request: $request)
        );
        updateSetupGuideCacheKey(key: 'order_setup', panel: 'vendor');
        ToastMagic::success(translate('updated_successfully'));
        return back();
    }
}
