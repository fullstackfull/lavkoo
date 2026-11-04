<?php

namespace App\Http\Middleware;

use App\Services\Marketplace\SellerPrincipal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a seller route on a permission.
 *
 * Enforcement lives here and in policies, never in whether a menu item is drawn. A staff member who
 * knows the URL, or writes their own client, gets the same answer as one who cannot see the button.
 *
 * Usage: `->middleware('seller_can:orders.manage')`. Several permissions mean any one of them will
 * do, which is how a read-or-write pair is expressed on a route both can reach.
 *
 * The same gate serves the seller app and the web panel, because both carry the same principal.
 * Only the shape of the refusal differs: a client that asked for JSON gets JSON, and a browser gets
 * the page frame with an explanation naming the permission — never a silent redirect to home
 * (handoff 11 §7 level 2).
 */
class EnsureSellerPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $principal = $request->attributes->get(SellerApiAuthMiddleware::PRINCIPAL);

        // No principal means the auth middleware did not run, which is a wiring mistake rather than
        // a permission problem — and one that must fail closed.
        if (!$principal instanceof SellerPrincipal) {
            if ($request->expectsJson()) {
                return response()->json(['errors' => [
                    ['code' => 'auth-001', 'message' => translate('Your existing session token does not authorize you any more')],
                ]], 401);
            }

            return redirect()->route('vendor.auth.login');
        }

        foreach ($permissions as $permission) {
            if ($principal->can($permission)) {
                return $next($request);
            }
        }

        // 403, not 404: the seller's own client should be able to tell "your role does not allow
        // this" from "there is nothing here", and this reveals nothing about another shop.
        if ($request->expectsJson()) {
            return response()->json(['errors' => [
                ['code' => 'permission', 'message' => translate('your_role_does_not_allow_this')],
            ]], 403);
        }

        return response()->view('seller-views.permission-denied', [
            'permissions' => $permissions,
        ], 403);
    }
}
