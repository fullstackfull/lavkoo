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
 */
final class WhenADeliveryEarnsPoints
{
    public const SETTING = 'gcc.loyalty.when_a_delivery_earns_points';

    public const AS_EACH_CHANNEL_DOES_TODAY = 'as_each_channel_does_today';

    public const ONE_RULE = 'one_rule';

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

    public static function earns(string $channel, DeliveryEarnFacts $facts): bool
    {
        if (self::reading() === self::ONE_RULE) {
            return self::theOneRule(facts: $facts);
        }

        return match ($channel) {
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
     * that cannot be claimed is a credit that can happen twice.
     */
    public static function claimsTheRewardRow(string $channel): bool
    {
        return self::reading() === self::ONE_RULE
            || in_array($channel, [self::ADMIN_PANEL, self::VENDOR_PANEL], true);
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
