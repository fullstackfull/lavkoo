<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\Storefront\StorefrontCatalog;
use App\Utils\OrderManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The six storefronts, each with one product priced in its own currency, and the refund tables a customer's refund request is written to.
 *
 * Every order here is placed through the API the app calls and every refund is asked for through the route the app calls, so what a refund row holds is
 * what the application put there. Extracted from Tests\Feature\Storefront\StorefrontRefundRecordTest so that the refund tests of the order's money and of
 * the payment account build the same shop once.
 */
trait BuildsStorefrontRefundWorld
{
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;

    protected const PLACE_ORDER = '/api/v1/customer/order/place';

    protected const ASK_FOR_REFUND = '/api/v1/customer/order/refund-store';

    /** The checkout world of the cart tests with the five storefronts other than the Saudi one open, each with one product priced in its own currency. */
    protected function buildTheSixStorefrontsWorld(): void
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
        // Being switched on in the configuration is not leave to sell: a storefront sells once its tax is decided and a person has made it
        // active, both of which are recorded rather than configured (StorefrontSellingGate). switchStorefrontsOn() does this for the Emirates
        // and the Saudi storefront is active without a record, so these are the other four; without them every order and every refund of
        // those storefronts is refused with 422 by the sale gate, which is not what any test using this world is about. The same two lines,
        // for the same reason, are in BuildsSixStorefrontCheckoutWorld.
        $this->approveNoTaxFor('KW', 'QA', 'BH', 'OM');
        $this->activateStorefrontFor('KW', 'QA', 'BH', 'OM');
        StorefrontCatalog::flush();
    }

    /** The refund tables of the installed dump, and the migrations that put the order's money on them. */
    protected function buildTheRefundTables(): void
    {
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

    protected function dropTheRefundTables(): void
    {
        Schema::dropIfExists('refund_requests');
        Schema::dropIfExists('order_details_rewards');
    }

    /** Places a cash-on-delivery order from the storefront with its one product and answers who ordered, the order and its line. */
    protected function placeFrom(string $country, int $productId): array
    {
        if (getenv('DB_STRICT_TESTS') === '1' && !method_exists(OrderManager::class, 'countCouponUses')) {
            $this->markTestSkipped('The use count of a coupon is still the bare GROUP BY that a strict server refuses (PR 89 fixes it).');
        }

        $customer = $this->makeCustomer();
        $this->fillTheCart($customer, $country, $productId);
        $this->asCustomer($customer)->withHeader('X-Storefront', $country)->getJson(self::PLACE_ORDER)->assertOk();

        $orderId = (int) DB::table('orders')->where('customer_id', $customer->id)->value('id');

        return [$customer, $orderId, (int) DB::table('order_details')->where('order_id', $orderId)->value('id')];
    }

    /** One product of the storefront in the customer's cart, with a shipping method chosen for each group, through the routes the app calls. */
    protected function fillTheCart(User $customer, string $country, int $productId): void
    {
        $this->asCustomer($customer)->withHeader('X-Storefront', $country)->postJson('/api/v1/cart/add', ['id' => $productId, 'quantity' => 1])->assertOk()->assertJsonPath('status', 1);

        foreach (DB::table('carts')->where('customer_id', $customer->id)->pluck('cart_group_id') as $group) {
            DB::table('cart_shippings')->insert(['cart_group_id' => $group, 'shipping_method_id' => 1, 'shipping_cost' => 25]);
        }
    }

    protected function askForRefund(User $customer, string $country, int $orderDetailsId): void
    {
        $this->asCustomer($customer)->withHeader('X-Storefront', $country)
            ->postJson(self::ASK_FOR_REFUND, ['order_details_id' => $orderDetailsId, 'amount' => 1, 'refund_reason' => 'wrong item'])
            ->assertOk();
    }
}
