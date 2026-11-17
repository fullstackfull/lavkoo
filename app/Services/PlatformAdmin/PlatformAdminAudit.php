<?php

namespace App\Services\PlatformAdmin;

use App\Exceptions\PlatformAdminAuditChainException;
use App\Models\PlatformAdminAuditLog;
use App\Models\PlatformAdminAuditTail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one writer of the platform admin audit trail. It never takes a secret: a caller passes what happened and who asked, not what they sent.
 *
 * Every line is chained to the one before it. The writer takes the row of the tail for the duration of the write, which is what makes two writers take turns
 * (locking "the last line" does not: a second writer that waited for it would still see the line it waited on as the last), reads from it the hash the new
 * line follows, writes the line, and moves the tail, all in one transaction. Called inside another transaction, it holds the tail until that one ends, so it
 * is the last thing that transaction takes: factor, then grant, then challenge, then tail. A deadlock between writers is retried by the outermost transaction.
 */
final class PlatformAdminAudit
{
    /** Where a controller leaves the market its answer was about (a two-letter code), for the line the middleware writes. */
    public const MARKET_ATTRIBUTE = 'platform_admin_audit_market';

    /** Where a controller leaves what its answer did that the line of the request should say (such as how many sessions it ended), for the line the middleware writes. */
    public const DETAIL_ATTRIBUTE = 'platform_admin_audit_detail';

    /** How often a transaction that ends by taking the tail is tried when it is the victim of a deadlock: whatever it did is rolled back and done again. */
    public const DEADLOCK_ATTEMPTS = 3;

    private const USER_AGENT_LIMIT = 255;

    /** The only parameters of a request that reach the trail. Anything else a request carries is not kept, and arrays are not kept at all. */
    private const KEPT_PARAMETERS = ['id', 'market', 'from', 'to', 'severity', 'page', 'per_page'];

    private const PARAMETER_LENGTH_LIMIT = 64;

    /**
     * @param array<string, mixed> $context
     *
     * @throws PlatformAdminAuditChainException when the tail has no row, because then there is nothing to chain the line to
     */
    public function record(
        string $action,
        ?int $adminId = null,
        ?string $market = null,
        ?int $tokenId = null,
        ?string $ip = null,
        ?string $userAgent = null,
        array $context = [],
    ): PlatformAdminAuditLog {
        $kept = $context === [] ? null : $context;
        $clippedUserAgent = $userAgent === null ? null : PlatformAdminText::clipped(text: $userAgent, limit: self::USER_AGENT_LIMIT);

        return DB::transaction(function () use ($action, $adminId, $market, $tokenId, $ip, $clippedUserAgent, $kept): PlatformAdminAuditLog {
            $tail = PlatformAdminAuditTail::query()->lockForUpdate()->find(PlatformAdminAuditTail::ROW) ?? throw PlatformAdminAuditChainException::tailMissing();
            $previousHash = $tail->last_hash ?? PlatformAdminAuditChain::GENESIS;
            $createdAt = now()->format('Y-m-d H:i:s');

            $line = (new PlatformAdminAuditLog())->forceFill([
                'admin_id' => $adminId,
                'action' => $action,
                'market' => $market,
                'token_id' => $tokenId,
                'ip' => $ip,
                'user_agent' => $clippedUserAgent,
                'context' => $kept,
                'created_at' => $createdAt,
                'prev_hash' => $previousHash,
                'hash' => PlatformAdminAuditChain::hashOf(
                    adminId: $adminId,
                    action: $action,
                    market: $market,
                    tokenId: $tokenId,
                    ip: $ip,
                    userAgent: $clippedUserAgent,
                    context: $kept,
                    createdAt: $createdAt,
                    previousHash: $previousHash,
                ),
            ]);
            $line->save();

            $tail->forceFill(['last_line_id' => $line->getKey(), 'last_hash' => $line->getAttribute('hash')])->save();

            return $line;
        }, attempts: self::DEADLOCK_ATTEMPTS);
    }

    /** The line for one request of a signed-in admin: what it was, who asked, from where, the market it was about and how it ended. */
    public function recordRequest(PlatformAdminPrincipal $principal, Request $request, Response $response): PlatformAdminAuditLog
    {
        $parameters = $this->keptParametersOf(request: $request);
        $market = $request->attributes->get(self::MARKET_ATTRIBUTE);
        $detail = $request->attributes->get(self::DETAIL_ATTRIBUTE);

        return $this->record(
            action: Str::after((string) $request->route()?->getName(), 'platform_admin.'),
            adminId: $principal->adminId(),
            market: is_string($market) ? $market : null,
            tokenId: (int) $principal->token->getKey(),
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            context: ['status' => $response->getStatusCode()] + ($parameters === [] ? [] : ['params' => $parameters]) + (is_array($detail) ? $detail : []),
        );
    }

    /** @return array<string, string> */
    private function keptParametersOf(Request $request): array
    {
        $given = array_merge($request->query(), $request->route()?->parameters() ?? []);
        $kept = [];

        foreach (self::KEPT_PARAMETERS as $name) {
            if (is_scalar($given[$name] ?? null)) {
                $kept[$name] = PlatformAdminText::clipped(text: (string) $given[$name], limit: self::PARAMETER_LENGTH_LIMIT);
            }
        }

        return $kept;
    }
}
