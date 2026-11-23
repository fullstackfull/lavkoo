<?php

namespace App\Http\Middleware;

use App\Services\AdminService;
use Closure;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\Request;

/**
 * Lets a master account through: the first admin, or an account holding the master role. For the screens the menus of the
 * panel offer to those accounts alone.
 */
class EnsureMasterAdmin
{
    public function __construct(private readonly AdminService $adminService)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        if ($this->adminService->isMasterAdmin(admin: auth('admin')->user())) {
            return $next($request);
        }

        if ($request->ajax()) {
            return response()->json(data: ['status' => 'error', 'message' => translate('access_denied')], status: 403);
        }
        ToastMagic::error(translate('access_Denied') . '!');

        return redirect()->route('admin.dashboard.index');
    }
}
