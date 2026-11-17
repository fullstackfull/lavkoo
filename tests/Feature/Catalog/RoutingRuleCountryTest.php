<?php

namespace Tests\Feature\Catalog;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\ExecutionPolicy;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Models\FulfillmentRoutingRule;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Routing\RoutingContext;
use App\Services\SalesChannels\Routing\RoutingContextFactory;
use App\Services\SalesChannels\Routing\RoutingLine;
use App\Services\SalesChannels\Routing\RoutingRuleMatcher;
use App\Services\SalesChannels\Sync\ChannelOrderIngestor;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\Support\BuildsRemoteOrders;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\TestCase;

/**
 * D6: a routing rule on a SKU matches the SKU of the order line, which is our own product code. Once a code may repeat across countries, a rule
 * written for the Saudi SKU would also fire for the Kuwaiti line and choose the provider, location and execution policy of the wrong order.
 *
 * With the country-scoped codes on, a SKU condition also needs the product of the line to be in the country the rule is for: the country the rule
 * names, and for a rule that names none the default storefront, because every rule written before the switch was written when there was one
 * country. A line whose product (or whose product's country) is not known satisfies no SKU condition. A rule keyed by a product id is untouched.
 * With the switch off nothing changes.
 */
class RoutingRuleCountryTest extends TestCase
{
    use BuildsCodeScopeCatalog, BuildsRemoteOrders, BuildsSalesChannelSchema, CreatesChannelAccounts;

    private RoutingRuleMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSalesChannelSchema();
        $this->addStorefrontCountryColumn();
        $this->matcher = app(RoutingRuleMatcher::class);
    }

    protected function tearDown(): void
    {
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    /** @param list<RoutingLine> $lines */
    private function context(array $lines, string $destination = 'SA'): RoutingContext
    {
        return new RoutingContext(
            mappingId: 1, channel: 'trendyol', accountId: 1, mode: FulfillmentMode::MerchantManaged, status: ChannelOrderStatus::New, channelPaysCarrier: null, isCod: false,
            allowCod: false, country: $destination, city: null, orderTotal: '100', currentExecutionPolicy: null, alreadyDispatched: false, lines: $lines,
            accountPolicy: ExecutionPolicy::ManualReview,
        );
    }

    private function line(?string $sku, ?string $productCountry, ?int $productId = 10): RoutingLine
    {
        return new RoutingLine(
            lineId: 1, externalLineId: 'L1', productId: $productId, sku: $sku, quantity: 1, locationId: null, provider: null, stockConfirmed: true, cancelled: false,
            productCountry: $productCountry,
        );
    }

    /** @param array<string, mixed> $values */
    private function rule(array $values): FulfillmentRoutingRule
    {
        return new FulfillmentRoutingRule($values + ['name' => 'Rule', 'priority' => 100, 'is_active' => true, 'action' => 'route_to_provider']);
    }

    // ---- the switch off: today's answer ----------------------------------------------------------------------------------------

    public function test_with_the_switch_off_a_sku_rule_matches_the_line_sku_whatever_the_country(): void
    {
        $rule = $this->rule(['sku' => 'sku-100']);

        $this->assertTrue($this->matcher->matches($rule, $this->context([$this->line('SKU-100', 'KW')])));
        $this->assertTrue($this->matcher->matches($rule, $this->context([$this->line('SKU-100', null)])));
        $this->assertFalse($this->matcher->matches($rule, $this->context([$this->line('SKU-200', 'SA')])));
    }

    // ---- the switch on ---------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: ?string, 1: ?string, 2: bool}> the country the rule names, the country of the line's product, and whether the rule matches */
    public static function countryCases(): array
    {
        return [
            'a rule of no country matches a Saudi product (the default storefront)' => [null, 'SA', true],
            'a rule of no country does not match a Kuwaiti product' => [null, 'KW', false],
            'a rule of no country does not match a product of no known country' => [null, null, false],
            'a rule of Kuwait matches a Kuwaiti product' => ['KW', 'KW', true],
            'a rule of Kuwait does not match a Saudi product' => ['KW', 'SA', false],
            'a rule of Kuwait in the wrong letter case matches a Kuwaiti product' => ['kw', 'KW', true],
            'a rule of Saudi Arabia matches a Saudi product' => ['SA', 'SA', true],
            'a rule that names a country matches no product of unknown country' => ['KW', null, false],
        ];
    }

    #[DataProvider('countryCases')]
    public function test_with_the_switch_on_a_sku_rule_needs_the_product_of_the_line_to_be_in_its_country(?string $ruleCountry, ?string $productCountry, bool $matches): void
    {
        $this->scopeCodesByCountry();
        $rule = $this->rule(['sku' => 'SKU-100', 'country' => $ruleCountry]);

        $this->assertSame($matches, $this->matcher->matches($rule, $this->context([$this->line('SKU-100', $productCountry)], destination: $ruleCountry === null ? 'SA' : strtoupper($ruleCountry))));
    }

    public function test_the_destination_condition_of_a_rule_still_decides_on_its_own(): void
    {
        $this->scopeCodesByCountry();
        $rule = $this->rule(['sku' => 'SKU-100', 'country' => 'KW']);

        $this->assertFalse($this->matcher->matches($rule, $this->context([$this->line('SKU-100', 'KW')], destination: 'SA')), 'a Kuwaiti product going to Saudi Arabia is not the rule\'s order');
    }

    public function test_with_the_switch_on_a_rule_with_no_sku_is_not_asked_about_countries(): void
    {
        $this->scopeCodesByCountry();

        $this->assertTrue($this->matcher->matches($this->rule(['sales_channel' => 'trendyol']), $this->context([$this->line('SKU-100', 'KW')])));
    }

    public function test_with_the_switch_on_a_rule_keyed_by_a_product_id_is_untouched(): void
    {
        $this->scopeCodesByCountry();

        $this->assertTrue($this->matcher->matches($this->rule(['product_id' => 10]), $this->context([$this->line('SKU-100', 'KW', productId: 10)])));
        $this->assertFalse($this->matcher->matches($this->rule(['product_id' => 11]), $this->context([$this->line('SKU-100', 'KW', productId: 10)])));
    }

    public function test_with_the_switch_on_any_line_of_the_right_country_is_enough(): void
    {
        $this->scopeCodesByCountry();
        $rule = $this->rule(['sku' => 'SKU-100']);

        $this->assertTrue($this->matcher->matches($rule, $this->context([$this->line('SKU-100', 'KW'), new RoutingLine(2, 'L2', 11, 'SKU-100', 1, null, null, true, false, 'SA')])));
    }

    public function test_a_missing_column_acts_as_off(): void
    {
        $this->scopeCodesByCountry();
        $this->dropStorefrontCountryColumn();

        $this->assertTrue($this->matcher->matches($this->rule(['sku' => 'SKU-100']), $this->context([$this->line('SKU-100', 'KW')])));
    }

    // ---- what the context says about a line -------------------------------------------------------------------------------------

    private function productOfCountry(int $id, string $country): void
    {
        DB::table('products')->insert(['id' => $id, 'name' => 'Product ' . $id, 'code' => 'SKU-100', 'barcode' => null, 'added_by' => 'admin', 'product_type' => 'physical', 'variation' => '[]', 'storefront_country' => $country]);
    }

    private function contextOfAnOrderFor(int $productId): RoutingContext
    {
        $account = $this->trendyolAccount();
        SalesChannelProductMapping::create(['account_id' => $account->id, 'product_id' => $productId, 'listing_key' => 'SKU-100', 'external_sku' => 'SKU-100', 'external_barcode' => 'SKU-100', 'status' => 'mapped']);
        $order = $this->remoteOrder('ORD-1', '900', ChannelOrderStatus::New, [$this->remoteLine('L1', 'SKU-100', 'SKU-100', 1, '100')], overrides: ['fulfillmentChannelRaw' => 'seller_pays', 'fulfillmentMode' => FulfillmentMode::MerchantManaged]);
        $mapping = app(ChannelOrderIngestor::class)->ingest($account, $order)->mapping;

        return app(RoutingContextFactory::class)->for($mapping->refresh());
    }

    public function test_with_the_switch_on_the_context_tells_the_country_of_the_product_of_each_line(): void
    {
        $this->scopeCodesByCountry();
        $this->productOfCountry(10, 'KW');

        $line = $this->contextOfAnOrderFor(10)->lines[0];

        $this->assertSame(10, $line->productId);
        $this->assertSame('KW', $line->productCountry);
    }

    public function test_with_the_switch_on_a_product_without_a_country_is_told_as_unknown(): void
    {
        $this->scopeCodesByCountry();
        $this->productOfCountry(10, '');

        $this->assertNull($this->contextOfAnOrderFor(10)->lines[0]->productCountry);
    }

    public function test_with_the_switch_off_the_context_asks_nothing_and_says_nothing(): void
    {
        $this->productOfCountry(10, 'KW');

        $this->assertNull($this->contextOfAnOrderFor(10)->lines[0]->productCountry);
    }

    public function test_the_recorded_inputs_name_the_country_only_when_it_was_known(): void
    {
        $withCountry = $this->context([$this->line('SKU-100', 'KW')])->toInputs()['lines'][0];
        $without = $this->context([$this->line('SKU-100', null)])->toInputs()['lines'][0];

        $this->assertSame('KW', $withCountry['product_country']);
        $this->assertArrayNotHasKey('product_country', $without, 'a decision recorded with the switch off reads exactly as before');
    }
}
