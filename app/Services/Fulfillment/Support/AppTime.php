<?php

namespace App\Services\Fulfillment\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Provider timestamps are instants (epoch milliseconds, ISO strings with a zone). Eloquent persists the
 * wall-clock of whatever Carbon it is given and later reads that string back in the PHP DEFAULT timezone,
 * so an instant left in UTC would come back shifted by that zone's UTC offset — and every
 * "is this event older than the last one?" comparison afterwards would be wrong by hours.
 *
 * Which zone that is matters: config('app.timezone') is only where it starts. This store's merchant setting
 * (BusinessSetting `timezone`, applied by ConfigServiceProvider with date_default_timezone_set()) overrides it
 * at runtime, and the two differ on this deployment. Carbon reads the database back in the zone that is
 * actually in force, so that is the one used here.
 *
 * Every provider time is converted here, once, before it is stored or compared.
 */
final class AppTime
{
    public static function local(?CarbonInterface $time): ?Carbon
    {
        return $time === null ? null : Carbon::instance($time)->setTimezone(date_default_timezone_get());
    }

    public static function fromMilliseconds(int|float|string $milliseconds): Carbon
    {
        return Carbon::createFromTimestampMs((int) $milliseconds, date_default_timezone_get());
    }
}
