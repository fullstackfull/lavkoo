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
        $admin = $this->adminRepo->getFirstWhere(params: ['id' => $id]);
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
        $admin = $this->adminRepo->getFirstWhere(params: ['id' => $id]);
        $this->adminRepo->update(id: $id, data: $this->adminService->getAdminDataForUpdate(request: $request, admin: $admin));
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
        $this->adminRepo->update(id: $id, data: $this->adminService->getAdminPasswordData(request: $request));
        ToastMagic::success(translate('admin_password_updated_successfully'));
        return redirect()->back();
    }

}
