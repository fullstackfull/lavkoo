<?php

namespace App\Exceptions;

use RuntimeException;

/** A grant, revocation or session change the operator asked for on the server that cannot be made. The message says why, in words the operator can act on. */
class PlatformAdminGrantRefusedException extends RuntimeException
{
}
