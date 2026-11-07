<?php

namespace Tests\Feature\Address;

use App\Models\Order;
use App\Models\ShippingAddress;
use App\Services\Address\AddressSchema;
use App\Services\Address\AddressSnapshot;
use App\Services\Address\AddressSnapshotLines;
use App\Services\Address\StorefrontAddressService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The storefront's address rules without a page in front of them: what a posted form must carry, which saved addresses still have
 * to be completed, whose orders a phone number opens, and how the order pages word a copy of an address.
 */
class StorefrontAddressServiceTest extends StorefrontTestCase
{
    private StorefrontAddressService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(StorefrontAddressService::class);
    }

    private function insertOrder(int $id, ?array $shipping = null, ?array $billing = null, ?int $shippingAddressId = null, ?int $billingAddressId = null): Order
    {
        DB::table('orders')->insert([
            'id' => $id, 'customer_id' => 5, 'is_guest' => 1, 'order_type' => 'default_type', 'shipping_address' => $shippingAddressId, 'billing_address' => $billingAddressId,
            'shipping_address_data' => $shipping === null ? null : json_encode($shipping), 'billing_address_data' => $billing === null ? null : json_encode($billing),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Order::findOrFail($id);
    }

    public function test_a_posted_form_must_carry_what_the_checkout_cannot_do_without(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $abroad = ['contact_person_name' => 'Sara', 'address_type' => 'home', 'address' => 'Street', 'country' => 'United Arab Emirates', 'phone' => '+971501234567', 'city' => 'Dubai'];
        $saudi = ['country' => 'Saudi Arabia'] + array_diff_key($abroad, ['city' => 1]);

        $this->assertFalse($this->service->isIncomplete($abroad, billing: false, guest: false));
        $this->assertFalse($this->service->isIncomplete($saudi, billing: false, guest: false), 'a Saudi address has no free-text city: its place is the region and the city of the list');
        $this->assertTrue($this->service->isIncomplete(array_diff_key($abroad, ['city' => 1]), billing: false, guest: false), 'any other country does');
        foreach (['contact_person_name', 'address_type', 'address', 'country', 'phone'] as $field) {
            $this->assertTrue($this->service->isIncomplete(array_diff_key($abroad, [$field => 1]), billing: false, guest: false), $field);
            $this->assertTrue($this->service->isIncomplete([$field => ''] + $abroad, billing: false, guest: false), $field . ' left empty');
            $this->assertTrue($this->service->isIncomplete([$field => ['an', 'array']] + $abroad, billing: false, guest: false), $field . ' sent as a list');
        }
        $this->assertTrue($this->service->isIncomplete($abroad, billing: false, guest: true), 'a guest must give an e-mail');
        $this->assertFalse($this->service->isIncomplete($abroad + ['email' => 'guest@example.test'], billing: false, guest: true));
    }

    public function test_the_billing_form_is_read_under_its_own_names(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $billing = [
            'billing_contact_person_name' => 'Sara', 'billing_address_type' => 'home', 'billing_address' => 'Street', 'billing_country' => 'United Arab Emirates',
            'billing_phone' => '+971501234567', 'billing_city' => 'Dubai',
        ];

        $this->assertFalse($this->service->isIncomplete($billing, billing: true, guest: false));
        $this->assertTrue($this->service->isIncomplete($billing, billing: true, guest: true));
        $this->assertFalse($this->service->isIncomplete($billing + ['billing_contact_email' => 'guest@example.test'], billing: true, guest: true));
        $this->assertTrue($this->service->isIncomplete(array_diff_key($billing, ['billing_phone' => 1]), billing: true, guest: false));
    }

    public function test_while_the_structured_address_is_off_a_saudi_address_still_needs_its_city_text(): void
    {
        $saudi = ['contact_person_name' => 'Sara', 'address_type' => 'home', 'address' => 'Street', 'country' => 'Saudi Arabia', 'phone' => '+966551234567'];

        $this->assertTrue($this->service->isIncomplete($saudi, billing: false, guest: false));
        $this->assertFalse($this->service->isIncomplete($saudi + ['city' => 'الرياض'], billing: false, guest: false));
    }

    public function test_which_saved_addresses_still_have_to_be_completed(): void
    {
        $customer = $this->makeCustomer();
        $old = $this->makeLegacyAddress($customer->id);
        $complete = $this->makeStructuredAddress($this->ids, $customer->id);
        $badNationalAddress = $this->makeStructuredAddress($this->ids, $customer->id, overrides: ['national_address' => 'oops']);
        $abroad = $this->makeLegacyAddress($customer->id, overrides: ['country' => 'United Arab Emirates', 'city' => 'Dubai']);

        $this->assertSame([false, false, false, false], array_map(fn (ShippingAddress $address): bool => $this->service->needsCompletion($address), [$old, $complete, $badNationalAddress, $abroad]), 'off: nothing has to be completed');

        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->assertSame([true, false, true, false], array_map(fn (ShippingAddress $address): bool => $this->service->needsCompletion($address), [$old, $complete, $badNationalAddress, $abroad]));

        DB::table('address_cities')->where('id', $this->ids['riyadh'])->update(['is_active' => false]);
        $this->assertTrue($this->service->needsCompletion($complete->fresh()), 'a city that has been switched off has to be chosen again');
    }

    public function test_the_saved_addresses_offered_at_checkout_are_the_customers_own(): void
    {
        $customer = $this->signIn();
        $mine = $this->makeLegacyAddress($customer->id);
        $this->makeLegacyAddress($this->makeCustomer()->id);
        $this->makeLegacyAddress($customer->id, isGuest: true);
        $this->makeLegacyAddress(0);

        $this->assertSame([$mine->id], $this->service->checkoutAddresses()->modelKeys());
        $this->assertSame([$mine->id], $this->service->addressBook()->modelKeys());

        auth('customer')->logout();
        $this->assertSame([], $this->service->checkoutAddresses()->modelKeys());
    }

    public function test_the_json_of_a_saved_address_is_the_old_row_until_the_structured_address_is_on(): void
    {
        $customer = $this->makeCustomer();
        $saved = $this->makeStructuredAddress($this->ids, $customer->id);

        $this->assertSame($saved->toJson(), $this->service->prefillJson($saved));

        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $json = json_decode($this->service->prefillJson($saved), true);
        $this->assertSame(array_keys($saved->attributesToArray()), array_slice(array_keys($json), 0, count($saved->attributesToArray())), 'every old key comes first, in its place');
        $this->assertSame([false, 'Riyadh', 'الرياض', 'unified'], [$json['needs_completion'], $json['city_name_en'], $json['city_name_ar'], $json['address_schema']]);
    }

    public function test_the_structured_columns_of_a_move_to_another_country_are_cleared_with_the_state(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $structured = $this->makeStructuredAddress($this->ids, 1);

        $this->assertSame(
            ['region_id' => null, 'city_id' => null, 'district' => null, 'national_address' => null, 'state' => null],
            $this->service->structured(['country' => 'United Arab Emirates'], $structured),
        );
        $this->assertSame([], $this->service->structured(['country' => 'United Arab Emirates']), 'an address that never had them has nothing to clear');
    }

    public function test_a_saudi_address_that_names_no_place_is_refused_with_the_fields_to_correct(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);

        try {
            $this->service->structured(['country' => 'Saudi Arabia', 'phone' => '+966551234567']);
            $this->fail('the address names no region, city or National Address');
        } catch (ValidationException $exception) {
            $this->assertEqualsCanonicalizing(['region_id', 'city_id', 'national_address'], array_keys($exception->errors()));
        }
    }

    public function test_the_addresses_of_the_session_are_checked_before_an_order_is_placed(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->signIn();
        $complete = $this->makeStructuredAddress($this->ids, $customer->id);
        $old = $this->makeLegacyAddress($customer->id);

        $this->withSession(['address_id' => $complete->id, 'billing_address_id' => $complete->id]);
        $this->assertNull($this->service->checkoutRefusal(), 'their own complete address');

        $this->withSession(['address_id' => $old->id, 'billing_address_id' => $complete->id]);
        $this->assertSame(translate('address_complete_required'), $this->service->checkoutRefusal(), 'an old Saudi address has to be completed first');

        $this->withSession(['address_id' => $complete->id, 'billing_address_id' => $old->id]);
        $this->assertNotNull($this->service->checkoutRefusal(), 'the billing address is judged like the delivery one');
    }

    public function test_an_address_that_is_not_the_callers_is_refused_before_an_order_is_placed(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->signIn();
        $stranger = $this->makeCustomer();
        $theirs = $this->makeStructuredAddress($this->ids, $stranger->id);
        $guests = $this->makeStructuredAddress($this->ids, 8801, isGuest: true);

        $this->withSession(['address_id' => $theirs->id, 'billing_address_id' => 0]);
        $this->assertSame(translate('address_not_found'), $this->service->checkoutRefusal());

        $this->withSession(['address_id' => 987654, 'billing_address_id' => 0]);
        $this->assertSame(translate('address_not_found'), $this->service->checkoutRefusal(), 'an address that no longer exists');

        $this->withSession(['address_id' => $guests->id, 'billing_address_id' => 0]);
        $this->assertSame(translate('address_not_found'), $this->service->checkoutRefusal(), 'a guest session\'s address is not a signed-in customer\'s');
        $this->assertSame($customer->id, auth('customer')->id());
    }

    public function test_a_guest_is_checked_against_the_guest_session_and_an_empty_session_is_left_alone(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $mine = $this->makeStructuredAddress($this->ids, 8801, isGuest: true);
        $old = $this->makeLegacyAddress(8801, isGuest: true);

        $this->withSession(['guest_id' => 8801, 'address_id' => $mine->id, 'billing_address_id' => $mine->id]);
        $this->assertNull($this->service->checkoutRefusal());

        $this->withSession(['guest_id' => 8801, 'address_id' => $old->id, 'billing_address_id' => $old->id]);
        $this->assertSame(translate('address_complete_required'), $this->service->checkoutRefusal());

        $this->withSession(['guest_id' => 8802, 'address_id' => $mine->id, 'billing_address_id' => 0]);
        $this->assertSame(translate('address_not_found'), $this->service->checkoutRefusal(), 'another guest\'s address');

        $this->withSession(['guest_id' => 8801]);
        $this->assertNull($this->service->checkoutRefusal(), 'no address in the session: the old checks own that case');
    }

    public function test_while_the_structured_address_is_off_an_old_saudi_address_is_not_refused(): void
    {
        $customer = $this->signIn();
        $old = $this->makeLegacyAddress($customer->id);

        $this->withSession(['address_id' => $old->id, 'billing_address_id' => $old->id]);
        $this->assertNull($this->service->checkoutRefusal());
    }

    public function test_a_guest_order_is_opened_by_the_phone_it_was_placed_with(): void
    {
        $order = $this->insertOrder(200, shipping: ['phone' => '+966551234567', 'address' => 'Street'], billing: ['phone' => '+966559876543']);

        $this->assertTrue($this->service->orderWasPlacedWithPhone($order, '+966551234567'));
        $this->assertTrue($this->service->orderWasPlacedWithPhone($order, '+966559876543'), 'the billing phone opens it too');
        $this->assertFalse($this->service->orderWasPlacedWithPhone($order, '+966550000000'));
        $this->assertFalse($this->service->orderWasPlacedWithPhone($order, ''));
        $this->assertFalse($this->service->orderWasPlacedWithPhone($order, null));
        $this->assertFalse($this->service->orderWasPlacedWithPhone($order, ['+966551234567']));
    }

    public function test_the_saved_address_an_order_was_made_from_does_not_decide_who_may_open_it(): void
    {
        $saved = $this->makeLegacyAddress(5, true, ['phone' => '+966551111111']);
        $order = $this->insertOrder(201, shipping: ['phone' => '+966552222222'], shippingAddressId: $saved->id);

        $this->assertTrue($this->service->orderWasPlacedWithPhone($order, '+966552222222'));
        $this->assertFalse($this->service->orderWasPlacedWithPhone($order, '+966551111111'), 'the number the saved address has now is not the order\'s');

        $saved->update(['phone' => '+966553333333']);
        $this->assertFalse($this->service->orderWasPlacedWithPhone(Order::findOrFail(201), '+966553333333'));
        $saved->delete();
        $this->assertTrue($this->service->orderWasPlacedWithPhone(Order::findOrFail(201), '+966552222222'), 'and removing it changes nothing');
    }

    public function test_an_order_that_kept_no_copy_falls_back_to_the_saved_addresses_it_points_to(): void
    {
        $shipping = $this->makeLegacyAddress(5, true, ['phone' => '+966551111111']);
        $billing = $this->makeLegacyAddress(5, true, ['phone' => '+966554444444']);
        $order = $this->insertOrder(202, shippingAddressId: $shipping->id, billingAddressId: $billing->id);

        $this->assertTrue($this->service->orderWasPlacedWithPhone($order, '+966551111111'));
        $this->assertTrue($this->service->orderWasPlacedWithPhone($order, '+966554444444'));
        $this->assertFalse($this->service->orderWasPlacedWithPhone($order, '+966555555555'));

        $this->assertFalse($this->service->orderWasPlacedWithPhone($this->insertOrder(203), '+966551111111'), 'an order with no address at all opens for nobody');
    }

    public function test_a_phone_is_compared_the_way_it_always_was(): void
    {
        $order = $this->insertOrder(204, shipping: ['phone' => '0551234567']);

        $this->assertTrue($this->service->orderWasPlacedWithPhone($order, '0551234567'));
        $this->assertTrue($this->service->orderWasPlacedWithPhone($order, '551234567'), 'two numeric strings are equal when their numbers are');
        $this->assertFalse($this->service->orderWasPlacedWithPhone($order, '0551234568'));
    }

    public function test_the_lines_of_an_address_copy_are_printed_only_when_the_copy_has_something_to_say(): void
    {
        $saved = $this->makeStructuredAddress($this->ids, 1, overrides: ['district' => 'Al Olaya']);
        $structured = AddressSnapshot::build($saved);

        $this->assertSame([
            ['label' => 'address_region', 'value' => 'منطقة الرياض'], ['label' => 'address_city', 'value' => 'الرياض'],
            ['label' => 'address_district', 'value' => 'Al Olaya'], ['label' => 'address_national_address', 'value' => 'RAGI2929'],
        ], AddressSnapshotLines::location($structured, 'ar'));
        $this->assertSame('Riyadh', AddressSnapshotLines::location($structured, 'en')[1]['value']);

        $this->assertSame([['label' => 'address_city', 'value' => 'الرياض'], ['label' => 'zip_code', 'value' => '12211']], AddressSnapshotLines::location(['city' => 'الرياض', 'zip' => '12211']));
        $this->assertSame([['label' => 'address_city', 'value' => 'الرياض']], AddressSnapshotLines::location((object) ['city' => 'الرياض', 'zip' => ' ']));
        $this->assertSame([['label' => 'address_region', 'value' => 'Riyadh Province'], ['label' => 'address_city', 'value' => 'Riyadh']], AddressSnapshotLines::location(['state' => 'Riyadh Province', 'city' => 'Riyadh']), 'an old copy that has a state names it');
        $this->assertSame([], AddressSnapshotLines::location([]));
        $this->assertSame([], AddressSnapshotLines::location(null));
        $this->assertSame([], AddressSnapshotLines::location(['contact_person_name' => 'Only a name']));
    }

    public function test_two_copies_are_the_same_address_when_they_name_the_same_place(): void
    {
        $saved = $this->makeStructuredAddress($this->ids, 1);
        $copy = AddressSnapshot::build($saved);

        $this->assertTrue(AddressSnapshotLines::same($copy, $copy));
        $this->assertTrue(AddressSnapshotLines::same((object) $copy, $copy), 'a decoded object and an array read alike');
        $this->assertFalse(AddressSnapshotLines::same($copy, ['national_address' => 'JEDA1234'] + $copy));
        $this->assertFalse(AddressSnapshotLines::same($copy, ['district' => 'Elsewhere'] + $copy));
        $this->assertFalse(AddressSnapshotLines::same($copy, ['city_id' => $this->ids['jeddah']] + $copy));
        $this->assertFalse(AddressSnapshotLines::same($copy, ['address' => 'Another street'] + $copy));
        $this->assertTrue(AddressSnapshotLines::same(['address' => 'A', 'city' => 'B', 'zip' => null], ['address' => 'A', 'city' => 'B']));
        $this->assertFalse(AddressSnapshotLines::same(null, $copy));
        $this->assertFalse(AddressSnapshotLines::same($copy, []));
    }
}
