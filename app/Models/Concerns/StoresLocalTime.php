<?php

namespace App\Models\Concerns;

use Carbon\CarbonInterface;

/**
 * Eloquent writes a date as the wall-clock of the Carbon instance it is given, and reads it back as the application's timezone. A channel reports instants in UTC, so an
 * instant assigned as it came would be read back hours away from what it was whenever the application's timezone is not UTC. Every instant is converted first.
 */
trait StoresLocalTime
{
    public function fromDateTime($value)
    {
        return parent::fromDateTime($value instanceof CarbonInterface ? $value->copy()->setTimezone(date_default_timezone_get()) : $value);
    }
}
