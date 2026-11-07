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
 * The store tables the website's checkout and address book read, on top of the address book of BuildsAddressSchema.
 *
 * Like the other schema builders these are INFERRED from the models and the code that writes them: the installation dump is not
 * in the repository. They hold only the columns the storefront address work touches and prove its logic, not the live schema.
 */
trait BuildsStorefrontAddressSchema
{
    protected function createStorefrontStoreTables(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_phone_verified')->default(false);
            $table->boolean('is_email_verified')->default(false);
            $table->string('referral_code')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('guest_users', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address')->nullable();
            $table->string('fcm_token')->nullable();
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

        Schema::create('delivery_zip_codes', function (Blueprint $table) {
            $table->id();
            $table->string('zipcode')->nullable();
            $table->timestamps();
        });

        Schema::create('delivery_country_codes', function (Blueprint $table) {
            $table->id();
            $table->string('country_code')->nullable();
            $table->timestamps();
        });

        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('approved');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('added_by')->default('admin');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->string('product_type')->default('physical');
            $table->integer('status')->default(1);
            $table->integer('request_status')->default(1);
            $table->decimal('unit_price', 16, 2)->default(10);
            $table->integer('current_stock')->default(10);
            $table->longText('variation')->nullable();
            $table->timestamps();
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('cart_group_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('product_type')->default('physical');
            $table->string('variant')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('price', 16, 2)->default(10);
            $table->decimal('tax', 16, 2)->default(0);
            $table->decimal('discount', 16, 2)->default(0);
            $table->decimal('shipping_cost', 16, 2)->default(0);
            $table->string('shipping_type')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is')->default('admin');
            $table->boolean('is_checked')->default(true);
            $table->timestamps();
        });

        Schema::create('shipping_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->default(0);
            $table->string('shipping_type')->default('order_wise');
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('order_type')->default('default_type');
            $table->string('order_status')->default('pending');
            $table->string('payment_status')->default('unpaid');
            $table->unsignedBigInteger('shipping_address')->nullable();
            $table->longText('shipping_address_data')->nullable();
            $table->unsignedBigInteger('billing_address')->nullable();
            $table->longText('billing_address_data')->nullable();
            $table->timestamps();
        });

        Cache::flush();
        AddressSchema::flush();
    }

    /** What the checkout page reads of the cart before it shows the address step: the tax set-up, the shipping methods, the shop. */
    protected function createCheckoutPageTables(): void
    {
        Schema::create('digital_product_variations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('variant_key')->nullable();
            $table->string('file')->nullable();
            $table->timestamps();
        });

        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->string('translationable_type');
            $table->unsignedBigInteger('translationable_id');
            $table->string('locale');
            $table->string('key');
            $table->text('value')->nullable();
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

        Schema::create('taxes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('taxables', function (Blueprint $table) {
            $table->id();
            $table->string('taxable_type')->nullable();
            $table->unsignedBigInteger('taxable_id')->nullable();
            $table->unsignedBigInteger('system_tax_setup_id')->nullable();
            $table->unsignedBigInteger('tax_id')->nullable();
            $table->string('tax_for')->nullable();
            $table->timestamps();
        });

        Schema::create('shipping_methods', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('creator_id')->nullable();
            $table->string('creator_type')->default('admin');
            $table->string('title')->nullable();
            $table->decimal('cost', 16, 2)->default(0);
            $table->string('duration')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('cart_shippings', function (Blueprint $table) {
            $table->id();
            $table->string('cart_group_id')->nullable();
            $table->unsignedBigInteger('shipping_method_id')->nullable();
            $table->decimal('shipping_cost', 16, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('author_type')->default('admin');
            $table->string('name')->nullable();
            $table->timestamps();
        });
        DB::table('shops')->insert(['id' => 1, 'author_type' => 'admin', 'name' => 'In-house shop']);
    }

    /** One in-house physical product in the cart of a customer, or of a guest session ($isGuest). */
    protected function putOneProductInTheCart(int $ownerId, bool $isGuest = false): void
    {
        if (!DB::table('products')->where('id', 1)->exists()) {
            DB::table('products')->insert(['id' => 1, 'name' => 'Paracetamol', 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => 100]);
        }

        DB::table('carts')->insert([
            'customer_id' => $ownerId, 'is_guest' => $isGuest, 'cart_group_id' => 'group-' . $ownerId . ($isGuest ? 'g' : 'c'), 'product_id' => 1, 'quantity' => 1,
            'price' => 100, 'is_checked' => 1, 'product_type' => 'physical', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function makeCustomer(array $overrides = []): User
    {
        return User::unguarded(static fn () => User::create($overrides + [
            'f_name' => 'Sara', 'l_name' => 'Alharbi', 'email' => 'sara' . random_int(1000, 9999) . '@example.test', 'phone' => '0551234567', 'is_active' => 1,
        ]));
    }

    /** An address as the old forms saved it: a free-text city, a ZIP code, no region or National Address. */
    protected function makeLegacyAddress(int|string $ownerId, bool $isGuest = false, array $overrides = []): ShippingAddress
    {
        return ShippingAddress::create($overrides + [
            'customer_id' => (string) $ownerId, 'is_guest' => $isGuest, 'contact_person_name' => 'Sara Alharbi', 'address_type' => 'home',
            'address' => 'King Fahd Rd 12', 'city' => 'الرياض', 'zip' => '12211', 'country' => 'Saudi Arabia', 'phone' => '0551234567',
            'latitude' => '24.7', 'longitude' => '46.6', 'is_billing' => false, 'email' => null,
        ]);
    }

    /** @param array<string, int> $ids the handles seedAddressCatalog() returned */
    protected function makeStructuredAddress(array $ids, int|string $ownerId, bool $isGuest = false, array $overrides = []): ShippingAddress
    {
        return $this->makeLegacyAddress($ownerId, $isGuest, $overrides + [
            'region_id' => $ids['region_RUH'], 'city_id' => $ids['riyadh'], 'district' => 'Al Olaya', 'national_address' => 'RAGI2929',
            'city' => 'الرياض', 'state' => null, 'zip' => null,
        ]);
    }

    protected function setAddressMode(string $mode): void
    {
        AddressSchema::setMode($mode);
        AddressSchema::flush();
    }

    protected function setBusinessSetting(string $type, mixed $value): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => $type], ['value' => json_encode($value), 'created_at' => now(), 'updated_at' => now()]);
        Cache::flush();
    }
}
