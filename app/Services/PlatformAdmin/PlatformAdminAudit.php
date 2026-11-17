<?php

namespace App\Services\PlatformAdmin;

use App\Models\PlatformAdminAuditLog;

/**
 * The one writer of the platform admin audit trail. It never takes a secret: a caller passes what happened and who asked, not what they sent.
 */
final class PlatformAdminAudit
{
    private const USER_AGENT_LIMIT = 255;

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
}
