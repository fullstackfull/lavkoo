<?php

namespace Tests\Feature\Address;

use App\Http\Controllers\RestAPI\v1\ConfigController;
use App\Models\ShippingAddress;
use App\Services\Address\AddressSchema;
use App\Services\OrderService;
use App\Utils\OrderManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The code is deployed before its migrations run. Until they have, even a store whose setting says "strict" behaves exactly as it
 * did before: the old columns, the old validation, the old copy of an address in every order.
 */
class ApiUnmigratedStoreTest extends ApiAddressTestCase
{
    protected function buildAddressSchema(): void
    {
        $this->resetAddressTestDatabase();
        $this->createLegacyAddressTables();
    }

    protected function seedAddressCatalog(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();
        $this->setAddressMode(AddressSchema::MODE_STRICT);
    }

    /** @return array<string, mixed> */
    private function oldPayload(array $overrides = []): array
    {
        return $overrides + [
            'contact_person_name' => 'Sara Alharbi', 'address_type' => 'home', 'address' => 'King Fahd Rd 12', 'city' => 'الرياض',
            'zip' => '12211', 'country' => 'Saudi Arabia', 'phone' => '0551234567', 'latitude' => '24.7', 'longitude' => '46.6',
        ];
    }

    public function test_the_address_book_of_the_app_works_on_the_old_table(): void
    {
        $this->assertFalse(Schema::hasColumn('shipping_addresses', 'city_id'));
        $customer = $this->makeCustomer();

        $this->asCustomer($customer)->postJson('/api/v1/customer/address/add', $this->oldPayload(['region_id' => 3, 'city_id' => 4, 'national_address' => 'RAGI2929']))->assertOk();
        $address = ShippingAddress::firstOrFail();
        $this->assertSame(['الرياض', '12211'], [$address->city, $address->zip]);

        $this->asCustomer($customer)->postJson('/api/v1/customer/address/update', ['id' => $address->id, 'address' => 'Olaya St 3', 'city' => 'جدة'])->assertOk();
        $this->assertSame(['Olaya St 3', 'جدة', '12211'], [$address->fresh()->address, $address->fresh()->city, $address->fresh()->zip]);

        $list = $this->asCustomer($customer)->getJson('/api/v1/customer/address/list')->assertOk()->json();
        $this->assertSame(array_keys($address->fresh()->toArray()), array_keys($list[0]), 'the list is the old row, key for key');

        $this->asCustomer($customer)->deleteJson('/api/v1/customer/address', ['address_id' => $address->id])->assertOk();
        $this->assertSame(0, ShippingAddress::count());
    }

    public function test_the_old_validation_of_the_app_is_unchanged(): void
    {
        $response = $this->postJson('/api/v1/customer/address/add', ['guest_id' => 7001] + $this->oldPayload(['city' => null, 'latitude' => null]));

        $response->assertStatus(403);
        $this->assertEqualsCanonicalizing(['city', 'latitude'], array_column($response->json('errors'), 'code'));
    }

    public function test_an_app_order_keeps_the_copy_the_checkout_always_kept_and_foreign_addresses_are_still_refused(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeLegacyAddress($customer->id);
        $theirs = $this->makeLegacyAddress($this->makeCustomer()->id);
        $this->putOneProductInTheCart($customer->id);
        $asLoaded = (string) ShippingAddress::find($address->id);

        $this->asCustomer($customer)->getJson('/api/v1/customer/order/place?address_id=' . $theirs->id)->assertStatus(403);
        $this->asCustomer($customer)->getJson('/api/v1/customer/order/place?address_id=' . $address->id)->assertOk();

        $this->assertSame($asLoaded, DB::table('orders')->value('shipping_address_data'));
    }

    public function test_a_paid_order_and_a_pos_sale_use_the_old_copy_too(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeLegacyAddress($customer->id);
        $this->putOneProductInTheCart($customer->id);
        request()->replace([]);
        digital_payment_success([
            'is_paid' => 1, 'payment_method' => 'ssl_commerz', 'transaction_id' => 'TXN-1',
            'additional_data' => json_encode(['customer_id' => $customer->id, 'is_guest' => 0, 'is_guest_in_order' => 0, 'order_note' => null, 'payment_mode' => 'web', 'new_customer_id' => 0, 'address_id' => $address->id]),
        ]);
        @unlink(storage_path('logs/order_generate_test.txt'));

        $this->assertSame((string) ShippingAddress::find($address->id), DB::table('orders')->value('shipping_address_data'));

        $posData = app(OrderService::class)->getPOSOrderData(orderId: 7, cart: [], amount: 10, totalTaxAmount: 0, paidAmount: 10, paymentType: 'cash', addedBy: 'admin', userId: $customer->id, fulfillment: 'delivery');
        $this->assertSame($address->fresh()->toArray(), $posData['shipping_address_data']);
    }

    public function test_the_config_says_off_and_the_snapshot_helper_writes_the_old_copy(): void
    {
        $this->assertSame('off', app(ConfigController::class)->unifiedAddressConfig()['mode']);
        $address = $this->makeLegacyAddress(5);

        $this->assertSame((string) ShippingAddress::find($address->id), OrderManager::getOrderAddressSnapshot($address->id));
    }
}
