<?php

namespace Tests\Feature\Address;

use App\Enums\Address\AddressChannel;
use App\Http\Controllers\RestAPI\v2\delivery_man\DeliveryManController;
use App\Models\AddressCity;
use App\Models\AddressRegion;
use App\Models\DeliveryMan;
use App\Models\Order;
use App\Models\ShippingAddress;
use App\Services\Address\AddressSchema;
use App\Services\Address\AddressSnapshot;
use App\Services\OrderService;
use App\Utils\OrderManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What an order keeps of its address: written once from the saved address, never rewritten by later edits, read back whatever
 * its age; and the check that stands in front of the write.
 */
class OrderSnapshotTest extends ApiAddressTestCase
{
    /** @return array<string, mixed> */
    private function storedSnapshot(int $orderId, string $column = 'shipping_address_data'): array
    {
        return json_decode((string) DB::table('orders')->where('id', $orderId)->value($column), true);
    }

    /** @return array<int, array<string, mixed>> */
    private function cartGroups(?int $shippingId, ?int $billingId, int $groups = 1): array
    {
        return array_fill(0, $groups, ['cart_group_id' => 'g', 'shipping_address_id' => $shippingId, 'billing_address_id' => $billingId]);
    }

    /** @return array{customer_id: int, is_guest: int, guest_id: int} */
    private function customerInfo(int $customerId, bool $isGuest = false, int $guestId = 0): array
    {
        return ['customer_id' => $customerId, 'is_guest' => (int) $isGuest, 'guest_id' => $guestId];
    }

    public function test_while_the_mode_is_off_the_stored_copy_is_byte_for_byte_what_the_model_used_to_bind(): void
    {
        $address = $this->makeLegacyAddress(5);
        $asTheCheckoutLoadedIt = (string) ShippingAddress::find($address->id);

        $this->assertSame($asTheCheckoutLoadedIt, OrderManager::getOrderAddressSnapshot($address->id));
        $this->assertSame($asTheCheckoutLoadedIt, OrderManager::getOrderAddressSnapshot((string) $address->id));
    }

    public function test_an_order_without_an_address_keeps_no_copy(): void
    {
        foreach ([null, 0, '0', '', 999] as $none) {
            $this->assertNull(OrderManager::getOrderAddressSnapshot($none));
        }
    }

    public function test_the_stored_copy_is_one_json_object_never_a_json_string_inside_json(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $address = $this->makeStructuredAddress($this->ids, 5);

        $stored = OrderManager::getOrderAddressSnapshot($address->id);

        $this->assertIsArray(json_decode($stored, true));
        $this->assertStringContainsString('\u', $stored, 'escaped like every other writer, so SQL on the raw text behaves the same');
    }

    public function test_the_copy_keeps_every_old_key_and_adds_the_structured_ones_with_the_names_of_that_day(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $address = $this->makeStructuredAddress($this->ids, 5, overrides: ['zip' => '12211']);

        $copy = json_decode((string) OrderManager::getOrderAddressSnapshot($address->id), true);

        foreach (['id', 'customer_id', 'is_guest', 'contact_person_name', 'email', 'address_type', 'address', 'city', 'zip', 'phone', 'state', 'country', 'latitude', 'longitude', 'is_billing', 'created_at', 'updated_at'] as $oldKey) {
            $this->assertArrayHasKey($oldKey, $copy, "the copy lost {$oldKey}");
        }
        $this->assertSame('12211', $copy['zip']);
        $this->assertSame([$address->id, 'الرياض', null, 'Saudi Arabia'], [$copy['id'], $copy['city'], $copy['state'], $copy['country']]);
        $this->assertSame([$this->ids['region_RUH'], $this->ids['riyadh'], 'Al Olaya', 'RAGI2929'], [$copy['region_id'], $copy['city_id'], $copy['district'], $copy['national_address']]);
        $this->assertSame(['منطقة الرياض', 'Riyadh', 'الرياض', 'Riyadh', 'unified'], [$copy['region_name_ar'], $copy['region_name_en'], $copy['city_name_ar'], $copy['city_name_en'], $copy['address_schema']]);
    }

    public function test_an_address_with_a_city_of_ours_but_no_national_address_is_copied_as_an_old_one_with_the_city_names_frozen(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $address = $this->makeStructuredAddress($this->ids, 5, overrides: ['national_address' => null, 'zip' => '12211']);

        $copy = json_decode((string) OrderManager::getOrderAddressSnapshot($address->id), true);

        $this->assertSame(['legacy', $this->ids['riyadh'], 'Riyadh', null, '12211'], [$copy['address_schema'], $copy['city_id'], $copy['city_name_en'], $copy['national_address'], $copy['zip']]);
        $this->assertFalse(AddressSnapshot::isUnified($copy));
    }

    public function test_the_zip_key_is_always_there_and_holds_exactly_what_the_address_held(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);

        foreach ([null, '', '12211'] as $zip) {
            $address = $this->makeLegacyAddress(5, overrides: ['zip' => $zip]);

            $copy = json_decode((string) OrderManager::getOrderAddressSnapshot($address->id), true);

            $this->assertArrayHasKey('zip', $copy);
            $this->assertSame($zip, $copy['zip']);
        }
    }

    public function test_a_past_order_does_not_change_when_the_address_the_city_or_the_region_does(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $address = $this->makeStructuredAddress($this->ids, 5);
        $this->insertOrder(100, ['shipping_address' => $address->id, 'shipping_address_data' => OrderManager::getOrderAddressSnapshot($address->id)]);
        $before = $this->storedSnapshot(100);

        $address->update(['address' => 'Moved street', 'phone' => '0550000000', 'national_address' => 'JEDD1111', 'city_id' => $this->ids['jeddah'], 'region_id' => $this->ids['region_MAK']]);
        AddressCity::whereKey($this->ids['riyadh'])->update(['name_ar' => 'الرياض الكبرى', 'name_en' => 'Greater Riyadh', 'is_active' => false]);
        AddressRegion::whereKey($this->ids['region_RUH'])->update(['name_ar' => 'منطقة أخرى', 'name_en' => 'Another region']);
        $address->delete();

        $this->assertSame($before, $this->storedSnapshot(100));
        $snapshot = Order::findOrFail(100)->shippingAddressSnapshot();
        $this->assertSame('الرياض', AddressSnapshot::cityName($snapshot, 'ar'));
        $this->assertSame('Riyadh', AddressSnapshot::cityName($snapshot, 'en'));
        $this->assertSame('Riyadh', AddressSnapshot::regionName($snapshot, 'en'));
        $this->assertSame('RAGI2929', AddressSnapshot::nationalAddress($snapshot));
    }

    /** @return array<string, array{0: mixed, 1: array<string, mixed>|null}> */
    public static function storedShapes(): array
    {
        $old = ['id' => 4, 'contact_person_name' => 'Sara', 'city' => 'الرياض', 'zip' => '12211', 'phone' => '0551234567', 'address' => 'King Fahd Rd'];

        return [
            'nothing' => [null, null],
            'empty text' => ['', null],
            'json null' => ['null', null],
            'empty list' => ['[]', null],
            'empty object' => ['{}', null],
            'a bare string' => ['"just a string"', null],
            'not json' => ['{broken', null],
            'an old copy without any new key' => [json_encode($old), $old],
            'a copy encoded twice by mistake' => [json_encode(json_encode($old)), $old],
        ];
    }

    /** @param array<string, mixed>|null $expected */
    #[DataProvider('storedShapes')]
    public function test_the_order_reads_back_every_shape_a_copy_has_ever_been_stored_in(mixed $stored, ?array $expected): void
    {
        $this->insertOrder(100, ['shipping_address_data' => $stored, 'billing_address_data' => $stored]);
        $order = Order::findOrFail(100);

        $this->assertSame($expected, $order->shippingAddressSnapshot());
        $this->assertSame($expected, $order->billingAddressSnapshot());
    }

    public function test_the_readers_of_the_snapshot_fall_back_to_the_old_keys_of_an_old_copy(): void
    {
        $old = ['city' => 'الرياض', 'state' => 'منطقة الرياض', 'zip' => '', 'address' => 'King Fahd Rd'];
        $this->insertOrder(100, ['shipping_address_data' => json_encode($old)]);
        $snapshot = Order::findOrFail(100)->shippingAddressSnapshot();

        $this->assertSame('الرياض', AddressSnapshot::cityName($snapshot, 'en'));
        $this->assertSame('منطقة الرياض', AddressSnapshot::regionName($snapshot, 'ar'));
        $this->assertNull(AddressSnapshot::nationalAddress($snapshot));
        $this->assertNull(AddressSnapshot::zip($snapshot), 'an empty ZIP is not a ZIP');
        $this->assertFalse(AddressSnapshot::isUnified($snapshot));
    }

    public function test_the_delivery_city_comes_from_the_copy_the_order_kept_and_the_saved_address_only_when_the_copy_names_none(): void
    {
        $saved = $this->makeLegacyAddress(5, overrides: ['city' => 'جدة']);
        $this->insertOrder(100, ['shipping_address' => $saved->id, 'shipping_address_data' => json_encode(['city' => 'الرياض'])]);
        $this->insertOrder(101, ['shipping_address' => $saved->id, 'shipping_address_data' => null]);
        $this->insertOrder(102, ['shipping_address' => null, 'shipping_address_data' => null]);
        $this->insertOrder(103, ['shipping_address' => $saved->id, 'shipping_address_data' => json_encode(['address' => 'King Fahd Rd', 'city' => ' '])]);

        $this->assertSame('الرياض', Order::findOrFail(100)->deliveryCity(), 'the customer moving the saved address later does not move a past order');
        $this->assertSame('جدة', Order::findOrFail(101)->deliveryCity());
        $this->assertNull(Order::findOrFail(102)->deliveryCity());
        $this->assertSame('جدة', Order::findOrFail(103)->deliveryCity(), 'a sparse copy that names no city reads like the old page: the saved address');
    }

    public function test_the_existing_property_accessors_of_the_order_are_unchanged(): void
    {
        $this->insertOrder(100, ['shipping_address_data' => json_encode(['city' => 'x']), 'billing_address_data' => null]);
        $this->insertOrder(101, ['shipping_address_data' => json_encode(json_encode(['city' => 'x'])), 'billing_address_data' => '[]']);

        $this->assertSame('x', Order::findOrFail(100)->shipping_address_data->city);
        $this->assertNull(Order::findOrFail(100)->billing_address_data);
        $this->assertIsString(Order::findOrFail(101)->shipping_address_data, 'a copy encoded twice reads as it always did');
        $this->assertNull(Order::findOrFail(101)->billing_address_data);
    }

    public function test_an_order_cannot_be_placed_with_an_address_that_is_not_the_customers(): void
    {
        $mine = $this->makeLegacyAddress(5);
        $theirs = $this->makeLegacyAddress(6);
        $data = ['address_channel' => AddressChannel::Api];

        foreach ([[$theirs->id, $mine->id], [$mine->id, $theirs->id], [999, null]] as [$shipping, $billing]) {
            $this->expectRefusal(fn () => OrderManager::scopeOrderAddresses($this->cartGroups($shipping, $billing), $data, $this->customerInfo(5)));
        }

        $carts = $this->cartGroups($mine->id, $mine->id, groups: 2);
        $this->assertSame($carts, OrderManager::scopeOrderAddresses($carts, $data, $this->customerInfo(5)));
    }

    private function expectRefusal(callable $attempt): void
    {
        try {
            $attempt();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('address', $exception->errors());

            return;
        }

        $this->fail('The order should have been refused.');
    }

    public function test_a_guest_who_registers_while_ordering_keeps_the_addresses_of_the_guest_they_were(): void
    {
        $guestAddress = $this->makeLegacyAddress(7001, isGuest: true);
        $info = $this->customerInfo(customerId: 55, isGuest: false, guestId: 7001);

        $carts = $this->cartGroups($guestAddress->id, $guestAddress->id);

        $this->assertSame($carts, OrderManager::scopeOrderAddresses($carts, ['address_channel' => AddressChannel::Api], $info));
        $this->expectRefusal(fn () => OrderManager::scopeOrderAddresses($carts, ['address_channel' => AddressChannel::Api], $this->customerInfo(customerId: 55)));
    }

    public function test_a_customer_who_signed_in_after_adding_an_address_as_a_guest_can_still_use_it_when_the_request_names_that_guest(): void
    {
        $guestAddress = $this->makeLegacyAddress(7001, isGuest: true);
        $carts = $this->cartGroups($guestAddress->id, null);

        $this->assertSame($carts, OrderManager::scopeOrderAddresses($carts, ['address_channel' => AddressChannel::Api, 'guest_id' => 7001], $this->customerInfo(5)));
        $this->expectRefusal(fn () => OrderManager::scopeOrderAddresses($carts, ['address_channel' => AddressChannel::Api, 'guest_id' => 7002], $this->customerInfo(5)));
    }

    public function test_the_website_may_order_with_an_address_typed_at_checkout_but_the_app_may_not(): void
    {
        $unsaved = $this->makeLegacyAddress(0);
        $carts = $this->cartGroups($unsaved->id, null);

        $this->assertSame($carts, OrderManager::scopeOrderAddresses($carts, [], $this->customerInfo(5)), 'no channel given means the website');
        $this->expectRefusal(fn () => OrderManager::scopeOrderAddresses($carts, ['address_channel' => AddressChannel::Api], $this->customerInfo(5)));
    }

    public function test_the_website_still_asks_a_customer_to_complete_an_old_saudi_address_in_soft_mode(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $old = $this->makeLegacyAddress(5);

        $this->expectRefusal(fn () => OrderManager::scopeOrderAddresses($this->cartGroups($old->id, null), [], $this->customerInfo(5)));
        $carts = $this->cartGroups($old->id, null);
        $this->assertSame($carts, OrderManager::scopeOrderAddresses($carts, ['address_channel' => AddressChannel::Api], $this->customerInfo(5)));
    }

    public function test_an_order_that_is_already_paid_is_never_refused_but_never_keeps_an_address_that_is_not_the_customers(): void
    {
        $this->setAddressMode(AddressSchema::MODE_STRICT);
        $old = $this->makeLegacyAddress(5);
        $theirs = $this->makeLegacyAddress(6);
        $paid = ['address_channel' => AddressChannel::Api, 'enforce_address_rules' => false];

        $kept = OrderManager::scopeOrderAddresses($this->cartGroups($old->id, $old->id, groups: 2), $paid, $this->customerInfo(5));
        $this->assertSame([$old->id, $old->id], [$kept[0]['shipping_address_id'], $kept[0]['billing_address_id']], 'an old Saudi address is kept for review, not refused after the money was taken');
        $this->assertCount(2, $kept);

        $stripped = OrderManager::scopeOrderAddresses($this->cartGroups($theirs->id, $old->id, groups: 2), $paid, $this->customerInfo(5));
        foreach ($stripped as $cart) {
            $this->assertSame([0, $old->id], [$cart['shipping_address_id'], $cart['billing_address_id']]);
            $this->assertSame('g', $cart['cart_group_id']);
        }
        $this->assertNull(OrderManager::getOrderAddressSnapshot($stripped[0]['shipping_address_id']), 'so nothing of the stranger lands in the order');
    }

    public function test_an_order_without_cart_groups_passes_through(): void
    {
        $this->assertSame([], OrderManager::scopeOrderAddresses([], ['address_channel' => AddressChannel::Api], $this->customerInfo(5)));
    }

    public function test_the_courier_is_shown_the_address_the_order_was_placed_with_not_the_saved_one_after_an_edit_or_delete(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $address = $this->makeStructuredAddress($this->ids, 5, overrides: ['zip' => '12211']);
        $this->insertOrder(100, ['delivery_man_id' => 9, 'shipping_address' => $address->id, 'shipping_address_data' => OrderManager::getOrderAddressSnapshot($address->id)]);
        $address->update(['city' => 'جدة', 'address' => 'Changed after the order', 'phone' => '0550000000']);

        $first = $this->courierOrders()[0]['shipping_address'];
        $this->assertSame(['الرياض', 'King Fahd Rd 12', '0551234567', '12211', 'RAGI2929', 'Al Olaya'], [$first['city'], $first['address'], $first['phone'], $first['zip'], $first['national_address'], $first['district']]);
        $this->assertSame(['Riyadh', 'منطقة الرياض', 'unified'], [$first['city_name_en'], $first['region_name_ar'], $first['address_schema']]);

        $address->delete();
        $this->assertSame('King Fahd Rd 12', $this->courierOrders()[0]['shipping_address']['address'], 'deleting the saved address does not blank the courier app');
    }

    public function test_the_courier_still_gets_every_key_of_the_old_address_object_and_the_same_key_name(): void
    {
        $this->insertOrder(100, ['delivery_man_id' => 9, 'shipping_address_data' => json_encode(['contact_person_name' => 'POS customer', 'phone' => '0551234567', 'address' => 'Somewhere', 'city' => 'الرياض'])]);

        $courierOrder = $this->courierOrders()[0];

        $this->assertIsArray($courierOrder['shipping_address']);
        foreach (['id', 'customer_id', 'is_guest', 'contact_person_name', 'email', 'address_type', 'address', 'city', 'zip', 'phone', 'state', 'country', 'latitude', 'longitude', 'is_billing', 'created_at', 'updated_at'] as $key) {
            $this->assertArrayHasKey($key, $courierOrder['shipping_address']);
        }
        $this->assertNull($courierOrder['shipping_address']['zip']);
        $this->assertArrayNotHasKey('national_address', $courierOrder['shipping_address'], 'an old copy has nothing structured to add');
    }

    public function test_an_order_that_kept_no_copy_still_shows_the_saved_address_to_the_courier(): void
    {
        $address = $this->makeLegacyAddress(5);
        $this->insertOrder(100, ['delivery_man_id' => 9, 'shipping_address' => $address->id]);

        $this->assertSame($address->id, $this->courierOrders()[0]['shipping_address']['id']);
    }

    public function test_the_other_courier_endpoints_use_the_copy_too(): void
    {
        $address = $this->makeLegacyAddress(5);
        $this->insertOrder(100, ['delivery_man_id' => 9, 'shipping_address' => $address->id, 'shipping_address_data' => json_encode(['address' => 'As ordered', 'city' => 'الرياض'])]);
        $address->update(['address' => 'Edited later']);
        $controller = app(DeliveryManController::class);

        $all = json_decode($controller->get_all_orders($this->courierRequest())->getContent(), true);
        $item = json_decode($controller->getOrderItem($this->courierRequest(['id' => 100]))->getContent(), true);

        $this->assertSame('As ordered', $all[0]['shipping_address']['address']);
        $this->assertSame('As ordered', $item['shipping_address']['address']);
    }

    public function test_a_courier_is_shown_only_the_orders_assigned_to_them(): void
    {
        $this->insertOrder(100, ['delivery_man_id' => 3, 'shipping_address_data' => json_encode(['address' => 'Somebody else\'s delivery', 'phone' => '0551234567'])]);
        $this->insertOrder(101, ['delivery_man_id' => 9, 'shipping_address_data' => json_encode(['address' => 'Mine', 'phone' => '0551234567'])]);
        $controller = app(DeliveryManController::class);

        $theirs = $controller->getOrderItem($this->courierRequest(['id' => 100]));
        $mine = json_decode($controller->getOrderItem($this->courierRequest(['id' => 101]))->getContent(), true);

        $this->assertSame([], json_decode($theirs->getContent(), true), 'the order of another courier is not there, as an order that does not exist is not');
        $this->assertSame('Mine', $mine['shipping_address']['address']);
    }

    /** @param array<string, mixed> $input */
    private function courierRequest(array $input = []): Request
    {
        $request = Request::create('/api/v2/delivery-man/orders', 'GET', $input);
        $request->merge(['delivery_man' => (new DeliveryMan())->forceFill(['id' => 9])]);

        return $request;
    }

    /** @return array<int, array<string, mixed>> */
    private function courierOrders(): array
    {
        return json_decode(app(DeliveryManController::class)->get_current_orders($this->courierRequest())->getContent(), true);
    }

    /** @return array<string, mixed> the order the panel POS would write for a delivery sale to this customer */
    private function posOrderFor(int $customerId): array
    {
        $this->useTaxIncludedPrices();

        return app(OrderService::class)->getPOSOrderData(
            orderId: 7, cart: [], amount: 10, totalTaxAmount: 0, paidAmount: 10, paymentType: 'cash', addedBy: 'admin', userId: $customerId, fulfillment: 'delivery',
        );
    }

    public function test_a_panel_pos_sale_keeps_the_copy_it_always_kept_while_the_mode_is_off(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeLegacyAddress($customer->id, overrides: ['zip' => '']);

        $order = $this->posOrderFor($customer->id);

        $this->assertSame($address->fresh()->toArray(), $order['shipping_address_data']);
        $this->assertSame($order['shipping_address_data'], $order['billing_address_data']);
        $this->assertSame($address->id, $order['shipping_address']);
        $this->assertSame('', $order['shipping_address_data']['zip']);
    }

    public function test_a_panel_pos_sale_freezes_the_names_once_the_mode_is_on(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->makeCustomer();
        $this->makeStructuredAddress($this->ids, $customer->id);

        $copy = $this->posOrderFor($customer->id)['shipping_address_data'];

        $this->assertSame(['unified', 'Riyadh', 'RAGI2929'], [$copy['address_schema'], $copy['city_name_en'], $copy['national_address']]);
        $this->assertArrayHasKey('zip', $copy);
    }

    public function test_a_panel_pos_sale_to_a_customer_without_a_saved_address_still_uses_the_customer_profile(): void
    {
        $customer = $this->makeCustomer(['street_address' => 'Profile street', 'city' => 'Dammam', 'zip' => '31411', 'country' => 'Saudi Arabia', 'house_no' => '12']);
        $this->makeLegacyAddress($customer->id, isGuest: true, overrides: ['address' => 'A guest with the same number']);

        $order = $this->posOrderFor($customer->id);

        $this->assertNull($order['shipping_address']);
        $this->assertSame(['Profile street', 'Dammam', '31411', '12'], [
            $order['shipping_address_data']['address'], $order['shipping_address_data']['city'], $order['shipping_address_data']['zip'], $order['shipping_address_data']['house_no'],
        ]);
    }

    public function test_the_address_of_an_auction_claim_is_left_as_it_was_given_unless_it_names_a_city_of_the_list_and_a_value_that_is_not_an_address_is_left_alone(): void
    {
        $claim = ['country' => 'Saudi Arabia', 'city' => 'Jeddah', 'zip' => '21589', 'address' => 'Tahlia St', 'region_id' => 'junk'];

        $this->assertSame($claim, auction_address_snapshot($claim), 'while the mode is off the claim keeps exactly the keys it was given');
        $this->assertNull(auction_address_snapshot(null));
        $this->assertSame('{"city":"x"}', auction_address_snapshot('{"city":"x"}'));
        $this->assertSame([], auction_address_snapshot([]));

        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $copy = auction_address_snapshot($claim + ['city_id' => $this->ids['jeddah'], 'national_address' => 'JEDD1111']);

        $this->assertSame(['21589', 'Tahlia St', $this->ids['jeddah'], $this->ids['region_MAK'], 'Jeddah', 'JEDD1111', 'unified'], [
            $copy['zip'], $copy['address'], $copy['city_id'], $copy['region_id'], $copy['city_name_en'], $copy['national_address'], $copy['address_schema'],
        ]);
        $this->assertSame($claim, auction_address_snapshot($claim), 'a claim from the claim page, which still posts the old fields, keeps exactly the keys it was given in every mode');
        $this->assertSame(['city' => 'Jeddah'], auction_address_snapshot(['city' => 'Jeddah']), 'no technical key is added to what the claim page prints');
    }
}
