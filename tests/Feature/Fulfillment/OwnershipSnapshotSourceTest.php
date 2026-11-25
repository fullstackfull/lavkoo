<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentItem;
use App\Models\ProviderProductMapping;
use App\Services\Fulfillment\Exceptions\UnknownFulfillmentSourceException;
use App\Services\Fulfillment\FulfillmentRouter;
use App\Services\Fulfillment\OrderItemOwnershipSnapshotter;
use App\Services\Fulfillment\OwnershipHistory;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\ProviderRegistry;
use App\Services\Fulfillment\Support\WhatAnUnknownSourceDoes;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Fulfillment\FakeGulfAdapter;

/**
 * What the checkout freezes about WHO fulfils a line and WHERE it was sold (C-03's unknown source, C-04's market and account).
 *
 * Two separate things were wrong, and the first was the expensive one.
 *
 * **A carrier the configuration declares got no snapshot at all.** `OrderItemOwnershipSnapshotter` asked the closed `ProviderCode` enum
 * whether a stored code was a carrier, so every carrier added the way H1-11 says carriers are added -- one adapter class, one entry in
 * `config/fulfillment.php`, no enum case -- was read as "not a provider" at checkout. The line wrote no ownership row, the router found
 * nothing to route, and the parcel was packed in-house without a word. It is the immutable record C-04 is about, written inside the
 * checkout transaction, so getting it wrong there cannot be repaired afterwards: nothing says what the line was supposed to be.
 *
 * **A code nothing can resolve was also read as in-house**, which is C-03's own criterion and is now the owner's choice between
 * refusing the sale and holding the line ({@see WhatAnUnknownSourceDoes}). The default is today's platform, and the first test says so.
 *
 * The market and the account are the C-04 half: a snapshot now records which storefront sold the line and which carrier account its
 * shipments run against, both nullable, with {@see OwnershipHistory} as the written rule for a row that names neither.
 */
class OwnershipSnapshotSourceTest extends FulfillmentTestCase
{
    /** A carrier declared the way H1-11 says a carrier is declared: one class, one configuration entry, no enum case. */
    private const DECLARED = 'fake_gulf';

    private const MIGRATION = '2027_01_02_100000_add_market_and_account_to_provider_fulfillment_items.php';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fulfillment.providers.' . self::DECLARED => [
                'label' => 'Fake Gulf Express',
                'reference_code' => 'FG',
                'adapter' => FakeGulfAdapter::class,
                'routes' => ['SA:SA', 'SA:AE'],
                'secret_keys' => ['api_key'],
            ],
        ]);

        $this->theSnapshotCanRecordItsMarket();
    }

    /** The migration under test, run here rather than in the shared schema so the class before this one sees the table it expected. */
    private function theSnapshotCanRecordItsMarket(): void
    {
        if (!Schema::hasColumn(OwnershipHistory::TABLE, OwnershipHistory::MARKET)) {
            (require database_path('migrations/' . self::MIGRATION))->up();
        }
    }

    private function capture(int $productId, ?string $source, int $detailId = 1, int $orderId = 1): ?ProviderFulfillmentItem
    {
        return app(OrderItemOwnershipSnapshotter::class)->capture(
            orderId: $orderId,
            orderDetailId: $detailId,
            product: ['id' => $productId, 'name' => 'Item', 'code' => 'SKU' . $productId, 'fulfillment_provider' => $source, 'variation' => '[]'],
            variant: null,
            quantity: 2,
            unitPrice: 25.0,
            netAmount: 50.0,
            lineTax: 0.0,
            taxModel: 'include',
        );
    }

    private function approvedMapping(string $provider, int $productId, string $externalId): ProviderProductMapping
    {
        return ProviderProductMapping::create([
            'provider' => $provider, 'product_id' => $productId, 'variant_key' => '',
            'external_id' => $externalId, 'external_variant_id' => '', 'external_sku' => 'X-' . $externalId,
            'status' => MappingStatus::Approved,
        ]);
    }

    public function test_the_rule_ships_as_the_platform_runs_today_and_nothing_about_an_in_house_line_moved(): void
    {
        $this->makeOrder(1);

        $this->assertSame(WhatAnUnknownSourceDoes::IN_HOUSE_TODAY, WhatAnUnknownSourceDoes::rule(), 'the setting ships as the platform runs');
        $this->assertFalse(WhatAnUnknownSourceDoes::refuses());
        $this->assertFalse(WhatAnUnknownSourceDoes::holds());

        $this->assertNull($this->capture(10, null), 'no source is in-house, which is every product this platform has');
        $this->assertNull($this->capture(11, '', 2), 'and so is an empty string, which is what a cleared form writes');
        $this->assertNull($this->capture(12, 'aramex', 3), 'a code nothing declares is in-house while the rule says so');
        $this->assertSame(0, ProviderFulfillmentItem::count());
    }

    /**
     * The defect, stated as the requirement states it: adding a carrier is one class and one configuration entry.
     *
     * It was not, at the one place where being wrong is permanent.
     */
    public function test_a_carrier_the_configuration_declares_is_frozen_like_the_two_with_an_enum_case(): void
    {
        $this->makeProduct(10);
        DB::table('products')->where('id', 10)->update(['fulfillment_provider' => self::DECLARED]);
        $this->makeOrder(1);
        $mapping = $this->approvedMapping(self::DECLARED, 10, 'FG-900');

        $item = $this->capture(10, self::DECLARED);

        $this->assertNull(ProviderCode::tryFrom(self::DECLARED), 'the point of this test is a carrier with no case in the enum');
        $this->assertNotNull($item, 'a declared carrier owns its line, so the line is frozen');
        $this->assertSame(self::DECLARED, $item->provider);
        $this->assertSame($mapping->id, $item->mapping_id, 'and its mapping is found, which needs the code and not the enum');
        $this->assertSame('FG-900', $item->external_product_id);
        $this->assertSame(ProviderFulfillmentItem::STATUS_UNROUTED, $item->status);
    }

    /** Another carrier's mapping is never used for it: the mapping lookup is keyed by the code, exactly as it was for the enum. */
    public function test_a_declared_carrier_never_takes_another_carriers_mapping(): void
    {
        $this->makeProduct(10);
        $this->makeOrder(1);
        $this->approvedMapping(ProviderCode::M5azn->value, 10, 'M5-1');

        $item = $this->capture(10, self::DECLARED);

        $this->assertNotNull($item);
        $this->assertNull($item->mapping_id);
        $this->assertNull($item->external_product_id);
    }

    /**
     * And the whole way: the snapshot a declared carrier's line writes is one the real router moves into a real shipment.
     *
     * A test of the snapshotter alone would not have found the defect's consequence, because the consequence is in the router: it reads
     * `provider_fulfillment_items` and nothing else, so no snapshot is no shipment, silently.
     */
    public function test_the_router_turns_a_declared_carriers_snapshot_into_a_shipment(): void
    {
        $identity = app(ProviderRegistry::class)->identity(self::DECLARED);
        app(ProviderConnectionManager::class)->save(
            provider: $identity,
            enabled: true,
            baseUrl: null,
            settings: ['dispatch_enabled' => true, 'auto_dispatch' => true, 'dry_run' => false],
        );
        $this->makeProduct(10);
        $this->makeOrder(5);
        $detailId = DB::table('order_details')->insertGetId([
            'order_id' => 5, 'product_id' => 10, 'qty' => 2, 'price' => 25,
            'product_details' => json_encode(['id' => 10]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->approvedMapping(self::DECLARED, 10, 'FG-901');
        $this->capture(10, self::DECLARED, $detailId, 5);

        app(FulfillmentRouter::class)->route(5);

        $fulfillment = ProviderFulfillment::where('order_id', 5)->firstOrFail();
        $this->assertSame(self::DECLARED, $fulfillment->provider);
        $this->assertSame(ProviderFulfillmentItem::STATUS_ROUTED, ProviderFulfillmentItem::where('order_id', 5)->firstOrFail()->status);
    }

    public function test_the_refuse_rule_stops_the_line_and_writes_nothing(): void
    {
        config(['fulfillment.unknown_source' => WhatAnUnknownSourceDoes::REFUSE]);
        $this->makeOrder(1);

        try {
            $this->capture(10, 'aramex');
            $this->fail('A source nobody can resolve must not pass the checkout while the rule refuses it.');
        } catch (UnknownFulfillmentSourceException $refused) {
            $this->assertSame('aramex', $refused->storedSource);
            $this->assertSame(10, $refused->productId);
        }

        $this->assertSame(0, ProviderFulfillmentItem::count());
    }

    /** The distinction the whole setting rests on: a DECLARED carrier is known, so the refusal never touches it. */
    public function test_a_declared_carrier_is_not_an_unknown_source_even_while_the_rule_refuses(): void
    {
        config(['fulfillment.unknown_source' => WhatAnUnknownSourceDoes::REFUSE]);
        $this->makeProduct(10);
        $this->makeOrder(1);

        $this->assertNotNull($this->capture(10, self::DECLARED), 'the configuration is the gate, so this line is ordinary');
    }

    /**
     * The other answer: the order is placed and the line is frozen with the source it names, and NOTHING ships it.
     *
     * The hold needs no status of its own because `unrouted` already is one: the router resolves each group's code through the registry
     * and skips a group it cannot resolve, so the line waits for a person instead of being packed by the shop.
     */
    public function test_the_hold_rule_freezes_the_line_and_the_router_will_not_move_it(): void
    {
        config(['fulfillment.unknown_source' => WhatAnUnknownSourceDoes::HOLD]);
        $this->makeProduct(10);
        $this->makeOrder(1);

        $item = $this->capture(10, 'aramex');

        $this->assertNotNull($item);
        $this->assertSame('aramex', $item->provider, 'the line keeps the source it actually names, which is what a person has to see');
        $this->assertNull($item->mapping_id);
        $this->assertSame(ProviderFulfillmentItem::STATUS_UNROUTED, $item->status);

        app(FulfillmentRouter::class)->route(1);

        $this->assertSame(0, ProviderFulfillment::count(), 'nothing can be sent to a carrier nobody declared');
        $this->assertSame(ProviderFulfillmentItem::STATUS_UNROUTED, $item->refresh()->status, 'so the line is still waiting for a person');
    }

    /** An unrecognised setting is the default, so a typo in the environment cannot stop a checkout. */
    public function test_a_misspelt_rule_is_read_as_the_platform_runs_today(): void
    {
        config(['fulfillment.unknown_source' => 'hold_it_maybe']);
        $this->makeOrder(1);

        $this->assertSame(WhatAnUnknownSourceDoes::IN_HOUSE_TODAY, WhatAnUnknownSourceDoes::rule());
        $this->assertNull($this->capture(10, 'aramex'));
    }

    public function test_the_snapshot_records_the_market_the_line_was_sold_in(): void
    {
        $this->productsCanCarryAMarket();
        $this->makeProduct(10, null, [StorefrontCatalog::COLUMN => 'KW']);
        $this->makeOrder(1);

        $item = $this->capture(10, self::DECLARED);

        $this->assertSame('KW', $item->getAttribute(OwnershipHistory::MARKET));
        $this->assertTrue(OwnershipHistory::namesItsOwnMarket($item));
        $this->assertSame('KW', app(OwnershipHistory::class)->marketOf($item));
    }

    /** The order line's own copy of the product comes first, because that is the product as it was on the day: every other value here is. */
    public function test_the_market_comes_from_the_line_copy_before_the_product_row(): void
    {
        $this->productsCanCarryAMarket();
        $this->makeProduct(10, null, [StorefrontCatalog::COLUMN => 'AE']);
        $this->makeOrder(1);

        $item = app(OrderItemOwnershipSnapshotter::class)->capture(
            orderId: 1,
            orderDetailId: 1,
            product: ['id' => 10, 'name' => 'Item', 'fulfillment_provider' => self::DECLARED, 'variation' => '[]', StorefrontCatalog::COLUMN => 'KW'],
            variant: null,
            quantity: 1,
            unitPrice: 10.0,
            netAmount: 10.0,
            lineTax: 0.0,
            taxModel: 'include',
        );

        $this->assertSame('KW', $item->getAttribute(OwnershipHistory::MARKET), 'the copy is the product as it was, and the row is what it is now');
    }

    /** The written rule: a row that names no market is the default storefront's, because that is the only storefront there has ever been. */
    public function test_a_row_that_names_no_market_reads_as_the_default_storefront(): void
    {
        $this->makeProduct(10);
        $this->makeOrder(1);

        $item = $this->capture(10, self::DECLARED);

        $this->assertNull($item->getAttribute(OwnershipHistory::MARKET), 'nothing is invented for a product with no market of its own');
        $this->assertFalse(OwnershipHistory::namesItsOwnMarket($item));
        $this->assertSame('SA', app(OwnershipHistory::class)->marketOf($item), 'and the rule reads it as the market it was placed in');
    }

    public function test_the_snapshot_records_the_carrier_account_that_markets_shipments_run_against(): void
    {
        $this->productsCanCarryAMarket();
        $this->carriersCanHaveAnAccountPerCountry();
        $identity = app(ProviderRegistry::class)->identity(self::DECLARED);
        $connections = app(ProviderConnectionManager::class);
        $connections->save(provider: $identity, enabled: true, baseUrl: null, settings: []);
        $connections->save(provider: $identity, enabled: true, baseUrl: null, settings: [], country: 'KW');
        $this->makeProduct(10, null, [StorefrontCatalog::COLUMN => 'KW']);
        $this->makeProduct(11);
        $this->makeOrder(1);

        $kuwait = $this->capture(10, self::DECLARED, 1);
        $saudi = $this->capture(11, self::DECLARED, 2);

        $this->assertSame($connections->accountIdFor($identity, 'KW'), OwnershipHistory::accountIdOf($kuwait));
        $this->assertSame($connections->accountIdFor($identity), OwnershipHistory::accountIdOf($saudi));
        $this->assertNotSame(
            OwnershipHistory::accountIdOf($kuwait),
            OwnershipHistory::accountIdOf($saudi),
            'two markets of one carrier are two accounts, which is the whole reason the id is recorded',
        );
    }

    /** Dormant until the migration runs: the snapshot is byte for byte the row it has always been, and the checkout does not fail. */
    public function test_without_the_migration_the_snapshot_is_the_row_it_has_always_been(): void
    {
        (require database_path('migrations/' . self::MIGRATION))->down();
        $this->assertFalse(Schema::hasColumn(OwnershipHistory::TABLE, OwnershipHistory::MARKET));

        $this->makeProduct(10);
        $this->makeOrder(1);

        try {
            $item = $this->capture(10, self::DECLARED);

            $this->assertNotNull($item, 'the carrier is still a carrier');
            $this->assertSame(self::DECLARED, $item->provider);
            $this->assertArrayNotHasKey(OwnershipHistory::MARKET, $item->getAttributes());
            $this->assertArrayNotHasKey(OwnershipHistory::ACCOUNT, $item->getAttributes());
        } finally {
            // Tables persist across classes in one process, so what this test took away it puts back.
            $this->theSnapshotCanRecordItsMarket();
        }
    }

    private function productsCanCarryAMarket(): void
    {
        if (!Schema::hasColumn('products', StorefrontCatalog::COLUMN)) {
            (require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php'))->up();
        }
    }

    private function carriersCanHaveAnAccountPerCountry(): void
    {
        if (!Schema::hasColumn('integration_settings', 'country')) {
            (require database_path('migrations/2026_12_23_100000_add_country_to_provider_credentials_and_cities.php'))->up();
        }

        app()->forgetInstance(ProviderConnectionManager::class);
    }
}
