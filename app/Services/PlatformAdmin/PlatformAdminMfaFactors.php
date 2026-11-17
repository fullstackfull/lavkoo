<?php

namespace App\Services\PlatformAdmin;

use App\Models\PlatformAdminMfaFactor;

/** Where the second factor of an admin is looked up, so that a sign-in, a profile and a console command all read it the same way. */
final class PlatformAdminMfaFactors
{
    /**
     * The factor of the admin with this id, in whatever state, or null. An id that no admin has (0) finds none, and costs the read all the same.
     *
     * @param bool $lock hold the row until the end of the transaction, for whoever is about to change it or to use a code of it
     */
    public function forAdmin(int $adminId, bool $lock = false): ?PlatformAdminMfaFactor
    {
        $query = PlatformAdminMfaFactor::query()->where('admin_id', $adminId);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }
}
