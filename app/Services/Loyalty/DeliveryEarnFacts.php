<?php

namespace App\Services\Loyalty;

/**
 * Everything the five delivery channels put in front of the question "does this delivery earn points" (E-14).
 *
 * One object rather than eight parameters, because the whole point of the rule beside it is that each channel hands over the SAME facts and
 * the rule decides -- a channel that can leave a fact out is a channel that can disagree.
 *
 * `rewardIsClaimable` is the three conditions the panels have always read as one: a reward row exists for the order, it has not been
 * delivered, and it carries an amount. It is the condition that makes a credit happen once, because `RefundClaims::claimRewardDelivered`
 * then takes it.
 */
final readonly class DeliveryEarnFacts
{
    public function __construct(
        public bool $statusIsDelivered,
        public bool $isGuest,
        public bool $paymentIsPaid,
        public bool $walletIsOn,
        public bool $programmeIsOn,
        public bool $pointsForEachOrder,
        public bool $rewardIsClaimable,
        public bool $hasSeller,
    ) {
    }
}
