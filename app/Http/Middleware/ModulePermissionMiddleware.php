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
     * @param string ...$modules the permissions of `module:a,b,c`: an employee who holds any one of them passes
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string ...$modules): mixed
    {
        if (collect($modules)->contains(static fn (string $module): bool => Helpers::module_permission_check(mod_name: $module))) {
            return $next($request);
        }

        ToastMagic::error(translate('access_Denied') . '!');
        if (auth('admin')->check()) {
            return redirect()->route('admin.dashboard.index');
        }
        return back();
    }
}
