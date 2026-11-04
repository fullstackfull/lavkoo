<?php

namespace App\Http\Controllers\Vendor;

use App\Contracts\Repositories\ShopRepositoryInterface;
use App\Enums\ViewPaths\Vendor\Profile;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Vendor\VendorBankInfoRequest;
use App\Http\Requests\Vendor\VendorPasswordRequest;
use App\Http\Requests\Vendor\VendorRequest;
use App\Repositories\VendorRepository;
use App\Services\VendorService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

class ProfileController extends BaseController
{
    /**
     * @param VendorRepository $vendorRepo
     * @param VendorService $vendorService
     * @param ShopRepositoryInterface $shopRepo
     */
    public function __construct(
        private readonly VendorRepository        $vendorRepo,
        private readonly VendorService           $vendorService,
        private readonly ShopRepositoryInterface $shopRepo,
    )
    {
    }

    /**
     * @param Request|null $request
     * @param string|null $type
     * @return \Illuminate\Contracts\View\View|Collection|LengthAwarePaginator|callable|RedirectResponse|null
     */
    public function index(?Request $request, ?string $type = null): \Illuminate\Contracts\View\View|Collection|LengthAwarePaginator|null|callable|RedirectResponse
    {
        return $this->getListView();
    }

    /**
     * @return View
     */
    public function getListView(): View
    {
        $vendor = $this->vendorRepo->getFirstWhere(['id' => auth('seller')->id()]);
        return view('vendor-views.profile.index', compact('vendor'));
    }

    /**
     * @param string|int $id
     * @return View|RedirectResponse
     */
    public function getUpdateView(string|int $id): View|RedirectResponse
    {
        if (auth('seller')->id() != $id) {
            ToastMagic::warning(translate('you_can_not_change_others_profile'));
            return redirect()->back();
        }
        $vendor = $this->vendorRepo->getFirstWhere(['id' => auth('seller')->id()]);
        $shopBanner = $this->shopRepo->getFirstWhere(['seller_id' => auth('seller')->id()])->banner;
        return view('vendor-views.profile.update-view', compact('vendor', 'shopBanner'));
    }

    /**
     * @param VendorRequest $request
     * @param string|int $id
     * @return JsonResponse
     */
    public function update(VendorRequest $request, string|int $id): JsonResponse
    {
        // Always act on the authenticated seller — never the route {id} — so one seller cannot overwrite
        // another seller's profile (IDOR). The read views already enforce this; the write paths did not.
        $sellerId = auth('seller')->id();
        $vendor = $this->vendorRepo->getFirstWhere(['id' => $sellerId]);
        $this->vendorRepo->update(id: $sellerId, data: $this->vendorService->getVendorDataForUpdate(request: $request, vendor: $vendor));
        return response()->json(['message' => translate('profile_updated_successfully')]);
    }

    /**
     * @param VendorPasswordRequest $request
     * @param string|int $id
     * @return JsonResponse
     */
    public function updatePassword(VendorPasswordRequest $request, string|int $id): JsonResponse
    {
        // Reset only the authenticated seller's own password (route {id} is ignored) — otherwise any
        // seller could take over another seller's account.
        $this->vendorRepo->update(id: auth('seller')->id(), data: $this->vendorService->getVendorPasswordData(request: $request));
        return response()->json(['message' => translate('password_updated_successfully')]);
    }

    /**
     * @param string|int $id
     * @return View|RedirectResponse
     */
    public function getBankInfoUpdateView(string|int $id): View|RedirectResponse
    {
        $vendorId = auth('seller')->id();
        if ($vendorId != $id) {
            ToastMagic::warning(translate('you_can_not_change_others_info'));
            return redirect()->back();
        }
        $vendor = $this->vendorRepo->getFirstWhere(['id' => $vendorId]);
        return view('vendor-views.profile.bank-info-update-view', compact('vendor'));
    }

    /**
     * @param VendorBankInfoRequest $request
     * @param string|int $id
     * @return RedirectResponse
     */
    public function updateBankInfo(VendorBankInfoRequest $request, string|int $id): RedirectResponse
    {
        // Only the authenticated seller's own bank/payout details — never the route {id} — so a seller
        // cannot redirect another seller's payouts to their account.
        $vendor = $this->vendorRepo->getFirstWhere(['id' => auth('seller')->id()]);

        $bankData = $this->vendorService->getVendorBankInfoData(request: $request);
        $previous = [
            'bank_name' => $vendor['bank_name'] ?? null, 'branch' => $vendor['branch'] ?? null,
            'holder_name' => $vendor['holder_name'] ?? null, 'account_no' => $vendor['account_no'] ?? null,
        ];

        $this->vendorRepo->update(id: $vendor['id'], data: $bankData);

        // Arm the payout cooling window on a real change so a hijacked account cannot immediately
        // redirect earnings — the delay lets the real seller notice a payout they did not make.
        if ($previous !== $bankData) {
            app(\App\Services\Marketplace\PayoutService::class)->recordBankChange(
                sellerId: $vendor['id'],
                previous: $previous,
                current: $bankData,
                changedBy: auth('seller')->id(),
                changedByType: 'seller',
                ip: $request->ip(),
            );
        }

        ToastMagic::success(translate('Bank_info_updated_successfully') . '!!');
        return redirect()->route('vendor.profile.index');
    }


}
