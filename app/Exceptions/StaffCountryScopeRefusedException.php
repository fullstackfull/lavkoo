<?php

namespace App\Exceptions;

use RuntimeException;

/** An assignment, revocation or listing of an employee's countries that the operator asked for on the server and that cannot be made. The message says why, in words the operator can act on. */
class StaffCountryScopeRefusedException extends RuntimeException
{
}
