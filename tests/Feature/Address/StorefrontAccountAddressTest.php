<?php

namespace Tests\Feature\Address;

use App\Models\ShippingAddress;
use App\Services\Address\AddressSchema;
use Illuminate\Support\Facades\DB;

/**
 * The customer's address book on the website (account-address, -store, -edit, -update, -delete): the old form while the
 * structured address is off, the Saudi form once it is on, old addresses completed in place, and an address book that is
 * the signed-in customer's own and nobody else's.
 */
class StorefrontAccountAddressTest extends StorefrontTestCase
{
    /** @return array<string, mixed> what the old form posted */
    private function oldForm(array $overrides = []): array
    {
        return $overrides + [
            'address_type' => 'home', 'is_billing' => '0', 'name' => 'Sara Alharbi', 'phone' => '+966551234567', 'city' => 'الرياض', 'zip' => '12211',
            'country' => 'Saudi Arabia', 'address' => 'King Fahd Rd 12', 'latitude' => '24.7', 'longitude' => '46.6',
        ];
    }

    /** @return array<string, mixed> what the Saudi form posts: no city text and no ZIP field */
    private function saudiForm(array $overrides = []): array
    {
        return $overrides + [
            'address_type' => 'home', 'is_billing' => '0', 'name' => 'Sara Alharbi', 'phone' => '+966551234567', 'country' => 'Saudi Arabia',
            'region_id' => $this->ids['region_RUH'], 'city_id' => $this->ids['riyadh'], 'district' => 'Al Olaya', 'national_address' => 'ragi 2929',
            'address' => 'King Fahd Rd 12', 'latitude' => '', 'longitude' => '',
        ];
    }

    /** How many rows of the form are marked as the old ones (shown for other countries only). */
    private function markedRows(string $html): int
    {
        return preg_match_all('/<div[^>]*\sdata-ua-other[\s>]/', $html);
    }

    public function test_off_the_old_form_adds_an_address_to_the_customers_book(): void
    {
        $customer = $this->signIn();

        $this->post(route('address-store'), $this->oldForm())->assertRedirect();

        $address = ShippingAddress::sole();
        $this->assertSame([(string) $customer->id, false, false], [(string) $address->customer_id, $address->is_guest, $address->is_billing]);
        $this->assertSame(['Sara Alharbi', 'home', 'King Fahd Rd 12', 'الرياض', '12211', 'Saudi Arabia', '+966551234567', '24.7', '46.6'], [
            $address->contact_person_name, $address->address_type, $address->address, $address->city, $address->zip, $address->country, $address->phone,
            $address->latitude, $address->longitude,
        ]);
        $this->assertSame([null, null, null, null], [$address->region_id, $address->city_id, $address->district, $address->national_address]);
    }

    public function test_off_the_old_validation_still_applies(): void
    {
        $this->signIn();

        $this->post(route('address-store'), $this->oldForm(['city' => '']))->assertSessionHasErrors('city');
        $this->post(route('address-store'), $this->oldForm(['name' => '']))->assertSessionHasErrors('name');
        $this->post(route('address-store'), $this->oldForm(['phone' => '123']))->assertSessionHasErrors('phone');
        $this->assertSame(0, ShippingAddress::count());

        $this->setBusinessSetting('delivery_zip_code_area_restriction', 1);
        DB::table('delivery_zip_codes')->insert(['zipcode' => '12211']);
        $this->post(route('address-store'), $this->oldForm(['zip' => '']))->assertSessionHasErrors('zip');
        $this->post(route('address-store'), $this->oldForm(['zip' => '99999']));
        $this->assertSame(0, ShippingAddress::count(), 'a ZIP that is not on the list is refused');
        $this->post(route('address-store'), $this->oldForm(['zip' => '12211']));
        $this->assertSame(1, ShippingAddress::count());
    }

    public function test_off_the_old_form_updates_an_address_and_keeps_when_it_was_made(): void
    {
        $customer = $this->signIn();
        $saved = $this->makeLegacyAddress($customer->id);
        ShippingAddress::whereKey($saved->id)->update(['created_at' => '2025-01-02 03:04:05']);

        $this->post(route('address-update'), $this->oldForm(['id' => $saved->id, 'address' => 'Tahlia St 9', 'zip' => '54321']))->assertRedirect(route('account-address'));

        $updated = $saved->fresh();
        $this->assertSame(['Tahlia St 9', '54321'], [$updated->address, $updated->zip]);
        $this->assertSame('2025-01-02 03:04:05', $updated->created_at->format('Y-m-d H:i:s'));
    }

    public function test_off_moving_an_address_that_already_has_a_city_with_the_old_form_does_not_leave_the_old_city_behind(): void
    {
        $customer = $this->signIn();
        $saved = $this->makeStructuredAddress($this->ids, $customer->id);

        $this->post(route('address-update'), $this->oldForm(['id' => $saved->id, 'city' => 'جدة']))->assertRedirect(route('account-address'));

        $moved = $saved->fresh();
        $this->assertSame(['جدة', null, null, null, null, null], [$moved->city, $moved->state, $moved->region_id, $moved->city_id, $moved->district, $moved->national_address]);
    }

    public function test_off_changing_only_the_street_or_respelling_the_same_city_keeps_the_city_it_has(): void
    {
        $customer = $this->signIn();
        $saved = $this->makeStructuredAddress($this->ids, $customer->id);
        $kept = [$saved->region_id, $saved->city_id, $saved->national_address];

        $this->post(route('address-update'), $this->oldForm(['id' => $saved->id, 'city' => $saved->city, 'address' => 'Tahlia St 9']))->assertRedirect();
        $this->post(route('address-update'), $this->oldForm(['id' => $saved->id, 'city' => 'رياض']))->assertRedirect();

        $after = $saved->fresh();
        $this->assertSame($kept, [$after->region_id, $after->city_id, $after->national_address]);
    }

    public function test_an_update_from_a_form_without_a_zip_field_keeps_the_postal_code(): void
    {
        $customer = $this->signIn();
        $saved = $this->makeLegacyAddress($customer->id, overrides: ['zip' => '12211']);
        $form = $this->oldForm(['id' => $saved->id, 'address' => 'Tahlia St 9']);
        unset($form['zip']);

        $this->post(route('address-update'), $form)->assertRedirect(route('account-address'));

        $this->assertSame(['Tahlia St 9', '12211'], [$saved->fresh()->address, $saved->fresh()->zip]);
    }

    public function test_an_update_that_clears_the_zip_field_clears_the_zip(): void
    {
        $customer = $this->signIn();
        $saved = $this->makeLegacyAddress($customer->id, overrides: ['zip' => '12211']);

        $this->post(route('address-update'), $this->oldForm(['id' => $saved->id, 'zip' => '']))->assertRedirect(route('account-address'));

        $this->assertNull($saved->fresh()->zip);
    }

    public function test_the_customer_can_remove_an_address_of_their_own_only(): void
    {
        $customer = $this->signIn();
        $mine = $this->makeLegacyAddress($customer->id);
        $theirs = $this->makeLegacyAddress($this->makeCustomer()->id);

        $this->get(route('address-delete', ['id' => $theirs->id]))->assertRedirect();
        $this->assertNotNull($theirs->fresh(), 'another customer\'s address is still there');

        $this->get(route('address-delete', ['id' => $mine->id]))->assertRedirect();
        $this->assertNull(ShippingAddress::find($mine->id));
    }

    public function test_soft_the_saudi_form_adds_a_structured_address(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->signIn();

        $this->post(route('address-store'), $this->saudiForm())->assertRedirect();

        $address = ShippingAddress::sole();
        $this->assertSame((string) $customer->id, (string) $address->customer_id);
        $this->assertSame([$this->ids['region_RUH'], $this->ids['riyadh'], 'Al Olaya', 'RAGI2929'], [$address->region_id, $address->city_id, $address->district, $address->national_address]);
        $this->assertSame(['الرياض', 'منطقة الرياض', 'Saudi Arabia'], [$address->city, $address->state, $address->country]);
        $this->assertNull($address->zip, 'a ZIP is never derived from the National Address');
        $this->assertNull($address->latitude, 'no coordinates are invented');
    }

    public function test_soft_a_free_text_city_is_not_accepted_for_a_saudi_address(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->signIn();

        $this->post(route('address-store'), $this->oldForm())->assertSessionHasErrors(['region_id', 'city_id', 'national_address']);
        $this->post(route('address-store'), $this->saudiForm(['city_id' => $this->ids['jeddah']]))->assertSessionHasErrors('city_id');
        $this->post(route('address-store'), $this->saudiForm(['national_address' => 'RAGI29']))->assertSessionHasErrors('national_address');
        $this->post(route('address-store'), $this->saudiForm(['phone' => '+971501234567']))->assertSessionHasErrors('phone');
        $this->assertSame(0, ShippingAddress::count());
    }

    public function test_soft_other_countries_keep_the_free_text_city_and_the_zip(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->signIn();

        $this->post(route('address-store'), $this->oldForm(['country' => 'United Arab Emirates', 'city' => 'Dubai', 'zip' => '00000-AE', 'phone' => '+971501234567']))->assertRedirect();

        $address = ShippingAddress::sole();
        $this->assertSame(['United Arab Emirates', 'Dubai', '00000-AE', null, null], [$address->country, $address->city, $address->zip, $address->city_id, $address->state]);
    }

    public function test_soft_an_old_address_is_completed_in_place_and_keeps_its_postal_code(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->signIn();
        $old = $this->makeLegacyAddress($customer->id, overrides: ['zip' => '12211']);

        $this->post(route('address-update'), $this->oldForm(['id' => $old->id]))->assertSessionHasErrors(['region_id', 'city_id', 'national_address']);
        $this->assertNull($old->fresh()->city_id);

        $this->post(route('address-update'), $this->saudiForm(['id' => $old->id]))->assertRedirect(route('account-address'));

        $completed = $old->fresh();
        $this->assertSame([$this->ids['riyadh'], 'RAGI2929', 'منطقة الرياض'], [$completed->city_id, $completed->national_address, $completed->state]);
        $this->assertSame('12211', $completed->zip, 'the Saudi form has no ZIP field, so the postal code the customer once typed stays');
        $this->assertSame(1, ShippingAddress::count());
    }

    public function test_soft_moving_a_saved_address_to_another_country_clears_the_region_and_city(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->signIn();
        $saved = $this->makeStructuredAddress($this->ids, $customer->id);

        $this->post(route('address-update'), $this->oldForm(['id' => $saved->id, 'country' => 'United Arab Emirates', 'city' => 'Dubai', 'zip' => '', 'phone' => '+971501234567']))
            ->assertRedirect(route('account-address'));

        $updated = $saved->fresh();
        $this->assertSame([null, null, null, null, null, 'Dubai'], [$updated->region_id, $updated->city_id, $updated->district, $updated->national_address, $updated->state, $updated->city]);
    }

    public function test_soft_a_wrong_pair_on_update_changes_nothing(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->signIn();
        $saved = $this->makeStructuredAddress($this->ids, $customer->id);

        $this->post(route('address-update'), $this->saudiForm(['id' => $saved->id, 'city_id' => $this->ids['makkah'], 'address' => 'Changed']))->assertSessionHasErrors('city_id');

        $this->assertSame(['King Fahd Rd 12', $this->ids['riyadh']], [$saved->fresh()->address, $saved->fresh()->city_id]);
    }

    public function test_the_address_book_is_never_reachable_without_signing_in(): void
    {
        $owner = $this->makeCustomer();
        $address = $this->makeLegacyAddress($owner->id);

        $this->post(route('address-store'), $this->oldForm())->assertRedirect(route('customer.auth.login'));
        $this->get(route('address-edit', ['id' => $address->id]))->assertRedirect(route('customer.auth.login'));
        $this->post(route('address-update'), $this->oldForm(['id' => $address->id, 'address' => 'Hijacked']))->assertRedirect(route('customer.auth.login'));
        $this->get(route('address-delete', ['id' => $address->id]))->assertRedirect(route('customer.auth.login'));

        $this->assertSame(1, ShippingAddress::count(), 'nothing was added, not even an address that belongs to nobody');
        $this->assertSame('King Fahd Rd 12', $address->fresh()->address);
    }

    public function test_a_customer_cannot_open_change_or_remove_another_customers_address(): void
    {
        $this->signIn();
        $theirs = $this->makeLegacyAddress($this->makeCustomer()->id);

        $this->get(route('address-edit', ['id' => $theirs->id]))->assertRedirect();
        $this->post(route('address-update'), $this->oldForm(['id' => $theirs->id, 'address' => 'Hijacked']))->assertRedirect(route('account-address'));
        $this->get(route('address-delete', ['id' => $theirs->id]))->assertRedirect();

        $this->assertSame('King Fahd Rd 12', $theirs->fresh()->address);
    }

    public function test_a_guests_address_with_the_same_number_as_a_customer_is_not_the_customers(): void
    {
        $customer = $this->signIn();
        $guestRow = $this->makeLegacyAddress($customer->id, isGuest: true, overrides: ['address' => 'A guest typed this']);

        $this->get(route('account-address'))->assertOk()->assertDontSee('A guest typed this');
        $this->get(route('address-edit', ['id' => $guestRow->id]))->assertRedirect();
        $this->post(route('address-update'), $this->oldForm(['id' => $guestRow->id, 'address' => 'Hijacked']))->assertRedirect(route('account-address'));
        $this->get(route('address-delete', ['id' => $guestRow->id]));

        $this->assertSame('A guest typed this', $guestRow->fresh()->address);
    }

    public function test_off_the_pages_show_the_old_fields_only(): void
    {
        $customer = $this->signIn();
        $saved = $this->makeLegacyAddress($customer->id);

        $list = $this->get(route('account-address'))->assertOk();
        $list->assertDontSee('data-unified-address', false)->assertDontSee('data-ua-other', false)->assertDontSee('badge badge-warning', false);
        $list->assertSee('name="city"', false)->assertSee('name="zip"', false)->assertSee('الرياض')->assertSee('12211');
        $this->assertStringContainsString('value="0"', $list->getContent(), 'the default location is posted as before');

        $edit = $this->get(route('address-edit', ['id' => $saved->id]))->assertOk();
        $edit->assertDontSee('data-unified-address', false)->assertDontSee('data-ua-other', false);
        $edit->assertSee('name="city"', false)->assertSee('value="الرياض"', false);
    }

    public function test_soft_the_add_form_offers_the_saudi_fields_and_marks_the_old_rows(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->signIn();

        $page = $this->get(route('account-address'))->assertOk();

        $page->assertSee('data-unified-address', false)->assertSee('name="region_id"', false)->assertSee('name="city_id"', false)
            ->assertSee('name="national_address"', false)->assertSee('name="district"', false);
        $this->assertSame(2, $this->markedRows($page->getContent()), 'the free-text city row and the ZIP row');
        $this->assertSame(1, substr_count($page->getContent(), 'window.unifiedAddress = '), 'the shared script is included once');
        $this->assertStringContainsString('<option value="Saudi Arabia" selected>', $page->getContent());
        $page->assertSee('Street, building and landmark');
    }

    public function test_soft_the_list_names_the_region_and_city_and_flags_an_old_address(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->signIn();
        $this->makeStructuredAddress($this->ids, $customer->id, overrides: ['district' => 'Al Olaya', 'address' => 'Complete street']);
        $this->makeLegacyAddress($customer->id, overrides: ['address' => 'Old street', 'zip' => '12211']);

        $page = $this->get(route('account-address'))->assertOk();

        $content = $page->getContent();
        $this->assertSame(1, substr_count($content, 'badge badge-warning'), 'only the old address is flagged');
        $page->assertSee(translate('address_needs_completion'));
        $page->assertSee('RAGI2929')->assertSee('Al Olaya')->assertSee('Complete street')->assertSee('Old street');
        $this->assertSame(1, substr_count($content, '12211'), 'the postal code of the old address is shown, the structured one has none');
        $this->assertStringContainsString('Riyadh', $content);
    }

    public function test_soft_the_edit_page_preselects_what_the_address_has(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->signIn();
        $saved = $this->makeStructuredAddress($this->ids, $customer->id, overrides: ['district' => 'Al Olaya']);

        $page = $this->get(route('address-edit', ['id' => $saved->id]))->assertOk();

        $page->assertSee('<option value="' . $this->ids['region_RUH'] . '" data-name-ar="منطقة الرياض" data-name-en="Riyadh" selected>', false);
        $page->assertSee('name="city_id" data-ua-city-id value="' . $this->ids['riyadh'] . '"', false)->assertSee('value="RAGI2929"', false)->assertSee('value="Al Olaya"', false);
        $this->assertSame(0, substr_count($page->getContent(), 'alert alert-warning'), 'a complete address has nothing to complete');
        $this->assertSame(2, $this->markedRows($page->getContent()));
    }

    public function test_soft_the_edit_page_of_an_old_address_asks_for_the_missing_fields_and_shows_the_old_city(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->signIn();
        $old = $this->makeLegacyAddress($customer->id, overrides: ['country' => 'KSA', 'city' => 'حي قديم']);

        $page = $this->get(route('address-edit', ['id' => $old->id]))->assertOk();

        $page->assertSee('needs to be completed with a region, city and National Address');
        $page->assertSee(translate('address_previous_city') . ': حي قديم', false);
        $page->assertSee('name="city_id" data-ua-city-id value=""', false);
        $this->assertStringContainsString('<option value="Saudi Arabia" selected>', $page->getContent(), 'an address saved with another spelling of the country is still a Saudi address');
    }

    public function test_the_edit_page_of_a_non_saudi_address_keeps_the_old_fields_in_force(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->signIn();
        $dubai = $this->makeLegacyAddress($customer->id, overrides: ['country' => 'United Arab Emirates', 'city' => 'Dubai', 'zip' => '00000-AE']);

        $page = $this->get(route('address-edit', ['id' => $dubai->id]))->assertOk();

        $page->assertDontSee('needs to be completed');
        $page->assertSee('value="Dubai"', false)->assertSee('value="00000-AE"', false);
        $this->assertStringContainsString('<option value="United Arab Emirates" selected', $page->getContent());
    }
}
