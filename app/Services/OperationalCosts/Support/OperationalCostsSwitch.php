<?php

namespace App\Services\OperationalCosts\Support;

/**
 * Whether the cost module calculates by itself. Read through config() every time: nothing is remembered, so a test (or a restarted worker) sees the value it is given.
 *
 * `enabled()` is the master switch: with it off nothing posts a cost entry, a FIFO consumption or a storage charge on its own. `scheduled()` additionally needs the
 * schedule switch (`scheduleSwitchedOn()`, the value on its own) and only decides whether the scheduler runs the cost commands; they can always be run by hand while the
 * master switch is on.
 */
final class OperationalCostsSwitch
{
    /** What a command prints, in one line, when it stops because the master switch is off. */
    public const OFF_MESSAGE = 'Operational costs are switched off (OPERATIONAL_COSTS_ENABLED): nothing was calculated or written.';

    public static function enabled(): bool
    {
        return (bool) config('operational_costs.enabled', false);
    }

    public static function scheduleSwitchedOn(): bool
    {
        return (bool) config('operational_costs.schedule_enabled', false);
    }

    public static function scheduled(): bool
    {
        return self::enabled() && self::scheduleSwitchedOn();
    }
}
