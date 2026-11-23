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
use App\Services\Storefront\ProductCountry;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\Support\BuildsCountryMatrix;
use Tests\Support\BuildsRemoteOrders;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\TestCase;

/**
 * D6 across the six countries: a routing rule on a SKU matches the order line's SKU, which is our own product code, only when the product of the line
 * is in the country the rule names, or in the default storefront's when the rule names none. A rule that names a country is also for orders going to
 * that country, and both must hold. With the switch off a SKU rule matches the SKU whatever the country, as today.
 */
class SixCountryRoutingRuleTest extends TestCase
{
    use BuildsCodeScopeCatalog, BuildsCountryMatrix, BuildsRemoteOrders, BuildsSalesChannelSchema, CreatesChannelAccounts;

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

    private function context(?string $productCountry, string $destination): RoutingContext
    {
        return new RoutingContext(
            mappingId: 1, channel: 'trendyol', accountId: 1, mode: FulfillmentMode::MerchantManaged, status: ChannelOrderStatus::New, channelPaysCarrier: null, isCod: false,
            allowCod: false, country: $destination, city: null, orderTotal: '100', currentExecutionPolicy: null, alreadyDispatched: false,
            lines: [new RoutingLine(lineId: 1, externalLineId: 'L1', productId: 10, sku: 'SKU-100', quantity: 1, locationId: null, provider: null, stockConfirmed: true, cancelled: false, productCountry: $productCountry)],
            accountPolicy: ExecutionPolicy::ManualReview,
        );
    }

    private function rule(?string $country): FulfillmentRoutingRule
    {
        return new FulfillmentRoutingRule(['name' => 'Rule', 'priority' => 100, 'is_active' => true, 'action' => 'route_to_provider', 'sku' => 'sku-100', 'country' => $country]);
    }

    public function test_with_the_switch_on_a_rule_of_a_country_matches_the_product_of_that_country_going_there_and_no_other_product(): void
    {
        $this->scopeCodesByCountry();
        $failures = [];
        foreach ($this->matrixCountries() as $ruleCountry) {
            foreach ($this->matrixCountries() as $productCountry) {
                $matches = $this->matcher->matches($this->rule($ruleCountry), $this->context($productCountry, destination: $ruleCountry));
                $this->matrixCase($failures, $ruleCountry === $productCountry, $matches, "a rule of {$ruleCountry}, a line whose product is in {$productCountry}, going to {$ruleCountry}");
            }
            $this->matrixCase($failures, false, $this->matcher->matches($this->rule($ruleCountry), $this->context(null, destination: $ruleCountry)), "a rule of {$ruleCountry}, a product of no known country");
            foreach (array_diff($this->matrixCountries(), [$ruleCountry]) as $destination) {
                $this->matrixCase($failures, false, $this->matcher->matches($this->rule($ruleCountry), $this->context($ruleCountry, destination: $destination)), "a rule of {$ruleCountry}, its own product going to {$destination}: the destination decides too");
            }
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_with_the_switch_on_a_rule_of_no_country_matches_the_default_storefronts_product_only(): void
    {
        $this->scopeCodesByCountry();
        $default = ProductCountry::defaultCountry();
        $failures = [];
        foreach ($this->matrixCountries() as $productCountry) {
            foreach ($this->matrixCountries() as $destination) {
                $this->matrixCase($failures, $productCountry === $default, $this->matcher->matches($this->rule(null), $this->context($productCountry, destination: $destination)), "a rule of no country, a product of {$productCountry}, going to {$destination} (the default storefront is {$default})");
            }
        }
        $this->matrixCase($failures, false, $this->matcher->matches($this->rule(null), $this->context(null, destination: $default)), 'a rule of no country, a product of no known country');

        $this->assertMatrixHolds($failures);
    }

    public function test_a_rule_of_no_country_follows_the_default_storefront(): void
    {
        $this->scopeCodesByCountry();
        $failures = [];
        foreach ($this->matrixCountries() as $default) {
            config(["gcc.storefronts.countries.{$default}.active" => true, 'gcc.storefronts.default_country' => $default]);
            foreach ($this->matrixCountries() as $productCountry) {
                $this->matrixCase($failures, $productCountry === $default, $this->matcher->matches($this->rule(null), $this->context($productCountry, destination: $default)), "a rule of no country, a product of {$productCountry}, the default storefront is {$default}");
            }
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_with_the_switch_off_a_sku_rule_matches_the_sku_whatever_the_country_of_the_product(): void
    {
        $failures = [];
        foreach ([...$this->matrixCountries(), null] as $productCountry) {
            foreach ([null, ...$this->matrixCountries()] as $ruleCountry) {
                $destination = $ruleCountry ?? 'SA';
                $this->matrixCase($failures, true, $this->matcher->matches($this->rule($ruleCountry), $this->context($productCountry, destination: $destination)), 'a rule of ' . ($ruleCountry ?? 'no country') . ', a product of ' . ($productCountry ?? 'no known country') . ': the old rule');
            }
        }

        $this->assertMatrixHolds($failures);
    }

    // ---- what the context says about a line --------------------------------------------------------------------------------------

    private function contextOfAnOrderFor(int $productId, int $number): RoutingContext
    {
        $account = $this->trendyolAccount(sellerId: 'seller-' . $number);
        SalesChannelProductMapping::create(['account_id' => $account->id, 'product_id' => $productId, 'listing_key' => 'SKU-100', 'external_sku' => 'SKU-100', 'external_barcode' => 'SKU-100', 'status' => 'mapped']);
        $order = $this->remoteOrder('ORD-' . $number, '900' . $number, ChannelOrderStatus::New, [$this->remoteLine('L1', 'SKU-100', 'SKU-100', 1, '100')], overrides: ['fulfillmentChannelRaw' => 'seller_pays', 'fulfillmentMode' => FulfillmentMode::MerchantManaged]);
        $mapping = app(ChannelOrderIngestor::class)->ingest($account, $order)->mapping;

        return app(RoutingContextFactory::class)->for($mapping->refresh());
    }

    public function test_the_context_tells_the_country_of_the_product_of_each_line_only_while_the_switch_is_on(): void
    {
        $failures = [];
        foreach ([true, false] as $on) {
            if ($on) {
                $this->scopeCodesByCountry();
            } else {
                $this->scopeCodesByCountry(on: false);
            }
            foreach ($this->matrixCountries() as $index => $country) {
                $productId = 100 + $index + ($on ? 0 : 50);
                DB::table('products')->insert(['id' => $productId, 'name' => "Box {$country}", 'code' => 'SKU-100', 'added_by' => 'admin', 'product_type' => 'physical', 'variation' => '[]', 'storefront_country' => $country]);

                $line = $this->contextOfAnOrderFor($productId, $productId)->lines[0];

                $this->matrixCase($failures, true, $line->productCountry === ($on ? $country : null), "the product of {$country}, the switch " . ($on ? 'on' : 'off') . ': the line says ' . json_encode($line->productCountry));
            }
        }

        $this->assertMatrixHolds($failures);
    }
}
