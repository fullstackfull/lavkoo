<?php

namespace App\Http\Middleware;

use Closure;
use App\Utils\Helpers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SellerMiddleware
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
        if (auth('seller')->check() && auth('seller')->user()->status == 'approved') {
            return $next($request);
        }
        auth()->guard('seller')->logout();

        return redirect()->route('vendor.auth.login');
    }
}
