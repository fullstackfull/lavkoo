<?php

namespace App\Models\Builders;

use App\Exceptions\PlatformAdminAuditImmutableException;
use Illuminate\Database\Eloquent\Builder;

/**
 * The query of the platform admin audit trail, which can read and add and can do nothing else: every way of changing or removing lines that a query has is
 * refused. (A line can still be inserted; whether it came from the writer is what the chain is for.)
 */
class PlatformAdminAuditLogBuilder extends Builder
{
    public function update(array $values): never
    {
        throw PlatformAdminAuditImmutableException::forAnyChange();
    }

    public function upsert(array $values, $uniqueBy, $update = null): never
    {
        throw PlatformAdminAuditImmutableException::forAnyChange();
    }

    public function increment($column, $amount = 1, array $extra = []): never
    {
        throw PlatformAdminAuditImmutableException::forAnyChange();
    }

    public function decrement($column, $amount = 1, array $extra = []): never
    {
        throw PlatformAdminAuditImmutableException::forAnyChange();
    }

    public function delete(): never
    {
        throw PlatformAdminAuditImmutableException::forAnyChange();
    }

    public function forceDelete(): never
    {
        throw PlatformAdminAuditImmutableException::forAnyChange();
    }

    public function truncate(): never
    {
        throw PlatformAdminAuditImmutableException::forAnyChange();
    }
}
