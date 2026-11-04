<?php

namespace App\Http\Middleware;

use App\Utils\Helpers;
use Closure;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\Request;

class ModulePermissionMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param $module
     * @return mixed
     */
    public function handle(Request $request, Closure $next, $module): mixed
    {
        if (Helpers::module_permission_check($module)) {
            return $next($request);
        }

        ToastMagic::error(translate('access_Denied') . '!');
        if (auth('admin')->check()) {
            return redirect()->route('admin.dashboard.index');
        }
        return back();
    }
}
