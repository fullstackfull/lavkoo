<?php

namespace App\Http\Controllers\RestAPI\PlatformAdmin\v1;

use App\Http\Controllers\Controller;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\PlatformAdmin\PlatformAdminPresenter;
use App\Services\PlatformAdmin\PlatformAdminPrincipal;
use App\Services\PlatformAdmin\PlatformAdminResponses;
use App\Services\PlatformAdmin\PlatformMarketDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketController extends Controller
{
    public function __construct(private readonly PlatformMarketDirectory $directory)
    {
    }

    #[ApiDoc(
        audience: ApiDoc::ADMIN,
        visibility: ApiDoc::INTERNAL,
        stability: ApiDoc::BETA,
        since: 'v1',
        group: 'platform_admin',
        summary: 'The markets this admin may see',
        description: 'Each market of the configuration the admin is allowed: its code, path and currency, whether it is active, whether storefronts are switched on, whether its legal documents are approved (the answer of the storefront gate itself) and the legal entity. A restricted admin sees only their own markets.',
        idempotent: true,
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $principal = PlatformAdminPrincipal::fromRequest($request);

        return PlatformAdminResponses::data([
            'scope' => $principal->scope(),
            'generated_at' => PlatformAdminPresenter::timestamp(now()),
            'markets' => $this->directory->listFor($principal),
        ]);
    }
}
