<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if (Auth::guard('admin')->check()) {
            if (Auth::guard('admin')->check() && (Auth::guard('admin')->id() != 1 && Auth::guard('admin')->user()->status != 1)) {
                Auth::guard('admin')->logout();
                return redirect('login/' . getWebConfig(name: 'employee_login_url'));
            }
            return $next($request);
        } else {
            abort(404);
        }
    }
}
