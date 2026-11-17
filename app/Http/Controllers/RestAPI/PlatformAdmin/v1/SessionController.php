<?php

namespace App\Http\Controllers\RestAPI\PlatformAdmin\v1;

use App\Exceptions\PlatformAdminApiException;
use App\Http\Controllers\Controller;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\PlatformAdmin\PlatformAdminPresenter;
use App\Services\PlatformAdmin\PlatformAdminPrincipal;
use App\Services\PlatformAdmin\PlatformAdminResponses;
use App\Services\PlatformAdmin\PlatformAdminTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function __construct(private readonly PlatformAdminTokenService $tokens)
    {
    }

    #[ApiDoc(audience: ApiDoc::ADMIN, visibility: ApiDoc::INTERNAL, stability: ApiDoc::BETA, since: 'v1', group: 'platform_admin', summary: "The signed-in admin's open sessions", description: 'Newest first, with the device, when each was opened and last used and when it expires, and which one is making this call. A token or its hash is never part of it.', idempotent: true)]
    public function index(Request $request): JsonResponse
    {
        $principal = PlatformAdminPrincipal::fromRequest(request: $request);
        $currentTokenId = (int) $principal->token->getKey();

        return PlatformAdminResponses::data(
            data: $this->tokens->openSessionsOf(adminId: $principal->adminId())->map(fn ($token): array => PlatformAdminPresenter::session(token: $token, currentTokenId: $currentTokenId))->all(),
        );
    }

    #[ApiDoc(audience: ApiDoc::ADMIN, visibility: ApiDoc::INTERNAL, stability: ApiDoc::BETA, since: 'v1', group: 'platform_admin', summary: 'End one of your own open sessions', description: "A lost phone: its token stops working on the next request. Another admin's session, one that has already ended and one that does not exist all answer 404.", idempotent: false)]
    public function destroy(Request $request, string $id): JsonResponse
    {
        $session = $this->tokens->openSessionOf(adminId: PlatformAdminPrincipal::fromRequest(request: $request)->adminId(), tokenId: (int) $id)
            ?? throw PlatformAdminApiException::sessionNotFound();

        $this->tokens->revoke(token: $session);

        return PlatformAdminResponses::data(data: ['id' => (int) $session->getKey(), 'revoked' => true]);
    }
}
