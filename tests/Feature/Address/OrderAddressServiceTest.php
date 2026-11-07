<?php

namespace Tests\Feature\Address;

use App\Enums\Address\AddressChannel;
use App\Models\AddressCity;
use App\Models\ShippingAddress;
use App\Services\Address\AddressSchema;
use App\Services\Address\OrderAddressService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Which saved addresses an order may be placed with, and the copy the order keeps of them.
 */
class OrderAddressServiceTest extends ApiAddressTestCase
{
    private function service(): OrderAddressService
    {
        return app(OrderAddressService::class);
    }

    /** @return array<string, list<string>> */
    private function refusal(callable $attempt): array
    {
        try {
            $attempt();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('The order should have been refused.');
    }

    public function test_an_address_is_the_callers_only_when_customer_and_guest_flag_both_match(): void
    {
        $customer = $this->makeLegacyAddress(5);
        $guest = $this->makeLegacyAddress(5, isGuest: true);

        $this->assertSame($customer->id, $this->service()->owned(addressId: $customer->id, customerId: 5, isGuest: false)?->id);
        $this->assertNull($this->service()->owned(addressId: $customer->id, customerId: 5, isGuest: true), 'guest 5 is not customer 5');
        $this->assertSame($guest->id, $this->service()->owned(addressId: $guest->id, customerId: 5, isGuest: true)?->id);
        $this->assertNull($this->service()->owned(addressId: $guest->id, customerId: 5, isGuest: false), 'customer 5 is not guest 5');
        $this->assertNull($this->service()->owned(addressId: $customer->id, customerId: 6, isGuest: false));
    }

    public function test_a_customer_may_use_an_address_of_the_guest_they_were_before_signing_in_but_not_another_guests(): void
    {
        $earlier = $this->makeLegacyAddress(7001, isGuest: true);

        $this->assertSame($earlier->id, $this->service()->owned(addressId: $earlier->id, customerId: 5, isGuest: false, guestId: 7001)?->id);
        $this->assertNull($this->service()->owned(addressId: $earlier->id, customerId: 5, isGuest: false, guestId: 7002));
        $this->assertNull($this->service()->owned(addressId: $earlier->id, customerId: 5, isGuest: false));
    }

    public function test_an_address_typed_at_checkout_and_never_saved_belongs_to_nobody_and_is_usable_on_the_website_only(): void
    {
        $unsaved = $this->makeLegacyAddress(0);

        $this->assertNull($this->service()->owned(addressId: $unsaved->id, customerId: 5, isGuest: false));
        $this->assertSame($unsaved->id, $this->service()->owned(addressId: $unsaved->id, customerId: 5, isGuest: false, allowUnsaved: true)?->id);
        $this->assertSame($unsaved->id, $this->service()->owned(addressId: $unsaved->id, customerId: null, isGuest: false, allowUnsaved: true)?->id);
    }

    #[DataProvider('notIds')]
    public function test_whatever_is_not_a_plain_positive_number_is_not_found(mixed $hostile): void
    {
        $address = $this->makeLegacyAddress(5);
        $this->makeLegacyAddress(0);

        $this->assertNull($this->service()->owned(addressId: $hostile, customerId: 5, isGuest: false, allowUnsaved: true));
        $this->assertNull($this->service()->owned(addressId: $address->id, customerId: $hostile, isGuest: false));
        $this->assertNull($this->service()->owned(addressId: $address->id, customerId: $hostile, isGuest: false, guestId: $hostile));
    }

    /** @return array<string, array{0: mixed}> */
    public static function notIds(): array
    {
        return ['text' => ['abc'], 'sql' => ['1 OR 1=1'], 'zero' => [0], 'zero text' => ['0'], 'negative' => [-1], 'float' => [1.0], 'array' => [[1]], 'null' => [null], 'prefix' => ['1abc'], 'blank' => ['']];
    }

    public function test_an_order_without_addresses_is_left_alone(): void
    {
        $this->setAddressMode(AddressSchema::MODE_STRICT);

        $this->service()->assertOrderable(shippingId: null, billingId: 0, customerId: 5, isGuest: false, channel: AddressChannel::Api);
        $this->service()->assertOrderable(shippingId: '', billingId: '0', customerId: null, isGuest: true, channel: AddressChannel::Web);

        $this->addToAssertionCount(1);
    }

    public function test_an_address_of_someone_else_or_one_that_is_gone_is_refused_for_the_delivery_and_the_billing_side(): void
    {
        $mine = $this->makeLegacyAddress(5);
        $theirs = $this->makeLegacyAddress(6);

        foreach ([[$theirs->id, $mine->id], [$mine->id, $theirs->id], [999, null], [null, 999]] as [$shipping, $billing]) {
            $errors = $this->refusal(fn () => $this->service()->assertOrderable(shippingId: $shipping, billingId: $billing, customerId: 5, isGuest: false, channel: AddressChannel::Api));

            $this->assertSame(['address'], array_keys($errors));
        }

        $this->service()->assertOrderable(shippingId: $mine->id, billingId: $mine->id, customerId: 5, isGuest: false, channel: AddressChannel::Api);
        $this->addToAssertionCount(1);
    }

    public function test_the_old_saudi_address_is_usable_while_the_mode_is_off(): void
    {
        $address = $this->makeLegacyAddress(5);

        foreach ([AddressChannel::Web, AddressChannel::Api] as $channel) {
            $this->service()->assertOrderable(shippingId: $address->id, billingId: null, customerId: 5, isGuest: false, channel: $channel);
        }

        $this->addToAssertionCount(1);
    }

    public function test_in_soft_mode_the_website_must_complete_an_old_saudi_address_but_the_app_may_still_use_it(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $address = $this->makeLegacyAddress(5);

        $this->service()->assertOrderable(shippingId: $address->id, billingId: null, customerId: 5, isGuest: false, channel: AddressChannel::Api);
        $errors = $this->refusal(fn () => $this->service()->assertOrderable(shippingId: $address->id, billingId: null, customerId: 5, isGuest: false, channel: AddressChannel::Web));

        $this->assertSame(translate('address_complete_required'), $errors['address'][0]);
    }

    public function test_in_strict_mode_nobody_orders_with_an_old_saudi_address_but_other_countries_are_unaffected(): void
    {
        $this->setAddressMode(AddressSchema::MODE_STRICT);
        $saudi = $this->makeLegacyAddress(5);
        $abroad = $this->makeLegacyAddress(5, overrides: ['country' => 'Egypt', 'city' => 'Cairo']);

        $this->refusal(fn () => $this->service()->assertOrderable(shippingId: $saudi->id, billingId: null, customerId: 5, isGuest: false, channel: AddressChannel::Api));
        $this->service()->assertOrderable(shippingId: $abroad->id, billingId: null, customerId: 5, isGuest: false, channel: AddressChannel::Api);
        $this->addToAssertionCount(1);
    }

    public function test_a_billing_address_that_cannot_be_used_stops_the_order_even_when_the_delivery_address_is_fine(): void
    {
        $this->setAddressMode(AddressSchema::MODE_STRICT);
        $structured = $this->makeStructuredAddress($this->ids, 5);
        $old = $this->makeLegacyAddress(5);

        $this->service()->assertOrderable(shippingId: $structured->id, billingId: $structured->id, customerId: 5, isGuest: false, channel: AddressChannel::Api);
        $this->refusal(fn () => $this->service()->assertOrderable(shippingId: $structured->id, billingId: $old->id, customerId: 5, isGuest: false, channel: AddressChannel::Api));
        $this->addToAssertionCount(1);
    }

    public function test_an_address_whose_city_was_switched_off_since_it_was_saved_is_stale(): void
    {
        $this->setAddressMode(AddressSchema::MODE_STRICT);
        $address = $this->makeStructuredAddress($this->ids, 5);
        AddressCity::whereKey($this->ids['riyadh'])->update(['is_active' => false]);
        AddressSchema::flush();

        $errors = $this->refusal(fn () => $this->service()->assertOrderable(shippingId: $address->id, billingId: null, customerId: 5, isGuest: false, channel: AddressChannel::Api));

        $this->assertSame(translate('address_city_invalid'), $errors['address'][0]);
    }

    public function test_the_copy_of_an_old_address_is_exactly_what_the_checkout_always_stored_while_the_mode_is_off(): void
    {
        $address = $this->makeLegacyAddress(5, overrides: ['zip' => '']);

        $snapshot = $this->service()->snapshot($address);

        $this->assertSame($address->toArray(), $snapshot);
        $this->assertSame((string) $address, json_encode($snapshot), 'byte for byte what binding the model used to store');
        $this->assertSame('', $snapshot['zip']);
        $this->assertArrayNotHasKey('city_name_ar', $snapshot);
    }

    public function test_the_copy_of_a_structured_address_freezes_the_names_even_when_the_mode_is_off(): void
    {
        $address = $this->makeStructuredAddress($this->ids, 5);

        $snapshot = $this->service()->snapshot($address);

        $this->assertSame(['unified', 'الرياض', 'Riyadh', 'منطقة الرياض', 'Riyadh', 'RAGI2929'], [
            $snapshot['address_schema'], $snapshot['city_name_ar'], $snapshot['city_name_en'], $snapshot['region_name_ar'], $snapshot['region_name_en'], $snapshot['national_address'],
        ]);
    }

    public function test_once_the_mode_is_on_the_copy_of_an_old_address_keeps_every_old_key_and_marks_itself_old(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $address = $this->makeLegacyAddress(5, overrides: ['zip' => null]);

        $snapshot = $this->service()->snapshot($address);

        foreach ($address->toArray() as $key => $value) {
            $this->assertArrayHasKey($key, $snapshot);
            $this->assertSame($value, $snapshot[$key], $key);
        }
        $this->assertArrayHasKey('zip', $snapshot);
        $this->assertNull($snapshot['zip']);
        $this->assertSame('legacy', $snapshot['address_schema']);
        $this->assertNull($snapshot['city_id']);
    }

    public function test_the_copy_of_a_form_array_gets_a_zip_key_and_a_stranger_city_id_is_dropped(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);

        $copy = $this->service()->snapshot(['city' => 'Jeddah', 'address' => 'Tahlia', 'city_id' => 999999, 'national_address' => 'RAGI2929']);

        $this->assertNull($copy['zip']);
        $this->assertNull($copy['city_id']);
        $this->assertNull($copy['national_address'], 'a National Address without a known city is not kept');
        $this->assertSame('legacy', $copy['address_schema']);
        $this->assertNull($this->service()->snapshot(null));
        $this->assertNull($this->service()->snapshot([]));
    }

    public function test_an_edit_replaces_what_was_sent_and_keeps_the_rest_of_an_old_copy(): void
    {
        $old = ['contact_person_name' => 'Sara', 'phone' => '0551234567', 'city' => 'الرياض', 'zip' => '12211', 'address' => 'King Fahd Rd', 'country' => 'Saudi Arabia', 'latitude' => '24.7'];

        $revised = $this->service()->revise($old, ['phone' => '0559999999', 'address' => 'Olaya St'], [], AddressChannel::Api);

        $this->assertSame(array_merge($old, ['phone' => '0559999999', 'address' => 'Olaya St']), $revised);
        $this->assertSame('12211', $revised['zip']);
    }

    public function test_an_edit_that_sends_no_zip_does_not_clear_it_and_one_that_sends_null_does(): void
    {
        $old = ['zip' => '12211', 'city' => 'الرياض'];

        $this->assertSame('12211', $this->service()->revise($old, ['city' => 'جدة'], [], AddressChannel::Api)['zip']);
        $this->assertNull($this->service()->revise($old, ['zip' => null], [], AddressChannel::Api)['zip']);
    }

    public function test_the_copy_of_an_address_that_has_no_copy_yet_can_be_edited_and_starts_with_every_key_the_pages_read(): void
    {
        $revised = $this->service()->revise(null, ['phone' => '0551234567'], [], AddressChannel::Api);

        $this->assertSame('0551234567', $revised['phone']);
        foreach (['contact_person_name', 'country', 'city', 'zip', 'email', 'address', 'latitude', 'longitude'] as $key) {
            $this->assertArrayHasKey($key, $revised, 'the order pages read this key without a default');
            $this->assertNull($revised[$key]);
        }
    }

    public function test_an_edit_that_gives_a_structured_address_a_free_text_city_is_refused_in_soft_mode(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $unified = $this->service()->snapshot($this->makeStructuredAddress($this->ids, 5));

        $errors = $this->refusal(fn () => $this->service()->revise($unified, ['city' => 'Somewhere'], [], AddressChannel::Api));
        $this->assertSame(['address'], array_keys($errors));

        $phoneOnly = $this->service()->revise($unified, ['phone' => '0559999999'], [], AddressChannel::Api);
        $this->assertSame('0559999999', $phoneOnly['phone']);
        $this->assertSame($unified['city_id'], $phoneOnly['city_id']);
        $this->assertSame('Riyadh', $phoneOnly['city_name_en']);
    }

    public function test_an_edit_that_moves_the_address_to_another_city_refreshes_the_ids_and_the_names_together(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $unified = $this->service()->snapshot($this->makeStructuredAddress($this->ids, 5));

        $revised = $this->service()->revise($unified, ['phone' => '0551234567'], [
            'region_id' => $this->ids['region_EAS'], 'city_id' => $this->ids['khobar'], 'national_address' => 'khbr 1234', 'district' => 'Al Rakah',
        ], AddressChannel::Api);

        $this->assertSame([$this->ids['region_EAS'], $this->ids['khobar'], 'KHBR1234', 'Al Rakah'], [$revised['region_id'], $revised['city_id'], $revised['national_address'], $revised['district']]);
        $this->assertSame(['الخبر', 'Al Khobar', 'المنطقة الشرقية', 'Eastern Province', 'الخبر'], [
            $revised['city_name_ar'], $revised['city_name_en'], $revised['region_name_ar'], $revised['region_name_en'], $revised['city'],
        ]);
        $this->assertSame('unified', $revised['address_schema']);
    }

    public function test_an_edit_with_a_city_from_another_region_is_refused(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);

        $errors = $this->refusal(fn () => $this->service()->revise(['country' => 'Saudi Arabia'], [], ['region_id' => $this->ids['region_EAS'], 'city_id' => $this->ids['riyadh'], 'national_address' => 'RAGI2929'], AddressChannel::Api));

        $this->assertArrayHasKey('city_id', $errors);
    }

    public function test_staff_deliver_to_the_latest_address_of_a_registered_customer_and_never_to_a_guests(): void
    {
        $this->makeLegacyAddress(5, overrides: ['address' => 'older']);
        $latest = $this->makeLegacyAddress(5, overrides: ['address' => 'latest']);
        $this->makeLegacyAddress(5, isGuest: true, overrides: ['address' => 'a guest with the same number']);
        $billing = $this->makeLegacyAddress(5, overrides: ['address' => 'billing', 'is_billing' => true]);

        $this->assertSame($latest->id, $this->service()->latestSavedAddress(5)?->id, 'a delivery address comes before a billing one, a guest address never');
        ShippingAddress::whereKey($latest->id)->delete();
        ShippingAddress::where('address', 'older')->delete();
        $this->assertSame($billing->id, $this->service()->latestSavedAddress('5')?->id, 'with nothing else the billing address is better than none');
        $this->assertNull($this->service()->latestSavedAddress(0));
        $this->assertNull($this->service()->latestSavedAddress('abc'));
        $this->assertNull($this->service()->latestSavedAddress(99));
    }
}
