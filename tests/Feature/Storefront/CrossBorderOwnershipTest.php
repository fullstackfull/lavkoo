<?php

namespace Tests\Feature\Storefront;

use App\Services\Shipping\EligibilityDecision;
use App\Services\Shipping\ShippingEligibility;
use App\Services\Storefront\LegalEntityRegistry;
use App\Services\Storefront\OrderStorefrontSnapshot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsLegalEntityWorld;
use Tests\TestCase;

/**
 * A cross-border order records WHERE IT WAS SOLD and WHO SOLD IT, and the country it is going to is a third, separate fact (H1-07).
 *
 * The rule is that a foreign destination changes nothing about the sale: the order is made in the source storefront, from that
 * storefront's product rows, and invoiced by that storefront's company. The destination decides shipping, tax and duty and nothing
 * else. Three facts, and the danger is that one of them quietly becomes another -- an order stamped with the country of the ADDRESS
 * would be attributed to a market that never sold it, and its money counted in that market's books.
 *
 * Each of the three is written by its own service and each already has tests of its own. What none of them asserts, and what this
 * does, is that on ONE order the three disagree in the right direction: Saudi lines, a Saudi company, a Kuwaiti address, and the
 * `KW` nowhere near the two stamps. It is the case a later tidy-up would break -- reading the destination from the address and
 * "filling in" the order's market with it looks like an improvement until the books stop adding up.
 *
 * Dormant: with storefronts off the order carries no market and no company at all, which is every order this platform has placed.
 */
class CrossBorderOwnershipTest extends TestCase
{
    use BuildsLegalEntityWorld;

    private const ORDER = 9101;

    /** Where it was sold, and where it is going. */
    private const SOURCE = 'SA';

    private const DESTINATION = 'KW';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildLegalEntityWorld();
        Schema::table('orders', static fn (Blueprint $table) => $table->longText('shipping_address_data')->nullable());

        // The market stamp lives in the CURRENCY snapshot (`OrderStorefrontSnapshot::recordCurrency`), so an order records which
        // market sold it only while GCC-05's switch is on. Said here rather than left as a surprise: it is the coupling a reader of
        // this test needs to know, and the dormancy case below is the other side of it.
        config(['gcc.storefronts.currency.enabled' => true]);
    }

    protected function tearDown(): void
    {
        $this->tearDownLegalEntityWorld();

        parent::tearDown();
    }

    public function test_the_order_records_the_market_that_sold_it_and_the_company_that_sold_it_and_not_the_destination(): void
    {
        $saudiCompany = $this->registerEntity(self::SOURCE);
        $kuwaitiCompany = $this->registerEntity(self::DESTINATION);
        $this->aSaudiOrderGoingToKuwait();

        app(OrderStorefrontSnapshot::class)->record(orderId: self::ORDER);

        $order = DB::table('orders')->where('id', self::ORDER)->first();

        $this->assertSame(self::SOURCE, (string) $order->storefront_country, 'the market that sold it, which is the market of its lines');
        $this->assertSame(
            (int) $saudiCompany->id,
            (int) $order->legal_entity_id,
            'the company of that market, and not the company of the country the parcel is going to',
        );
        $this->assertNotSame((int) $kuwaitiCompany->id, (int) $order->legal_entity_id);
        $this->assertSame(
            self::SOURCE,
            (string) (json_decode((string) $order->entity_snapshot, true)['country'] ?? ''),
            'the frozen copy of the identity names the same market',
        );
        $this->assertSame(
            self::DESTINATION,
            (string) (json_decode((string) $order->shipping_address_data, true)['country'] ?? ''),
            'and the destination is the order\'s own address copy, which is a separate fact and stays one',
        );
    }

    /**
     * The destination is not merely stored: the shipping engine READS it, off that same copy, and says the order crosses a border.
     *
     * The decision's allowance is deliberately not asserted. With no destination-permission row for this route the engine refuses,
     * which is its own rule (H1-03: no record means no) and is proved where that rule lives. What matters here is the KIND -- that the
     * engine can tell this order is going abroad from a fact the order kept, without the order's market having been overwritten.
     */
    public function test_the_engine_reads_the_destination_off_the_orders_own_copy(): void
    {
        config(['gcc.shipping.cross_border.enabled' => true]);
        $this->registerEntity(self::SOURCE);
        $this->aSaudiOrderGoingToKuwait();
        app(OrderStorefrontSnapshot::class)->record(orderId: self::ORDER);

        $decision = app(ShippingEligibility::class)->decideForOrder(self::ORDER);

        $this->assertSame(EligibilityDecision::CROSS_BORDER, $decision->kind);
        $this->assertSame(self::SOURCE, (string) DB::table('orders')->where('id', self::ORDER)->value('storefront_country'), 'and reading it changed nothing');
    }

    public function test_an_order_of_the_destinations_own_market_is_local_and_is_that_markets_sale(): void
    {
        config(['gcc.shipping.cross_border.enabled' => true]);
        $kuwaitiCompany = $this->registerEntity(self::DESTINATION);
        $this->registerEntity(self::SOURCE);
        $this->orderOf(self::ORDER, self::DESTINATION);
        $this->addressOf(self::DESTINATION);

        app(OrderStorefrontSnapshot::class)->record(orderId: self::ORDER);
        $decision = app(ShippingEligibility::class)->decideForOrder(self::ORDER);

        $order = DB::table('orders')->where('id', self::ORDER)->first();

        $this->assertSame(EligibilityDecision::LOCAL, $decision->kind, 'nothing crosses a border');
        $this->assertTrue($decision->allowed, implode(', ', $decision->reasons));
        $this->assertSame(
            [self::DESTINATION, (int) $kuwaitiCompany->id],
            [(string) $order->storefront_country, (int) $order->legal_entity_id],
            'a Kuwaiti sale is Kuwait\'s, by its lines: the twin that shows the first case is about the market and not about the address',
        );
    }

    public function test_with_storefronts_off_the_order_carries_no_market_and_no_company(): void
    {
        config(['gcc.storefronts.enabled' => false, 'gcc.storefronts.currency.enabled' => false]);
        $this->registerEntity(self::SOURCE);
        $this->aSaudiOrderGoingToKuwait();

        app(OrderStorefrontSnapshot::class)->record(orderId: self::ORDER);

        $order = DB::table('orders')->where('id', self::ORDER)->first();

        $this->assertNull($order->storefront_country, 'dormant: every order this platform has placed is in this state');
        $this->assertNull($order->legal_entity_id);
        $this->assertSame(
            self::DESTINATION,
            (string) (json_decode((string) $order->shipping_address_data, true)['country'] ?? ''),
            'the address copy is the address copy whatever the switches say',
        );
    }

    /** Saudi lines, a Kuwaiti delivery address: the order this requirement is about. */
    private function aSaudiOrderGoingToKuwait(): void
    {
        $this->orderOf(self::ORDER, self::SOURCE);
        $this->addressOf(self::DESTINATION);
    }

    /** The address copy an order keeps, as the engine reads it: the country as the address book stores it, which is the English name. */
    private function addressOf(string $country): void
    {
        DB::table('orders')->where('id', self::ORDER)->update([
            'shipping_address_data' => json_encode(['country' => $country, 'city' => 'Anywhere', 'address' => 'A street']),
        ]);
    }
}
