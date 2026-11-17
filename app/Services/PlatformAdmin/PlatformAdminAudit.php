<?php

namespace App\Services\PlatformAdmin;

use App\Models\PlatformAdminAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one writer of the platform admin audit trail. It never takes a secret: a caller passes what happened and who asked, not what they sent.
 */
final class PlatformAdminAudit
{
    /** Where a controller leaves the market its answer was about (a two-letter code), for the line the middleware writes. */
    public const MARKET_ATTRIBUTE = 'platform_admin_audit_market';

    private const USER_AGENT_LIMIT = 255;

    /** The only parameters of a request that reach the trail. Anything else a request carries is not kept, and arrays are not kept at all. */
    private const KEPT_PARAMETERS = ['id', 'market', 'from', 'to', 'severity', 'page', 'per_page'];

    private const PARAMETER_LENGTH_LIMIT = 64;

    /** @param array<string, mixed> $context */
    public function record(
        string $action,
        ?int $adminId = null,
        ?string $market = null,
        ?int $tokenId = null,
        ?string $ip = null,
        ?string $userAgent = null,
        array $context = [],
    ): PlatformAdminAuditLog {
        return PlatformAdminAuditLog::create([
            'admin_id' => $adminId,
            'action' => $action,
            'market' => $market,
            'token_id' => $tokenId,
            'ip' => $ip,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, self::USER_AGENT_LIMIT),
            'context' => $context === [] ? null : $context,
        ]);
    }

    /** The line for one request of a signed-in admin: what it was, who asked, from where, the market it was about and how it ended. */
    public function recordRequest(PlatformAdminPrincipal $principal, Request $request, Response $response): PlatformAdminAuditLog
    {
        $parameters = $this->keptParametersOf($request);
        $market = $request->attributes->get(self::MARKET_ATTRIBUTE);

        return $this->record(
            action: Str::after((string) $request->route()?->getName(), 'platform_admin.'),
            adminId: $principal->adminId(),
            market: is_string($market) ? $market : null,
            tokenId: (int) $principal->token->getKey(),
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            context: ['status' => $response->getStatusCode()] + ($parameters === [] ? [] : ['params' => $parameters]),
        );
    }

    /** @return array<string, string> */
    private function keptParametersOf(Request $request): array
    {
        $given = array_merge($request->query(), $request->route()?->parameters() ?? []);
        $kept = [];

        foreach (self::KEPT_PARAMETERS as $name) {
            if (is_scalar($given[$name] ?? null)) {
                $kept[$name] = mb_substr((string) $given[$name], 0, self::PARAMETER_LENGTH_LIMIT);
            }
        }

        return $kept;
    }
}
