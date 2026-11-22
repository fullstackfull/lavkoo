<?php

namespace Tests\Feature\Storefront;

use App\Traits\OrderEditManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsStorefrontMoneyWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * GCC-05, slice 5: a panel sees every country, so an edit of an order of one of them compared its amounts with the platform's minimum order and
 * free-delivery threshold (in riyals), priced its shipping from the platform's tables and printed the platform's symbol. The three reads now run in
 * the money of the order: the storefront its record names, or the default storefront for an order that has none, whichever storefront the editor's
 * own request is served from.
 */
class StorefrontOrderEditMoneyTest extends TestCase
{
    use BuildsStorefrontMoneyWorld;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildStorefrontMoneyWorld();
        Schema::dropIfExists('shipping_types');
        Schema::dropIfExists('shipping_methods');
        Schema::create('shipping_types', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id')->default(0);
            $table->string('shipping_type')->nullable();
            $table->timestamps();
        });
        Schema::create('shipping_methods', function (Blueprint $table): void {
            $table->id();
            $table->decimal('cost', 20, 6)->default(0);
        });
        DB::table('shipping_types')->insert(['seller_id' => 0, 'shipping_type' => 'order_wise']);
        DB::table('business_settings')->insert([
            ['type' => 'minimum_order_amount_status', 'value' => '1'],
            ['type' => 'minimum_order_amount_by_seller', 'value' => '0'],
            ['type' => 'free_delivery_status', 'value' => '1'],
            ['type' => 'free_delivery_responsibility', 'value' => 'admin'],
            ['type' => 'shipping_method', 'value' => 'inhouse_shipping'],
        ]);
        clearWebConfigCacheKeys();
        Cache::put(CACHE_IN_HOUSE_SHOP_TABLE, ['name' => 'In-house shop'], 3600);
    }

    protected function tearDown(): void
    {
        Cache::forget(CACHE_IN_HOUSE_SHOP_TABLE);
        Schema::dropIfExists('shipping_methods');
        Schema::dropIfExists('shipping_types');
        $this->dropStorefrontMoneyWorld();
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function editor(): object
    {
        return new class {
            use OrderEditManager;
        };
    }

    /** @return array<string, mixed> an order of the shop itself */
    private function order(?string $country): array
    {
        return ['id' => 1, 'seller_is' => 'admin', 'seller_id' => 1, 'storefront_country' => $country, 'shipping_cost' => 25, 'shipping_method_id' => null];
    }

    private function minimumOrder(?string $country, float $total): array
    {
        return $this->editor()->checkEditOrderMinimumOrderAmount(order: $this->order($country), totalCartAmount: $total, data: ['order_request_from' => 'panel']);
    }

    public function test_an_order_of_a_storefront_with_its_own_currency_meets_that_storefronts_minimum_not_the_platforms(): void
    {
        $this->giveStorefrontAmounts(country: 'KW', currency: 'KWD', amounts: ['minimum_order_amount' => '5.000']);

        $below = $this->minimumOrder(country: 'KW', total: 4.5);
        $equal = $this->minimumOrder(country: 'KW', total: 5.0);

        $this->assertSame('error', $below['status']);
        $this->assertStringContainsString('KD', $below['message'], 'the message prints the order\'s currency');
        $this->assertStringContainsString('5.000', $below['message'], 'with its three decimals, and the amount as given');
        $this->assertSame('success', $equal['status'], 'equal passes; the platform minimum of 100 is not what it is compared with');
        $this->assertSame('5.000', (string) $equal['minimum_order_amount']);
    }

    #[DataProvider('storefrontsWithTheirOwnCurrency')]
    public function test_each_storefront_with_its_own_currency_meets_its_own_minimum(string $country, string $currency): void
    {
        $this->giveStorefrontAmounts(country: $country, currency: $currency, amounts: ['minimum_order_amount' => '40.5']);

        $this->assertSame('error', $this->minimumOrder(country: $country, total: 40.0)['status']);
        $this->assertSame('success', $this->minimumOrder(country: $country, total: 40.5)['status']);
    }

    public function test_a_storefront_that_was_given_no_minimum_has_none_on_an_edit(): void
    {
        $this->assertSame('success', $this->minimumOrder(country: 'KW', total: 0.5)['status']);
    }

    public function test_an_order_of_the_default_storefront_and_an_order_with_no_record_use_the_platform_minimum_wherever_the_editor_is_served_from(): void
    {
        $this->giveStorefrontAmounts(country: 'KW', currency: 'KWD', amounts: ['minimum_order_amount' => '5.000']);

        $this->assertSame('error', $this->minimumOrder(country: 'SA', total: 50.0)['status']);
        $this->assertSame('success', $this->minimumOrder(country: 'SA', total: 100.0)['status'], 'equal passes');
        $this->assertSame('error', $this->minimumOrder(country: null, total: 50.0)['status'], 'an order with no record is in the platform\'s money');
        $this->assertSame('error', $this->inStorefront('KW', fn (): array => $this->minimumOrder(country: null, total: 50.0))['status'], 'and an editor served from /kw does not change that');
        $this->assertSame('success', $this->inStorefront('KW', fn (): array => $this->minimumOrder(country: 'SA', total: 100.0))['status']);
    }

    public function test_the_money_of_the_order_is_in_force_only_for_the_read_and_the_editors_own_is_put_back(): void
    {
        $this->giveStorefrontAmounts(country: 'KW', currency: 'KWD', amounts: ['minimum_order_amount' => '5.000']);

        $this->minimumOrder(country: 'KW', total: 4.5);

        $this->assertSame('100', getWebConfig('minimum_order_amount'));
        $this->assertSame('multi_currency', getWebConfig('currency_model'));
        $this->assertSame('5.000', $this->inStorefront('KW', fn () => getWebConfig('minimum_order_amount')));
    }

    public function test_nothing_changes_while_the_switch_is_off(): void
    {
        config(['gcc.storefronts.currency.enabled' => false]);
        $this->giveStorefrontAmounts(country: 'KW', currency: 'KWD', amounts: ['minimum_order_amount' => '5.000']);

        $this->assertSame('error', $this->minimumOrder(country: 'KW', total: 50.0)['status'], 'the platform minimum stands');
    }

    public function test_the_free_delivery_threshold_of_an_order_is_its_storefronts_own(): void
    {
        $this->giveStorefrontAmounts(country: 'KW', currency: 'KWD', amounts: ['free_delivery_over_amount' => '50.000']);
        $items = [['price' => 30, 'discount' => 0, 'qty' => 1]];

        $kuwait = $this->editor()::checkFreeDeliveryOrderAmountArray(order: $this->order('KW'), editedOrder: $items);
        $saudi = $this->editor()::checkFreeDeliveryOrderAmountArray(order: $this->order('SA'), editedOrder: $items);
        $noRecord = $this->inStorefront('KW', fn (): array => $this->editor()::checkFreeDeliveryOrderAmountArray(order: $this->order(null), editedOrder: $items));

        $this->assertEquals(['status' => 1, 'amount' => 50.0, 'amount_need' => 20.0], ['status' => $kuwait['status'], 'amount' => $kuwait['amount'], 'amount_need' => $kuwait['amount_need']]);
        $this->assertEquals(['status' => 1, 'amount' => 500.0, 'amount_need' => 470.0], ['status' => $saudi['status'], 'amount' => $saudi['amount'], 'amount_need' => $saudi['amount_need']]);
        $this->assertEquals(500.0, $noRecord['amount'], 'an order with no record is in the platform\'s money');
    }

    public function test_a_storefront_that_was_given_no_free_delivery_threshold_has_no_free_delivery_on_an_edit(): void
    {
        $result = $this->editor()::checkFreeDeliveryOrderAmountArray(order: $this->order('OM'), editedOrder: [['price' => 30, 'discount' => 0, 'qty' => 1]]);

        $this->assertSame(0, $result['status']);
        $this->assertEquals(0, $result['amount']);
    }

    public function test_shipping_of_an_order_of_a_storefront_with_its_own_currency_is_the_products_never_the_stored_order_figure_or_a_platform_table(): void
    {
        $this->assertEquals(25, $this->editor()::getEditOrderTotalShippingCost(order: $this->order('SA'), editedOrder: []), 'the default storefront keeps the order wise figure');
        $this->assertEquals(25, $this->editor()::getEditOrderTotalShippingCost(order: $this->order(null), editedOrder: []));
        $this->assertEquals(0, $this->editor()::getEditOrderTotalShippingCost(order: $this->order('KW'), editedOrder: []), 'product wise with no physical line costs nothing');
        $this->assertEquals(25, $this->editor()::getEditOrderTotalShippingCost(order: $this->order('SA'), editedOrder: []), 'and the next read is back in the platform money');
    }
}
