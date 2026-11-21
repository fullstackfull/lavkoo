<?php

namespace Tests\Support\Concurrency;

use RuntimeException;

/**
 * A meeting point the worker processes of one ConcurrentRunner run hold each other at.
 *
 * A run's common start barrier puts every process on the same wall clock and then lets them go. That is
 * enough for a test whose subject is an invariant — "whatever order they land in, each chain's running
 * balance is right" — because the assertion has to hold for every interleaving the scheduler picks.
 *
 * It is NOT enough for a test whose subject is ONE interleaving. Released together and left to run free,
 * two processes meet wherever the scheduler puts them, so "the second writer read before the first
 * committed" happens on some runs and not others, and the assertion that names it passes or fails with the
 * machine. Measured on this repository: the case that raced two deliveries of one order line off the
 * barrier alone was red on 1 run in 10 here and on 6 in 10 on an idle machine.
 *
 * So a process marks a point it has reached and waits for a point another process has reached, and the
 * interleaving becomes an arrangement rather than a hope.
 *
 * ## Why files and not the database
 *
 * The database is what these tests measure. A rendezvous held in it would open a connection, take a read
 * view and possibly a lock inside the very window under test, and a hold that changes the thing it is
 * timing is worth nothing. A mark is a file in a directory the run owns and throws away.
 */
final class Rendezvous
{
    private const POLL_MICROSECONDS = 500;

    /** Long enough for a process still booting the application, short enough to fail inside the runner's own timeout. */
    private const WAIT_SECONDS = 30.0;

    private static ?string $directory = null;

    /** Where this run's marks live. ConcurrentRunner gives every run a directory of its own and passes it to each worker. */
    public static function in(?string $directory): void
    {
        self::$directory = $directory;
    }

    /** Says this process has reached `$point`, releasing anything waiting for it. */
    public static function mark(string $point): void
    {
        file_put_contents(self::path($point), (string) getmypid());
    }

    /**
     * Holds this process until another marks `$point`, and reports how long it was held so a test can show the hold really happened.
     *
     * Throws rather than giving up quietly: a rendezvous that timed out and carried on would put back exactly the coin flip it exists to remove, and the test above it would
     * go green or red by luck while reading as though it had proved something.
     */
    public static function waitFor(string $point, float $seconds = self::WAIT_SECONDS): float
    {
        $began = microtime(true);
        $path = self::path($point);

        while (true) {
            clearstatcache(true, $path);
            if (file_exists($path)) {
                return round(microtime(true) - $began, 6);
            }
            if (microtime(true) - $began > $seconds) {
                throw new RuntimeException("Waited {$seconds}s at \"{$point}\" and no process marked it.");
            }
            usleep(self::POLL_MICROSECONDS);
        }
    }

    private static function path(string $point): string
    {
        if (self::$directory === null) {
            throw new RuntimeException('This process has no rendezvous directory, so it cannot meet another one: only a worker started by ConcurrentRunner is given one.');
        }

        return self::$directory . '/' . preg_replace('/[^A-Za-z0-9._-]/', '-', $point);
    }
}
