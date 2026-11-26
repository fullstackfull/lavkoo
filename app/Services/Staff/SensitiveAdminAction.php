<?php

namespace App\Services\Staff;

use App\Exceptions\AuditLineNotWrittenException;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * An action that changes who may do what, carried out so that it cannot happen untraceably (G-14, third criterion).
 *
 * This writes NO line of its own. The platform already has one way of recording who changed a panel account or a role --
 * `AuditTrailObserver` on the model, and `AuditedBuilder` for the writes that raise no model event -- and a second line per action would
 * be noise in a trail somebody reads to answer a question. What was missing is not the line: it is that the line could silently fail.
 * `AuditLogger::record()` swallows a failure on purpose, because a missing note is better than a rolled-back payout. A permission change
 * runs the other way round.
 *
 * So this does two things and no more:
 *
 * - it opens a TRANSACTION, because Laravel fires `updated` after the row is written and not inside one, so a throw from the trail would
 *   otherwise leave the change standing;
 * - it marks the trail fail-closed for the length of the action (`AuditLogger::whileLinesAreRequired`), so a line that cannot be written
 *   throws `AuditLineNotWrittenException`, the transaction takes the change back, and the caller is told by name.
 *
 * Nothing here catches. An exception from the WRITE keeps its own name and still rolls the transaction back: calling a validation error or
 * a dead connection "the audit line could not be written" would send whoever reads it looking in the wrong place.
 *
 * While `gcc.staff.sensitive_actions.recorded` is off -- which is how it ships -- this is the write and nothing else: no transaction is
 * opened and the trail behaves exactly as it does today, swallowing a failure and recording what it already records.
 */
final class SensitiveAdminAction
{
    public function isRecorded(): bool
    {
        return (bool) config('gcc.staff.sensitive_actions.recorded', false);
    }

    /**
     * @template TResult
     * @param callable(): TResult $write the change itself
     * @return TResult
     *
     * @throws AuditLineNotWrittenException when a line could not be written; the change is taken back first
     */
    public function perform(callable $write): mixed
    {
        if (!$this->isRecorded()) {
            return $write();
        }

        return DB::transaction(static fn (): mixed => AuditLogger::whileLinesAreRequired($write));
    }
}
