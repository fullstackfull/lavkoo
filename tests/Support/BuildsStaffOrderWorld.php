<?php

namespace Tests\Support;

use App\Contracts\Repositories\CustomerRepositoryInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;

/**
 * The orders, their lines, the refund requests and the customers the staff country scope of the order screens reads (GCC-14c, slice 2), on top of the product and
 * employee tables of BuildsStaffScopeWorld.
 *
 * An order carries no country: it follows from the products of its lines. So a test says which products an order holds, and that is all the country it has. A line whose
 * product id is not a product is a deleted product; a product saved with an empty country is a product without one (the column is NOT NULL, so that is how a product
 * ends up with none).
 *
 * Employees: the ones of BuildsStaffScopeWorld, plus 7 who holds the orders and the POS screens (role 5) and 8 who holds the POS only (role 6).
 */
trait BuildsStaffOrderWorld
{
    use BuildsStaffScopeWorld;

    protected function buildStaffOrderTables(): void
    {
        $this->buildStaffAdminTables();
        $this->buildStaffProductTables();
        $this->runStaffScopeMigration();
        $this->addStaffOrderRoles();

        foreach (['digital_product_variations', 'colors', 'stock_clearance_products', 'stock_clearance_setups', 'currencies', 'refund_statuses', 'order_details_rewards', 'delivery_men', 'refund_requests', 'order_edit_histories', 'order_status_histories', 'order_expected_delivery_histories', 'order_transactions', 'refund_transactions', 'orders', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::dropIfExists('order_details');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->unsignedBigInteger('referred_by')->nullable();
            $table->integer('loyalty_point')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        DB::table('users')->insert([['id' => 1, 'f_name' => 'Customer', 'l_name' => 'One', 'phone' => '500000001', 'email' => 'c1@example.test']]);

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('customer_type')->nullable();
            $table->string('payment_status')->default('unpaid');
            $table->string('order_status')->default('pending');
            $table->string('payment_method')->nullable();
            $table->string('transaction_ref')->nullable();
            $table->string('payment_by')->nullable();
            $table->text('payment_note')->nullable();
            $table->decimal('order_amount', 24, 2)->default(0);
            $table->decimal('init_order_amount', 24, 2)->default(0);
            $table->decimal('edit_due_amount', 24, 2)->default(0);
            $table->decimal('edit_return_amount', 24, 2)->default(0);
            $table->decimal('total_tax_amount', 24, 2)->default(0);
            $table->string('tax_type')->nullable();
            $table->string('tax_model')->nullable();
            $table->decimal('paid_amount', 24, 2)->default(0);
            $table->decimal('bring_change_amount', 24, 2)->default(0);
            $table->string('bring_change_amount_currency')->nullable();
            $table->decimal('admin_commission', 24, 2)->default(0);
            $table->boolean('is_pause')->default(false);
            $table->text('cause')->nullable();
            $table->string('shipping_address')->nullable();
            $table->string('discount_type')->nullable();
            $table->decimal('discount_amount', 24, 2)->default(0);
            $table->string('coupon_code')->nullable();
            $table->string('coupon_discount_bearer')->nullable();
            $table->string('shipping_responsibility')->nullable();
            $table->unsignedBigInteger('shipping_method_id')->nullable();
            $table->decimal('shipping_cost', 24, 2)->default(0);
            $table->boolean('is_shipping_free')->default(false);
            $table->string('order_group_id')->default('def-order-group');
            $table->string('verification_code')->nullable();
            $table->boolean('verification_status')->default(false);
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is')->default('admin');
            $table->text('shipping_address_data')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->timestamp('deliveryman_assigned_at')->nullable();
            $table->decimal('deliveryman_charge', 24, 2)->default(0);
            $table->date('expected_delivery_date')->nullable();
            $table->text('order_note')->nullable();
            $table->unsignedBigInteger('billing_address')->nullable();
            $table->text('billing_address_data')->nullable();
            $table->string('order_type')->default('default_type');
            $table->decimal('extra_discount', 24, 2)->default(0);
            $table->string('extra_discount_type')->nullable();
            $table->decimal('refer_and_earn_discount', 24, 2)->default(0);
            $table->string('free_delivery_bearer')->nullable();
            $table->boolean('checked')->default(false);
            $table->string('shipping_type')->nullable();
            $table->string('delivery_type')->nullable();
            $table->string('delivery_service_name')->nullable();
            $table->string('third_party_delivery_tracking_id')->nullable();
            $table->integer('edited_status')->default(0);
            $table->timestamps();
        });

        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->text('product_details')->nullable();
            $table->integer('qty')->default(1);
            $table->decimal('price', 24, 2)->default(10);
            $table->decimal('tax', 24, 2)->default(0);
            $table->decimal('discount', 24, 2)->default(0);
            $table->string('tax_model')->nullable();
            $table->string('discount_type')->nullable();
            $table->unsignedBigInteger('shipping_method_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->string('payment_status')->nullable();
            $table->string('digital_file_after_sell')->nullable();
            $table->string('variant')->nullable();
            $table->text('variation')->nullable();
            $table->integer('refund_request')->default(0);
            $table->boolean('is_stock_decreased')->default(false);
            $table->timestamp('refund_started_at')->nullable();
            $table->timestamps();
        });

        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_details_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('status')->default('pending');
            $table->integer('approved_count')->default(0);
            $table->integer('denied_count')->default(0);
            $table->decimal('amount', 24, 2)->default(10);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->text('refund_reason')->nullable();
            $table->text('images')->nullable();
            $table->text('approved_note')->nullable();
            $table->text('rejected_note')->nullable();
            $table->text('payment_info')->nullable();
            $table->string('change_by')->nullable();
            $table->timestamps();
        });

        Schema::create('order_edit_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->decimal('order_due_amount', 24, 2)->default(0);
            $table->decimal('order_return_amount', 24, 2)->default(0);
            $table->string('order_due_payment_status')->nullable();
            $table->string('order_return_payment_status')->nullable();
            $table->timestamps();
        });
        Schema::create('digital_product_variations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('variant_key')->nullable();
            $table->decimal('price', 24, 2)->default(0);
            $table->string('sku')->nullable();
            $table->string('file')->nullable();
            $table->timestamps();
        });
        Schema::create('colors', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->timestamps();
        });
        Schema::create('stock_clearance_setups', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active')->default(false);
            $table->date('duration_start_date')->nullable();
            $table->date('duration_end_date')->nullable();
            $table->string('offer_active_time')->nullable();
            $table->time('offer_active_range_start')->nullable();
            $table->time('offer_active_range_end')->nullable();
            $table->timestamps();
        });
        Schema::create('stock_clearance_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('setup_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->string('added_by')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_amount', 24, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('symbol')->nullable();
            $table->string('code')->nullable();
            $table->decimal('exchange_rate', 24, 6)->default(1);
            $table->boolean('status')->default(true);
        });
        DB::table('currencies')->insert([['id' => 1, 'name' => 'Saudi riyal', 'symbol' => 'SAR', 'code' => 'SAR', 'exchange_rate' => 1]]);
        DB::table('business_settings')->insert([['type' => 'system_default_currency', 'value' => '1']]);
        Schema::create('delivery_men', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('phone')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        DB::table('delivery_men')->insert([['id' => 1, 'f_name' => 'Courier', 'l_name' => 'One', 'phone' => '500000100', 'seller_id' => 0]]);
        Schema::create('order_details_rewards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('order_details_id')->nullable();
            $table->string('reward_type')->nullable();
            $table->decimal('reward_amount', 24, 2)->default(0);
            $table->boolean('reward_delivered')->default(false);
            $table->timestamps();
        });
        Schema::create('refund_statuses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('refund_request_id')->nullable();
            $table->string('change_by')->nullable();
            $table->string('change_by_id')->nullable();
            $table->string('status')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
        Schema::create('order_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->decimal('order_amount', 24, 2)->default(0);
            $table->decimal('seller_amount', 24, 2)->default(0);
            $table->decimal('admin_commission', 24, 2)->default(0);
            $table->decimal('admin_expense', 24, 2)->default(0);
            $table->string('received_by')->nullable();
            $table->string('status')->nullable();
            $table->decimal('delivery_charge', 24, 2)->default(0);
            $table->decimal('tax', 24, 2)->default(0);
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('seller_is')->nullable();
            $table->string('delivered_by')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('transaction_id')->nullable();
            $table->timestamps();
        });
        Schema::create('refund_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('payment_for')->nullable();
            $table->unsignedBigInteger('payer_id')->nullable();
            $table->unsignedBigInteger('payment_receiver_id')->nullable();
            $table->string('paid_by')->nullable();
            $table->string('paid_to')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_status')->nullable();
            $table->decimal('amount', 24, 2)->default(0);
            $table->string('transaction_type')->nullable();
            $table->unsignedBigInteger('order_details_id')->nullable();
            $table->string('refund_id')->nullable();
            $table->timestamps();
        });
    }

    /** The list of customers a screen offers is written in MySQL (IF, CONCAT) and is not what these tests are about: it is answered empty. */
    protected function answerNoCustomers(): void
    {
        $customers = Mockery::mock(app(CustomerRepositoryInterface::class))->makePartial();
        $customers->shouldReceive('getCustomerNameList')->andReturn(collect());
        $this->app->instance(CustomerRepositoryInterface::class, $customers);
    }

    /** The product search of the POS locates a keyword with LOCATE, a function MySQL has and SQLite has not. */
    protected function teachSqliteWhatMysqlKnows(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateFunction('LOCATE', static fn (?string $needle, ?string $haystack): int => ($position = strpos((string) $haystack, (string) $needle)) === false ? 0 : $position + 1, 2);
        }
    }

    /** The graphs and reports group by the date parts MySQL names (YEAR, MONTH, DAY, DAYNAME, DAYOFWEEK, DATE_FORMAT): SQLite has none of them. */
    protected function teachSqliteTheDateFunctionsOfMysql(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $pdo = DB::connection()->getPdo();
        $part = static fn (string $format): \Closure => static fn (?string $date): int|string|null => $date === null ? null : (ctype_alpha($format) && strlen($format) === 1 && in_array($format, ['Y', 'n', 'j', 'w'], true) ? (int) date($format, strtotime($date)) : date($format, strtotime($date)));
        $pdo->sqliteCreateFunction('YEAR', $part('Y'), 1);
        $pdo->sqliteCreateFunction('MONTH', $part('n'), 1);
        $pdo->sqliteCreateFunction('DAY', $part('j'), 1);
        $pdo->sqliteCreateFunction('DAYNAME', $part('l'), 1);
        $pdo->sqliteCreateFunction('DAYOFWEEK', static fn (?string $date): ?int => $date === null ? null : (int) date('w', strtotime($date)) + 1, 1);
        $pdo->sqliteCreateFunction('DATE_FORMAT', static fn (?string $date, string $format): ?string => $date === null ? null : date(strtr($format, ['%Y' => 'Y', '%y' => 'y', '%m' => 'm', '%d' => 'd', '%M' => 'F', '%D' => 'jS', '%W' => 'l', '%b' => 'M', '%a' => 'D', '%e' => 'j', '%H' => 'H', '%i' => 'i', '%s' => 's']), strtotime($date)), 2);
    }

    /** The roles of the employees who work with orders and the POS: the master admin and the catalog employees of BuildsStaffScopeWorld have none of these modules. */
    private function addStaffOrderRoles(): void
    {
        DB::table('admin_roles')->insert([
            ['id' => 5, 'name' => 'Orders and POS', 'module_access' => json_encode(['orders', 'pos']), 'status' => 1],
            ['id' => 6, 'name' => 'POS', 'module_access' => json_encode(['pos']), 'status' => 1],
        ]);
        DB::table('admins')->insert([
            ['id' => 7, 'name' => 'Orders and POS', 'email' => 'seven@example.test', 'admin_role_id' => 5],
            ['id' => 8, 'name' => 'POS only', 'email' => 'eight@example.test', 'admin_role_id' => 6],
        ]);
    }

    /**
     * One order that holds a line for each product given, in that order. A product id that is not a product is a deleted product, and no product at all is an order
     * without a line.
     *
     * @param list<int> $products
     */
    protected function insertStaffOrder(int $id, array $products, array $extra = []): void
    {
        DB::table('orders')->insert(array_merge([
            'id' => $id,
            'customer_id' => 1,
            'is_guest' => 0,
            'order_status' => 'pending',
            'payment_status' => 'unpaid',
            'payment_method' => 'cash_on_delivery',
            'order_amount' => 10 * max(1, count($products)),
            'seller_is' => 'admin',
            'seller_id' => 1,
            'order_type' => 'default_type',
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));

        foreach ($products as $productId) {
            DB::table('order_details')->insert([
                'order_id' => $id,
                'product_id' => $productId,
                'seller_id' => 1,
                'product_details' => json_encode(['name' => "Product {$productId}", 'product_type' => 'physical', 'variation' => '[]', 'choice_options' => '[]', 'colors' => '[]', 'unit_price' => 10, 'tax' => 0, 'tax_model' => 'exclude', 'minimum_order_qty' => 1, 'current_stock' => 5]),
                'delivery_status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** One refund request about the first line of an order that holds the product, or about no line at all when none is given. */
    protected function insertStaffRefund(int $id, int $orderId, ?int $aboutProduct, array $extra = []): void
    {
        $line = $aboutProduct === null ? null : DB::table('order_details')->where('order_id', $orderId)->where('product_id', $aboutProduct)->value('id');

        DB::table('refund_requests')->insert(array_merge([
            'id' => $id,
            'order_details_id' => $line,
            'customer_id' => 1,
            'status' => 'pending',
            'amount' => 10,
            'product_id' => $aboutProduct,
            'order_id' => $orderId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }

    /** One money record of an order (`order_transactions`), as the order is delivered and its seller is paid. */
    protected function insertStaffOrderTransaction(int $id, int $orderId, array $extra = []): void
    {
        DB::table('order_transactions')->insert(array_merge([
            'id' => $id,
            'order_id' => $orderId,
            'seller_id' => 1,
            'seller_is' => 'admin',
            'customer_id' => 1,
            'order_amount' => 100,
            'seller_amount' => 90,
            'admin_commission' => 10,
            'delivery_charge' => 5,
            'tax' => 2,
            'status' => 'disburse',
            'payment_method' => 'cash_on_delivery',
            // One second apart, the later id the later: the screens list the newest first, and rows with the same timestamp come back in whatever order the plan finds them (MariaDB changed it when the employee's filter was added).
            'created_at' => now()->subSeconds(max(0, 200 - $id)),
            'updated_at' => now(),
        ], $extra));
    }

    /** One money record of a refund (`refund_transactions`), about the first line of an order that holds the product, or about no line when none is given. */
    protected function insertStaffRefundTransaction(int $id, int $orderId, ?int $aboutProduct, array $extra = []): void
    {
        $line = $aboutProduct === null ? null : DB::table('order_details')->where('order_id', $orderId)->where('product_id', $aboutProduct)->value('id');

        DB::table('refund_transactions')->insert(array_merge([
            'id' => $id,
            'order_id' => $orderId,
            'order_details_id' => $line,
            'payment_for' => 'Refund Request',
            'payer_id' => 1,
            'payment_receiver_id' => 1,
            'paid_by' => 'admin',
            'paid_to' => 'customer',
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'amount' => 10,
            'transaction_type' => 'Refund',
            'refund_id' => (string) $id,
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }

    /** @return list<int> the ids of the lines of an order */
    protected function staffOrderLineIds(int $orderId): array
    {
        return DB::table('order_details')->where('order_id', $orderId)->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }
}
