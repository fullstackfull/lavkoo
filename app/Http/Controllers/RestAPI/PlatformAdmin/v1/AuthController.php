<?php

namespace App\Http\Controllers\RestAPI\PlatformAdmin\v1;

use App\Enums\PlatformAdmin\MfaMethod;
use App\Http\Controllers\Controller;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\PlatformAdmin\LoginAttempt;
use App\Services\PlatformAdmin\MfaAttempt;
use App\Services\PlatformAdmin\PlatformAdminAuthenticator;
use App\Services\PlatformAdmin\PlatformAdminLoginThrottle;
use App\Services\PlatformAdmin\PlatformAdminMfaThrottle;
use App\Services\PlatformAdmin\PlatformAdminPresenter;
use App\Services\PlatformAdmin\PlatformAdminPrincipal;
use App\Services\PlatformAdmin\PlatformAdminResponses;
use App\Services\PlatformAdmin\PlatformAdminSecondStep;
use App\Services\PlatformAdmin\PlatformAdminTokenService;
use App\Services\PlatformAdmin\SignInOutcome;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly PlatformAdminLoginThrottle $throttle,
        private readonly PlatformAdminMfaThrottle $mfaThrottle,
        private readonly PlatformAdminAuthenticator $authenticator,
        private readonly PlatformAdminSecondStep $secondStep,
        private readonly PlatformAdminTokenService $tokens,
    ) {
    }

    #[ApiDoc(
        audience: ApiDoc::ADMIN, visibility: ApiDoc::INTERNAL, stability: ApiDoc::BETA, since: 'v1', group: 'platform_admin',
        summary: 'Sign in as the platform admin',
        description: 'The e-mail and password of an admin who holds a platform admin grant, and a name for the device. While the second factor is required (the default) a good password is answered with a challenge for POST /auth/mfa and no token; otherwise with a bearer token, shown once and never stored. Every failure is the same 401, and attempts are limited per address and per e-mail.',
        idempotent: false,
    )]
    public function login(Request $request): JsonResponse
    {
        $attempt = LoginAttempt::fromRequest(request: $request);

        if ($this->throttle->exceeded(attempt: $attempt)) {
            return PlatformAdminResponses::throttled(retryAfterSeconds: $this->throttle->secondsUntilAvailable(attempt: $attempt));
        }
        $this->throttle->hit(attempt: $attempt);

        $outcome = $this->authenticator->authenticate(attempt: $attempt);

        if ($outcome->session !== null) {
            $this->throttle->forgetPair(attempt: $attempt);
        }

        return $this->answerOf(outcome: $outcome);
    }

    #[ApiDoc(
        audience: ApiDoc::ADMIN, visibility: ApiDoc::INTERNAL, stability: ApiDoc::BETA, since: 'v1', group: 'platform_admin',
        summary: 'Complete a sign-in with the second factor',
        description: 'The challenge the sign-in gave, and either the six digits of the authenticator app or a recovery code. Answers with the bearer token, shown once, and how the second factor was passed. Every failure is the same 401; a challenge allows a few tries and is consumed by the last, and an address and an admin are limited.',
        idempotent: false,
    )]
    public function mfa(Request $request): JsonResponse
    {
        $attempt = MfaAttempt::fromRequest(request: $request);

        if ($this->mfaThrottle->exceeded(attempt: $attempt)) {
            return PlatformAdminResponses::throttled(retryAfterSeconds: $this->mfaThrottle->secondsUntilAvailable(attempt: $attempt));
        }
        $this->mfaThrottle->hit(attempt: $attempt);

        return $this->answerOf(outcome: $this->secondStep->verify(attempt: $attempt));
    }

    #[ApiDoc(audience: ApiDoc::ADMIN, visibility: ApiDoc::INTERNAL, stability: ApiDoc::BETA, since: 'v1', group: 'platform_admin', summary: 'Sign out: end the session this token belongs to', description: 'The other sessions of the admin are not touched.', idempotent: false)]
    public function logout(Request $request): JsonResponse
    {
        $this->tokens->revoke(token: PlatformAdminPrincipal::fromRequest(request: $request)->token);

        return PlatformAdminResponses::data(data: ['revoked' => true]);
    }

    /** The answer a step of the sign-in earned: the session, the challenge, the throttle or the one refusal. */
    private function answerOf(SignInOutcome $outcome): JsonResponse
    {
        return match (true) {
            $outcome->session !== null => $this->sessionOpened(outcome: $outcome),
            $outcome->challenge !== null => PlatformAdminResponses::data(data: [
                'mfa_required' => true,
                'challenge' => $outcome->challenge->plain,
                'challenge_expires_at' => PlatformAdminPresenter::timestamp(moment: $outcome->challenge->expiresAt),
                'methods' => array_map(static fn (MfaMethod $method): string => $method->value, MfaMethod::cases()),
            ]),
            $outcome->retryAfterSeconds !== null => PlatformAdminResponses::throttled(retryAfterSeconds: $outcome->retryAfterSeconds),
            default => PlatformAdminResponses::unauthenticated(),
        };
    }

    /** The token and who it is for; and, when the second factor was passed to get it, how and how many recovery codes are left. */
    private function sessionOpened(SignInOutcome $outcome): JsonResponse
    {
        $session = $outcome->session;
        $answer = [
            'token' => $session->plainToken,
            'token_type' => 'Bearer',
            'expires_at' => PlatformAdminPresenter::timestamp(moment: $session->principal->token->expires_at),
            'me' => PlatformAdminPresenter::me(principal: $session->principal),
        ];

        if ($outcome->verdict !== null) {
            $answer['mfa'] = ['state' => 'verified', 'method' => $outcome->verdict->method->value, 'recovery_codes_left' => $outcome->verdict->recoveryCodesLeft];
        }

        return PlatformAdminResponses::data(data: $answer);
    }
}
