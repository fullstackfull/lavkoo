<?php

namespace App\Http\Middleware;

use App\Exceptions\PlatformAdminDormantException;
use App\Services\PlatformAdmin\PlatformAdminAudit;
use App\Services\PlatformAdmin\PlatformAdminPrincipal;
use App\Services\PlatformAdmin\PlatformAdminResponses;
use App\Services\PlatformAdmin\PlatformAdminSettings;
use App\Services\PlatformAdmin\PlatformAdminTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns the bearer token of the platform admin API into who is calling, or into the one refusal every failure gets.
 *
 * Only a token of this API counts. A seller token, a seller staff token, a seller API key, a customer's Passport token, the cookie of an
 * admin who is signed in to the panel: none of them is looked at, and none is sent here by the framework's guards. A token in the query
 * string, the body or a cookie is not a credential either.
 *
 * The principal is put on the request's attribute bag and every request it makes is written to the audit trail, with how it ended.
 */
class AuthenticatePlatformAdmin
{
    private const BEARER_PREFIX = 'bearer ';

    public function __construct(
        private readonly PlatformAdminSettings $settings,
        private readonly PlatformAdminTokenService $tokens,
        private readonly PlatformAdminAudit $audit,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->settings->enabled()) {
            throw new PlatformAdminDormantException(path: $request->path());
        }

        $principal = $this->tokens->principalFor(candidate: $this->bearerTokenOf(request: $request), ip: $request->ip(), userAgent: $request->userAgent());

        if ($principal === null) {
            return PlatformAdminResponses::unauthenticated();
        }

        $request->attributes->set(PlatformAdminPrincipal::REQUEST_ATTRIBUTE, $principal);
        $this->tokens->recordUse(token: $principal->token);

        $response = $next($request);
        $this->audit->recordRequest(principal: $principal, request: $request, response: $response);

        return $response;
    }

    /** What follows "Bearer " in the Authorization header, or an empty string. The token's shape is judged by the token service. */
    private function bearerTokenOf(Request $request): string
    {
        $header = (string) $request->headers->get('Authorization', '');

        return str_starts_with(strtolower($header), self::BEARER_PREFIX) ? substr($header, strlen(self::BEARER_PREFIX)) : '';
    }
}
