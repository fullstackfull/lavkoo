<?php

namespace App\Console\Commands\Concerns;

use App\Services\OperationalCosts\Support\OperationalCostsSwitch;

/**
 * The cost module ships off and nothing in it starts by itself because its migrations ran. A command that would post a cost, take units from the cost layers or accrue a
 * storage charge says so and stops, without writing anything, until OPERATIONAL_COSTS_ENABLED is on. It is not a failure: the scheduler must not report one.
 */
trait SkipsWhileOperationalCostsAreOff
{
    protected function operationalCostsAreOff(): bool
    {
        if (OperationalCostsSwitch::enabled()) {
            return false;
        }

        Refusal::say(command: $this, message: OperationalCostsSwitch::OFF_MESSAGE);

        return true;
    }
}
