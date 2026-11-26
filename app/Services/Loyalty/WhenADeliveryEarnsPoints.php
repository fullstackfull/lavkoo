<?php

namespace App\Services\Loyalty;

/**
 * Whether a delivery earns the customer points, and whether the credit is claimed first (E-14, finding LO-08).
 *
 * Five places in this codebase decide this, and all five decide it differently. Measured on this tree:
 *
 * | | reward row | `loyalty_point_for_each_order` | wallet on | guest refused | payment paid | has a seller | claims the row |
 * |---|---|---|---|---|---|---|---|
 * | admin panel | yes | yes | no | yes | no | no | **yes** |
 * | vendor panel | yes | yes | **yes** | yes | no | **yes** | **yes** |
 * | seller API v2 | **no** | **no** | **yes** | **no** | **yes** | no | **no** |
 * | seller API v3, twice | **no** | **no** | **yes** | yes | no | no | **no** |
 *
 * What each disagreement costs, in the order it matters:
 *
 * - **A seller API delivery claims nothing, so one order can earn more than once.** The panels take the reward row before crediting
 *   (IDEM-08), which is what makes a second delivery of the same order credit nothing. The three seller-API sites do not read the row at
 *   all, so a seller marking an order delivered twice credits twice, and an order the admin panel has already credited is credited again.
 *   That is pinned as it stands by `ADeliveryEarnsUnderOneRuleTest` and REPAIRED by the `one_rule` reading, not by the default.
 * - **The wallet switch stops the points on four of the five.** Points are not the wallet, and a shop with the wallet off earns from the
 *   admin panel and nowhere else.
 * - **`loyalty_point_for_each_order` is honoured by the panels and ignored by the seller APIs**, so a shop that switched that setting off
 *   still earns points whenever the seller app delivers.
 * - **Seller API v2 does not refuse a GUEST order**, so it credits points against a `customer_id` that is not a customer.
 * - The delivery-man app makes no earn call at all. That is a MISSING credit path rather than a disagreement between existing ones, and it
 *   is not added here: a new place that credits a customer is the owner's to ask for.
 *
 * `gcc.loyalty.when_a_delivery_earns_points` names the two readings, and the default is the platform exactly as it runs today, channel by
 * channel, to the condition -- so no customer's points move while the question is open. An unrecognised value is read as the default.
 *
 *   'as_each_channel_does_today'  the default: the table above, unchanged
 *   'one_rule'                    every channel: delivered, not a guest, the programme on, points for each order on, and a claimable
 *                                 reward row which is then claimed -- the admin panel's reading, applied everywhere
 *
 * **The one rule is the admin panel's and not the union**, and the two deliberate choices in that are the owner's to confirm (E-14, LO-08):
 * it does NOT require the wallet, because points are not wallet money, which makes it more permissive than four channels; and it does NOT
 * require `payment_status` to be paid, because four of the five do not, which makes it more permissive than seller API v2. Both change what
 * a customer is credited, which is why the reading ships unadopted.
 *
 * **The smaller repair, and why it is a key of its own.** Of the five disagreements above, exactly one loses money that nobody decided to
 * give away: the double credit. The other four change WHO EARNS, which is a programme decision. So the claim is switchable by itself,
 * `gcc.loyalty.a_seller_delivery_claims_its_reward_row`, OFF as it ships:
 *
 *   off  the default: the three seller-API sites read the reward row, answer the rule with it and claim NOTHING, exactly as today
 *   on   the three seller-API sites require a claimable reward row and CLAIM it, and every other condition of every channel is untouched
 *
 * With it on, a seller moving an order off `delivered` and back is credited once, and an order the admin panel has already paid is not paid
 * again -- without settling what the wallet switch, `payment_status`, `loyalty_point_for_each_order` or a guest order should mean on each
 * channel, which is LF-1 and stays the owner's. It is a no-op under `one_rule`, which already claims on every channel; the two switches
 * cannot disagree, because the one rule is strictly the stricter of the two.
 */
final class WhenADeliveryEarnsPoints
{
    public const SETTING = 'gcc.loyalty.when_a_delivery_earns_points';

    public const AS_EACH_CHANNEL_DOES_TODAY = 'as_each_channel_does_today';

    public const ONE_RULE = 'one_rule';

    /** The claim on its own, without the rest of the one rule: the repair for the double credit and nothing else. */
    public const SELLER_CLAIM_SETTING = 'gcc.loyalty.a_seller_delivery_claims_its_reward_row';

    public const ADMIN_PANEL = 'admin_panel';

    public const VENDOR_PANEL = 'vendor_panel';

    public const SELLER_API_V2 = 'seller_api_v2';

    public const SELLER_API_V3 = 'seller_api_v3';

    /** @var list<string> */
    private const READINGS = [self::AS_EACH_CHANNEL_DOES_TODAY, self::ONE_RULE];

    public static function reading(): string
    {
        $chosen = (string) config(self::SETTING, self::AS_EACH_CHANNEL_DOES_TODAY);

        return in_array($chosen, self::READINGS, true) ? $chosen : self::AS_EACH_CHANNEL_DOES_TODAY;
    }

    /**
     * Whether a seller-API delivery takes the order's reward row before crediting it, while the channels keep every other condition of
     * their own. Off as it ships, so the default platform is the table above to the condition.
     */
    public static function aSellerDeliveryClaimsItsRewardRow(): bool
    {
        return filter_var(config(self::SELLER_CLAIM_SETTING, false), FILTER_VALIDATE_BOOLEAN);
    }

    /** The two channels the smaller repair reaches: the three seller-API sites, which are two channels. */
    private static function isASellerApi(string $channel): bool
    {
        return in_array($channel, [self::SELLER_API_V2, self::SELLER_API_V3], true);
    }

    public static function earns(string $channel, DeliveryEarnFacts $facts): bool
    {
        if (self::reading() === self::ONE_RULE) {
            return self::theOneRule(facts: $facts);
        }

        // The smaller repair adds the claimable reward row to a seller API's own conditions and nothing else. It is a CONDITION and not
        // only a write: a credit that cannot be claimed is a credit that can happen twice, so the two halves move together.
        $claimIsRequired = self::isASellerApi(channel: $channel) && self::aSellerDeliveryClaimsItsRewardRow() && !$facts->rewardIsClaimable;

        return !$claimIsRequired && match ($channel) {
            self::ADMIN_PANEL => self::theOneRule(facts: $facts),
            self::VENDOR_PANEL => self::theOneRule(facts: $facts) && $facts->walletIsOn && $facts->hasSeller,
            self::SELLER_API_V2 => $facts->statusIsDelivered && $facts->paymentIsPaid && $facts->walletIsOn && $facts->programmeIsOn,
            self::SELLER_API_V3 => $facts->statusIsDelivered && !$facts->isGuest && $facts->walletIsOn && $facts->programmeIsOn,
            default => false,
        };
    }

    /**
     * Whether the credit takes the order's reward row before it is written, which is what makes one order earn once.
     *
     * The panels have always done this and the seller APIs have never done it. Under the one rule every channel does, because a credit
     * that cannot be claimed is a credit that can happen twice -- and so do the seller APIs alone, under the smaller key, which is the
     * repair for the double credit without any of the one rule's programme changes.
     */
    public static function claimsTheRewardRow(string $channel): bool
    {
        return self::reading() === self::ONE_RULE
            || in_array($channel, [self::ADMIN_PANEL, self::VENDOR_PANEL], true)
            || (self::isASellerApi(channel: $channel) && self::aSellerDeliveryClaimsItsRewardRow());
    }

    /** The admin panel's reading, which is the one rule: a delivered order of a real customer with a reward still to be paid. */
    private static function theOneRule(DeliveryEarnFacts $facts): bool
    {
        return $facts->statusIsDelivered
            && !$facts->isGuest
            && $facts->programmeIsOn
            && $facts->pointsForEachOrder
            && $facts->rewardIsClaimable;
    }
}
