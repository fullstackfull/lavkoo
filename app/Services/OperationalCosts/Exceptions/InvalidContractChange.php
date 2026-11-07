<?php

namespace App\Services\OperationalCosts\Exceptions;

use RuntimeException;

/** A change to a contract that would break its history or its consistency (editing an active contract, overlapping dates, a malformed rule). */
final class InvalidContractChange extends RuntimeException
{
}
