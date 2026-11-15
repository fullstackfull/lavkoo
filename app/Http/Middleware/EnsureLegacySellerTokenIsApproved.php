<?php

namespace App\Http\Middleware;

use App\Services\Marketplace\SellerPermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds an old seller app (API v2) token to the seller's current standing, the way v3 does on every request.
 *
 * v2 controllers resolve the token with Helpers::get_seller_by_token, which matches the token and nothing else, so a seller approved
 * at login and rejected or set back to pending afterwards kept acting. Only a shop owner is accepted because v2 has never served staff
 * or issued keys. The refusal is the 401 body every v2 seller endpoint already answers with.
 */
class EnsureLegacySellerTokenIsApproved
{
    private const MINIMUM_TOKEN_LENGTH = 30;

    public function __construct(private readonly SellerPermissionService $permissions)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = explode(' ', (string) $request->header('authorization'));

        if (count($token) > 1 && strlen($token[1]) > self::MINIMUM_TOKEN_LENGTH && $this->permissions->principalFor(token: $token[1])?->isOwner()) {
            return $next($request);
        }

        return response()->json([
            'auth-001' => translate('Your existing session token does not authorize you any more')
        ], 401);
    }
}
