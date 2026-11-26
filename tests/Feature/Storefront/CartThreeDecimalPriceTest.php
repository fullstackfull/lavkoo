<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;

/**
 * M2-07's last unproved surface: a price in a three-decimal currency, through the CART.
 *
 * Saving such a price from the admin and reading it back on the catalogue were already proved -- `AdminProductMoneyTest` for the save and the
 * admin list, `PriceTilesPerMarketTest` for the tile. The cart was not, and could not have been: `CartManager::addToCart` writes
 * `$product->unit_price` verbatim, with no rounding of its own, so whether a dinar's third decimal survives is decided entirely by the column
 * it lands in. The real migration `2026_05_07_000001_update_all_decimal_columns_to_40_20` leaves `carts.price` at `DECIMAL(40,20)`, but every
 * test fixture built it narrower -- three different precisions across five fixtures, `decimal(16,2)` in most -- so a cart test written against
 * those fixtures would have lost the third decimal for a reason that exists only in the test world, and reported a defect the platform does not
 * have (or, worse, passed while asserting the rounded figure).
 *
 * So `test_the_cart_column_can_hold_what_the_platform_stores` is not ceremony: it is the guard that keeps the fixture honest, and it is the
 * reason the other cases here mean anything. Without it a later narrowing of the fixture would turn this file green on a false premise.
 */
class CartThreeDecimalPriceTest extends ApiAddressTestCase
{
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;

    private const DINAR_PRICE = 3.505;
    private const KUWAITI_PRODUCT = 7;
    private const ADD = '/api/v1/cart/add';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontCartWorld();
        $this->useMultiCurrencyPlatform();
        $this->seedAKuwaitiProduct();
    }

    public function test_the_cart_column_can_hold_what_the_platform_stores(): void
    {
        $this->assertSame(
            20,
            $this->scaleOfTheCartPriceColumn(),
            'the fixture must build carts.price at the precision the real migration leaves it, or a three-decimal cart test proves nothing',
        );
    }

    public function test_a_dinar_price_reaches_the_cart_with_its_third_decimal(): void
    {
        $this->addTheKuwaitiProduct();

        $this->assertEqualsWithDelta(
            self::DINAR_PRICE,
            (float) DB::table('carts')->where('product_id', self::KUWAITI_PRODUCT)->value('price'),
            0.0000001,
            'the dinar price was rounded on its way into the cart',
        );
    }

    public function test_the_third_decimal_is_not_quietly_the_second(): void
    {
        $this->addTheKuwaitiProduct();

        $stored = (float) DB::table('carts')->where('product_id', self::KUWAITI_PRODUCT)->value('price');

        $this->assertNotEqualsWithDelta(3.50, $stored, 0.0001, 'the price was truncated to two decimals');
        $this->assertNotEqualsWithDelta(3.51, $stored, 0.0001, 'the price was rounded up to two decimals');
    }

    public function test_the_quantity_multiplies_the_whole_price_and_not_a_rounded_one(): void
    {
        $this->addTheKuwaitiProduct(quantity: 3);

        $row = DB::table('carts')->where('product_id', self::KUWAITI_PRODUCT)->first();

        $this->assertSame(3, (int) $row->quantity);
        $this->assertEqualsWithDelta(
            self::DINAR_PRICE * 3,
            (float) $row->price * (int) $row->quantity,
            0.0000001,
            'three dinars-and-a-half-and-five-mils is 10.515, not 10.50 or 10.53',
        );
    }

    public function test_with_the_storefronts_off_the_cart_stores_what_it_always_did(): void
    {
        config(['gcc.storefronts.enabled' => false]);
        StorefrontCatalog::flush();

        $customer = $this->makeCustomer();
        $this->asCustomer($customer)->postJson(self::ADD, ['id' => self::KUWAITI_PRODUCT, 'quantity' => 1])->assertOk();

        $this->assertEqualsWithDelta(
            self::DINAR_PRICE,
            (float) DB::table('carts')->where('product_id', self::KUWAITI_PRODUCT)->value('price'),
            0.0000001,
            'the dormant path must store the product price unchanged, as it does today',
        );
    }

    private function addTheKuwaitiProduct(int $quantity = 1): void
    {
        $this->switchKuwaitOn();

        $this->asCustomer($this->makeCustomer())
            ->withHeader('X-Storefront', 'KW')
            ->postJson(self::ADD, ['id' => self::KUWAITI_PRODUCT, 'quantity' => $quantity])
            ->assertOk()
            ->assertJsonPath('status', 1);
    }

    /** Kuwait allowed to sell, on the same terms the shared helper gives the UAE, so that the sale gate is not what is under test. */
    private function switchKuwaitOn(): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.currency.enabled' => true,
            'gcc.storefronts.countries.KW.active' => true,
            'gcc.storefronts.legal.grandfathered' => ['SA', 'KW'],
        ]);
        $this->approveNoTaxFor('KW');
        $this->activateStorefrontFor('KW');
        StorefrontCatalog::flush();
    }

    private function seedAKuwaitiProduct(): void
    {
        DB::table('products')->insert([
            'id' => self::KUWAITI_PRODUCT, 'name' => 'Paracetamol KW', 'slug' => 'paracetamol-kw',
            'unit_price' => self::DINAR_PRICE, 'current_stock' => 10, 'storefront_country' => 'KW',
            'added_by' => 'admin', 'user_id' => 1, 'code' => 'SKU-KW', 'variation' => '[]', 'images' => '[]',
            'thumbnail' => 'x.png', 'category_ids' => '[]', 'discount' => 0, 'discount_type' => 'flat',
            'choice_options' => '[]', 'tax' => 0, 'tax_type' => 'percent', 'tax_model' => 'exclude',
        ]);
    }

    /**
     * The declared scale of `carts`.`price`, read from the engine rather than from the fixture's source, so that an engine which silently
     * narrows the column is caught as well as a fixture which declares it narrow. SQLite has no decimal type at all and stores the full
     * value, so there is nothing there to read and nothing to catch.
     */
    private function scaleOfTheCartPriceColumn(): int
    {
        $driver = DB::connection()->getDriverName();

        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('only a server declares a decimal scale; SQLite keeps the whole value');
        }

        return (int) DB::selectOne(
            'select numeric_scale as scale from information_schema.columns where table_schema = database() and table_name = ? and column_name = ?',
            ['carts', 'price'],
        )->scale;
    }

    private function useMultiCurrencyPlatform(): void
    {
        Schema::dropIfExists('currencies');
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('symbol')->nullable();
            $table->string('code')->nullable();
            $table->decimal('exchange_rate', 20, 8)->default(1);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        foreach (['SAR' => ['SAR', 3.75], 'KWD' => ['KD', 0.307]] as $code => [$symbol, $rate]) {
            DB::table('currencies')->insert(['name' => $code, 'symbol' => $symbol, 'code' => $code, 'exchange_rate' => $rate, 'status' => 1]);
        }

        DB::table('business_settings')->whereIn('type', ['currency_model', 'system_default_currency', 'decimal_point_settings'])->delete();
        DB::table('business_settings')->insert([
            ['type' => 'currency_model', 'value' => 'multi_currency'],
            ['type' => 'system_default_currency', 'value' => (string) DB::table('currencies')->where('code', 'SAR')->value('id')],
            ['type' => 'decimal_point_settings', 'value' => '2'],
        ]);
        Cache::flush();
    }
}
