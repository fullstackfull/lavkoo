<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class APIGuestMiddleware
{
    /**
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): mixed
    {
        // Previously: `if ($request->header('Authorization') && app('auth')->guard('api'))`.
        // app('auth')->guard('api') returns a guard OBJECT and is always truthy, so the condition
        // reduced to "is an Authorization header present?" — the token was never validated, and
        // $request['user'] was silently set to null. Validate the token for real instead.
        if ($request->header('Authorization') && auth('api')->check()) {
            $request->merge(['user' => auth('api')->user()]);
            return $next($request);
        }

        if ($request->guest_id) {
            return $next($request);
        }

        // Previously `response()->json(['Unauthorized', 401])` — the 401 was passed as part of the
        // BODY, not as the status, so a failed authorization returned HTTP 200 and clients that
        // trust status codes read it as success.
        return response()->json(['message' => 'Unauthorized'], 401);
    }
}
