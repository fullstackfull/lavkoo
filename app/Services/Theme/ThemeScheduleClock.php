<?php

namespace App\Services\Theme;

use App\Models\Theme;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The clock a theme's schedule is read on (F-08).
 *
 * `config/app.php` sets one time zone for the whole platform -- `Asia/Dhaka`, the upstream vendor's -- and the schedule form takes a
 * `datetime-local` value, which carries no zone at all. So `Carbon::parse('2026-12-01T09:00')` meant nine o'clock in DHAKA: an operator of
 * the Emirati storefront who scheduled a seasonal home page for nine in the morning got it at six, and one who scheduled a midnight launch
 * got it at eight the evening before. Nobody is watching when a scheduled publish runs, which is exactly why the hour has to be the one the
 * operator meant.
 *
 * Only the READING and the WRITING BACK of a wall clock change. The stored moment stays absolute and in the platform's zone, like every
 * other timestamp of this database, so `ThemeVersion::dueToPublish()` still compares against `now()` and the runner needs no change: a
 * schedule set for nine in Dubai is stored as eleven, fires at eleven Dhaka time, and is shown back to the Emirati operator as nine.
 *
 * While themes do not belong to storefronts there is one clock and it is the platform's, so a single-market installation reads and writes
 * exactly what it did. A market whose entry names no zone, or names one this machine's database does not know, is the platform's clock too
 * rather than an exception: a schedule nobody can read is worse than a schedule in the wrong zone.
 */
#[Singleton]
final class ThemeScheduleClock
{
    /**
     * The last moment a MySQL `TIMESTAMP` column can hold, in UTC.
     *
     * `theme_versions.publish_at` is a timestamp on every installation whose schema the test support mirrors, and this platform ships no
     * migration for the theme tables at all, so the column cannot be widened from here. Beyond this moment MySQL stores `0000-00-00`,
     * which `ThemeVersion::dueToPublish()` reads as ALREADY DUE -- so a schedule set far enough ahead publishes IMMEDIATELY, which is the
     * exact opposite of what was asked. Refused rather than stored.
     */
    public const LAST_MOMENT_THE_COLUMN_HOLDS = '2038-01-19 03:14:07';

    /** Why a typed moment was refused, for the screen to say which of the two it was. */
    public const TOO_LATE_FOR_THE_COLUMN = 'this_server_cannot_hold_a_publish_date_that_far_ahead';

    public const NOT_IN_THE_FUTURE = 'choose_a_date_and_time_in_the_future';

    public function __construct(private readonly StorefrontThemeResolver $themes)
    {
    }

    /** The zone a theme's schedule is typed and read in: its market's own, else the platform's. */
    public function timezoneOf(?Theme $theme): string
    {
        $platform = (string) config('app.timezone');

        if ($theme === null || !$this->themes->keepsThemesByStorefront()) {
            return $platform;
        }

        $country = $this->themes->storefrontOf(theme: $theme);
        $zone = config("gcc.storefronts.countries.{$country}.timezone");

        return is_string($zone) && $this->machineKnows($zone) ? $zone : $platform;
    }

    /**
     * The absolute moment a `datetime-local` value from this theme's schedule form stands for, in the platform's own zone so that it is
     * stored like every other timestamp. Null for a blank value, a value that cannot be read, or a moment that has already passed.
     */
    public function momentTyped(?Theme $theme, mixed $typed): ?Carbon
    {
        if (!is_string($typed) || trim($typed) === '') {
            return null;
        }

        try {
            $moment = Carbon::parse($typed, $this->timezoneOf(theme: $theme))->setTimezone((string) config('app.timezone'));
        } catch (Throwable) {
            return null;
        }

        return $this->refusalOf($moment) === null ? $moment : null;
    }

    /** Which refusal a parsed moment earns, or null when it is storable. */
    public function refusalOf(?Carbon $moment): ?string
    {
        if ($moment === null || !$moment->isFuture()) {
            return self::NOT_IN_THE_FUTURE;
        }

        return $moment->greaterThan(Carbon::parse(self::LAST_MOMENT_THE_COLUMN_HOLDS, 'UTC')) ? self::TOO_LATE_FOR_THE_COLUMN : null;
    }

    /** The reason a typed value cannot be scheduled, for the screen's message. Null when it can. */
    public function whyItCannotBeScheduled(?Theme $theme, mixed $typed): ?string
    {
        if (!is_string($typed) || trim($typed) === '') {
            return self::NOT_IN_THE_FUTURE;
        }

        try {
            $moment = Carbon::parse($typed, $this->timezoneOf(theme: $theme))->setTimezone((string) config('app.timezone'));
        } catch (Throwable) {
            return self::NOT_IN_THE_FUTURE;
        }

        return $this->refusalOf($moment);
    }

    /** A stored moment on the market's own clock, which is how the screen shows it back to the operator who typed it. */
    public function onTheMarketsClock(?Theme $theme, mixed $stored): ?Carbon
    {
        if (!$stored instanceof Carbon) {
            return null;
        }

        return $stored->copy()->setTimezone($this->timezoneOf(theme: $theme));
    }

    /** The zone's short name for the screen, so a time with no zone beside it cannot be read as the wrong one. */
    public function labelOf(?Theme $theme): string
    {
        $zone = $this->timezoneOf(theme: $theme);

        return str_contains($zone, '/') ? str_replace('_', ' ', substr($zone, strrpos($zone, '/') + 1)) : $zone;
    }

    /** Whether every configured market names a zone this machine knows, for the readiness screens and for a test to assert over all six. */
    public function everyMarketNamesAZone(): bool
    {
        foreach (StorefrontResolver::fromConfig()->configuredCountries() as $country) {
            $zone = config("gcc.storefronts.countries.{$country}.timezone");

            if (!is_string($zone) || !$this->machineKnows($zone)) {
                return false;
            }
        }

        return true;
    }

    private function machineKnows(string $zone): bool
    {
        try {
            new \DateTimeZone($zone);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
