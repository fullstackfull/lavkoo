<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SellerApiAuthMiddleware;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\Marketplace\SellerPrincipalProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who is signed in to the seller app.
 *
 * The principal is the one the auth middleware resolved from the token, and it is never taken from the request: a request that reaches this without
 * one is a routing mistake, which fails here as a server error and never as an answer about somebody.
 */
class SellerPrincipalController extends Controller
{
    #[ApiDoc(
        summary: 'Who is signed in',
        description: 'The caller and nobody else: owner or staff, the shop, the role, and the permission keys '
            . 'held right now. They are read from the role on every request rather than from the token, so a '
            . 'change takes effect on the next call. It needs no permission, because an app that cannot ask '
            . 'who it is cannot know which of its screens to draw. No market is granted to anybody yet, so it '
            . 'answers an unrestricted scope and no list of markets.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function me(Request $request, SellerPrincipalProfile $profile): JsonResponse
    {
        return response()->json($profile->of(principal: $request->attributes->get(SellerApiAuthMiddleware::PRINCIPAL)), 200);
    }
}
