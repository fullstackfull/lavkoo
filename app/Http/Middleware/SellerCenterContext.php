<?php

namespace App\Http\Middleware;

use App\Services\Marketplace\SellerPermissionService;
use App\Services\Marketplace\SellerPrincipal;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the web session's seller principal where everything else already looks for it.
 *
 * The seller app authenticates with a token and the panel with a session, but "who is acting, and
 * on whose shop" is the same question and must have the same answer. Resolving both into the same
 * `SellerPrincipal`, on the same request attribute, means one permission gate
 * (`EnsureSellerPermission`), one audit actor (`AuditLogger`) and one navigation filter serve both
 * clients — rather than a second authorization system that drifts from the first.
 *
 * It never decides access on its own: an unresolvable principal is sent back to sign in, and every
 * permission question is answered by the route's own gate.
 */
class SellerCenterContext
{
    public function __construct(private readonly SellerPermissionService $permissions)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $sellerId = Auth::guard('seller')->id();

        if (!$sellerId) {
            return redirect()->route('vendor.auth.login');
        }

        $principal = $this->principal();

        // An approved shop that stops being approved, or a staff row that was deactivated mid
        // session, loses the panel on its very next request rather than at its next login.
        if (!$principal instanceof SellerPrincipal) {
            session()->forget('seller_staff_id');
            Auth::guard('seller')->logout();

            return redirect()->route('vendor.auth.login');
        }

        $request->attributes->set(SellerApiAuthMiddleware::PRINCIPAL, $principal);

        return $next($request);
    }

    /**
     * Who the web session is acting as, without the redirecting.
     *
     * The classic panel's pages do not run this middleware, but they render the Seller Center's
     * navigation, which has to be filtered by the same permissions as the Seller Center's own rail.
     * Exposing the resolution rather than repeating it is what keeps one answer to "who is acting".
     */
    public function principal(): ?SellerPrincipal
    {
        $sellerId = Auth::guard('seller')->id();

        if (!$sellerId) {
            return null;
        }

        return $this->permissions->principalForSeller(
            sellerId: $sellerId,
            staffId: session('seller_staff_id'),
        );
    }
}
