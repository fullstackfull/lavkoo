<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProductProviderChange;
use App\Models\ProviderProductMapping;
use App\Services\Fulfillment\ProductProviderAssignmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A product's fulfilment provider has to be able to serve the product's own market (C-01).
 *
 * The rule the plan settles on is one execution source per product per country storefront, and the row has carried the country since
 * `2026_11_25_100000_add_storefront_country_to_products`. What nothing compared was the two: a Kuwaiti product could be assigned to a
 * carrier that only operates in Saudi Arabia, and the refusal would arrive from the CARRIER, after a customer had bought the thing and
 * the dispatcher had tried to send it. H1-02 already wrote down where each carrier operates -- `fulfillment.providers.<code>.routes`,
 * read by {@see \App\Services\Fulfillment\Support\ProviderRouteSupport} -- so this is the assignment door asking the question the
 * dispatcher already asks, at the only moment where the answer is free.
 *
 * **It ships declaring exactly today's platform.** Every provider's default route list is `['SA:SA']`, so a Saudi product assigned to a
 * Saudi carrier is untouched, and a product of any other market is refused until somebody writes that carrier's real coverage down.
 * Which carrier serves which country, under which contract, is the owner's Q-33: this reads the answer, it does not invent one.
 *
 * **A product that records no market is not judged at all.** That is every row of a Saudi installation and every row written before the
 * column existed, so the check cannot refuse an assignment that works today.
 */
class ProductAssignmentCountryTest extends FulfillmentTestCase
{
    private const MIGRATION = '2026_11_25_100000_add_storefront_country_to_products.php';

    protected function setUp(): void
    {
        parent::setUp();

        // The real migration, so the column is exactly what production gets rather than a hand-built copy of it.
        if (!Schema::hasColumn('products', 'storefront_country')) {
            (require database_path('migrations/' . self::MIGRATION))->up();
        }
    }

    private function assignments(): ProductProviderAssignmentService
    {
        return app(ProductProviderAssignmentService::class);
    }

    private function approveMapping(int $productId, ProviderCode $provider): void
    {
        ProviderProductMapping::create([
            'provider' => $provider->value, 'product_id' => $productId, 'variant_key' => '',
            'external_id' => 'EXT-' . $productId, 'external_variant_id' => '', 'status' => MappingStatus::Approved,
        ]);
    }

    /** A product of one market, mapped to the carrier, so the only thing left to judge is the market. */
    private function productOfMarket(int $id, ?string $market, ProviderCode $provider): int
    {
        $this->makeProduct($id, overrides: $market === null ? [] : ['storefront_country' => $market]);
        $this->approveMapping($id, $provider);

        return $id;
    }

    private function providerOf(int $productId): ?string
    {
        return DB::table('products')->where('id', $productId)->value('fulfillment_provider');
    }

    /** What the carrier's contract says, written where H1-02 put it. */
    private function declareRoutes(ProviderCode $provider, array $routes): void
    {
        config(['fulfillment.providers.' . $provider->value . '.routes' => $routes]);
    }

    public function test_a_provider_that_does_not_serve_the_products_market_is_refused_with_a_named_reason(): void
    {
        $product = $this->productOfMarket(1, 'KW', ProviderCode::TorodPoint);

        $result = $this->assignments()->assign($product, 'torod_point', 'admin', 1);

        $this->assertFalse($result->ok, 'a Kuwaiti product may not be given a carrier that operates only in Saudi Arabia');
        $this->assertSame('fulfillment_provider_does_not_serve_the_products_market', $result->reasonKey);
        $this->assertStringContainsString('KW', (string) $result->detail, 'the refusal names the market, so the operator knows what to declare');
        $this->assertNull($this->providerOf($product), 'and nothing is written');
        $this->assertSame(0, ProductProviderChange::count(), 'a refusal is not a change');
    }

    /** The Saudi platform as it stands: the default route list is SA:SA, so nothing about a Saudi assignment moves. */
    public function test_a_saudi_product_is_assigned_exactly_as_it_always_was(): void
    {
        $product = $this->productOfMarket(1, 'SA', ProviderCode::TorodPoint);

        $this->assertTrue($this->assignments()->assign($product, 'torod_point', 'admin', 1)->ok);
        $this->assertSame('torod_point', $this->providerOf($product));
    }

    /** Every row of a single-market installation, and every row written before the column existed. */
    public function test_a_product_that_records_no_market_is_not_judged(): void
    {
        $product = $this->productOfMarket(1, null, ProviderCode::TorodPoint);

        $this->assertTrue($this->assignments()->assign($product, 'torod_point', 'admin', 1)->ok, 'an unmarked row is not refused');
        $this->assertSame('torod_point', $this->providerOf($product));
    }

    /** Once the owner writes the coverage down, the assignment goes through -- the gate is configuration, not code. */
    public function test_a_market_the_carrier_is_declared_for_is_allowed(): void
    {
        $this->declareRoutes(ProviderCode::TorodPoint, ['SA:SA', 'KW:KW']);
        $product = $this->productOfMarket(1, 'KW', ProviderCode::TorodPoint);

        $this->assertTrue($this->assignments()->assign($product, 'torod_point', 'admin', 1)->ok);
        $this->assertSame('torod_point', $this->providerOf($product));
    }

    /** A carrier declared for everything covers every market, which is what `*` is for. */
    public function test_a_carrier_that_covers_everything_serves_every_market(): void
    {
        $this->declareRoutes(ProviderCode::M5azn, ['*:*']);
        $product = $this->productOfMarket(1, 'BH', ProviderCode::M5azn);

        $this->assertTrue($this->assignments()->assign($product, 'm5azn', 'admin', 1)->ok);
    }

    /**
     * Two twins of one SKU in two markets hold their own providers, independently.
     *
     * This is the criterion the row-per-country design rests on, and it had no test: the evidence was the shape of the schema plus a
     * single writer. Here both rows are written through that writer, one after the other, and each is read back.
     */
    public function test_two_twins_of_one_sku_in_two_markets_hold_different_providers(): void
    {
        $this->declareRoutes(ProviderCode::M5azn, ['SA:SA', 'KW:KW']);
        $this->declareRoutes(ProviderCode::TorodPoint, ['SA:SA', 'KW:KW']);

        $saudi = $this->productOfMarket(1, 'SA', ProviderCode::TorodPoint);
        $kuwaiti = $this->productOfMarket(2, 'KW', ProviderCode::M5azn);

        $this->assertTrue($this->assignments()->assign($saudi, 'torod_point', 'admin', 1)->ok);
        $this->assertTrue($this->assignments()->assign($kuwaiti, 'm5azn', 'admin', 1)->ok);

        $this->assertSame('torod_point', $this->providerOf($saudi));
        $this->assertSame('m5azn', $this->providerOf($kuwaiti), 'the twin keeps its own source');

        // And changing one does not reach the other.
        $this->approveMapping($saudi, ProviderCode::M5azn);
        $this->assertTrue($this->assignments()->assign($saudi, 'm5azn', 'admin', 1)->ok);

        $this->assertSame('m5azn', $this->providerOf($saudi));
        $this->assertSame('m5azn', $this->providerOf($kuwaiti), 'the Kuwaiti row is untouched by a write to the Saudi one');
    }

    /** Taking a provider away is never refused for coverage: a product going back in-house needs no carrier at all. */
    public function test_returning_a_product_to_the_platforms_own_fulfilment_is_never_refused_for_coverage(): void
    {
        $this->declareRoutes(ProviderCode::TorodPoint, ['SA:SA', 'KW:KW']);
        $product = $this->productOfMarket(1, 'KW', ProviderCode::TorodPoint);
        $this->assertTrue($this->assignments()->assign($product, 'torod_point', 'admin', 1)->ok);

        $this->declareRoutes(ProviderCode::TorodPoint, ['SA:SA']);

        $this->assertTrue($this->assignments()->assign($product, null, 'admin', 1)->ok, 'in-house is not a carrier and has no coverage to check');
        $this->assertNull($this->providerOf($product));
    }
}
