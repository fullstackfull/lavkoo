<?php

namespace App\Services\Fulfillment\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Provider timestamps are instants (epoch milliseconds, ISO strings with a zone). Eloquent persists the
 * wall-clock of whatever Carbon it is given and later reads that string back in the APPLICATION timezone,
 * so an instant left in UTC would come back shifted by the application's UTC offset — and every
 * "is this event older than the last one?" comparison afterwards would be wrong by hours.
 *
 * Every provider time is converted here, once, before it is stored or compared.
 */
final class AppTime
{
    public static function local(?CarbonInterface $time): ?Carbon
    {
        return $time === null ? null : Carbon::instance($time)->setTimezone((string) config('app.timezone'));
    }

    public static function fromMilliseconds(int|float|string $milliseconds): Carbon
    {
        return Carbon::createFromTimestampMs((int) $milliseconds, (string) config('app.timezone'));
    }
}
