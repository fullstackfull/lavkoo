<?php

namespace App\Http\Controllers\Vendor\Marketplace;

use App\Http\Controllers\BaseController;
use App\Services\Platform\PasswordPolicy;
use App\Models\SellerRole;
use App\Models\SellerStaff;
use App\Services\Marketplace\SellerPermissionService;
use App\Services\Marketplace\SellerTeamService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The seller manages their own staff and roles from the panel.
 *
 * Identity is always `auth('seller')` and every row is scoped to that shop, so one shop can never
 * see or touch another's team. The rules themselves live in `SellerTeamService`, which the seller
 * app's API calls too — the same authority granted through two doors has to mean the same thing.
 */
class SellerStaffController extends BaseController
{
    public function __construct(
        private readonly SellerPermissionService $permissions,
        private readonly SellerTeamService $team,
    ) {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $sellerId = auth('seller')->id();

        return view('vendor-views.marketplace.staff', [
            'roles' => $this->permissions->rolesFor($sellerId),
            'staff' => $this->permissions->staffFor($sellerId),
            'catalog' => $this->permissions->catalog(),
        ]);
    }

    public function storeRole(Request $request): RedirectResponse
    {
        // The panel is the owner's own door, so nothing is withheld here.
        $this->team->createRole(auth('seller')->id(), $request->validate([
            'name' => 'required|string|max:120',
            'permissions' => 'nullable|array',
        ]));

        ToastMagic::success(translate('role_created'));

        return back();
    }

    public function updateRole(Request $request, int $id): RedirectResponse
    {
        $this->team->updateRole($this->ownedRole($id), $request->validate([
            'name' => 'required|string|max:120',
            'permissions' => 'nullable|array',
            'status' => 'nullable|in:active,inactive',
        ]));

        ToastMagic::success(translate('role_updated'));

        return back();
    }

    public function destroyRole(int $id): RedirectResponse
    {
        $this->team->deleteRole($this->ownedRole($id));
        ToastMagic::success(translate('role_deleted'));

        return back();
    }

    public function storeStaff(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:191',
            'password' => app(PasswordPolicy::class)->ruleString(),
            'seller_role_id' => 'nullable|integer',
        ]);

        try {
            $this->team->createStaff(auth('seller')->id(), $validated);
        } catch (ValidationException $exception) {
            ToastMagic::error($exception->validator->errors()->first());

            return back();
        }

        ToastMagic::success(translate('staff_member_added'));

        return back();
    }

    public function updateStaff(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'seller_role_id' => 'nullable|integer',
            'status' => 'nullable|in:active,inactive',
            'password' => app(PasswordPolicy::class)->ruleString(required: false),
        ]);

        try {
            $this->team->updateStaff($this->ownedStaff($id), $validated);
        } catch (ValidationException $exception) {
            ToastMagic::error($exception->validator->errors()->first());

            return back();
        }

        ToastMagic::success(translate('staff_member_updated'));

        return back();
    }

    public function destroyStaff(int $id): RedirectResponse
    {
        $this->team->deleteStaff($this->ownedStaff($id));
        ToastMagic::success(translate('staff_member_removed'));

        return back();
    }

    private function ownedRole(int $id): SellerRole
    {
        return SellerRole::where(['id' => $id, 'seller_id' => auth('seller')->id()])->firstOrFail();
    }

    private function ownedStaff(int $id): SellerStaff
    {
        return SellerStaff::where(['id' => $id, 'seller_id' => auth('seller')->id()])->firstOrFail();
    }
}
