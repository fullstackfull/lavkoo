<?php

namespace Tests\Feature\Address;

use App\Models\ShippingAddress;
use App\Services\Address\AddressSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * The address step of the website's checkout (customer.choose-shipping-address-other), for a signed-in customer and for a guest:
 * what the old form still does when the structured address is off, what the Saudi form must send when it is on, and the id of
 * an address that never comes from anything but the caller's own address book.
 */
class StorefrontCheckoutTest extends StorefrontTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setBusinessSetting('billing_input_by_customer', 1);
    }

    /** @return array<string, mixed> what the old page posted for a shipping address */
    private function oldForm(array $overrides = []): array
    {
        return $overrides + [
            'contact_person_name' => 'Sara Alharbi', 'phone' => '+966551234567', 'address_type' => 'home', 'country' => 'Saudi Arabia',
            'city' => 'الرياض', 'zip' => '12211', 'address' => 'King Fahd Rd 12', 'latitude' => '24.7', 'longitude' => '46.6', 'shipping_method_id' => 0,
        ];
    }

    /** @return array<string, mixed> what the page posts for a Saudi shipping address while the structured address is on */
    private function saudiForm(array $overrides = []): array
    {
        return $overrides + [
            'contact_person_name' => 'Sara Alharbi', 'phone' => '+966551234567', 'address_type' => 'home', 'country' => 'Saudi Arabia',
            'region_id' => $this->ids['region_RUH'], 'city_id' => $this->ids['riyadh'], 'district' => 'Al Olaya', 'national_address' => 'RAGI2929',
            'address' => 'King Fahd Rd 12', 'latitude' => '', 'longitude' => '', 'shipping_method_id' => 0,
        ];
    }

    /** @return array<string, mixed> the same, for the billing form */
    private function saudiBillingForm(array $overrides = []): array
    {
        return $overrides + [
            'billing_contact_person_name' => 'Khalid Alharbi', 'billing_phone' => '+966559876543', 'billing_address_type' => 'office', 'billing_country' => 'Saudi Arabia',
            'billing_region_id' => $this->ids['region_MAK'], 'billing_city_id' => $this->ids['jeddah'], 'billing_district' => 'Al Rawdah', 'billing_national_address' => 'JEDA1234',
            'billing_address' => 'Tahlia St 5', 'billing_latitude' => '', 'billing_longitude' => '', 'billing_method_id' => 0,
        ];
    }

    private function postCheckout(array $shipping, ?array $billing = null, array $extra = []): TestResponse
    {
        return $this->postJson(route('customer.choose-shipping-address-other'), $extra + [
            'physical_product' => 'yes',
            'shipping' => http_build_query($shipping),
            'billing' => http_build_query($billing ?? []),
            'billing_addresss_same_shipping' => $billing === null ? 'true' : 'false',
        ]);
    }

    /** The id the checkout step left in the session for the payment step (it may be kept as the string the form sent). */
    private function sessionAddress(string $key = 'address_id'): ?int
    {
        return session($key) === null ? null : (int) session($key);
    }

    private function asGuest(int $guestId = 701): void
    {
        $this->withSession(['guest_id' => $guestId]);
    }

    public function test_off_a_new_address_is_kept_as_an_unsaved_copy_for_the_customer(): void
    {
        $customer = $this->signIn();

        $this->postCheckout($this->oldForm())->assertOk();

        $address = ShippingAddress::sole();
        $this->assertSame(['0', false, false], [(string) $address->customer_id, $address->is_guest, $address->is_billing]);
        $this->assertSame(['Sara Alharbi', 'home', 'King Fahd Rd 12', 'الرياض', '12211', 'Saudi Arabia', '+966551234567', '24.7', '46.6'], [
            $address->contact_person_name, $address->address_type, $address->address, $address->city, $address->zip, $address->country, $address->phone,
            $address->latitude, $address->longitude,
        ]);
        $this->assertNull($address->email, 'a signed-in customer has an account, so the copy carries no e-mail');
        $this->assertNull($address->region_id);
        $this->assertSame($address->id, $this->sessionAddress());
        $this->assertSame($address->id, $this->sessionAddress('billing_address_id'), 'the billing address is the shipping one when the box is ticked');
        $this->assertSame(0, ShippingAddress::where('customer_id', $customer->id)->count());
    }

    public function test_off_saving_the_address_puts_it_in_the_customers_address_book(): void
    {
        $customer = $this->signIn();

        $this->postCheckout($this->oldForm(['save_address' => 'on']))->assertOk();

        $address = ShippingAddress::sole();
        $this->assertSame([(string) $customer->id, false], [(string) $address->customer_id, $address->is_guest]);
        $this->assertSame($address->id, $this->sessionAddress());
    }

    public function test_off_updating_a_saved_address_changes_that_row_and_nothing_else(): void
    {
        $customer = $this->signIn();
        $saved = $this->makeLegacyAddress($customer->id);
        $other = $this->makeLegacyAddress($customer->id, overrides: ['address' => 'Olaya St 3']);

        $this->postCheckout($this->oldForm(['shipping_method_id' => $saved->id, 'update_address' => 'on', 'address' => 'Tahlia St 9', 'zip' => '12345']))->assertOk();

        $this->assertSame(['Tahlia St 9', '12345'], [$saved->fresh()->address, $saved->fresh()->zip]);
        $this->assertSame('Olaya St 3', $other->fresh()->address);
        $this->assertSame($saved->id, $this->sessionAddress());
        $this->assertSame(2, ShippingAddress::count());
    }

    public function test_off_identical_proceeds_share_one_unsaved_copy(): void
    {
        $this->signIn();

        $this->postCheckout($this->oldForm())->assertOk();
        $this->postCheckout($this->oldForm())->assertOk();
        $this->assertSame(1, ShippingAddress::count());

        $this->postCheckout($this->oldForm(['address' => 'Another street']))->assertOk();
        $this->assertSame(2, ShippingAddress::count());
    }

    public function test_off_a_guest_checkout_is_kept_for_the_guest_session_with_the_email(): void
    {
        $this->asGuest(701);

        $this->postCheckout($this->oldForm(['email' => 'guest@example.test']))->assertOk();

        $address = ShippingAddress::sole();
        $this->assertSame(['701', true, 'guest@example.test'], [(string) $address->customer_id, $address->is_guest, $address->email]);
        $this->assertSame($address->id, $this->sessionAddress());
    }

    public function test_off_a_guest_must_give_an_email(): void
    {
        $this->asGuest();

        $response = $this->postCheckout($this->oldForm());

        $response->assertStatus(403);
        $this->assertStringContainsString('email', strtolower($response->json('errors')));
        $this->assertSame(0, ShippingAddress::count());
    }

    public function test_off_missing_fields_are_refused_with_the_old_message(): void
    {
        $this->signIn();

        $response = $this->postCheckout($this->oldForm(['city' => '']));

        $response->assertStatus(403);
        $this->assertIsString($response->json('errors'), 'the checkout script prints errors as one message');
        $this->assertSame(0, ShippingAddress::count());
        $this->assertNull($this->sessionAddress());
    }

    public function test_off_a_client_that_posts_the_structured_fields_gets_the_old_address(): void
    {
        $this->signIn();

        $this->postCheckout($this->oldForm(['region_id' => $this->ids['region_RUH'], 'city_id' => $this->ids['riyadh'], 'national_address' => 'RAGI2929']))->assertOk();

        $address = ShippingAddress::sole();
        $this->assertSame(['الرياض', '12211'], [$address->city, $address->zip]);
        $this->assertSame([null, null, null, null], [$address->region_id, $address->city_id, $address->district, $address->national_address]);
    }

    public function test_the_zip_is_optional_in_the_posted_form(): void
    {
        $this->signIn();
        $form = $this->oldForm();
        unset($form['zip']);

        $this->postCheckout($form)->assertOk();

        $this->assertNull(ShippingAddress::sole()->zip);
    }

    public function test_the_zip_allow_list_still_judges_the_posted_zip(): void
    {
        $this->setBusinessSetting('delivery_zip_code_area_restriction', 1);
        DB::table('delivery_zip_codes')->insert(['zipcode' => '12211']);
        $this->signIn();

        $this->postCheckout($this->oldForm(['zip' => '99999']))->assertStatus(403);
        $this->postCheckout($this->oldForm(['zip' => '']))->assertStatus(403);
        $form = $this->oldForm();
        unset($form['zip']);
        $this->postCheckout($form)->assertStatus(403);
        $this->assertSame(0, ShippingAddress::count());

        $this->postCheckout($this->oldForm(['zip' => '12211']))->assertOk();
        $this->assertSame('12211', ShippingAddress::sole()->zip);
    }

    public function test_the_country_allow_list_still_judges_the_posted_country(): void
    {
        $this->setBusinessSetting('delivery_country_restriction', 1);
        DB::table('delivery_country_codes')->insert(['country_code' => 'AE']);
        $this->signIn();

        $this->postCheckout($this->oldForm())->assertStatus(403);
        $this->postCheckout($this->oldForm(['country' => 'United Arab Emirates']))->assertOk();
    }

    public function test_a_crafted_save_flag_cannot_put_another_customers_address_into_the_session(): void
    {
        $this->signIn();
        $victim = $this->makeLegacyAddress($this->makeCustomer()->id, overrides: ['contact_person_name' => 'Victim Name', 'address' => 'Victim Street 1']);

        $this->postCheckout($this->oldForm(['shipping_method_id' => $victim->id, 'save_address' => 'off']))->assertOk();

        $this->assertNotSame($victim->id, $this->sessionAddress());
        $this->assertSame('Sara Alharbi', ShippingAddress::findOrFail($this->sessionAddress())->contact_person_name);
        $this->assertSame('Victim Name', $victim->fresh()->contact_person_name);
    }

    public function test_a_crafted_update_flag_cannot_put_another_customers_address_into_the_session(): void
    {
        $this->signIn();
        $victim = $this->makeLegacyAddress($this->makeCustomer()->id, overrides: ['contact_person_name' => 'Victim Name']);

        $this->postCheckout($this->oldForm(['shipping_method_id' => $victim->id, 'update_address' => 'off']))->assertOk();

        $this->assertNotSame($victim->id, $this->sessionAddress());
        $this->assertSame('Victim Name', $victim->fresh()->contact_person_name);
    }

    public function test_updating_another_customers_address_is_refused_and_leaves_it_alone(): void
    {
        $this->signIn();
        $victim = $this->makeLegacyAddress($this->makeCustomer()->id, overrides: ['contact_person_name' => 'Victim Name']);

        $this->postCheckout($this->oldForm(['shipping_method_id' => $victim->id, 'update_address' => 'on']))->assertStatus(403);

        $this->assertSame('Victim Name', $victim->fresh()->contact_person_name);
        $this->assertNull($this->sessionAddress());
    }

    public function test_a_guest_cannot_update_or_reuse_a_customers_address(): void
    {
        $this->asGuest(701);
        $victim = $this->makeLegacyAddress($this->makeCustomer()->id, overrides: ['contact_person_name' => 'Victim Name']);

        $this->postCheckout($this->oldForm(['email' => 'guest@example.test', 'shipping_method_id' => $victim->id, 'update_address' => 'on']))->assertStatus(403);
        $this->postCheckout($this->oldForm(['email' => 'guest@example.test', 'shipping_method_id' => $victim->id, 'save_address' => 'nope']))->assertOk();

        $this->assertNotSame($victim->id, $this->sessionAddress());
        $this->assertSame('Victim Name', $victim->fresh()->contact_person_name);
    }

    public function test_the_billing_side_has_the_same_protection(): void
    {
        $this->signIn();
        $victim = $this->makeLegacyAddress($this->makeCustomer()->id, overrides: ['contact_person_name' => 'Victim Name']);
        $billing = [
            'billing_contact_person_name' => 'Sara Alharbi', 'billing_phone' => '+966551234567', 'billing_address_type' => 'home', 'billing_country' => 'Saudi Arabia',
            'billing_city' => 'جدة', 'billing_zip' => '', 'billing_address' => 'Tahlia St 5', 'billing_latitude' => '', 'billing_longitude' => '',
        ];

        $this->postCheckout($this->oldForm(), $billing + ['billing_method_id' => $victim->id, 'save_address_billing' => 'off'])->assertOk();
        $this->assertNotSame($victim->id, $this->sessionAddress('billing_address_id'));
        $this->assertSame('جدة', ShippingAddress::findOrFail($this->sessionAddress('billing_address_id'))->city);

        $this->postCheckout($this->oldForm(), $billing + ['billing_method_id' => $victim->id, 'update_billing_address' => 'on'])->assertStatus(403);
        $this->assertSame('Victim Name', $victim->fresh()->contact_person_name);
    }

    public function test_the_old_endpoint_has_the_same_protection_and_the_same_rules(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->signIn();
        $this->withSession(['_token' => 'the-sessions-token']);
        $victim = $this->makeLegacyAddress($this->makeCustomer()->id, overrides: ['contact_person_name' => 'Victim Name']);
        $payload = fn (array $shipping): array => ['_token' => 'the-sessions-token', 'physical_product' => 'yes', 'shipping' => http_build_query($shipping), 'billing' => '', 'billing_addresss_same_shipping' => 'true'];

        $this->postJson(route('customer.choose-shipping-address'), $payload($this->saudiForm(['shipping_method_id' => $victim->id])))->assertOk();
        $this->assertNotSame($victim->id, $this->sessionAddress());
        $this->assertSame('Sara Alharbi', ShippingAddress::findOrFail($this->sessionAddress())->contact_person_name);

        $this->postJson(route('customer.choose-shipping-address'), $payload($this->oldForm()))->assertStatus(403);
    }

    public function test_the_old_endpoint_does_not_answer_a_request_that_lacks_the_sessions_token(): void
    {
        $this->signIn();
        $this->withSession(['_token' => 'the-sessions-token']);
        $payload = ['physical_product' => 'yes', 'shipping' => http_build_query($this->oldForm()), 'billing' => '', 'billing_addresss_same_shipping' => 'true'];

        $this->postJson(route('customer.choose-shipping-address'), $payload)->assertStatus(419);
        $this->postJson(route('customer.choose-shipping-address'), ['_token' => 'forged'] + $payload)->assertStatus(419);
        $this->assertSame(0, ShippingAddress::count());
        $this->assertNull($this->sessionAddress());

        $this->withHeader('X-CSRF-TOKEN', 'the-sessions-token')->postJson(route('customer.choose-shipping-address'), $payload)->assertOk();
        $this->assertSame(1, ShippingAddress::count());
    }

    public function test_soft_a_saudi_address_is_saved_with_its_region_city_and_national_address(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->signIn();

        $this->postCheckout($this->saudiForm(['national_address' => ' ragi-2929 ']))->assertOk();

        $address = ShippingAddress::sole();
        $this->assertSame(
            [$this->ids['region_RUH'], $this->ids['riyadh'], 'Al Olaya', 'RAGI2929'],
            [$address->region_id, $address->city_id, $address->district, $address->national_address],
        );
        $this->assertSame(['الرياض', null, 'Saudi Arabia'], [$address->city, $address->state, $address->country], 'the old city and country columns carry the names the readers expect; the state is not written (the shipping zones read it)');
        $this->assertNull($address->zip, 'a ZIP is never derived from the National Address');
        $this->assertSame('', $address->latitude, 'a post without coordinates stores none (the page itself posts the default location, as the old form did)');
        $this->assertSame($address->id, $this->sessionAddress());
    }

    public function test_soft_the_same_rules_hold_in_strict_mode(): void
    {
        $this->setAddressMode(AddressSchema::MODE_STRICT);
        $this->signIn();

        $this->postCheckout($this->oldForm())->assertStatus(403);
        $this->postCheckout($this->saudiForm())->assertOk();
    }

    public function test_soft_a_free_text_city_is_not_accepted_for_a_saudi_address(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->signIn();

        $response = $this->postCheckout($this->oldForm());

        $response->assertStatus(403);
        $this->assertIsString($response->json('errors'));
        $this->assertEqualsCanonicalizing(['region_id', 'city_id', 'national_address'], array_keys($response->json('fields')));
        $this->assertSame(0, ShippingAddress::count());
        $this->assertNull($this->sessionAddress());
    }

    public function test_soft_a_city_that_is_not_in_the_chosen_region_is_refused(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->signIn();

        $response = $this->postCheckout($this->saudiForm(['city_id' => $this->ids['jeddah']]));

        $response->assertStatus(403);
        $this->assertSame(['city_id'], array_keys($response->json('fields')));
        $this->assertSame('', $response->json('prefix'), 'the shipping form names its inputs as the fields are named');
        $this->assertSame(0, ShippingAddress::count());
    }

    public function test_soft_ids_that_are_not_plain_numbers_are_refused(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->signIn();

        foreach (['1e1', '0', '-1', '1.5', 'x', '1 OR 1=1'] as $tampered) {
            $this->postCheckout($this->saudiForm(['region_id' => $tampered]))->assertStatus(403);
        }
        $this->assertSame(0, ShippingAddress::count());
    }

    public function test_soft_a_switched_off_city_is_refused(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->signIn();
        DB::table('address_cities')->where('id', $this->ids['riyadh'])->update(['is_active' => false]);

        $this->postCheckout($this->saudiForm())->assertStatus(403);
        $this->assertSame(0, ShippingAddress::count());
    }

    public function test_soft_a_malformed_national_address_is_refused(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->signIn();

        foreach (['RAG2929', 'RAGI29', '12345678', 'RAGI29292', 'RAGI-ABCD', ''] as $malformed) {
            $response = $this->postCheckout($this->saudiForm(['national_address' => $malformed]));
            $response->assertStatus(403);
            $this->assertSame(['national_address'], array_keys($response->json('fields')), $malformed);
        }
        $this->assertSame(0, ShippingAddress::count());
    }

    public function test_soft_a_phone_that_is_not_a_saudi_mobile_is_refused_for_a_saudi_address(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->signIn();

        $response = $this->postCheckout($this->saudiForm(['phone' => '+971501234567']));

        $response->assertStatus(403);
        $this->assertSame(['phone'], array_keys($response->json('fields')));
    }

    public function test_soft_provider_ids_sent_by_a_client_are_never_stored(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->signIn();

        $this->postCheckout($this->saudiForm(['torod_city_id' => '987', 'external_city_id' => '654', 'provider_city_id' => '321']))->assertOk();

        $this->assertStringNotContainsString('987', json_encode(ShippingAddress::sole()->getAttributes()));
    }

    public function test_soft_other_countries_keep_the_free_text_city_and_the_zip(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->signIn();

        $this->postCheckout($this->oldForm(['country' => 'United Arab Emirates', 'city' => 'Dubai', 'zip' => '00000-AE', 'phone' => '+971501234567']))->assertOk();

        $address = ShippingAddress::sole();
        $this->assertSame(['United Arab Emirates', 'Dubai', '00000-AE'], [$address->country, $address->city, $address->zip]);
        $this->assertSame([null, null, null, null, null], [$address->region_id, $address->city_id, $address->district, $address->national_address, $address->state]);
    }

    public function test_soft_a_saudi_address_without_a_zip_field_gets_no_zip(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->signIn();
        $form = $this->saudiForm();
        $this->assertArrayNotHasKey('zip', $form);

        $this->postCheckout($form)->assertOk();

        $this->assertNull(ShippingAddress::sole()->zip);
    }

    public function test_soft_an_old_saved_address_must_be_completed_before_the_checkout_continues(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->signIn();
        $old = $this->makeLegacyAddress($customer->id);

        $this->postCheckout($this->oldForm(['shipping_method_id' => $old->id, 'update_address' => 'on']))->assertStatus(403);
        $this->assertNull($old->fresh()->city_id);
        $this->assertNull($this->sessionAddress());

        $this->postCheckout($this->saudiForm(['shipping_method_id' => $old->id, 'update_address' => 'on', 'latitude' => '24.7', 'longitude' => '46.6']))->assertOk();

        $completed = $old->fresh();
        $this->assertSame([$this->ids['riyadh'], 'RAGI2929', 'الرياض', null], [$completed->city_id, $completed->national_address, $completed->city, $completed->state]);
        $this->assertSame('12211', $completed->zip, 'the postal code the customer once typed is kept');
        $this->assertSame(['24.7', '46.6'], [$completed->latitude, $completed->longitude]);
        $this->assertSame($old->id, $this->sessionAddress());
        $this->assertSame(1, ShippingAddress::count());
    }

    public function test_soft_an_old_saved_address_can_be_used_through_a_complete_copy_and_stays_as_it_was(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->signIn();
        $old = $this->makeLegacyAddress($customer->id);

        $this->postCheckout($this->saudiForm(['shipping_method_id' => $old->id]))->assertOk();

        $this->assertNull($old->fresh()->city_id);
        $this->assertNotSame($old->id, $this->sessionAddress());
        $this->assertSame($this->ids['riyadh'], ShippingAddress::findOrFail($this->sessionAddress())->city_id);
    }

    public function test_soft_switching_a_saved_structured_address_to_another_country_clears_the_region(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->signIn();
        $saved = $this->makeStructuredAddress($this->ids, $customer->id);

        $this->postCheckout($this->oldForm(['shipping_method_id' => $saved->id, 'update_address' => 'on', 'country' => 'United Arab Emirates', 'city' => 'Dubai', 'phone' => '+971501234567', 'zip' => '']))->assertOk();

        $updated = $saved->fresh();
        $this->assertSame([null, null, null, null, null, 'Dubai'], [$updated->region_id, $updated->city_id, $updated->district, $updated->national_address, $updated->state, $updated->city]);
    }

    public function test_soft_two_national_addresses_with_the_same_old_columns_are_two_addresses(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->signIn();

        $this->postCheckout($this->saudiForm())->assertOk();
        $first = $this->sessionAddress();
        $this->postCheckout($this->saudiForm())->assertOk();
        $this->assertSame($first, $this->sessionAddress(), 'the same address is reused');

        $this->postCheckout($this->saudiForm(['national_address' => 'RAGI3030']))->assertOk();
        $this->assertNotSame($first, $this->sessionAddress());
        $this->postCheckout($this->saudiForm(['district' => 'Al Malaz']))->assertOk();
        $this->assertSame(3, ShippingAddress::count());
        $this->assertSame('RAGI2929', ShippingAddress::findOrFail($first)->national_address);
    }

    public function test_soft_a_unsaved_old_style_row_is_never_reused_for_a_structured_address(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->signIn();
        $existing = ShippingAddress::create([
            'customer_id' => '0', 'is_guest' => false, 'contact_person_name' => 'Sara Alharbi', 'address_type' => 'home', 'address' => 'King Fahd Rd 12',
            'city' => 'الرياض', 'state' => 'منطقة الرياض', 'country' => 'Saudi Arabia', 'phone' => '+966551234567', 'email' => null, 'latitude' => '', 'longitude' => '', 'is_billing' => false,
        ]);

        $this->postCheckout($this->saudiForm())->assertOk();

        $this->assertNotSame($existing->id, $this->sessionAddress());
    }

    public function test_soft_billing_is_a_separate_structured_address_when_it_differs(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->signIn();

        $this->postCheckout($this->saudiForm(), $this->saudiBillingForm())->assertOk();

        $shipping = ShippingAddress::findOrFail($this->sessionAddress());
        $billing = ShippingAddress::findOrFail($this->sessionAddress('billing_address_id'));
        $this->assertNotSame($shipping->id, $billing->id);
        $this->assertSame([true, $this->ids['jeddah'], 'JEDA1234', 'جدة', null], [$billing->is_billing, $billing->city_id, $billing->national_address, $billing->city, $billing->state]);
        $this->assertSame([false, $this->ids['riyadh']], [$shipping->is_billing, $shipping->city_id]);
    }

    public function test_soft_a_billing_address_is_checked_like_the_shipping_one(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->signIn();

        $response = $this->postCheckout($this->saudiForm(), $this->saudiBillingForm(['billing_city_id' => $this->ids['riyadh']]));

        $response->assertStatus(403);
        $this->assertSame(['city_id'], array_keys($response->json('fields')));
        $this->assertSame('billing_', $response->json('prefix'), 'the page needs the prefix to find the billing input the message belongs to');
    }

    public function test_soft_a_refused_checkout_leaves_no_saved_address_behind_for_the_retry_to_duplicate(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->signIn();

        $this->postCheckout($this->saudiForm(['save_address' => 'on']), $this->saudiBillingForm(['billing_city_id' => $this->ids['riyadh']]))->assertStatus(403);
        $this->assertSame(0, ShippingAddress::count(), 'the shipping address was fine, but it is not saved when the billing one is refused');
        $this->assertNull($this->sessionAddress());

        $this->postCheckout($this->saudiForm(['save_address' => 'on']), $this->saudiBillingForm())->assertOk();
        $this->assertSame(2, ShippingAddress::count(), 'one saved shipping address and one billing copy: what a single good request makes');
    }

    public function test_a_guest_whose_account_details_are_refused_leaves_no_address_behind(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->asGuest(704);

        $this->postCheckout($this->saudiForm(['email' => 'guest@example.test']), null, ['is_check_create_account' => 1, 'customer_password' => 'secret-pass-1', 'customer_confirm_password' => 'another-pass-2'])->assertStatus(403);

        $this->assertSame(0, ShippingAddress::count());
        $this->assertNull($this->sessionAddress());
    }

    public function test_soft_a_guest_gets_the_same_form_and_the_same_rules(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->asGuest(702);

        $this->postCheckout($this->oldForm(['email' => 'guest@example.test']))->assertStatus(403);
        $this->postCheckout($this->saudiForm(['email' => 'guest@example.test']))->assertOk();

        $address = ShippingAddress::sole();
        $this->assertSame(['702', true, 'guest@example.test', $this->ids['riyadh']], [(string) $address->customer_id, $address->is_guest, $address->email, $address->city_id]);
    }

    public function test_soft_a_saved_address_of_a_guest_session_can_be_updated_by_that_guest_only(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->asGuest(702);
        $mine = $this->makeLegacyAddress(702, true);
        $theirs = $this->makeLegacyAddress(703, true);

        $this->postCheckout($this->saudiForm(['email' => 'guest@example.test', 'shipping_method_id' => $theirs->id, 'update_address' => 'on']))->assertStatus(403);
        $this->postCheckout($this->saudiForm(['email' => 'guest@example.test', 'shipping_method_id' => $mine->id, 'update_address' => 'on']))->assertOk();

        $this->assertNull($theirs->fresh()->city_id);
        $this->assertSame($this->ids['riyadh'], $mine->fresh()->city_id);
    }

    public function test_soft_a_zip_restriction_that_is_on_pauses_the_structured_address_and_keeps_judging_the_zip(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->setBusinessSetting('delivery_zip_code_area_restriction', 1);
        DB::table('delivery_zip_codes')->insert(['zipcode' => '12211']);
        $this->signIn();

        $this->assertSame(AddressSchema::MODE_OFF, AddressSchema::mode(), 'a ZIP list cannot judge a National Address, so the store keeps the old address');
        $this->postCheckout($this->saudiForm())->assertStatus(403);
        $this->postCheckout($this->oldForm(['zip' => '99999']))->assertStatus(403);
        $this->assertSame(0, ShippingAddress::count());

        $this->postCheckout($this->oldForm(['zip' => '12211']))->assertOk();
        $address = ShippingAddress::sole();
        $this->assertSame('12211', $address->zip, 'a postal code the customer typed is kept as typed');
        $this->assertNull($address->city_id, 'nothing structured is written while the structured address is paused');
        $this->assertNull($address->national_address);

        $this->setBusinessSetting('delivery_zip_code_area_restriction', 0);
        $this->assertSame(AddressSchema::MODE_SOFT, AddressSchema::mode(), 'turning the restriction off brings the chosen mode back');
    }

    public function test_soft_a_digital_order_only_needs_the_billing_address(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->signIn();

        $response = $this->postJson(route('customer.choose-shipping-address-other'), [
            'physical_product' => 'no', 'shipping' => null, 'billing' => http_build_query($this->saudiBillingForm()), 'billing_addresss_same_shipping' => 'false',
        ]);

        $response->assertOk();
        $billing = ShippingAddress::sole();
        $this->assertSame([true, $this->ids['jeddah'], 'JEDA1234'], [$billing->is_billing, $billing->city_id, $billing->national_address]);
        $this->assertSame($billing->id, $this->sessionAddress('billing_address_id'));
        $this->assertEmpty(session('address_id'));
    }

    public function test_the_billing_address_of_a_digital_order_is_checked_like_any_other(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->signIn();
        $digital = fn (array $billing) => $this->postJson(route('customer.choose-shipping-address-other'), [
            'physical_product' => 'no', 'shipping' => null, 'billing' => http_build_query($billing), 'billing_addresss_same_shipping' => 'false',
        ]);

        $digital($this->saudiBillingForm(['billing_national_address' => 'bad']))->assertStatus(403);
        $digital($this->saudiBillingForm(['billing_city_id' => $this->ids['riyadh']]))->assertStatus(403);
        $this->assertSame(0, ShippingAddress::count());
    }

    public function test_the_errors_keep_the_shape_the_checkout_script_prints(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->signIn();

        $response = $this->postCheckout($this->saudiForm(['national_address' => 'bad']));

        $response->assertStatus(403);
        $this->assertIsString($response->json('errors'));
        $this->assertNotSame('', $response->json('errors'));
    }
}
