<?php

namespace App\Exceptions;

use RuntimeException;

/** The chain of the platform admin audit trail cannot be extended or cut as things stand. The message says why, in words the operator can act on. */
class PlatformAdminAuditChainException extends RuntimeException
{
    public static function tailMissing(): self
    {
        return new self('The tail of the audit trail (platform_admin_audit_tail) has no row, so no line can be written after the chain. Run gcc:platform-admin:audit:verify to see what else is wrong, and restore the row from a backup of the table; the migration that makes it starts it from the newest hashed line if the table is made again.');
    }

    public static function trailChanged(): self
    {
        return new self('The audit trail changed while the prune was being made ready, so nothing was removed. Run it again.');
    }
}
