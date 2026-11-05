<?php

namespace App\Services\Fulfillment\Support;

use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Work whose failure would be reported with its whole message: a database error echoes a bound value, a provider's answer echoes a
 * phone number. What escapes names the exception, where it happened and a scrubbed message, and chains nothing: the shipment itself
 * says what happened to the order.
 */
final class ScrubbedFailure
{
    /** The framework turns these into an answer of their own (a 404, a redirect back with the errors): they are not failures to rewrite. */
    private const FRAMEWORK_ANSWERS = [HttpExceptionInterface::class, HttpResponseException::class, ModelNotFoundException::class, ValidationException::class];

    /**
     * @param list<class-string<Throwable>> $expected exceptions whose messages are ours, and which the caller handles: they pass through.
     *        Exactly these classes, not their subclasses: a library's date parser extends InvalidArgumentException and echoes what it was given.
     */
    public static function run(Closure $work, array $expected = []): mixed
    {
        try {
            return $work();
        } catch (Throwable $exception) {
            if (in_array($exception::class, $expected, true) || self::isAFrameworkAnswer($exception)) {
                throw $exception;
            }

            throw self::of($exception);
        }
    }

    public static function of(Throwable $exception): RuntimeException
    {
        return new RuntimeException($exception::class . ' at ' . basename($exception->getFile()) . ':' . $exception->getLine() . ': ' . PiiRedactor::scrub($exception->getMessage(), 500));
    }

    private static function isAFrameworkAnswer(Throwable $exception): bool
    {
        foreach (self::FRAMEWORK_ANSWERS as $class) {
            if ($exception instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
