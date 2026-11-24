<?php

namespace App\Services\Refund;

use Illuminate\Support\Facades\DB;

/**
 * The claims that decide who pays a refund, a return amount or a cancellation, and who credits the points of a delivery (RF-01, RF-03,
 * RF-04, IDEM-08).
 *
 * Each of those four handlers read a row, saw that the money had not been paid yet, and then paid it. Two requests that arrive together
 * both read the same row before either writes, so both pay: the wallet is credited twice for one refund, a return amount is given back
 * twice, a cancelled point-of-sale order is refunded twice, the points of one delivery are credited twice. A read is not a claim.
 *
 * A claim is one conditional UPDATE. It moves the row out of the state that allows the payment, and it says how many rows it moved: one
 * means this request owns the payment, none means somebody else already does. The row itself is the lock, so the second request waits for
 * the first to commit and then finds nothing to claim — no table, no setting and no new column is needed for it.
 *
 * Every claim must be made inside the transaction that pays, so that a payment which fails gives the claim back. A claim outside one
 * leaves a refund nobody paid.
 */
final class RefundClaims
{
    /** The state a refund request is in once it has been paid, and the one a delivery's reward is in once its points are credited. */
    private const REFUNDED = 'refunded';

    /**
     * Claims the right to pay this refund by moving it to `refunded`. False when it is already there, whoever put it there.
     *
     * The status is written here rather than with the rest of the refund's fields at the end of the handler, because it IS the claim: a
     * handler that paid first and wrote the status afterwards is the handler that pays twice.
     */
    public function claimRefunded(int|string $refundId): bool
    {
        return DB::table('refund_requests')
            ->where('id', $refundId)
            ->where('status', '!=', self::REFUNDED)
            ->update(['status' => self::REFUNDED, 'updated_at' => now()]) === 1;
    }

    /**
     * Claims the return amount an order owes by zeroing it, which is what paying it does anyway. False when the amount is no longer the
     * one the operator saw, which covers a second submission of the same screen and an amount changed in between.
     */
    public function claimReturnAmount(int|string $orderId, string|float $amount): bool
    {
        return DB::table('orders')
            ->where('id', $orderId)
            ->where('edit_return_amount', $amount)
            ->update(['edit_return_amount' => 0, 'updated_at' => now()]) === 1;
    }

    /**
     * Claims an order's move to a status by making it, so that whatever the move pays for is paid once. False when the order is already in
     * that status.
     */
    public function claimOrderStatus(int|string $orderId, string $status): bool
    {
        return DB::table('orders')
            ->where('id', $orderId)
            ->where('order_status', '!=', $status)
            ->update(['order_status' => $status, 'updated_at' => now()]) === 1;
    }

    /** Claims the reward of a delivered order line by marking it delivered. False when its points have already been credited. */
    public function claimRewardDelivered(int|string $rewardId): bool
    {
        return DB::table('order_details_rewards')
            ->where('id', $rewardId)
            ->where('reward_delivered', '!=', 1)
            ->update(['reward_delivered' => 1, 'updated_at' => now()]) === 1;
    }
}
