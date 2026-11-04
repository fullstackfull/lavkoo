<?php

namespace App\Services\Monitoring\Support;

use Illuminate\Support\Carbon;

/**
 * One clock for the whole monitoring system, and it runs on UTC.
 *
 * This class exists because of a bug that silently emptied every chart. This deployment has
 * `app.timezone` set to Asia/Dhaka while the PHP process default is Asia/Kuwait — so
 * `Carbon::now()` returned 03:49 while `Carbon::createFromTimestamp()` returned 00:49 for the same
 * instant. Buckets were therefore WRITTEN three hours away from where they were later LOOKED FOR,
 * and every "last 15 minutes" query came back empty on a system that was recording perfectly.
 *
 * Nothing about that failure is visible: no error, no exception, just a dashboard that says there
 * has been no traffic. So the rule is absolute — every timestamp that monitoring stores or
 * compares is UTC, produced here. The dashboard's own timezone is applied once, at render, by
 * display(). Server metrics, database timestamps, mobile telemetry and browser beacons all land on
 * the same axis, which is the only way an event at 02:11 can be lined up against a deploy at 02:00.
 */
class Clock
{
    public const TIMEZONE = 'UTC';

    /** Now, in UTC. The only "current time" monitoring code is allowed to ask for. */
    public static function now(): Carbon
    {
        return Carbon::now(self::TIMEZONE);
    }

    /** The start of the minute containing the given unix timestamp, as a UTC datetime string. */
    public static function minuteAt(int $timestamp): string
    {
        return Carbon::createFromTimestampUTC(intdiv($timestamp, 60) * 60)->toDateTimeString();
    }

    /** A UTC datetime string for storage, from anything Carbon understands. */
    public static function stamp(Carbon|string|int|null $moment = null): string
    {
        return self::parse($moment)->toDateTimeString();
    }

    /**
     * Read any stored monitoring timestamp back as UTC.
     *
     * Values come out of the driver as naive strings; parsing them without saying UTC would apply
     * the process timezone and reintroduce the exact offset this class exists to remove.
     */
    public static function parse(Carbon|string|int|null $moment = null): Carbon
    {
        if ($moment === null) {
            return self::now();
        }
        if ($moment instanceof Carbon) {
            return $moment->copy()->setTimezone(self::TIMEZONE);
        }
        if (is_int($moment)) {
            return Carbon::createFromTimestampUTC($moment);
        }

        return Carbon::parse($moment, self::TIMEZONE);
    }

    /**
     * The same instant, in the timezone the dashboard should show it in.
     *
     * Conversion happens once, here, at the edge — never in a query, and never before storage.
     */
    public static function display(Carbon|string|int|null $moment = null): Carbon
    {
        return self::parse($moment)->setTimezone(self::displayTimezone());
    }

    /**
     * The timezone the panel renders in: the operator's configured setting, falling back to the
     * application's own. Only ever used for display.
     */
    public static function displayTimezone(): string
    {
        $configured = (string) config('monitoring.display_timezone', '');
        if ($configured !== '' && in_array($configured, timezone_identifiers_list(), true)) {
            return $configured;
        }

        $app = (string) config('app.timezone', 'UTC');

        return in_array($app, timezone_identifiers_list(), true) ? $app : 'UTC';
    }

    /** A window start `$minutes` back, in UTC — the left edge of every "last N minutes" query. */
    public static function minutesAgo(int $minutes): Carbon
    {
        return self::now()->subMinutes($minutes);
    }

    public static function hoursAgo(int $hours): Carbon
    {
        return self::now()->subHours($hours);
    }

    public static function daysAgo(int $days): Carbon
    {
        return self::now()->subDays($days);
    }
}
