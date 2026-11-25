<?php

namespace App\Services\Checkout;

use App\Services\Storefront\OrderStorefrontSnapshot;

/**
 * Every path in this application that writes an `orders` row, and what each one does about the market it sold in (D1-01).
 *
 * The criterion this answers asks for one of two things from every order creator OUTSIDE the storefront -- the three tills, the
 * marketplace dispatcher, an auction claim: either it records the market, or there is an EXPLICIT EXEMPTION saying it does not and why.
 * The point of writing them down here rather than in a document is that a register nobody can check drifts: a sixth order creator added
 * next month would simply not be in anybody's list, and the first anyone would know of it is a market's orders coming out blank.
 * `OrderCreatorsAreAccountedForTest` reads this register against the repository itself, so a new creator, or a stamp quietly dropped from
 * a path that claims to make one, fails a named test.
 *
 * This class DECIDES NOTHING AND IS CALLED BY NOTHING AT RUNTIME. It is a record of decisions already taken in the code it names, and the
 * one exemption below records a decision NOT taken, with the owner's open question attached to it. Reading it is how the next person finds
 * out which of the two a path is, without reading five controllers.
 */
final class OrderCreatorRegister
{
    /** The writer every order creator records its market through, named once so the guard and the register cannot disagree. */
    public const RECORDER = OrderStorefrontSnapshot::class . '::record';

    /** The rule a creator asks before it makes a NEW sale, named once for the same reason. */
    public const SALE_RULE = PointOfSaleSaleRule::class . '::marketThatMayNotSell';

    /** The middleware alias the customer-facing routes carry, which is the sale gate for everything served from a storefront. */
    public const ROUTE_GATE = 'storefront.sellable';

    public const ASKS_THE_SALE_RULE = 'asks_the_sale_rule';

    public const BEHIND_THE_ROUTE_GATE = 'behind_the_route_gate';

    public const NOT_A_NEW_SALE = 'not_a_new_sale';

    public const RECORDS_ITS_MARKET = 'records_its_market';

    public const EXEMPT = 'exempt';

    /**
     * Where an `orders` row is written, keyed by the file that writes it.
     *
     * `records_its_market` means that file calls {@see OrderStorefrontSnapshot::record()} for the order it has just written, which is
     * silent while markets are off and so changes nothing for a Saudi installation. `exempt` means it deliberately does not, and the
     * reason says whose decision that is.
     *
     * Each entry also says what it does about the SALE RULE (D1-16, owner decision I2-09): `behind_the_route_gate` for a creator whose
     * routes carry `storefront.sellable`, `asks_the_sale_rule` for a till, which is served from no storefront and so has no bound market
     * to gate and asks {@see PointOfSaleSaleRule} of the goods on the counter instead, and `not_a_new_sale` for a creator that materialises
     * an obligation already incurred, which I2-09 says keeps being processed.
     *
     * @var array<string, array{state: string, what: string, sale_rule: string, reason?: string, decision?: string, sale_rule_reason?: string}>
     */
    public const CREATORS = [
        'app/Utils/OrderManager.php' => [
            'state' => self::RECORDS_ITS_MARKET,
            'sale_rule' => self::BEHIND_THE_ROUTE_GATE,
            'what' => 'The customer checkout: the website, REST v1 and v2, and the settlement hook of a paid gateway payment. One order '
                . 'per vendor group, each stamped from its own lines after they are saved.',
        ],
        'app/Http/Controllers/Admin/POS/POSOrderController.php' => [
            'state' => self::RECORDS_ITS_MARKET,
            'sale_rule' => self::ASKS_THE_SALE_RULE,
            'what' => "The administrator's till.",
        ],
        'app/Http/Controllers/Vendor/POS/POSOrderController.php' => [
            'state' => self::RECORDS_ITS_MARKET,
            'sale_rule' => self::ASKS_THE_SALE_RULE,
            'what' => "The vendor panel's till.",
        ],
        'app/Http/Controllers/RestAPI/v3/seller/POSController.php' => [
            'state' => self::RECORDS_ITS_MARKET,
            'sale_rule' => self::ASKS_THE_SALE_RULE,
            'what' => "The seller app's till, which stamps the order and then its money rows.",
        ],
        'app/Services/SalesChannels/Dispatch/ChannelOrderMaterializer.php' => [
            'state' => self::EXEMPT,
            'sale_rule' => self::NOT_A_NEW_SALE,
            'sale_rule_reason' => 'It materialises an obligation already incurred on somebody else\'s marketplace, and I2-09 is explicit '
                . 'that orders placed before a stop keep being processed, sales-channel obligations included. Gating it would refuse to '
                . 'deliver a sale the platform is already committed to, which is the opposite of what the sale gate is for: the gate '
                . 'refuses the START of an order, never an obligation. What happens to a marketplace order of a PAUSED country is the '
                . 'remainder of I2-09 and is still open.',
            'what' => 'Marketplace dispatch: an order claimed from an external sales channel and materialised here.',
            'reason' => 'It records NO market, deliberately. The question is not a technical one -- the goods of such an order have a '
                . 'country like any others, and the same reader would answer it -- but whether a sale made on somebody else\'s '
                . 'marketplace is a sale of one of OUR storefronts at all. Stamping it would answer that quietly, in code, and the '
                . 'answer carries the selling company of the market with it, which is who the sale is a liability of. So the order is '
                . 'left with no record, which is what every order on this platform has today, and the gap is written down here instead '
                . 'of being closed by a guess.',
            'decision' => 'D1-01: whether point-of-sale and marketplace sales are storefront sales (STOREFRONT_FLAG_RUNBOOK). The tills '
                . 'half was settled by the owner as I2-09; the marketplace half was not.',
        ],
    ];

    /** @return array<string, array<string, string>> */
    public static function thatRecordTheirMarket(): array
    {
        return array_filter(self::CREATORS, static fn (array $creator): bool => $creator['state'] === self::RECORDS_ITS_MARKET);
    }

    /** @return array<string, array<string, string>> */
    public static function exempt(): array
    {
        return array_filter(self::CREATORS, static fn (array $creator): bool => $creator['state'] === self::EXEMPT);
    }

    /** @return array<string, array<string, string>> the creators that ask the point-of-sale rule, because no storefront is serving them */
    public static function thatAskTheSaleRule(): array
    {
        return array_filter(self::CREATORS, static fn (array $creator): bool => $creator['sale_rule'] === self::ASKS_THE_SALE_RULE);
    }
}
