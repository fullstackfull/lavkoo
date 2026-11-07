<?php

namespace Tests\Support;

use App\Models\ShippingAddress;
use App\Models\User;
use App\Services\Address\AddressSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The store tables the REST API and order-placement address tests read, on top of the address book of BuildsAddressSchema.
 *
 * Like the other schema builders these are INFERRED from the models and the code that writes them: the installation dump is not in
 * the repository. They hold only the columns the address work touches and prove its logic, not the live schema.
 */
trait BuildsApiAddressSchema
{
    protected function createApiStoreTables(): void
    {
        $this->createTableOnce('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('street_address')->nullable();
            $table->string('city')->nullable();
            $table->string('zip')->nullable();
            $table->string('country')->nullable();
            $table->string('house_no')->nullable();
            $table->string('apartment_no')->nullable();
            $table->string('referral_code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->decimal('wallet_balance', 16, 2)->default(0);
            $table->timestamps();
        });

        $this->createTableOnce('storages', function (Blueprint $table) {
            $table->id();
            $table->string('data_type');
            $table->unsignedBigInteger('data_id');
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });

        $this->createTableOnce('delivery_zip_codes', function (Blueprint $table) {
            $table->id();
            $table->string('zipcode')->nullable();
            $table->timestamps();
        });

        $this->createTableOnce('delivery_country_codes', function (Blueprint $table) {
            $table->id();
            $table->string('country_code')->nullable();
            $table->timestamps();
        });

        $this->createTableOnce('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('status')->default('approved');
            $table->timestamps();
            $table->softDeletes();
        });

        $this->createTableOnce('shops', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('author_type')->default('vendor');
            $table->string('name')->nullable();
            $table->timestamps();
        });

        $this->createTableOnce('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('verification_code')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('customer_type')->nullable();
            $table->string('order_status')->default('pending');
            $table->string('payment_status')->default('unpaid');
            $table->string('payment_method')->nullable();
            $table->string('transaction_ref')->nullable();
            $table->string('payment_by')->nullable();
            $table->text('payment_note')->nullable();
            $table->string('order_group_id')->nullable();
            $table->string('order_type')->default('default_type');
            $table->decimal('discount_amount', 16, 2)->nullable();
            $table->string('discount_type')->nullable();
            $table->string('coupon_code')->nullable();
            $table->string('coupon_discount_bearer')->nullable();
            $table->decimal('order_amount', 16, 2)->default(0);
            $table->decimal('init_order_amount', 16, 2)->default(0);
            $table->decimal('paid_amount', 16, 2)->default(0);
            $table->decimal('total_tax_amount', 16, 2)->default(0);
            $table->string('tax_type')->nullable();
            $table->string('tax_model')->nullable();
            $table->decimal('bring_change_amount', 16, 2)->nullable();
            $table->string('bring_change_amount_currency')->nullable();
            $table->decimal('admin_commission', 16, 2)->default(0);
            $table->unsignedBigInteger('shipping_address')->nullable();
            $table->longText('shipping_address_data')->nullable();
            $table->unsignedBigInteger('billing_address')->nullable();
            $table->longText('billing_address_data')->nullable();
            $table->string('shipping_responsibility')->nullable();
            $table->decimal('shipping_cost', 16, 2)->default(0);
            $table->decimal('extra_discount', 16, 2)->default(0);
            $table->string('extra_discount_type')->nullable();
            $table->decimal('refer_and_earn_discount', 16, 2)->default(0);
            $table->string('free_delivery_bearer')->nullable();
            $table->boolean('is_shipping_free')->default(false);
            $table->unsignedBigInteger('shipping_method_id')->nullable();
            $table->string('shipping_type')->nullable();
            $table->text('order_note')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is')->default('admin');
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->dateTime('expected_delivery_date')->nullable();
            $table->boolean('is_pause')->default(false);
            $table->boolean('checked')->default(false);
            $table->timestamps();
        });

        $this->createTableOnce('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->longText('product_details')->nullable();
            $table->integer('qty')->default(1);
            $table->decimal('price', 16, 2)->default(0);
            $table->decimal('discount', 16, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('tax', 16, 2)->default(0);
            $table->string('tax_model')->nullable();
            $table->string('variant')->nullable();
            $table->longText('variation')->nullable();
            $table->unsignedBigInteger('shipping_method_id')->nullable();
            $table->string('delivery_status')->default('pending');
            $table->string('payment_status')->default('unpaid');
            $table->timestamps();
        });

        Cache::flush();
    }

    /** The cart, the product it holds and the currency of the store: what an order endpoint reads before it looks at the address. */
    protected function createCartTables(): void
    {
        $this->createTableOnce('products', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->string('added_by')->default('admin');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->string('product_type')->default('physical');
            $table->string('digital_product_type')->nullable();
            $table->string('digital_file_ready')->nullable();
            $table->integer('current_stock')->default(10);
            $table->longText('variation')->nullable();
            $table->integer('status')->default(1);
            $table->integer('request_status')->default(1);
            $table->decimal('unit_price', 16, 2)->default(10);
            $table->decimal('purchase_price', 16, 2)->default(0);
            $table->decimal('tax', 16, 2)->default(0);
            $table->string('tax_type')->nullable();
            $table->string('tax_model')->nullable();
            $table->decimal('discount', 16, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->integer('minimum_order_qty')->default(1);
            $table->decimal('shipping_cost', 16, 2)->default(0);
            $table->integer('multiply_qty')->default(0);
            $table->text('category_ids')->nullable();
            $table->string('thumbnail')->nullable();
            $table->longText('images')->nullable();
            $table->timestamps();
        });

        $this->createTableOnce('reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        $this->createTableOnce('carts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('cart_group_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('product_type')->default('physical');
            $table->string('variant')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('price', 16, 2)->default(10);
            $table->decimal('discount', 16, 2)->default(0);
            $table->decimal('shipping_cost', 16, 2)->default(0);
            $table->string('shipping_type')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is')->default('admin');
            $table->boolean('is_checked')->default(true);
            $table->timestamps();
        });

        $this->createTableOnce('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('symbol', 20)->nullable();
            $table->string('code', 20)->nullable();
            $table->decimal('exchange_rate', 20, 8)->default(1);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        DB::table('currencies')->insert(['id' => 1, 'name' => 'Saudi Riyal', 'code' => 'SAR', 'symbol' => 'SAR', 'exchange_rate' => 1, 'status' => 1]);
        DB::table('business_settings')->insert([
            ['type' => 'currency_model', 'value' => json_encode('single_currency')],
            ['type' => 'system_default_currency', 'value' => json_encode(1)],
        ]);
        Cache::flush();
    }

    /** Everything OrderManager::generateOrder reads and writes for a one-line, cash-on-delivery order of the in-house shop. */
    protected function createOrderPipelineTables(): void
    {
        $this->createCartTables();

        $this->createTableOnce('translations', function (Blueprint $table) {
            $table->id();
            $table->string('translationable_type');
            $table->unsignedBigInteger('translationable_id');
            $table->string('locale');
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
        });

        $this->createTableOnce('stock_clearance_setups', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active')->default(false);
            $table->date('duration_start_date')->nullable();
            $table->date('duration_end_date')->nullable();
            $table->string('offer_active_time')->nullable();
            $table->time('offer_active_range_start')->nullable();
            $table->time('offer_active_range_end')->nullable();
            $table->timestamps();
        });

        $this->createTableOnce('stock_clearance_products', function (Blueprint $table) {
            $table->id();
            $table->string('added_by')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->boolean('is_active')->default(false);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_amount', 16, 2)->default(0);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('setup_id')->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->timestamps();
        });

        $this->createTableOnce('digital_product_variations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('variant_key')->nullable();
            $table->string('file')->nullable();
            $table->timestamps();
        });

        $this->createTableOnce('taxes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        $this->createTableOnce('taxables', function (Blueprint $table) {
            $table->id();
            $table->string('taxable_type')->nullable();
            $table->unsignedBigInteger('taxable_id')->nullable();
            $table->unsignedBigInteger('system_tax_setup_id')->nullable();
            $table->unsignedBigInteger('tax_id')->nullable();
            $table->string('tax_for')->nullable();
            $table->timestamps();
        });

        $this->createTableOnce('referral_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('ref_by')->nullable();
            $table->boolean('is_used')->default(false);
            $table->timestamps();
        });

        $this->createTableOnce('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable();
            $table->string('coupon_type')->nullable();
            $table->boolean('status')->default(true);
            $table->date('start_date')->nullable();
            $table->date('expire_date')->nullable();
            $table->timestamps();
        });

        DB::table('business_settings')->insert([
            ['type' => 'mail_config', 'value' => json_encode(['status' => 0])],
            ['type' => 'mail_config_sendgrid', 'value' => json_encode(['status' => 0])],
        ]);
        Cache::flush();

        $this->createTableOnce('offline_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->longText('payment_info')->nullable();
            $table->timestamps();
        });

        $this->createTableOnce('offline_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('method_name')->nullable();
            $table->longText('method_informations')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        if (!DB::table('shops')->where('author_type', 'admin')->exists()) {
            DB::table('shops')->insert(['author_type' => 'admin', 'name' => 'In-house shop']);
        }

        $this->createTableOnce('order_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->string('seller_is')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->decimal('order_amount', 16, 2)->default(0);
            $table->decimal('seller_amount', 16, 2)->default(0);
            $table->decimal('admin_commission', 16, 2)->default(0);
            $table->string('received_by')->nullable();
            $table->string('status')->nullable();
            $table->decimal('delivery_charge', 16, 2)->default(0);
            $table->decimal('tax', 16, 2)->default(0);
            $table->string('delivered_by')->nullable();
            $table->string('payment_method')->nullable();
            $table->timestamps();
        });

        $this->createTableOnce('admin_wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->decimal('inhouse_earning', 16, 2)->default(0);
            $table->decimal('withdrawn', 16, 2)->default(0);
            $table->decimal('commission_earned', 16, 2)->default(0);
            $table->decimal('delivery_charge_earned', 16, 2)->default(0);
            $table->decimal('pending_amount', 16, 2)->default(0);
            $table->decimal('total_tax_collected', 16, 2)->default(0);
            $table->timestamps();
        });

        $this->createTableOnce('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_type')->nullable();
            $table->string('status')->nullable();
            $table->text('cause')->nullable();
            $table->timestamps();
        });

        $this->createTableOnce('payment_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('payer_id')->nullable();
            $table->string('receiver_id')->nullable();
            $table->decimal('payment_amount', 24, 2)->default(0);
            $table->string('gateway_callback_url')->nullable();
            $table->string('success_hook')->nullable();
            $table->string('failure_hook')->nullable();
            $table->string('transaction_id')->nullable();
            $table->string('currency_code', 10)->default('SAR');
            $table->string('payment_method')->nullable();
            $table->longText('additional_data')->nullable();
            $table->boolean('is_paid')->default(false);
            $table->longText('payer_information')->nullable();
            $table->longText('receiver_information')->nullable();
            $table->string('external_redirect_link')->nullable();
            $table->string('attribute')->nullable();
            $table->string('attribute_id')->nullable();
            $table->string('payment_platform')->nullable();
            $table->timestamps();
        });

        $this->createTableOnce('shipping_methods', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('creator_id')->nullable();
            $table->string('creator_type')->default('admin');
            $table->string('title')->nullable();
            $table->decimal('cost', 16, 2)->default(0);
            $table->string('duration')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        $this->createTableOnce('shipping_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->default(0);
            $table->string('shipping_type')->default('order_wise');
            $table->timestamps();
        });

        $this->createTableOnce('cart_shippings', function (Blueprint $table) {
            $table->id();
            $table->string('cart_group_id')->nullable();
            $table->unsignedBigInteger('shipping_method_id')->nullable();
            $table->decimal('shipping_cost', 16, 2)->default(0);
            $table->timestamps();
        });
    }

    /** One in-house product and a cart line for the caller: a customer ($isGuest false) or a guest, the id being either's. */
    protected function putOneProductInTheCart(int $callerId, bool $isGuest = false, int $stock = 10): void
    {
        if (!DB::table('products')->where('id', 1)->exists()) {
            DB::table('products')->insert(['id' => 1, 'name' => 'Paracetamol', 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => 100, 'current_stock' => $stock]);
        }

        DB::table('carts')->insert([
            'customer_id' => $callerId, 'is_guest' => $isGuest, 'cart_group_id' => 'group-' . $callerId . ($isGuest ? 'g' : 'c'), 'product_id' => 1, 'quantity' => 1,
            'price' => 100, 'seller_id' => 1, 'seller_is' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A store whose prices already include tax: the tax set-up the order code asks for is answered from the cache instead of the tax module's tables. */
    protected function useTaxIncludedPrices(): void
    {
        Cache::put('tax_system_type_vendor_with_vat', [
            'SystemTaxVat' => ['tax_ids' => [], 'additionalData' => [], 'tax_type' => 'order_wise'],
            'SystemTaxVatType' => 'order_wise', 'is_included' => 1, 'productWiseTax' => false, 'categoryWiseTax' => false, 'taxVats' => collect([]),
        ], 3600);
    }

    private function createTableOnce(string $name, callable $definition): void
    {
        if (!Schema::hasTable($name)) {
            Schema::create($name, $definition);
        }
    }

    protected function setAddressMode(string $mode): void
    {
        AddressSchema::setMode($mode);
        AddressSchema::flush();
    }

    /** @var array{private: string, public: string}|null generated once per run: making a key pair is the slowest step of a test */
    private static ?array $passportKeys = null;

    /** A throwaway RSA key pair: Passport's token guard refuses to exist without one. */
    protected function useThrowawayPassportKeys(): void
    {
        if (self::$passportKeys === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            self::$passportKeys = ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
        }

        config(['passport.private_key' => self::$passportKeys['private'], 'passport.public_key' => self::$passportKeys['public']]);
    }

    protected function makeCustomer(array $overrides = []): User
    {
        return User::unguarded(static fn () => User::create($overrides + [
            'f_name' => 'Sara', 'l_name' => 'Alharbi', 'email' => 'sara' . random_int(1000, 9999) . '@example.test', 'phone' => '0551234567',
        ]));
    }

    /** An address as the old forms saved it: a free-text city, a ZIP code, no region or National Address. */
    protected function makeLegacyAddress(int $customerId, bool $isGuest = false, array $overrides = []): ShippingAddress
    {
        return ShippingAddress::create($overrides + [
            'customer_id' => $customerId, 'is_guest' => $isGuest, 'contact_person_name' => 'Sara Alharbi', 'address_type' => 'home',
            'address' => 'King Fahd Rd 12', 'city' => 'الرياض', 'zip' => '12211', 'country' => 'Saudi Arabia', 'phone' => '0551234567',
            'latitude' => '24.7', 'longitude' => '46.6', 'is_billing' => false, 'email' => 'sara@example.test',
        ]);
    }

    /** @param array<string, int> $ids the handles seedAddressCatalog() returned */
    protected function makeStructuredAddress(array $ids, int $customerId, bool $isGuest = false, array $overrides = []): ShippingAddress
    {
        return $this->makeLegacyAddress($customerId, $isGuest, $overrides + [
            'region_id' => $ids['region_RUH'], 'city_id' => $ids['riyadh'], 'district' => 'Al Olaya', 'national_address' => 'RAGI2929',
            'city' => 'الرياض', 'state' => 'منطقة الرياض', 'zip' => null,
        ]);
    }

    protected function insertOrder(int $id, array $overrides = []): void
    {
        DB::table('orders')->insert($overrides + ['id' => $id, 'customer_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
}
