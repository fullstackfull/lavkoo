<?php

namespace App\Http\Controllers\RestAPI\PlatformAdmin\v1;

use App\Http\Controllers\Controller;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\PlatformAdmin\PlatformAdminPrincipal;
use App\Services\PlatformAdmin\PlatformAdminResponses;
use App\Services\PlatformAdmin\PlatformAuditLogReader;
use App\Services\PlatformAdmin\PlatformPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    public function __construct(private readonly PlatformAuditLogReader $reader)
    {
    }

    #[ApiDoc(
        audience: ApiDoc::ADMIN,
        visibility: ApiDoc::INTERNAL,
        stability: ApiDoc::BETA,
        since: 'v1',
        group: 'platform_admin',
        summary: 'What the platform admin API did and who asked',
        description: 'The platform owner reads every line, a restricted admin only their own. Newest first, at most 100 to a page, and no secret is ever in a line. Reading it is audited like every other request.',
        idempotent: true,
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $principal = PlatformAdminPrincipal::fromRequest(request: $request);
        $pagination = PlatformPagination::fromRequest(request: $request, defaultPerPage: self::DEFAULT_PER_PAGE, maxPerPage: self::MAX_PER_PAGE);
        $page = $this->reader->page(principal: $principal, pagination: $pagination);

        return PlatformAdminResponses::data(data: $page['lines'], meta: [
            'scope' => $principal->scope(),
            'page' => $pagination->page,
            'per_page' => $pagination->perPage,
            'has_more' => $page['has_more'],
        ]);
    }
}
