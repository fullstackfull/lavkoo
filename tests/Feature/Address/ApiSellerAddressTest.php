<?php

namespace Tests\Feature\Address;

use App\Http\Controllers\RestAPI\v3\seller\OrderController;
use App\Http\Controllers\RestAPI\v3\seller\POSController;
use App\Models\AddressCity;
use App\Models\Order;
use App\Models\Seller;
use App\Models\ShippingAddress;
use App\Services\Address\AddressSchema;
use App\Services\Address\OrderAddressService;
use Illuminate\Http\Request;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The seller app: correcting the address of one of the seller's orders, and placing a delivery sale at the point of sale.
 */
class ApiSellerAddressTest extends ApiAddressTestCase
{
    private const SELLER_ID = 5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTaxIncludedPrices();
    }

    /** A JSON request from the seller app, signed in as the seller the way the API middleware does it. */
    private function sellerRequest(string $uri, array $input): Request
    {
        $request = Request::create($uri, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($input));
        $request['seller'] = (new Seller())->forceFill(['id' => self::SELLER_ID]);

        return $request;
    }

    /** @return array<string, mixed> the copy an order keeps of a structured address */
    private function structuredCopy(): array
    {
        return app(OrderAddressService::class)->snapshot($this->makeStructuredAddress($this->ids, 5));
    }

    /** @param array<string, mixed> $snapshot */
    private function sellerOrder(int $id, array $snapshot, array $overrides = []): void
    {
        $this->insertOrder($id, $overrides + ['seller_id' => self::SELLER_ID, 'seller_is' => 'seller', 'shipping_address_data' => json_encode($snapshot), 'billing_address_data' => json_encode($snapshot)]);
    }

    /** @return array<string, mixed> */
    private function storedCopy(int $orderId, string $column = 'shipping_address_data'): array
    {
        return json_decode((string) DB::table('orders')->where('id', $orderId)->value($column), true);
    }

    private function updateAddress(array $input)
    {
        return app(OrderController::class)->address_update($this->sellerRequest('/api/v3/seller/orders/address-update', $input));
    }

    public function test_the_seller_edit_of_an_old_order_replaces_what_is_sent_and_keeps_everything_else_including_the_zip(): void
    {
        $this->sellerOrder(100, ['contact_person_name' => 'Sara', 'phone' => '0551234567', 'city' => 'الرياض', 'zip' => '12211', 'email' => 'sara@example.test', 'address' => 'King Fahd Rd', 'latitude' => '24.7', 'longitude' => '46.6']);

        $response = $this->updateAddress(['order_id' => 100, 'address_type' => 'shipping', 'phone' => '0559999999', 'address' => 'Olaya St 3']);

        $this->assertSame(200, $response->getStatusCode());
        $copy = $this->storedCopy(100);
        $this->assertSame(['0559999999', 'Olaya St 3', '12211', 'sara@example.test', '24.7', 'الرياض'], [$copy['phone'], $copy['address'], $copy['zip'], $copy['email'], $copy['latitude'], $copy['city']]);
        $this->assertArrayHasKey('updated_at', $copy);
        $this->assertSame('12211', $this->storedCopy(100, 'billing_address_data')['zip'], 'the other address of the order is untouched');
        $this->assertSame('King Fahd Rd', $this->storedCopy(100, 'billing_address_data')['address']);
    }

    public function test_the_full_old_payload_still_replaces_every_field_it_names(): void
    {
        $this->sellerOrder(100, ['contact_person_name' => 'Sara', 'phone' => '0551234567', 'city' => 'الرياض', 'zip' => '12211', 'address' => 'King Fahd Rd']);

        $this->updateAddress([
            'order_id' => 100, 'address_type' => 'billing', 'contact_person_name' => 'Huda', 'phone' => '0550000001', 'city' => 'جدة', 'zip' => '21589',
            'email' => 'huda@example.test', 'address' => 'Tahlia St', 'latitude' => '21.5', 'longitude' => '39.1',
        ]);

        $billing = $this->storedCopy(100, 'billing_address_data');
        $this->assertSame(['Huda', '0550000001', 'جدة', '21589', 'huda@example.test', 'Tahlia St', '21.5', '39.1'], [
            $billing['contact_person_name'], $billing['phone'], $billing['city'], $billing['zip'], $billing['email'], $billing['address'], $billing['latitude'], $billing['longitude'],
        ]);
        $this->assertSame('Sara', $this->storedCopy(100)['contact_person_name'], 'only the billing address was edited');
    }

    public function test_an_order_that_kept_no_copy_can_be_given_one(): void
    {
        $this->insertOrder(100, ['seller_id' => self::SELLER_ID, 'seller_is' => 'seller']);

        $this->assertSame(200, $this->updateAddress(['order_id' => 100, 'address_type' => 'shipping', 'contact_person_name' => 'Sara', 'phone' => '0551234567', 'city' => 'الرياض', 'address' => 'Somewhere'])->getStatusCode());

        $this->assertSame('الرياض', $this->storedCopy(100)['city']);
    }

    public function test_a_field_that_is_not_text_or_is_far_too_long_is_refused_and_nothing_changes(): void
    {
        $this->sellerOrder(100, ['address' => 'untouched', 'phone' => '0551234567']);

        foreach ([['contact_person_name' => ['Sara']], ['address' => str_repeat('a', 5000)], ['phone' => true], ['email' => str_repeat('e', 300)]] as $bad) {
            $response = $this->updateAddress(['order_id' => 100, 'address_type' => 'shipping'] + $bad);

            $this->assertSame(403, $response->getStatusCode(), json_encode($bad));
            $this->assertSame(array_key_first($bad), json_decode($response->getContent(), true)['errors'][0]['code'], 'the app is told which field it got wrong');
        }
        $this->assertSame(['untouched', '0551234567'], [$this->storedCopy(100)['address'], $this->storedCopy(100)['phone']]);
    }

    public function test_the_edit_is_audited_with_who_which_order_and_which_fields_but_no_address_text(): void
    {
        Schema::create('audit_logs', static function (Blueprint $table): void {
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
        $this->sellerOrder(100, ['contact_person_name' => 'Sara', 'phone' => '0551234567', 'city' => 'الرياض', 'address' => 'King Fahd Rd']);

        $this->updateAddress(['order_id' => 100, 'address_type' => 'shipping', 'phone' => '0559999999', 'address' => 'Olaya St 3']);

        $entry = DB::table('audit_logs')->where('action', 'order.address_updated')->sole();
        $context = json_decode($entry->context, true);
        $this->assertSame(['order', '100', 'shipping', 'api'], [$entry->subject_type, $entry->subject_id, $context['address_type'], $context['channel']]);
        $this->assertEqualsCanonicalizing(['phone', 'address'], array_diff($context['changed'], ['updated_at']));
        $this->assertStringNotContainsString('0559999999', json_encode($entry));
        $this->assertStringNotContainsString('Olaya', json_encode($entry));
    }

    public function test_a_phone_that_is_not_a_saudi_mobile_is_refused_on_a_phone_only_edit_once_every_client_must_send_the_structured_address(): void
    {
        $this->setAddressMode(AddressSchema::MODE_STRICT);
        $this->sellerOrder(100, $this->structuredCopy());

        $refused = $this->updateAddress(['order_id' => 100, 'address_type' => 'shipping', 'phone' => 'call me']);

        $this->assertSame(403, $refused->getStatusCode());
        $this->assertSame('phone', json_decode($refused->getContent(), true)['errors'][0]['code']);
        $this->assertSame('0551234567', $this->storedCopy(100)['phone']);
        $this->assertSame(200, $this->updateAddress(['order_id' => 100, 'address_type' => 'shipping', 'phone' => '0558888888'])->getStatusCode());
        $this->assertSame('0558888888', $this->storedCopy(100)['phone']);

        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->assertSame(200, $this->updateAddress(['order_id' => 100, 'address_type' => 'shipping', 'phone' => '12345'])->getStatusCode(), 'an older app may keep sending what it always sent');
    }

    public function test_a_seller_cannot_edit_the_address_of_an_order_that_is_not_theirs(): void
    {
        $this->sellerOrder(100, ['address' => 'untouched'], ['seller_id' => 6]);

        $response = $this->updateAddress(['order_id' => 100, 'address_type' => 'shipping', 'address' => 'Hijacked']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('untouched', $this->storedCopy(100)['address']);
    }

    public function test_an_unknown_address_type_changes_nothing_and_answers_as_before(): void
    {
        $this->sellerOrder(100, ['address' => 'untouched']);

        $response = $this->updateAddress(['order_id' => 100, 'address_type' => 'both', 'address' => 'Hijacked']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('untouched', $this->storedCopy(100)['address']);
    }

    public function test_an_order_with_a_structured_copy_cannot_be_given_a_free_text_city_but_its_phone_can_be_corrected(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $unified = $this->structuredCopy();
        $this->sellerOrder(100, $unified);

        $refused = $this->updateAddress(['order_id' => 100, 'address_type' => 'shipping', 'city' => 'Somewhere else']);
        $this->assertSame(403, $refused->getStatusCode());
        $this->assertSame('address', json_decode($refused->getContent(), true)['errors'][0]['code']);
        $this->assertSame($unified['city'], $this->storedCopy(100)['city']);

        $this->assertSame(200, $this->updateAddress(['order_id' => 100, 'address_type' => 'shipping', 'phone' => '0558888888'])->getStatusCode());
        $copy = $this->storedCopy(100);
        $this->assertSame(['0558888888', $this->ids['riyadh'], 'Riyadh', 'RAGI2929'], [$copy['phone'], $copy['city_id'], $copy['city_name_en'], $copy['national_address']]);
    }

    public function test_the_seller_can_move_an_order_to_another_city_and_the_ids_names_and_old_text_move_together(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->sellerOrder(100, $this->structuredCopy());

        $response = $this->updateAddress([
            'order_id' => 100, 'address_type' => 'shipping', 'region_id' => $this->ids['region_EAS'], 'city_id' => $this->ids['khobar'], 'national_address' => 'khbr 1234', 'district' => 'Al Rakah',
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $copy = $this->storedCopy(100);
        $this->assertSame([$this->ids['region_EAS'], $this->ids['khobar'], 'KHBR1234', 'Al Rakah'], [$copy['region_id'], $copy['city_id'], $copy['national_address'], $copy['district']]);
        $this->assertSame(['Al Khobar', 'Eastern Province', 'الخبر', 'المنطقة الشرقية'], [$copy['city_name_en'], $copy['region_name_en'], $copy['city'], $copy['state']]);
    }

    public function test_a_city_of_another_region_or_a_provider_id_is_refused_and_the_order_is_untouched(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->sellerOrder(100, $this->structuredCopy());
        $before = $this->storedCopy(100);

        foreach ([['region_id' => $this->ids['region_EAS'], 'city_id' => $this->ids['riyadh']], ['region_id' => $this->ids['region_RUH'], 'city_id' => 36189]] as $wrong) {
            $response = $this->updateAddress($wrong + ['order_id' => 100, 'address_type' => 'shipping', 'national_address' => 'RAGI2929']);

            $this->assertSame(403, $response->getStatusCode());
            $this->assertSame('city_id', json_decode($response->getContent(), true)['errors'][0]['code']);
        }
        $this->assertSame($before, $this->storedCopy(100));
    }

    public function test_the_copy_of_a_past_order_keeps_its_names_when_only_the_phone_is_corrected_after_the_city_was_renamed(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->sellerOrder(100, $this->structuredCopy());
        AddressCity::whereKey($this->ids['riyadh'])->update(['name_ar' => 'اسم جديد', 'name_en' => 'New name']);

        $this->updateAddress(['order_id' => 100, 'address_type' => 'shipping', 'phone' => '0558888888']);

        $copy = $this->storedCopy(100);
        $this->assertSame(['Riyadh', 'الرياض'], [$copy['city_name_en'], $copy['city_name_ar']], 'the order still says what it said the day it was placed');
    }

    /** @return array<string, mixed> */
    private function posSale(array $overrides = []): array
    {
        return $overrides + ['customer_id' => 5, 'cart' => [], 'modified_cart' => [], 'fulfillment' => 'delivery', 'payment_method' => 'cash', 'paid_amount' => 0];
    }

    private function placePosOrder(array $input)
    {
        return app(POSController::class)->place_order($this->sellerRequest('/api/v3/seller/pos/place-order', $input));
    }

    public function test_a_delivery_sale_to_a_registered_customer_keeps_the_copy_the_pos_always_kept_while_the_mode_is_off(): void
    {
        $address = $this->makeLegacyAddress(5, overrides: ['zip' => '']);
        $asLoaded = ShippingAddress::find($address->id);

        $response = $this->placePosOrder($this->posSale());

        $this->assertSame(200, $response->getStatusCode());
        $order = DB::table('orders')->first();
        $this->assertSame($address->id, (int) $order->shipping_address);
        $this->assertSame(json_encode($asLoaded), $order->shipping_address_data);
    }

    public function test_a_delivery_sale_never_ships_to_a_guest_address_that_happens_to_share_the_customers_number(): void
    {
        $this->makeLegacyAddress(5, isGuest: true, overrides: ['address' => 'A guest with the same number']);

        $response = $this->placePosOrder($this->posSale());

        $this->assertSame(200, $response->getStatusCode());
        $order = DB::table('orders')->first();
        $this->assertNull($order->shipping_address_data);
        $this->assertNull($order->shipping_address);
    }

    public function test_once_the_mode_is_on_a_delivery_sale_keeps_the_structured_copy_with_the_names_of_that_day(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->makeStructuredAddress($this->ids, 5, overrides: ['zip' => '12211']);

        $this->placePosOrder($this->posSale());

        $copy = json_decode((string) DB::table('orders')->value('shipping_address_data'), true);
        $this->assertSame(['unified', 'Riyadh', 'RAGI2929', '12211'], [$copy['address_schema'], $copy['city_name_en'], $copy['national_address'], $copy['zip']]);
    }

    public function test_in_strict_mode_a_delivery_sale_to_an_old_saudi_address_is_refused_before_anything_is_taken(): void
    {
        $this->setAddressMode(AddressSchema::MODE_STRICT);
        $this->makeLegacyAddress(5);

        $response = $this->placePosOrder($this->posSale());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('address', json_decode($response->getContent(), true)['errors'][0]['code']);
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_an_instant_sale_does_not_look_at_addresses_at_all(): void
    {
        $this->setAddressMode(AddressSchema::MODE_STRICT);
        $this->makeLegacyAddress(5);

        $response = $this->placePosOrder($this->posSale(['fulfillment' => 'instant']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull(DB::table('orders')->value('shipping_address_data'));
        $this->assertNotNull(Order::first());
    }
}
