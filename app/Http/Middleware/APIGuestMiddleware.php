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
        // reduced to "is an Authorization header present?" — the token was never validated. Validate the token for real instead.
        // The customer the token names becomes the request's user. It is never written into the input: input is what the
        // caller sends, so a "user" field there is not an identity and must not be read as one.
        if ($request->header('Authorization') && auth('api')->check()) {
            $tokenCustomer = auth('api')->user();
            $request->setUserResolver(static fn (?string $guard = null) => $guard === null ? $tokenCustomer : auth($guard)->user());
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
