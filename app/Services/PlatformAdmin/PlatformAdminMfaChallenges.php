<?php

namespace App\Services\PlatformAdmin;

use App\Models\PlatformAdminMfaChallenge;

/** The sign-ins that have a good password and wait for the second factor. */
final class PlatformAdminMfaChallenges
{
    /** Consumes every challenge of the admin that is not consumed yet, and returns how many it ended. A challenge consumed earlier keeps its own time. */
    public function endAllFor(int $adminId): int
    {
        return PlatformAdminMfaChallenge::query()->where('admin_id', $adminId)->whereNull('consumed_at')->update(['consumed_at' => now()]);
    }
}
