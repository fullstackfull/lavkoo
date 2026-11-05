<?php

namespace App\Services\Fulfillment\Support;

use Closure;
use RuntimeException;
use Throwable;

/**
 * Work whose failure would be reported with its whole message: a database error echoes a bound value, a provider's answer echoes a
 * phone number. What escapes names the exception, where it happened and a scrubbed message, and chains nothing: the shipment itself
 * says what happened to the order.
 */
final class ScrubbedFailure
{
    /**
     * @param list<class-string<Throwable>> $expected exceptions whose messages are ours, and which the caller handles: they pass through
     */
    public static function run(Closure $work, array $expected = []): mixed
    {
        try {
            return $work();
        } catch (Throwable $exception) {
            foreach ($expected as $class) {
                if ($exception instanceof $class) {
                    throw $exception;
                }
            }

            throw self::of($exception);
        }
    }

    public static function of(Throwable $exception): RuntimeException
    {
        return new RuntimeException($exception::class . ' at ' . basename($exception->getFile()) . ':' . $exception->getLine() . ': ' . PiiRedactor::scrub($exception->getMessage(), 500));
    }
}
