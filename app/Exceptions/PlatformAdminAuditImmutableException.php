<?php

namespace App\Exceptions;

use LogicException;

/** A change to a line of the platform admin audit trail that was asked for through the application. There is none that is allowed. */
class PlatformAdminAuditImmutableException extends LogicException
{
    public static function forAnyChange(): self
    {
        return new self('The platform admin audit trail is append only: a line is never changed or deleted through the application. Lines leave only through gcc:platform-admin:audit:prune.');
    }
}
