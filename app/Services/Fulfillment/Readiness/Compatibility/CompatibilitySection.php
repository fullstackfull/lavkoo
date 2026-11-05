<?php

namespace App\Services\Fulfillment\Readiness\Compatibility;

/** One part of the compatibility report. A section only reads, and only through the probe it is given. */
interface CompatibilitySection
{
    /** @return list<CompatibilityCheck> */
    public function checks(ReadOnlyProbe $probe, CompatibilityOptions $options): array;
}
