<?php

namespace App\Http\Controllers\Vendor;

use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\ReviewRepositoryInterface;
use App\Contracts\Repositories\VendorRepositoryInterface;
use App\Contracts\Repositories\ShopRepositoryInterface;
use App\Enums\ViewPaths\Vendor\Shop;
use App\Http\Requests\Vendor\ShopRequest;
use App\Http\Requests\Vendor\ShopVacationRequest;
use App\Http\Controllers\BaseController;
use App\Services\ShopService;
use App\Services\VendorService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

class PaymentInformationController extends BaseController
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
        return $this->getView(request: $request);
    }

    /**
     * @param Request|null $request
     * @param string|null $type
     * @return View|Collection|LengthAwarePaginator|callable|null
     */
    public function getView(?Request $request, ?string $type = null): View|Collection|LengthAwarePaginator|null|callable
    {
        return view('vendor-views.shop.payment-information');
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function addPaymentInfo(Request $request): RedirectResponse
    {

        updateSetupGuideCacheKey(key: 'payment_information', panel: 'vendor');
        return redirect()->back();
    }

}
