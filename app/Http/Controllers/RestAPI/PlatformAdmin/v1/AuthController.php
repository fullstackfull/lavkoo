<?php

namespace App\Http\Controllers\RestAPI\PlatformAdmin\v1;

use App\Http\Controllers\Controller;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\PlatformAdmin\LoginAttempt;
use App\Services\PlatformAdmin\PlatformAdminAuthenticator;
use App\Services\PlatformAdmin\PlatformAdminLoginThrottle;
use App\Services\PlatformAdmin\PlatformAdminPresenter;
use App\Services\PlatformAdmin\PlatformAdminPrincipal;
use App\Services\PlatformAdmin\PlatformAdminResponses;
use App\Services\PlatformAdmin\PlatformAdminTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly PlatformAdminLoginThrottle $throttle,
        private readonly PlatformAdminAuthenticator $authenticator,
        private readonly PlatformAdminTokenService $tokens,
    ) {
    }

    #[ApiDoc(
        audience: ApiDoc::ADMIN, visibility: ApiDoc::INTERNAL, stability: ApiDoc::BETA, since: 'v1', group: 'platform_admin',
        summary: 'Sign in as the platform admin',
        description: 'The e-mail and password of an admin who holds a platform admin grant, and a name for the device. Answers with a bearer token, shown once and never stored. Every failure is the same 401, and attempts are limited per address and per e-mail.',
        idempotent: false,
    )]
    public function login(Request $request): JsonResponse
    {
        $attempt = LoginAttempt::fromRequest($request);

        if ($this->throttle->exceeded($attempt)) {
            return PlatformAdminResponses::throttled($this->throttle->secondsUntilAvailable($attempt));
        }
        $this->throttle->hit($attempt);

        $session = $this->authenticator->authenticate($attempt);

        if ($session === null) {
            return PlatformAdminResponses::unauthenticated();
        }
        $this->throttle->forgetPair($attempt);

        return PlatformAdminResponses::data([
            'token' => $session->plainToken,
            'token_type' => 'Bearer',
            'expires_at' => PlatformAdminPresenter::timestamp($session->principal->token->expires_at),
            'me' => PlatformAdminPresenter::me($session->principal),
        ]);
    }

    #[ApiDoc(audience: ApiDoc::ADMIN, visibility: ApiDoc::INTERNAL, stability: ApiDoc::BETA, since: 'v1', group: 'platform_admin', summary: 'Sign out: end the session this token belongs to', description: 'The other sessions of the admin are not touched.', idempotent: false)]
    public function logout(Request $request): JsonResponse
    {
        $this->tokens->revoke(PlatformAdminPrincipal::fromRequest($request)->token);

        return PlatformAdminResponses::data(['revoked' => true]);
    }
}
