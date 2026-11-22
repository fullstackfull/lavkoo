<?php

namespace Tests\Feature\Storefront;

use App\Events\RefundEvent;
use App\Models\User;
use App\Services\Storefront\StorefrontCatalog;
use App\Utils\OrderManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;

/**
 * D1-10: a customer's refund request, made through the API the app calls, carries the money and the company of the order it refunds, copied from the order's own record.
 * The amount is what it always was.
 */
class StorefrontRefundRecordTest extends ApiAddressTestCase
{
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;

    private const COD = '/api/v1/customer/order/place';

    private const REFUND = '/api/v1/customer/order/refund-store';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildTheSixStorefrontsWorld();
        (require database_path('migrations/2026_11_27_100100_add_legal_entity_to_orders_table.php'))->up();
        Event::fake([RefundEvent::class]);

        Schema::dropIfExists('refund_requests');
        Schema::create('refund_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_details_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('status')->nullable();
            $table->decimal('amount', 16, 2)->default(0);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->text('refund_reason')->nullable();
            $table->text('images')->nullable();
            $table->timestamps();
        });
        (require database_path('migrations/2026_12_03_100000_add_order_money_to_refund_tables.php'))->up();

        Schema::dropIfExists('order_details_rewards');
        Schema::create('order_details_rewards', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_details_id')->nullable();
            $table->string('reward_type')->nullable();
            $table->decimal('reward_amount', 16, 2)->default(0);
            $table->timestamps();
        });

        if (!Schema::hasColumn('order_details', 'refund_request')) {
            Schema::table('order_details', fn (Blueprint $table) => $table->integer('refund_request')->default(0));
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('refund_requests');
        Schema::dropIfExists('order_details_rewards');

        parent::tearDown();
    }

    /** The checkout world of the cart tests with the five storefronts other than the Saudi one open, each with one product priced in its own currency. */
    private function buildTheSixStorefrontsWorld(): void
    {
        $this->buildStorefrontCartWorld();
        $this->seedTwoCountries();
        (require database_path('migrations/2026_11_26_100000_add_storefront_snapshot_to_orders_table.php'))->up();

        foreach ([2 => ['AED', 'UAE Dirham'], 3 => ['KWD', 'Kuwaiti Dinar'], 4 => ['QAR', 'Qatari Riyal'], 5 => ['BHD', 'Bahraini Dinar'], 6 => ['OMR', 'Omani Rial']] as $id => [$code, $name]) {
            DB::table('currencies')->insert(['id' => $id, 'name' => $name, 'code' => $code, 'symbol' => $code, 'exchange_rate' => 1, 'status' => 1]);
        }
        cache()->forget('active_currency_list');

        $product = ['added_by' => 'admin', 'user_id' => 1, 'code' => 'SKU-1', 'variation' => '[]', 'images' => '[]', 'thumbnail' => 'x.png', 'category_ids' => '[]', 'discount' => 0, 'discount_type' => 'flat', 'choice_options' => '[]', 'tax' => 0, 'tax_type' => 'percent', 'tax_model' => 'exclude', 'current_stock' => 10];
        DB::table('products')->insert([
            $product + ['id' => 3, 'name' => 'Paracetamol KW', 'slug' => 'paracetamol-kw', 'unit_price' => 30, 'storefront_country' => 'KW'],
            $product + ['id' => 4, 'name' => 'Paracetamol QA', 'slug' => 'paracetamol-qa', 'unit_price' => 45, 'storefront_country' => 'QA'],
            $product + ['id' => 5, 'name' => 'Paracetamol BH', 'slug' => 'paracetamol-bh', 'unit_price' => 12.5, 'storefront_country' => 'BH'],
            $product + ['id' => 6, 'name' => 'Paracetamol OM', 'slug' => 'paracetamol-om', 'unit_price' => 14, 'storefront_country' => 'OM'],
        ]);

        $this->switchStorefrontsOn();
        config(['gcc.storefronts.currency.enabled' => true, 'gcc.storefronts.legal.grandfathered' => ['SA', 'AE', 'KW', 'QA', 'BH', 'OM']]);
        foreach (['QA', 'BH', 'OM', 'KW'] as $storefront) {
            config(["gcc.storefronts.countries.{$storefront}.active" => true]);
        }
        $this->approveNoTaxFor('KW', 'QA', 'BH', 'OM');
        $this->activateStorefrontFor('KW', 'QA', 'BH', 'OM');
        StorefrontCatalog::flush();
    }

    /** Places a cash-on-delivery order from the storefront with its one product and answers who ordered, the order and its line. */
    private function placeFrom(string $country, int $productId): array
    {
        if (getenv('DB_STRICT_TESTS') === '1' && !method_exists(OrderManager::class, 'countCouponUses')) {
            $this->markTestSkipped('The use count of a coupon is still the bare GROUP BY that a strict server refuses (PR 89 fixes it).');
        }

        $customer = $this->makeCustomer();
        $this->asCustomer($customer)->withHeader('X-Storefront', $country)->postJson('/api/v1/cart/add', ['id' => $productId, 'quantity' => 1])->assertOk()->assertJsonPath('status', 1);
        foreach (DB::table('carts')->where('customer_id', $customer->id)->pluck('cart_group_id') as $group) {
            DB::table('cart_shippings')->insert(['cart_group_id' => $group, 'shipping_method_id' => 1, 'shipping_cost' => 25]);
        }
        $this->asCustomer($customer)->withHeader('X-Storefront', $country)->getJson(self::COD)->assertOk();

        $orderId = (int) DB::table('orders')->where('customer_id', $customer->id)->value('id');

        return [$customer, $orderId, (int) DB::table('order_details')->where('order_id', $orderId)->value('id')];
    }

    private function askForRefund(User $customer, string $country, int $orderDetailsId): void
    {
        $this->asCustomer($customer)->withHeader('X-Storefront', $country)
            ->postJson(self::REFUND, ['order_details_id' => $orderDetailsId, 'amount' => 1, 'refund_reason' => 'wrong item'])
            ->assertOk();
    }

    public function test_a_refund_request_of_a_kuwaiti_order_carries_its_country_currency_rate_and_company(): void
    {
        [$customer, $orderId, $lineId] = $this->placeFrom('KW', 3);
        DB::table('orders')->where('id', $orderId)->update(['legal_entity_id' => 5]);

        $this->askForRefund($customer, 'KW', $lineId);

        $refund = DB::table('refund_requests')->first();
        $this->assertSame(['KW', 'KWD', 1.0, 5], [$refund->storefront_country, $refund->currency_code, (float) $refund->currency_rate, (int) $refund->legal_entity_id]);
        $this->assertEquals(30, $refund->amount, 'the amount is the order line\'s, as it always was');
        $this->assertSame($orderId, (int) $refund->order_id);
    }

    public function test_each_storefront_refund_names_its_own_currency(): void
    {
        foreach ([['AE', 2, 'AED'], ['QA', 4, 'QAR'], ['BH', 5, 'BHD'], ['OM', 6, 'OMR']] as [$country, $productId, $currency]) {
            [$customer, , $lineId] = $this->placeFrom($country, $productId);
            $this->askForRefund($customer, $country, $lineId);
        }

        $this->assertSame(
            ['AE' => 'AED', 'QA' => 'QAR', 'BH' => 'BHD', 'OM' => 'OMR'],
            DB::table('refund_requests')->orderBy('id')->pluck('currency_code', 'storefront_country')->all(),
        );
    }

    public function test_a_refund_request_of_an_order_that_recorded_nothing_is_made_as_it_always_was(): void
    {
        [$customer, $orderId, $lineId] = $this->placeFrom('SA', 1);
        DB::table('orders')->where('id', $orderId)->update(['storefront_country' => null, 'currency_code' => null, 'currency_rate' => null, 'legal_entity_id' => null]);

        $this->askForRefund($customer, 'SA', $lineId);

        $refund = DB::table('refund_requests')->first();
        $this->assertSame([null, null, null, null], [$refund->storefront_country, $refund->currency_code, $refund->currency_rate, $refund->legal_entity_id]);
        $this->assertEquals(100, $refund->amount);
    }

    public function test_a_refund_request_before_the_migration_is_made_without_the_columns(): void
    {
        (require database_path('migrations/2026_12_03_100000_add_order_money_to_refund_tables.php'))->down();
        [$customer, , $lineId] = $this->placeFrom('KW', 3);

        $this->askForRefund($customer, 'KW', $lineId);

        $this->assertSame(1, DB::table('refund_requests')->count());
        $this->assertFalse(Schema::hasColumn('refund_requests', 'currency_code'));
    }
}
