<?php

namespace App\Http\Controllers\RestAPI\PlatformAdmin\v1;

use App\Http\Controllers\Controller;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\PlatformAdmin\PlatformAdminMfaFactors;
use App\Services\PlatformAdmin\PlatformAdminPresenter;
use App\Services\PlatformAdmin\PlatformAdminPrincipal;
use App\Services\PlatformAdmin\PlatformAdminResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __construct(private readonly PlatformAdminMfaFactors $factors)
    {
    }

    #[ApiDoc(audience: ApiDoc::ADMIN, visibility: ApiDoc::INTERNAL, stability: ApiDoc::BETA, since: 'v1', group: 'platform_admin', summary: 'Who is signed in, what they may see and until when', description: 'The admin, the level of the grant, its scope (all or restricted), the markets they may see, the expiry of this token, and whether the admin has a second factor and whether this session passed it. Read from the server on every call: the app must never decide for itself what an admin may see.', idempotent: true)]
    public function __invoke(Request $request): JsonResponse
    {
        $principal = PlatformAdminPrincipal::fromRequest(request: $request);
        $factor = $this->factors->forAdmin(adminId: $principal->adminId());

        return PlatformAdminResponses::data(data: PlatformAdminPresenter::profile(principal: $principal, enrolled: $factor !== null && $factor->isActiveFor(admin: $principal->admin)));
    }
}
