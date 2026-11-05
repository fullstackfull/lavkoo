<?php

namespace App\Jobs\Fulfillment;

use App\Services\Fulfillment\Support\ScrubbedFailure;
use Closure;

/**
 * A job that throws is reported by the queue worker (to laravel.log, with the whole message) and kept in `failed_jobs`. What a
 * provider answered, or a database error that echoes a bound value, may carry a phone number or an e-mail address. So a failing
 * job lets nothing out but where it failed and a scrubbed message: the shipment itself says what happened to the order.
 */
trait KeepsPersonalDataOutOfFailures
{
    private function withoutPersonalDataInFailures(Closure $work): void
    {
        ScrubbedFailure::run($work);
    }
}
