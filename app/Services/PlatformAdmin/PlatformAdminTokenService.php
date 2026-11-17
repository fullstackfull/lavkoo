<?php

namespace App\Services\PlatformAdmin;

use App\Models\PlatformAdminToken;

/**
 * The sessions of the platform admin API: issued at sign-in, checked on every request, and revoked here.
 */
final class PlatformAdminTokenService
{
    /** Ends every session of an admin that is not already ended, and returns how many it ended. Rows revoked earlier keep their own time. */
    public function revokeAllFor(int $adminId): int
    {
        return PlatformAdminToken::query()->where('admin_id', $adminId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }
}
