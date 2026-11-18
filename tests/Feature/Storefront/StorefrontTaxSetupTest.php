<?php

namespace Tests\Feature\Storefront;

use App\Models\Product;
use App\Services\Storefront\StorefrontCurrency;
use App\Traits\OrderEditManager;
use App\Utils\OrderManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\TaxModule\app\Http\Controllers\SystemTaxVatSetupController;
use Modules\TaxModule\app\Models\SystemTaxSetup;
use Modules\TaxModule\app\Services\CalculateTaxService;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsSixStorefrontCheckoutWorld;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\BuildsStorefrontMoneyWorld;
use Tests\Support\BuildsTaxModuleWorld;

/**
 * GCC-05 and D1-07: a storefront with its own money is taxed by the setup of its own country, and by the platform's setup when it has none. The platform's setup is
 * the one no storefront owns, so a storefront's row can never become what the Saudi storefront, a panel or a queue reads. While the currency switch is off, and for the
 * default storefront, the tax is what it always was (the characterization is OrderTaxRecordedAtPlacementTest on main). The rates in the tests are examples, not rules.
 */
class StorefrontTaxSetupTest extends ApiAddressTestCase
{
    use BuildsSixStorefrontCheckoutWorld;
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;
    use BuildsStorefrontMoneyWorld;
    use BuildsTaxModuleWorld;

    private const COD = '/api/v1/customer/order/place';

    private const KUWAITI_VAT = 4;

    private const ZERO_RATE = 5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSixStorefrontCheckoutWorld();
        $this->createTaxModuleTables();
        $this->seedTheTaxRates();
        DB::table('taxes')->insert([
            ['id' => self::KUWAITI_VAT, 'name' => 'VAT of Kuwait', 'tax_rate' => 5, 'is_active' => 1, 'is_default' => 1],
            ['id' => self::ZERO_RATE, 'name' => 'Zero rate', 'tax_rate' => 0, 'is_active' => 1, 'is_default' => 1],
        ]);
    }

    /** Places a cash-on-delivery order from the storefront with its one product and answers the id of the order. */
    private function placeFrom(string $country, int $productId): int
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();

        $customer = $this->makeCustomer();
        $this->fillTheCartFrom(customer: $customer, country: $country, productId: $productId);
        $this->asCustomer($customer)->withHeader('X-Storefront', $country)->getJson(self::COD)->assertOk();

        return (int) DB::table('orders')->where('customer_id', $customer->id)->value('id');
    }

    /** @return array{type: ?string, model: ?string, tax: float, amount: float} */
    private function taxOfOrder(int $orderId): array
    {
        $order = DB::table('orders')->where('id', $orderId)->first();

        return ['type' => $order->tax_type, 'model' => $order->tax_model, 'tax' => round((float) $order->total_tax_amount, 3), 'amount' => round((float) $order->order_amount, 3)];
    }

    /** @return list<array{string, float, float, float, int}> name, rate, amount, before, setup of each row the order left in order_taxes */
    private function rowsOfOrder(int $orderId): array
    {
        return DB::table('order_taxes')->where('order_id', $orderId)->orderBy('id')->get()
            ->map(fn (object $row): array => [$row->tax_name, round((float) $row->tax_rate, 3), round((float) $row->tax_amount, 3), round((float) $row->before_tax_amount, 3), (int) $row->system_tax_setup_id])
            ->all();
    }

    private function setupSeenFrom(?string $country): ?int
    {
        $read = fn () => OrderManager::getTaxSystemType()['SystemTaxVat']?->id;

        return $country === null ? $read() : $this->inStorefront($country, $read);
    }

    /** What the panel edit computes for one line of 30 of an order of the storefront (null: an order that records none). */
    private function editTaxOfOrderOf(?string $country): array
    {
        $editor = new class {
            use OrderEditManager;
        };

        return $editor->calculateEditOrderTaxAmount(
            request: [],
            order: ['id' => 1, 'storefront_country' => $country, 'seller_is' => 'admin', 'seller_id' => 1],
            editedOrder: [['price' => 30, 'discount' => 0, 'qty' => 1, 'product_type' => 'physical', 'active_product' => ['id' => 3], 'product_id' => 3, 'seller_id' => 1, 'seller_is' => 'admin', 'variant' => '']],
            couponSummary: [],
            data: ['is_shipping_free' => 0, 'coupon_discount' => 0, 'free_delivery_discount' => 0, 'refer_and_earn_discount' => 0, 'shipping_cost' => 0, 'order_amount' => 30],
        );
    }

    // ---- the order -----------------------------------------------------------------------------------------------------------------------

    public function test_a_storefront_with_its_own_setup_is_taxed_by_it_and_its_rows_name_that_setup(): void
    {
        $platform = $this->openTheTaxSetup();
        $kuwait = $this->openTheTaxSetup(taxIds: [self::KUWAITI_VAT], country: 'KW');

        $orderId = $this->placeFrom('KW', 3);

        $this->assertSame(['type' => 'order_wise', 'model' => 'exclude', 'tax' => 1.5, 'amount' => 31.5], $this->taxOfOrder($orderId));
        $this->assertSame([['VAT of Kuwait', 5.0, 1.5, 30.0, $kuwait->id]], $this->rowsOfOrder($orderId));
        $this->assertNotSame($platform->id, $kuwait->id);
    }

    public function test_the_default_storefront_is_taxed_by_the_platform_setup_whatever_the_other_storefronts_hold(): void
    {
        $platform = $this->openTheTaxSetup();
        $this->openTheTaxSetup(taxIds: [self::KUWAITI_VAT], country: 'KW');
        $this->openTheTaxSetup(taxIds: [self::ZERO_RATE], country: 'AE');

        $orderId = $this->placeFrom('SA', 1);

        $this->assertSame(['type' => 'order_wise', 'model' => 'exclude', 'tax' => 20.0, 'amount' => 120.0], $this->taxOfOrder($orderId));
        $this->assertSame([['VAT', 15.0, 15.0, 100.0, $platform->id], ['Municipality fee', 5.0, 5.0, 100.0, $platform->id]], $this->rowsOfOrder($orderId));
    }

    public function test_a_storefront_with_no_setup_of_its_own_is_taxed_by_the_platform_setup(): void
    {
        $platform = $this->openTheTaxSetup(taxIds: [self::VAT]);
        $this->openTheTaxSetup(taxIds: [self::KUWAITI_VAT], country: 'KW');

        $orderId = $this->placeFrom('QA', 4);

        $this->assertSame(['type' => 'order_wise', 'model' => 'exclude', 'tax' => 6.75, 'amount' => 51.75], $this->taxOfOrder($orderId));
        $this->assertSame([['VAT', 15.0, 6.75, 45.0, $platform->id]], $this->rowsOfOrder($orderId));
    }

    public function test_a_setup_with_no_rate_is_how_a_storefront_says_it_charges_no_tax(): void
    {
        $this->openTheTaxSetup(taxIds: [self::VAT]);
        $kuwait = $this->openTheTaxSetup(taxIds: [], country: 'KW');

        $orderId = $this->placeFrom('KW', 3);

        $this->assertSame(['type' => 'order_wise', 'model' => 'exclude', 'tax' => 0.0, 'amount' => 30.0], $this->taxOfOrder($orderId));
        $this->assertSame([], $this->rowsOfOrder($orderId));
        $this->assertSame($kuwait->id, $this->setupSeenFrom('KW'));
    }

    public function test_the_included_flag_is_the_storefronts_own_and_the_platform_keeps_its_own(): void
    {
        $this->openTheTaxSetup();
        $this->openTheTaxSetup(taxIds: [self::KUWAITI_VAT], included: true, country: 'KW');

        $kuwaiti = $this->placeFrom('KW', 3);
        $saudi = $this->placeFrom('SA', 1);

        $this->assertSame(['type' => 'order_wise', 'model' => 'include', 'tax' => 0.0, 'amount' => 30.0], $this->taxOfOrder($kuwaiti));
        $this->assertSame([], $this->rowsOfOrder($kuwaiti));
        $this->assertSame(['type' => 'order_wise', 'model' => 'exclude', 'tax' => 20.0, 'amount' => 120.0], $this->taxOfOrder($saudi));
    }

    public function test_a_product_wise_setup_of_a_storefront_taxes_the_products_that_carry_a_tax(): void
    {
        $this->openTheTaxSetup();
        $kuwait = $this->openTheTaxSetup(type: 'product_wise', taxIds: [], country: 'KW');
        DB::table('taxables')->insert([
            'taxable_type' => Product::class, 'taxable_id' => 3, 'system_tax_setup_id' => $kuwait->id, 'tax_id' => self::KUWAITI_VAT, 'tax_for' => 'tax_for_orders',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Cache::flush();

        $orderId = $this->placeFrom('KW', 3);

        $this->assertSame(['type' => 'product_wise', 'model' => 'exclude', 'tax' => 1.5, 'amount' => 31.5], $this->taxOfOrder($orderId));
        $this->assertSame([['VAT of Kuwait', 5.0, 1.5, 30.0, $kuwait->id]], $this->rowsOfOrder($orderId));
    }

    public function test_a_setup_that_is_switched_off_or_is_not_the_default_is_ignored(): void
    {
        $platform = $this->openTheTaxSetup(taxIds: [self::VAT]);
        $kuwait = $this->openTheTaxSetup(taxIds: [self::KUWAITI_VAT], country: 'KW');

        $kuwait->update(['is_active' => 0]);
        $this->assertSame($platform->id, $this->setupSeenFrom('KW'));

        $kuwait->update(['is_active' => 1, 'is_default' => 0]);
        $this->assertSame($platform->id, $this->setupSeenFrom('KW'));

        $kuwait->update(['is_default' => 1]);
        $this->assertSame($kuwait->id, $this->setupSeenFrom('KW'));
    }

    // ---- what is the platform's ----------------------------------------------------------------------------------------------------------

    public function test_a_row_that_names_a_storefront_is_never_the_platform_setup_even_when_it_was_made_first(): void
    {
        $kuwait = $this->openTheTaxSetup(taxIds: [self::KUWAITI_VAT], country: 'KW');
        $platform = $this->openTheTaxSetup(taxIds: [self::VAT]);
        $this->assertLessThan($platform->id, $kuwait->id, 'the row of Kuwait comes first, so a lookup that did not tell them apart would return it');

        $this->assertSame($platform->id, $this->setupSeenFrom(null), 'a panel, a queue and a console command');
        $this->assertSame($platform->id, $this->setupSeenFrom('SA'), 'the default storefront');
        $this->assertSame($kuwait->id, $this->setupSeenFrom('KW'));
    }

    public function test_a_row_with_a_country_that_is_no_configured_storefront_stays_what_it_was(): void
    {
        $platform = $this->openTheTaxSetup(taxIds: [self::VAT], country: 'EG');

        $this->assertSame($platform->id, $this->setupSeenFrom(null));
        $this->assertSame($platform->id, $this->setupSeenFrom('KW'), 'with no row of its own a storefront reads the platform row, whatever country that carries');
    }

    // ---- the switch ----------------------------------------------------------------------------------------------------------------------

    public function test_nothing_changes_while_the_currency_switch_is_off(): void
    {
        config(['gcc.storefronts.currency.enabled' => false]);
        $platform = $this->openTheTaxSetup(taxIds: [self::VAT]);
        $this->openTheTaxSetup(taxIds: [self::KUWAITI_VAT], country: 'KW');

        $orderId = $this->placeFrom('KW', 3);

        $this->assertSame($platform->id, $this->setupSeenFrom('KW'));
        $this->assertSame(['type' => 'order_wise', 'model' => 'exclude', 'tax' => 4.5, 'amount' => 34.5], $this->taxOfOrder($orderId));
        $this->assertSame([['VAT', 15.0, 4.5, 30.0, $platform->id]], $this->rowsOfOrder($orderId));
    }

    // ---- the cache -----------------------------------------------------------------------------------------------------------------------

    public function test_each_storefront_keeps_its_own_cache_entry_and_the_platform_keeps_the_key_it_always_had(): void
    {
        $platform = $this->openTheTaxSetup(taxIds: [self::VAT]);
        $kuwait = $this->openTheTaxSetup(taxIds: [self::KUWAITI_VAT], country: 'KW');

        $this->setupSeenFrom('KW');
        $this->setupSeenFrom('SA');
        $this->setupSeenFrom(null);

        $this->assertTrue(Cache::has('tax_system_type_vendor_with_vat'), 'the key of the platform and of the default storefront is unchanged');
        $this->assertTrue(Cache::has('tax_system_type_vendor_with_vat_KW'));
        $this->assertSame($platform->id, Cache::get('tax_system_type_vendor_with_vat')['SystemTaxVat']->id);
        $this->assertSame($kuwait->id, Cache::get('tax_system_type_vendor_with_vat_KW')['SystemTaxVat']->id);
        $this->assertFalse(Cache::has('tax_system_type_vendor_with_vat_SA'));
    }

    public function test_changing_the_setup_of_a_storefront_reaches_its_cache_entry_at_once(): void
    {
        $this->openTheTaxSetup(taxIds: [self::VAT]);
        $kuwait = $this->openTheTaxSetup(taxIds: [self::KUWAITI_VAT], country: 'KW');
        $this->assertFalse($this->inStorefront('KW', fn () => OrderManager::getTaxSystemType()['is_included'] === 1));

        $kuwait->update(['is_included' => 1]);

        $this->assertTrue($this->inStorefront('KW', fn () => OrderManager::getTaxSystemType()['is_included'] === 1));
    }

    public function test_both_readers_of_the_setup_agree_for_a_storefront(): void
    {
        $platform = $this->openTheTaxSetup(taxIds: [self::VAT]);
        $kuwait = $this->openTheTaxSetup(taxIds: [self::KUWAITI_VAT], included: true, country: 'KW');

        $this->assertSame($kuwait->id, $this->inStorefront('KW', fn () => getTaxModuleSystemTypesConfig()['SystemTaxVat']->id));
        $this->assertSame(1, $this->inStorefront('KW', fn () => getTaxModuleSystemTypesConfig()['is_included']));
        $this->assertSame($platform->id, $this->inStorefront('SA', fn () => getTaxModuleSystemTypesConfig()['SystemTaxVat']->id));
        $this->assertSame($platform->id, getTaxModuleSystemTypesConfig()['SystemTaxVat']->id);
    }

    // ---- the panel and the order ---------------------------------------------------------------------------------------------------------

    public function test_a_panel_that_works_in_the_market_of_an_order_reads_the_setup_of_that_market(): void
    {
        $platform = $this->openTheTaxSetup(taxIds: [self::VAT]);
        $kuwait = $this->openTheTaxSetup(taxIds: [self::KUWAITI_VAT], country: 'KW');
        $currency = app(StorefrontCurrency::class);

        $inKuwait = $currency->within(country: 'KW', callback: fn () => OrderManager::getTaxSystemType()['SystemTaxVat']->id);

        $this->assertSame($kuwait->id, $inKuwait);
        $this->assertSame($platform->id, OrderManager::getTaxSystemType()['SystemTaxVat']->id, 'and the market in force is put back');
    }

    public function test_the_panel_edit_of_an_order_is_taxed_under_the_setup_the_order_was_placed_under(): void
    {
        $this->openTheTaxSetup(taxIds: [self::VAT]);
        $this->openTheTaxSetup(taxIds: [self::KUWAITI_VAT], country: 'KW');

        $kuwaiti = $this->editTaxOfOrderOf('KW');

        $this->assertSame(1.5, round((float) $kuwaiti['total_tax_amount'], 3));
        $this->assertSame(['VAT of Kuwait'], array_column($kuwaiti['applied_tax_cart_list'][0]['applied_tax_ids'], 'name'));
        foreach ([['SA', 'the default storefront'], ['QA', 'a storefront with no setup of its own'], [null, 'an order that records no storefront']] as [$country, $why]) {
            $this->assertSame(4.5, round((float) $this->editTaxOfOrderOf($country)['total_tax_amount'], 3), $why);
        }
    }

    public function test_the_panel_edit_puts_the_market_it_found_back(): void
    {
        $platform = $this->openTheTaxSetup(taxIds: [self::VAT]);
        $this->openTheTaxSetup(taxIds: [self::KUWAITI_VAT], country: 'KW');

        $this->editTaxOfOrderOf('KW');

        $this->assertSame($platform->id, OrderManager::getTaxSystemType()['SystemTaxVat']->id);
    }

    public function test_the_admin_screen_of_the_system_tax_setup_shows_the_platform_row_even_when_a_storefronts_row_has_the_lower_id(): void
    {
        $kuwait = $this->openTheTaxSetup(taxIds: [self::KUWAITI_VAT], country: 'KW');
        $platform = $this->openTheTaxSetup(taxIds: [self::VAT]);
        $this->assertLessThan($platform->id, $kuwait->id);

        $screen = app(SystemTaxVatSetupController::class)->index(Request::create('/'));

        $this->assertSame($platform->id, $screen->getData()['systemTaxVat']->id, 'the panel of the platform edits the platform setup, never a storefront\'s');
    }

    public function test_the_status_switch_of_the_admin_screen_toggles_the_platform_row_and_never_a_storefronts(): void
    {
        $kuwait = $this->openTheTaxSetup(taxIds: [self::KUWAITI_VAT], country: 'KW');
        $platform = $this->openTheTaxSetup(taxIds: [self::VAT]);

        $answer = app(SystemTaxVatSetupController::class)->vendorStatus(Request::create('/', 'GET', [], [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']));

        $this->assertSame($platform->id, $answer->getData()->id);
        $this->assertSame([0, 1], [$platform->fresh()->is_active, $kuwait->fresh()->is_active]);
    }

    public function test_the_modules_own_calculator_reads_the_platform_row_unless_it_is_given_a_country(): void
    {
        $this->openTheTaxSetup(taxIds: [self::KUWAITI_VAT], country: 'KW');
        $this->openTheTaxSetup(taxIds: [self::VAT]);

        $withNoCountry = CalculateTaxService::getCalculatedTax(amount: 100.0, productIds: []);
        $forKuwait = CalculateTaxService::getCalculatedTax(amount: 100.0, productIds: [], countryCode: 'KW');

        $this->assertSame([15.0, 5.0], [round((float) $withNoCountry['totalTaxPercent'], 3), round((float) $forKuwait['totalTaxPercent'], 3)]);
    }

    public function test_an_order_keeps_the_tax_it_was_placed_under_when_its_storefront_changes_its_setup(): void
    {
        $this->openTheTaxSetup();
        $kuwait = $this->openTheTaxSetup(taxIds: [self::KUWAITI_VAT], country: 'KW');
        $orderId = $this->placeFrom('KW', 3);
        $before = [$this->taxOfOrder($orderId), $this->rowsOfOrder($orderId)];

        DB::table('taxes')->where('id', self::KUWAITI_VAT)->update(['tax_rate' => 20]);
        $kuwait->update(['is_included' => 1, 'tax_ids' => []]);

        $this->assertSame($before, [$this->taxOfOrder($orderId), $this->rowsOfOrder($orderId)]);
        $this->assertSame(1, SystemTaxSetup::query()->where('country_code', 'KW')->count());
    }
}
