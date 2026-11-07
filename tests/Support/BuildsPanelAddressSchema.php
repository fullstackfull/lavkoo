<?php

namespace Tests\Support;

use App\Models\Order;
use App\Models\ShippingAddress;
use App\Services\Address\AddressSchema;
use App\Services\Address\AddressSnapshot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The store tables the admin and vendor panel address tests touch, on top of the address book of BuildsAddressSchema.
 *
 * Like the other schema builders these are INFERRED from the models and the code that writes them: the installation dump is not in the
 * repository. They hold only the columns the panel address work reads or writes and prove its logic, not the live schema.
 */
trait BuildsPanelAddressSchema
{
    protected function createPanelStoreTables(): void
    {
        $this->createPanelTable('users', function (Blueprint $table) {
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
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $this->createPanelTable('password_resets', function (Blueprint $table) {
            $table->id();
            $table->string('identity')->nullable();
            $table->string('token')->nullable();
            $table->string('user_type')->nullable();
            $table->timestamps();
        });

        $this->createPanelTable('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('status')->default('approved');
            $table->timestamps();
            $table->softDeletes();
        });

        $this->createPanelTable('shops', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('author_type')->default('vendor');
            $table->string('name')->nullable();
            $table->timestamps();
        });

        DB::table('shops')->insert(['id' => 1, 'seller_id' => 0, 'author_type' => 'admin', 'name' => 'In-house shop']);

        $this->createPanelTable('delivery_men', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->timestamps();
        });

        $this->createPanelTable('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('order_status')->default('pending');
            $table->string('payment_status')->default('unpaid');
            $table->string('order_type')->default('default_type');
            $table->decimal('order_amount', 16, 2)->default(0);
            $table->unsignedBigInteger('shipping_address')->nullable();
            $table->longText('shipping_address_data')->nullable();
            $table->unsignedBigInteger('billing_address')->nullable();
            $table->longText('billing_address_data')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is')->default('admin');
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->string('delivery_type')->nullable();
            $table->timestamps();
        });

        $this->createPanelTable('storages', function (Blueprint $table) {
            $table->id();
            $table->string('data_type');
            $table->unsignedBigInteger('data_id');
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });

        $this->createPanelTable('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        $this->createPanelTable('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->timestamps();
        });

        $this->createPanelTable('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        $this->createPanelTable('currencies', function (Blueprint $table) {
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
            ['type' => 'pagination_limit', 'value' => json_encode(10)],
        ]);

        Cache::flush();
    }

    private function createPanelTable(string $name, callable $definition): void
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

    /** An address as the old forms saved it: a free-text city, a ZIP code, no region or National Address. */
    protected function makeLegacyAddress(int $customerId = 1, array $overrides = []): ShippingAddress
    {
        return ShippingAddress::create($overrides + [
            'customer_id' => $customerId, 'is_guest' => false, 'contact_person_name' => 'Sara Alharbi', 'address_type' => 'home',
            'address' => 'King Fahd Rd 12', 'city' => 'الرياض', 'zip' => '12211', 'country' => 'Saudi Arabia', 'phone' => '0551234567',
            'latitude' => '24.7', 'longitude' => '46.6', 'is_billing' => false, 'email' => 'sara@example.test',
        ]);
    }

    /** @param array<string, int> $ids the handles seedAddressCatalog() returned */
    protected function makeStructuredAddress(array $ids, int $customerId = 1, array $overrides = []): ShippingAddress
    {
        return $this->makeLegacyAddress($customerId, $overrides + [
            'region_id' => $ids['region_RUH'], 'city_id' => $ids['riyadh'], 'district' => 'Al Olaya', 'national_address' => 'RAGI2929',
            'city' => 'الرياض', 'state' => 'منطقة الرياض', 'zip' => null,
        ]);
    }

    /**
     * The order's copy of an old address: the keys the checkout has always stored, and nothing about regions or cities.
     *
     * @return array<string, mixed>
     */
    protected function legacySnapshot(array $overrides = []): array
    {
        return $overrides + [
            'id' => 7, 'customer_id' => '1', 'is_guest' => false, 'contact_person_name' => 'Sara Alharbi', 'email' => 'sara@example.test',
            'address_type' => 'home', 'address' => 'King Fahd Rd 12', 'city' => 'الرياض', 'zip' => '12211', 'phone' => '0551234567',
            'state' => null, 'country' => 'Saudi Arabia', 'latitude' => '24.7', 'longitude' => '46.6', 'is_billing' => false,
        ];
    }

    /**
     * The order's copy of a structured address, built the way the checkout builds it.
     *
     * @param array<string, int> $ids
     * @return array<string, mixed>
     */
    protected function unifiedSnapshot(array $ids, array $overrides = []): array
    {
        return AddressSnapshot::build($this->makeStructuredAddress($ids, 1, $overrides));
    }

    /**
     * @param array<string, mixed>|null $shipping the copy of the delivery address (null: the order kept none)
     * @param array<string, mixed>|null $billing
     */
    protected function insertPanelOrder(int $id, ?array $shipping, ?array $billing = null, array $overrides = []): void
    {
        DB::table('orders')->insert($overrides + [
            'id' => $id, 'customer_id' => 1, 'created_at' => now(), 'updated_at' => now(),
            'shipping_address_data' => $shipping === null ? null : json_encode($shipping),
            'billing_address_data' => $billing === null ? null : json_encode($billing),
        ]);
    }

    /** @return array<string, mixed>|null the copy the order holds now */
    protected function storedCopy(int $orderId, string $type = 'shipping'): ?array
    {
        $stored = DB::table('orders')->where('id', $orderId)->value($type . '_address_data');

        return $stored === null ? null : json_decode((string) $stored, true);
    }

    /**
     * An order as the pages read it, without a database row. The copies are set as stored text: the legacy accessor turns them into the
     * objects the Blade files use, and writing them through the model's cast would encode them twice.
     */
    protected function orderWithCopies(?array $shipping, ?array $billing = null, array $attributes = []): Order
    {
        $order = (new Order())->setRawAttributes($attributes + [
            'id' => 100, 'customer_id' => 1, 'is_guest' => 0, 'order_status' => 'pending', 'payment_status' => 'unpaid', 'order_type' => 'default_type',
            'seller_is' => 'admin', 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
            'shipping_address_data' => $shipping === null ? null : json_encode($shipping),
            'billing_address_data' => $billing === null ? null : json_encode($billing),
        ]);

        foreach (['details', 'verificationImages', 'orderEditHistory', 'orderStatusHistory'] as $relation) {
            $order->setRelation($relation, collect());
        }
        foreach (['latestEditHistory', 'shipping', 'seller', 'offlinePayments', 'deliveryMan', 'customer'] as $relation) {
            $order->setRelation($relation, null);
        }

        return $order;
    }
}
