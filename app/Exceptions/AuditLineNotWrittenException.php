<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a line the trail was REQUIRED to write could not be written.
 *
 * `AuditLogger::record()` swallows a failure on purpose: for a settlement or a payout, a missing note is far better than the money being
 * rolled back because the note about it could not be saved. An action that changes who may do what runs the other way round -- a change
 * nobody can trace is worse than a change that did not happen -- so such an action marks the trail fail-closed
 * (`AuditLogger::whileLinesAreRequired`) and this is what comes out.
 *
 * It extends `RuntimeException` so that a caller already catching the shape `recordOrFail()` throws keeps working.
 *
 * It escapes to the framework rather than being turned into a friendly message in each controller. The caller opened a transaction, so by
 * the time anybody sees this the change has been taken back; a sensitive action that reported success while nothing recorded it would be
 * exactly the lie the mark exists to prevent, and there is nothing for a caller to recover.
 */
class AuditLineNotWrittenException extends RuntimeException
{
}
