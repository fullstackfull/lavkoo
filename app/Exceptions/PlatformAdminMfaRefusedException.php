<?php

namespace App\Exceptions;

use RuntimeException;

/** A change to a second factor that the operator asked for on the server and that cannot be made. The message says why, in words the operator can act on. */
class PlatformAdminMfaRefusedException extends RuntimeException
{
}
