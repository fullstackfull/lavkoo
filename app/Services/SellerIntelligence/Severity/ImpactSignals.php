<?php

namespace App\Services\SellerIntelligence\Severity;

/**
 * What a detector knows about how much its finding matters.
 *
 * Every field is optional, and that is the point: a detector supplies what it actually measured and
 * nothing else. A catalogue detector has no revenue figure and must not invent one; an SLA detector
 * has hours but no unit count. The engine scores only the signals it was given and says how much of
 * the picture it had, so a score built from one signal is never presented as though it were built
 * from five.
 */
final class ImpactSignals
{
    public function __construct(
        /** Money at stake, in base currency. Null when the detector cannot know it. */
        public readonly ?float $revenueAtRisk = null,

        /** The seller's own recent revenue, so the figure above can be read as a share of their business. */
        public readonly ?float $sellerRecentRevenue = null,

        /** How many things this issue is about — orders, products, shipments. */
        public readonly ?int $affectedCount = null,

        /** The seller's own total of that kind of thing, for the same reason as revenue. */
        public readonly ?int $sellerTotalCount = null,

        /** Hours until the deadline. Negative once it has passed. Null when nothing is due. */
        public readonly ?float $hoursUntilDue = null,

        /** How long this has been true, in hours. */
        public readonly ?float $openForHours = null,

        /** How many sweeps have seen it. */
        public readonly int $detectionCount = 1,

        /**
         * A severity the detector will not go below whatever the arithmetic says.
         *
         * Some findings are not a matter of degree. A listing rejected by a moderator is critical
         * for a seller with one product and for a seller with ten thousand; so is a financial
         * mismatch. The floor is how a detector says "this is not negotiable" without the engine
         * having to special-case it.
         */
        public readonly ?string $severityFloor = null,
    ) {
    }
}
