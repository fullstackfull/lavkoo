<?php

namespace App\Jobs\Fulfillment;

use App\Services\Fulfillment\Support\PiiRedactor;
use Closure;
use RuntimeException;
use Throwable;

/**
 * A job that throws is reported by the queue worker (to laravel.log, with the whole message) and kept in `failed_jobs`. What a
 * provider answered, or a database error that echoes a bound value, may carry a phone number or an e-mail address. So a failing
 * job lets nothing out but where it failed and a scrubbed message: the shipment itself says what happened to the order.
 */
trait KeepsPersonalDataOutOfFailures
{
    private function withoutPersonalDataInFailures(Closure $work): void
    {
        try {
            $work();
        } catch (Throwable $exception) {
            throw new RuntimeException($exception::class . ' at ' . basename($exception->getFile()) . ':' . $exception->getLine() . ': ' . PiiRedactor::scrub($exception->getMessage(), 500));
        }
    }
}
