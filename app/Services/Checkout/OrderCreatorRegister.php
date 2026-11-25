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

    public const RECORDS_ITS_MARKET = 'records_its_market';

    public const EXEMPT = 'exempt';

    /**
     * Where an `orders` row is written, keyed by the file that writes it.
     *
     * `records_its_market` means that file calls {@see OrderStorefrontSnapshot::record()} for the order it has just written, which is
     * silent while markets are off and so changes nothing for a Saudi installation. `exempt` means it deliberately does not, and the
     * reason says whose decision that is.
     *
     * @var array<string, array{state: string, what: string, reason?: string, decision?: string}>
     */
    public const CREATORS = [
        'app/Utils/OrderManager.php' => [
            'state' => self::RECORDS_ITS_MARKET,
            'what' => 'The customer checkout: the website, REST v1 and v2, and the settlement hook of a paid gateway payment. One order '
                . 'per vendor group, each stamped from its own lines after they are saved.',
        ],
        'app/Http/Controllers/Admin/POS/POSOrderController.php' => [
            'state' => self::RECORDS_ITS_MARKET,
            'what' => "The administrator's till.",
        ],
        'app/Http/Controllers/Vendor/POS/POSOrderController.php' => [
            'state' => self::RECORDS_ITS_MARKET,
            'what' => "The vendor panel's till.",
        ],
        'app/Http/Controllers/RestAPI/v3/seller/POSController.php' => [
            'state' => self::RECORDS_ITS_MARKET,
            'what' => "The seller app's till, which stamps the order and then its money rows.",
        ],
        'app/Services/SalesChannels/Dispatch/ChannelOrderMaterializer.php' => [
            'state' => self::EXEMPT,
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

    /** @return array<string, array{state: string, what: string, reason?: string, decision?: string}> */
    public static function thatRecordTheirMarket(): array
    {
        return array_filter(self::CREATORS, static fn (array $creator): bool => $creator['state'] === self::RECORDS_ITS_MARKET);
    }

    /** @return array<string, array{state: string, what: string, reason?: string, decision?: string}> */
    public static function exempt(): array
    {
        return array_filter(self::CREATORS, static fn (array $creator): bool => $creator['state'] === self::EXEMPT);
    }
}
