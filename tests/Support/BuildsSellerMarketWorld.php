<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use RuntimeException;

/**
 * Two sellers, their orders in several markets and a way to sign in as the owner or a staff member of either, behind the real seller API v3: the
 * route, the token middleware and the permission middleware. The store tables are an INFERRED copy, built from the models and the code that reads
 * them (the installation dump that owns them is not in the repository), so this proves the logic and not the live schema. The one real migration
 * is the one under test: the orders' storefront snapshot (GCC-05 slice 2), which a test may leave out to be a database that has not run it yet.
 *
 * Seller 1 is the caller and seller 2 the other seller. The orders carry what the snapshot writes: a storefront, a currency and a rate, or nothing
 * when the order is older than the snapshot or its lines span two countries. Every amount and every number the answers carry is an integer or a
 * cast float, so an answer is the same on SQLite and on MySQL and a recorded one can be compared as text, but for the rate of the snapshot: a decimal
 * the order model does not cast, which the test that compares writes the short way.
 *
 * Every table is dropped before it is built, so on a real engine the database must be unmistakably a throwaway one. translate() writes a placeholder
 * for a key it does not know into the tracked language files, so they are put back after every test.
 */
trait BuildsSellerMarketWorld
{
    use RestoresLanguageFiles;

    protected const SELLER = 1;

    protected const OTHER_SELLER = 2;

    protected const SELLER_TOKEN = 'seller-a-token-that-is-longer-than-thirty-characters';

    protected const OTHER_SELLER_TOKEN = 'seller-b-token-that-is-longer-than-thirty-characters';

    /** The order of the in-house shop whose seller_id is the same number as seller 1: it is nobody's order but the shop's. */
    protected const IN_HOUSE_ORDER = 301;

    private const SELLER_MARKET_TABLES = [
        'business_settings', 'sellers', 'shops', 'seller_roles', 'seller_staff', 'seller_api_keys', 'users', 'storages', 'orders', 'order_details',
        'shipping_methods', 'delivery_men', 'offline_payments', 'order_delivery_verifications', 'order_edit_histories', 'products', 'colors',
    ];

    protected function buildSellerMarketWorld(bool $ordersRecordTheirStorefront = true): void
    {
        $this->rememberLanguageFiles();
        $this->refuseToDropTablesOutsideATestDatabase();

        foreach (self::SELLER_MARKET_TABLES as $table) {
            Schema::dropIfExists($table);
        }

        $this->createTheSellerTables();
        $this->createTheOrderTables();

        if ($ordersRecordTheirStorefront) {
            (require database_path('migrations/2026_11_26_100000_add_storefront_snapshot_to_orders_table.php'))->up();
        }

        $this->seedTheSellersAndTheirOrders();
        $this->useOrderWiseTax();
    }

    protected function tearDownSellerMarketWorld(): void
    {
        $this->restoreLanguageFiles();
    }

    /** Every market the platform knows, active, so that a test of the filter is not about which storefront is switched on. */
    protected function switchStorefrontsOn(): void
    {
        config(['gcc.storefronts.enabled' => true]);

        foreach (['AE', 'KW', 'QA', 'BH', 'OM'] as $country) {
            config(["gcc.storefronts.countries.{$country}.active" => true]);
        }
    }

    /** @param array<string, mixed> $data */
    protected function sellerApi(string $method, string $uri, array $data = [], string $token = self::SELLER_TOKEN): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'])
            ->json($method, '/api/v3/seller/' . $uri, $data);
    }

    /** @param list<string> $permissions */
    protected function insertRole(int $sellerId, string $name, array $permissions, string $status = 'active'): int
    {
        return DB::table('seller_roles')->insertGetId([
            'seller_id' => $sellerId, 'name' => $name, 'permissions' => json_encode($permissions), 'status' => $status,
            'created_at' => '2026-11-01 09:00:00', 'updated_at' => '2026-11-01 09:00:00',
        ]);
    }

    protected function insertStaff(int $sellerId, ?int $roleId, string $token, string $status = 'active'): int
    {
        return DB::table('seller_staff')->insertGetId([
            'seller_id' => $sellerId, 'seller_role_id' => $roleId, 'name' => 'Staff member', 'email' => 'staff-' . substr(sha1($token), 0, 8) . '@example.test',
            'password' => 'not-a-real-hash', 'auth_token' => $token, 'status' => $status,
            'created_at' => '2026-11-01 09:00:00', 'updated_at' => '2026-11-01 09:00:00',
        ]);
    }

    /**
     * One order of a seller with one line. The storefront, currency and rate are what the snapshot wrote, and are left out of the row when the
     * orders migration has not run: the column is not there to hold them.
     */
    protected function insertSellerMarketOrder(int $id, int $sellerId, ?string $country, ?string $currency, string $sellerIs = 'seller'): void
    {
        $recorded = Schema::hasColumn('orders', 'storefront_country')
            ? ['storefront_country' => $country, 'currency_code' => $currency, 'currency_rate' => $country === null || $currency === null ? null : 1]
            : [];

        DB::table('orders')->insert([
            'id' => $id, 'customer_id' => 1, 'is_guest' => 0, 'customer_type' => 'customer', 'order_status' => 'pending', 'payment_status' => 'unpaid',
            'payment_method' => 'cash_on_delivery', 'transaction_ref' => 'ref-' . $id, 'order_group_id' => 'group-' . $id, 'order_type' => 'default_type',
            'order_amount' => 100 + $id, 'init_order_amount' => 100 + $id, 'paid_amount' => 0, 'total_tax_amount' => 15, 'shipping_cost' => 10,
            'seller_id' => $sellerId, 'seller_is' => $sellerIs, 'edited_status' => 0, 'edit_due_amount' => 0, 'edit_return_amount' => 0,
            'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00',
        ] + $recorded);

        $this->insertSellerMarketOrderLine(orderId: $id, sellerId: $sellerId);
    }

    /** The line of an order, with nothing else: a test may leave it without its order, as a deleted order leaves its lines. */
    protected function insertSellerMarketOrderLine(int $orderId, int $sellerId): void
    {
        DB::table('order_details')->insert([
            'order_id' => $orderId, 'product_id' => 500 + $orderId, 'seller_id' => $sellerId, 'qty' => 1, 'price' => 100, 'discount' => 0, 'tax' => 15,
            'tax_model' => 'exclude', 'delivery_status' => 'pending', 'payment_status' => 'unpaid', 'is_stock_decreased' => 0, 'variation' => '[]',
            'product_details' => json_encode(['id' => 500 + $orderId, 'name' => 'Product ' . $orderId, 'unit_price' => 100, 'current_stock' => 5, 'variation' => '[]']),
            'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00',
        ]);
    }

    /** The order detail asks which tax system the store runs on; the answer is given here instead of building the tax module's tables. */
    private function useOrderWiseTax(): void
    {
        Cache::put('tax_system_type_vendor_with_vat', [
            'SystemTaxVat' => null, 'SystemTaxVatType' => 'order_wise', 'is_included' => 0, 'productWiseTax' => false, 'categoryWiseTax' => false, 'taxVats' => collect([]),
        ], 3600);
    }

    private function seedTheSellersAndTheirOrders(): void
    {
        foreach ([self::SELLER => self::SELLER_TOKEN, self::OTHER_SELLER => self::OTHER_SELLER_TOKEN] as $id => $token) {
            DB::table('sellers')->insert([
                'id' => $id, 'f_name' => 'Seller ' . $id, 'l_name' => 'Test', 'status' => 'approved', 'auth_token' => $token,
                'created_at' => '2026-11-01 08:00:00', 'updated_at' => '2026-11-01 08:00:00',
            ]);
        }

        DB::table('users')->insert(['id' => 1, 'f_name' => 'Customer', 'l_name' => 'One', 'email' => 'customer-one@example.test']);

        // Seller 1: one order of each kind of record the snapshot can leave, and a second default-storefront order with no currency.
        $this->insertSellerMarketOrder(id: 101, sellerId: self::SELLER, country: 'SA', currency: 'SAR');
        $this->insertSellerMarketOrder(id: 102, sellerId: self::SELLER, country: 'AE', currency: 'AED');
        $this->insertSellerMarketOrder(id: 103, sellerId: self::SELLER, country: 'KW', currency: 'KWD');
        $this->insertSellerMarketOrder(id: 104, sellerId: self::SELLER, country: null, currency: null);
        $this->insertSellerMarketOrder(id: 105, sellerId: self::SELLER, country: 'SA', currency: null);
        // Seller 2, who also has an order with no record, and the shop's own order that shares seller 1's id.
        $this->insertSellerMarketOrder(id: 201, sellerId: self::OTHER_SELLER, country: 'AE', currency: 'AED');
        $this->insertSellerMarketOrder(id: 202, sellerId: self::OTHER_SELLER, country: 'SA', currency: 'SAR');
        $this->insertSellerMarketOrder(id: 203, sellerId: self::OTHER_SELLER, country: null, currency: null);
        $this->insertSellerMarketOrder(id: self::IN_HOUSE_ORDER, sellerId: self::SELLER, country: 'SA', currency: 'SAR', sellerIs: 'admin');
    }

    private function createTheSellerTables(): void
    {
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->longText('value')->nullable();
            $table->timestamps();
        });

        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('status')->default('approved');
            $table->string('auth_token')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('seller_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('name');
            $table->text('permissions')->nullable();
            $table->string('status')->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('seller_staff', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('seller_role_id')->nullable();
            $table->string('name');
            $table->string('email');
            $table->string('password')->nullable();
            $table->string('auth_token')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('seller_api_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('created_by_staff_id')->nullable();
            $table->string('name');
            $table->string('prefix', 12);
            $table->string('token_hash');
            $table->text('scopes')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    /** The orders and what the order list and the order detail of the seller app read around them. */
    private function createTheOrderTables(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
        });

        Schema::create('storages', function (Blueprint $table) {
            $table->id();
            $table->string('data_type');
            $table->unsignedBigInteger('data_id');
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('customer_type')->nullable();
            $table->string('order_status')->default('pending');
            $table->string('payment_status')->default('unpaid');
            $table->string('payment_method')->nullable();
            $table->string('transaction_ref')->nullable();
            $table->string('order_group_id')->nullable();
            $table->string('order_type')->default('default_type');
            $table->decimal('order_amount', 16, 2)->default(0);
            $table->decimal('init_order_amount', 16, 2)->default(0);
            $table->decimal('paid_amount', 16, 2)->default(0);
            $table->decimal('total_tax_amount', 16, 2)->default(0);
            $table->decimal('shipping_cost', 16, 2)->default(0);
            $table->unsignedBigInteger('shipping_method_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is')->default('admin');
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->integer('edited_status')->default(0);
            $table->integer('edit_due_amount')->default(0);
            $table->integer('edit_return_amount')->default(0);
            $table->timestamps();
        });

        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->longText('product_details')->nullable();
            $table->integer('qty')->default(1);
            $table->decimal('price', 16, 2)->default(0);
            $table->decimal('discount', 16, 2)->default(0);
            $table->decimal('tax', 16, 2)->default(0);
            $table->string('tax_model')->nullable();
            $table->string('variant')->nullable();
            $table->longText('variation')->nullable();
            $table->string('delivery_status')->default('pending');
            $table->string('payment_status')->default('unpaid');
            $table->boolean('is_stock_decreased')->default(false);
            $table->timestamps();
        });

        Schema::create('shipping_methods', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->timestamps();
        });

        Schema::create('delivery_men', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->timestamps();
        });

        Schema::create('offline_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->longText('payment_info')->nullable();
            $table->timestamps();
        });

        Schema::create('order_delivery_verifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('image')->nullable();
            $table->timestamps();
        });

        Schema::create('order_edit_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->integer('order_due_amount')->default(0);
            $table->string('order_due_payment_status')->nullable();
            $table->integer('order_return_amount')->default(0);
            $table->string('order_return_payment_status')->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('colors', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->timestamps();
        });
    }

    /**
     * The same rule the other schema builders apply before they drop tables: a real engine keeps its tables between tests, so a database whose
     * name does not say "test", or an environment that is not "testing", is refused before anything is dropped.
     */
    private function refuseToDropTablesOutsideATestDatabase(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() === 'sqlite') {
            return;
        }

        $database = (string) $connection->getDatabaseName();
        if (!app()->environment('testing') || !preg_match('/(^|_)test(s|ing)?($|_)/i', $database)) {
            throw new RuntimeException("Refusing to drop the seller tables of '{$database}': the test database name must contain 'test' and APP_ENV must be 'testing'.");
        }
    }
}
