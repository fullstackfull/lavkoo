<?php

namespace App\Http\Middleware;

use App\Services\Marketplace\SellerPrincipal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only the account holder, in person.
 *
 * A short list of actions take the shop rather than operate it: changing the owner's password,
 * deleting the account, redirecting where the money is paid. There is no permission that should
 * grant those, because a role that could would be a role that can take the shop — and an owner
 * handing out "manage settings" is not consenting to that.
 *
 * So this is deliberately not a permission. Staff are refused however generous their role, and an
 * issued key is refused outright: a credential without a person behind it must never be able to
 * change who the person is.
 */
class EnsureSellerIsOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $principal = $request->attributes->get(SellerApiAuthMiddleware::PRINCIPAL);

        if (!$principal instanceof SellerPrincipal) {
            return response()->json(['errors' => [
                ['code' => 'auth-001', 'message' => translate('Your existing session token does not authorize you any more')],
            ]], 401);
        }

        if (!$principal->isOwner()) {
            return response()->json(['errors' => [
                ['code' => 'owner_only', 'message' => translate('only_the_account_holder_can_do_this')],
            ]], 403);
        }

        return $next($request);
    }
}
