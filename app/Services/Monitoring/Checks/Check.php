<?php

namespace App\Services\Monitoring\Checks;

/**
 * Something that can be probed on a schedule and answer whether it is working.
 *
 * Checks cover what a request does not touch: the queue nobody dispatched to in the last hour,
 * the certificate that expires in nine days, the backup that stopped running last Tuesday. A
 * check NEVER throws — the runner treats a throw as a failing check, but a well-written check
 * catches its own and explains itself.
 */
interface Check
{
    /** Stable key the history is recorded under: database, redis, queue, ssl, homepage… */
    public function key(): string;

    /** health = a component probe; synthetic = a scripted journey through the app. */
    public function kind(): string;

    /** @return CheckResult|array<int, CheckResult> one result, or one per target */
    public function run(): CheckResult|array;
}
