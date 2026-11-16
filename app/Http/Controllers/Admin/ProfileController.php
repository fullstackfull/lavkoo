<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Repositories\AdminRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\AdminPasswordRequest;
use App\Http\Requests\Admin\AdminRequest;
use App\Services\AdminService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/** An admin reads and edits only their own profile: the {id} in these URLs is never trusted, because the routes carry no module permission. */
class ProfileController extends BaseController
{
    public function __construct(
        private readonly AdminRepositoryInterface $adminRepo,
        private readonly AdminService             $adminService,
    )
    {
    }

    public function index(?Request $request, ?string $type = null): View|Collection|LengthAwarePaginator|null|callable|RedirectResponse
    {

    }

    /**
     * @param string|int $id
     * @return View|RedirectResponse
     */
    public function getUpdateView(string|int $id): View|RedirectResponse
    {
        $signedInAdminId = auth('admin')->id();
        if ($signedInAdminId != $id) {
            ToastMagic::error(translate('access_Denied') . '!');
            return redirect()->route('admin.dashboard.index');
        }

        $admin = $this->adminRepo->getFirstWhere(params: ['id' => $signedInAdminId]);
        $shopBanner = getWebConfig('shop_banner');
        return view('admin-views.profile.update-view', compact('admin', 'shopBanner'));
    }

    /**
     * @param AdminRequest $request
     * @param string|int $id
     * @return RedirectResponse
     */
    public function update(AdminRequest $request, string|int $id): RedirectResponse
    {
        $signedInAdminId = auth('admin')->id();
        $admin = $this->adminRepo->getFirstWhere(params: ['id' => $signedInAdminId]);
        $this->adminRepo->update(id: $signedInAdminId, data: $this->adminService->getAdminDataForUpdate(request: $request, admin: $admin));
        ToastMagic::success(translate('profile_updated_successfully'));
        return redirect()->back();
    }

    /**
     * @param AdminPasswordRequest $request
     * @param string|int $id
     * @return RedirectResponse
     */
    public function updatePassword(AdminPasswordRequest $request, string|int $id): RedirectResponse
    {
        $this->adminRepo->update(id: auth('admin')->id(), data: $this->adminService->getAdminPasswordData(request: $request));
        ToastMagic::success(translate('admin_password_updated_successfully'));
        return redirect()->back();
    }

}
