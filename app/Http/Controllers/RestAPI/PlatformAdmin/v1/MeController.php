<?php

namespace App\Http\Controllers\RestAPI\PlatformAdmin\v1;

use App\Http\Controllers\Controller;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\PlatformAdmin\PlatformAdminPresenter;
use App\Services\PlatformAdmin\PlatformAdminPrincipal;
use App\Services\PlatformAdmin\PlatformAdminResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    #[ApiDoc(audience: ApiDoc::ADMIN, visibility: ApiDoc::INTERNAL, stability: ApiDoc::BETA, since: 'v1', group: 'platform_admin', summary: 'Who is signed in, what they may see and until when', description: 'The admin, the level of the grant, its scope (all or restricted), the markets they may see and the expiry of this token. Read from the server on every call: the app must never decide for itself what an admin may see.', idempotent: true)]
    public function __invoke(Request $request): JsonResponse
    {
        return PlatformAdminResponses::data(PlatformAdminPresenter::me(PlatformAdminPrincipal::fromRequest($request)));
    }
}
