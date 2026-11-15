<?php

namespace App\Http\Controllers\RestAPI\v1;

use App\Http\Controllers\Controller;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Http\JsonResponse;

/**
 * The storefronts an app may offer, so it can send one of them back in the X-Storefront header. Public because the list is
 * the same for every visitor and is needed before login.
 */
class StorefrontController extends Controller
{
    #[ApiDoc(
        summary: 'The storefront countries that are switched on, with their path and currency',
        audience: ApiDoc::CUSTOMER_APP,
        visibility: ApiDoc::PARTNER_VISIBLE,
        stability: ApiDoc::BETA,
        since: 'v1',
    )]
    public function index(): JsonResponse
    {
        $resolver = StorefrontResolver::fromConfig();

        return response()->json([
            'enabled' => $resolver->isEnabled(),
            'default_country' => $resolver->defaultCountry(),
            'storefronts' => $resolver->activeStorefronts(),
        ]);
    }
}
