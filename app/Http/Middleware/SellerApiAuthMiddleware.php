<?php

namespace App\Http\Middleware;

use App\Services\Marketplace\SellerApiKeyService;
use App\Services\Marketplace\SellerPermissionService;
use App\Services\Marketplace\SellerPrincipal;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SellerApiAuthMiddleware
{
    /** The seller middleware rejects anything shorter than this, so a malformed header never queries. */
    private const MINIMUM_TOKEN_LENGTH = 30;

    /** Where the resolved principal lives on the request, for anything downstream that needs it. */
    public const PRINCIPAL = 'seller_principal';

    public function __construct(
        private readonly SellerPermissionService $permissions,
        private readonly SellerApiKeyService $apiKeys,
    ) {
    }

    /**
     * Resolve the bearer token into who is acting, and on whose shop.
     *
     * `$request->seller` is still the shop, exactly as before — every controller that scopes on
     * `$request->seller->id` keeps meaning what it meant, whether the caller is the owner or one of
     * their staff. `$request->seller_principal` is the new part: it also names the person, and what
     * that person is allowed to do.
     *
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure(Request): (Response|RedirectResponse) $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $token = explode(' ', (string) $request->header('authorization'));

        if (count($token) > 1 && strlen($token[1]) > self::MINIMUM_TOKEN_LENGTH) {
            // Status is checked here, not only at login. It used to be enforced on the way in and
            // never again, so a vendor rejected or set back to pending after signing in kept full
            // API access — orders, POS, payouts, staff — until someone happened to press suspend.
            // What login refuses to start, this refuses to continue. The same holds for a staff
            // member whose account or role has since been switched off, and for a key issued while
            // the shop was in good standing.
            //
            // A login token is tried first, because that is what almost every request carries. An
            // issued key is a different kind of caller: held to its own scopes rather than to the
            // owner's authority, so a key that leaks costs the seller only what it was issued for.
            $principal = $this->permissions->principalFor($token[1])
                ?? $this->apiKeys->resolve($token[1]);

            if ($principal instanceof SellerPrincipal) {
                $request['seller'] = $principal->seller;
                // On the request's attribute bag, not its input bag: the input bag holds what the
                // caller sent and accepts only scalars and arrays. Attributes are where the
                // framework itself keeps per-request objects, and a caller cannot forge one.
                $request->attributes->set(self::PRINCIPAL, $principal);

                if ($principal->apiKey !== null) {
                    // A key may only reach a route that says what scope it needs.
                    //
                    // Scope enforcement lives on `seller_can`, and most of this API predates it:
                    // roughly a hundred authenticated routes carry `seller_api_auth` alone, which
                    // meant a key issued to read reviews could reset the owner's password, redirect
                    // the payout account and delete the shop. Requiring the declaration here rather
                    // than trusting each route to remember makes the gap fail closed — a route
                    // added tomorrow without a scope refuses keys instead of handing them the shop.
                    if (!$this->routeDeclaresAScope($request)) {
                        return response()->json(['errors' => [
                            ['code' => 'api_key', 'message' => translate('this_endpoint_does_not_accept_an_api_key')],
                        ]], 403);
                    }

                    // "Last used" has to come from real traffic, which is what makes it worth
                    // reading when deciding whether a key is still needed.
                    $this->apiKeys->touch($principal->apiKey, $request->ip());
                }

                return $next($request);
            }
        }

        return response()->json([
            'auth-001' => translate('Your existing session token does not authorize you any more')
        ], 401);
    }

    /**
     * Does the matched route say which permission it needs?
     *
     * Read from the route's own middleware rather than from a list kept somewhere else, so the
     * answer cannot drift from the routing table. `gatherMiddleware()` includes group middleware,
     * which is where most of these declarations live.
     */
    private function routeDeclaresAScope(Request $request): bool
    {
        $route = $request->route();

        if (!$route) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'seller_can:')) {
                return true;
            }
        }

        return false;
    }
}
