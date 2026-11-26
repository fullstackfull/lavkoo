<?php

namespace App\Services\Loyalty;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The reward row an order's points are paid from, for the callers that have no repository to hand (E-14).
 *
 * The admin and vendor panels read it through `OrderDetailsRewardRepository`; the three seller-API sites that credit points never read it
 * at all, which is why one order can earn more than once there. They need it to answer the shared rule and to claim it, and a seller API
 * controller is not a place to grow a second query of the same table.
 *
 * Read with the query BUILDER, not a model: `order_details_rewards` is written once per order line at order time and has no model of its
 * own in this repository, and a scope on a model is the last thing a credit decision should depend on.
 *
 * A host whose table is missing answers null, which the rule reads as "no claimable reward" -- the cautious answer, since a credit that
 * cannot be claimed is a credit that can happen twice.
 */
final class TheOrdersLoyaltyReward
{
    private const TABLE = 'order_details_rewards';

    private const TYPE = 'loyalty_point';

    public function rowFor(int|string $orderId): ?object
    {
        if (!Schema::hasTable(self::TABLE)) {
            return null;
        }

        return DB::table(self::TABLE)->where('order_id', $orderId)->where('reward_type', self::TYPE)->first();
    }

    /** The three conditions the panels have always read as one: a row exists, its points are not paid, and there are points to pay. */
    public function isClaimable(?object $row): bool
    {
        return $row !== null && (int) $row->reward_delivered !== 1 && (float) $row->reward_amount > 0;
    }
}
