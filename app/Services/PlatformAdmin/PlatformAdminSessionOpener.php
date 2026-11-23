<?php

namespace App\Services\PlatformAdmin;

use App\Enums\PlatformAdmin\MfaMethod;
use App\Models\Admin;
use App\Models\PlatformAdminGrant;

/** Opening a session and writing down that it was opened: what a sign-in that has passed every check does, whether it took one step or two. */
final class PlatformAdminSessionOpener
{
    public function __construct(private readonly PlatformAdminTokenService $tokens, private readonly PlatformAdminAudit $audit)
    {
    }

    public function open(Admin $admin, PlatformAdminGrant $grant, string $deviceName, ?string $ip, ?string $userAgent, ?MfaMethod $mfaMethod = null): PlatformAdminSession
    {
        $session = $this->tokens->issue(admin: $admin, grant: $grant, deviceName: $deviceName, ip: $ip, userAgent: $userAgent, mfaMethod: $mfaMethod);

        $this->audit->record(
            action: 'auth.login',
            adminId: $session->principal->adminId(),
            tokenId: (int) $session->principal->token->getKey(),
            ip: $ip,
            userAgent: $userAgent,
            context: ['device_name' => $deviceName] + ($mfaMethod === null ? [] : ['mfa' => $mfaMethod->value]),
        );

        return $session;
    }
}
