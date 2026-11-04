<?php

namespace App\Http\Middleware;

use App\Traits\ActivationClass;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

class ActivationCheckMiddleware
{
    use ActivationClass;

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param null $area
     * @return mixed
     */
    public function handle(Request $request, Closure $next, $area = null): mixed
    {
        /*
         * LOCAL DEVELOPMENT ESCAPE HATCH.
         *
         * The licence activation check is bound to the production domain, so it always fails on a
         * developer machine and makes the admin panel unreachable locally. This skips it, but ONLY
         * when BOTH conditions hold: the app is in the local environment AND an explicit opt-in
         * flag is set. Requiring both means a stray APP_ENV=local on a server cannot silently
         * disable licensing — someone has to deliberately add the flag too. It defaults to off, so
         * production behaviour is unchanged.
         */
        if (app()->environment('local') && env('DISABLE_ACTIVATION_CHECK') === true) {
            return $next($request);
        }

        $response = $this->checkActivationCache(app: $area);
        if (!$response) {
            if (!strpos(url()->current(), '/api')) {
                return Redirect::away(route(base64_decode('c3lzdGVtLmFjdGl2YXRpb24tY2hlY2s=')))->send();
            }

            return response()->json([
                'code' => 503,
                'message' => 'Please check activation for '. str_replace('_', ' ', $area),
            ], 503);
        }
        return $next($request);
    }
}
