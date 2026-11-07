<?php

namespace Tests\Feature\Address;

use App\Models\ShippingAddress;
use App\Services\Address\AddressSchema;
use Illuminate\Support\Facades\DB;

/**
 * The mobile app's address book (REST v1): add, update, list and delete in every mode of the unified Saudi address.
 */
class ApiAddressCrudTest extends ApiAddressTestCase
{
    private const ADD = '/api/v1/customer/address/add';

    private const UPDATE = '/api/v1/customer/address/update';

    private const LIST = '/api/v1/customer/address/list';

    private const DELETE = '/api/v1/customer/address';

    /** @return array<string, mixed> what the app sends today: free-text city, ZIP and coordinates */
    private function legacyPayload(array $overrides = []): array
    {
        return $overrides + [
            'contact_person_name' => 'Sara Alharbi', 'address_type' => 'home', 'address' => 'King Fahd Rd 12', 'city' => 'الرياض',
            'zip' => '12211', 'country' => 'Saudi Arabia', 'phone' => '0551234567', 'latitude' => '24.7', 'longitude' => '46.6',
        ];
    }

    /** @return array<string, mixed> what a build that knows the Saudi address sends: region and city ids, National Address, no ZIP */
    private function structuredPayload(array $overrides = []): array
    {
        return $overrides + [
            'contact_person_name' => 'Sara Alharbi', 'address_type' => 'home', 'address' => 'King Fahd Rd 12', 'country' => 'Saudi Arabia',
            'phone' => '0551234567', 'region_id' => $this->ids['region_RUH'], 'city_id' => $this->ids['riyadh'], 'district' => 'Al Olaya',
            'national_address' => 'ragi 2929',
        ];
    }

    /** @return list<string> the field names the app is told about */
    private function errorCodes($response): array
    {
        return array_column($response->json('errors') ?? [], 'code');
    }

    private function onlyAddress(): ShippingAddress
    {
        $this->assertSame(1, ShippingAddress::count());

        return ShippingAddress::firstOrFail();
    }

    public function test_while_the_mode_is_off_the_old_payload_is_stored_exactly_as_before_and_the_new_keys_are_ignored(): void
    {
        $customer = $this->makeCustomer();

        $this->asCustomer($customer)->postJson(self::ADD, $this->legacyPayload([
            'region_id' => $this->ids['region_EAS'], 'city_id' => $this->ids['khobar'], 'national_address' => 'RAGI2929', 'district' => 'Al Olaya',
        ]))->assertOk();

        $address = $this->onlyAddress();
        $this->assertSame('الرياض', $address->city);
        $this->assertSame('12211', $address->zip);
        $this->assertSame('Saudi Arabia', $address->country);
        $this->assertNull($address->state);
        $this->assertNull($address->city_id);
        $this->assertNull($address->region_id);
        $this->assertNull($address->national_address);
        $this->assertNull($address->district);
        $this->assertEquals($customer->id, $address->customer_id);
        $this->assertFalse($address->is_guest);
    }

    public function test_while_the_mode_is_off_the_old_validation_still_applies(): void
    {
        $response = $this->postJson(self::ADD, ['guest_id' => 7001] + $this->structuredPayload());

        $response->assertStatus(403);
        $this->assertEqualsCanonicalizing(['city', 'latitude', 'longitude'], $this->errorCodes($response));
        $this->assertSame(0, ShippingAddress::count());
    }

    public function test_the_old_payload_keeps_working_in_soft_mode_and_stays_an_old_address(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);

        $this->postJson(self::ADD, ['guest_id' => 7001] + $this->legacyPayload())->assertOk();

        $address = $this->onlyAddress();
        $this->assertSame('الرياض', $address->city);
        $this->assertSame('12211', $address->zip);
        $this->assertNull($address->city_id);
        $this->assertNull($address->national_address);
        $this->assertTrue($address->is_guest);
        $this->assertEquals(7001, $address->customer_id);
    }

    public function test_an_address_outside_saudi_arabia_is_never_asked_for_a_region_or_a_city_id(): void
    {
        foreach ([AddressSchema::MODE_SOFT, AddressSchema::MODE_STRICT] as $mode) {
            $this->setAddressMode($mode);

            $this->postJson(self::ADD, ['guest_id' => 7001] + $this->legacyPayload(['country' => 'Egypt', 'city' => 'Cairo', 'zip' => '11511']))->assertOk();
        }

        $this->assertSame(2, ShippingAddress::where('country', 'Egypt')->where('city', 'Cairo')->where('zip', '11511')->whereNull('city_id')->count());
    }

    public function test_a_new_app_stores_the_structured_address_with_matching_old_text_and_no_invented_zip(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->makeCustomer();

        $this->asCustomer($customer)->postJson(self::ADD, $this->structuredPayload(['country' => 'SA']))->assertOk();

        $address = $this->onlyAddress();
        $this->assertSame($this->ids['region_RUH'], $address->region_id);
        $this->assertSame($this->ids['riyadh'], $address->city_id);
        $this->assertSame('Al Olaya', $address->district);
        $this->assertSame('RAGI2929', $address->national_address);
        $this->assertSame('الرياض', $address->city);
        $this->assertSame('منطقة الرياض', $address->state);
        $this->assertSame('Saudi Arabia', $address->country, 'the old readers compare the English country name');
        $this->assertNull($address->zip, 'a postal code is never derived from the National Address');
        $this->assertNull($address->latitude);
        $this->assertNull($address->longitude);
        $this->assertEquals($customer->id, $address->customer_id);
    }

    public function test_a_zip_the_customer_typed_is_kept_as_typed_and_an_empty_one_is_stored_as_null(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);

        $this->postJson(self::ADD, ['guest_id' => 7001] + $this->structuredPayload(['zip' => ' 12211 ']))->assertOk();
        $this->postJson(self::ADD, ['guest_id' => 7001] + $this->structuredPayload(['zip' => '']))->assertOk();
        $this->postJson(self::ADD, ['guest_id' => 7001] + $this->structuredPayload())->assertOk();

        $this->assertSame(['12211', null, null], ShippingAddress::orderBy('id')->pluck('zip')->all());
    }

    public function test_strict_mode_refuses_the_old_payload_for_saudi_arabia_and_names_what_is_missing(): void
    {
        $this->setAddressMode(AddressSchema::MODE_STRICT);

        $response = $this->postJson(self::ADD, ['guest_id' => 7001] + $this->legacyPayload());

        $response->assertStatus(403);
        $this->assertEqualsCanonicalizing(['region_id', 'city_id', 'national_address'], $this->errorCodes($response));
        $this->assertSame(0, ShippingAddress::count());
    }

    public function test_a_wrong_pair_of_region_and_city_is_refused_with_the_field_name(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);

        $response = $this->postJson(self::ADD, ['guest_id' => 7001] + $this->structuredPayload(['region_id' => $this->ids['region_EAS'], 'city_id' => $this->ids['riyadh']]));

        $response->assertStatus(403);
        $this->assertSame(['city_id'], $this->errorCodes($response));
        $this->assertSame(0, ShippingAddress::count());
    }

    public function test_a_provider_city_id_is_not_a_city_and_provider_keys_are_never_read(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);

        $this->postJson(self::ADD, ['guest_id' => 7001] + $this->structuredPayload(['city_id' => 36189]))->assertStatus(403);
        $this->assertSame(0, ShippingAddress::count());

        $this->postJson(self::ADD, ['guest_id' => 7001] + $this->structuredPayload([
            'torod_city_id' => 36189, 'provider_city_id' => 230194, 'external_city_id' => 1204, 'village_id' => 5,
        ]))->assertOk();

        $address = $this->onlyAddress();
        $this->assertSame($this->ids['riyadh'], $address->city_id);
        $this->assertNull($address->getAttribute('torod_city_id'));
    }

    public function test_hostile_ids_are_refused_without_touching_the_database(): void
    {
        $this->setAddressMode(AddressSchema::MODE_STRICT);

        foreach (['1 OR 1=1', '-1', '0', '1.5', ['1'], '99999999999999999999'] as $hostile) {
            $this->postJson(self::ADD, ['guest_id' => 7001] + $this->structuredPayload(['city_id' => $hostile]))->assertStatus(403);
        }

        $this->assertSame(0, ShippingAddress::count());
    }

    public function test_the_country_restriction_still_applies_to_a_structured_address(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        DB::table('business_settings')->insert(['type' => 'delivery_country_restriction', 'value' => '1']);
        DB::table('delivery_country_codes')->insert(['country_code' => 'SA']);
        clearWebConfigCacheKeys();

        $this->postJson(self::ADD, ['guest_id' => 7001] + $this->structuredPayload())->assertOk();
        $this->assertSame('Saudi Arabia', $this->onlyAddress()->country);

        $this->postJson(self::ADD, ['guest_id' => 7001] + $this->legacyPayload(['country' => 'Egypt']))
            ->assertStatus(403)->assertJsonPath('message', translate('Delivery_unavailable_for_this_country'));
    }

    public function test_a_zip_restriction_pauses_the_structured_address_and_keeps_judging_the_zip(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        DB::table('business_settings')->insert(['type' => 'delivery_zip_code_area_restriction', 'value' => '1']);
        DB::table('delivery_zip_codes')->insert(['zipcode' => '12211']);
        clearWebConfigCacheKeys();

        $this->assertSame(AddressSchema::MODE_OFF, AddressSchema::mode());
        $this->postJson(self::ADD, ['guest_id' => 7001] + $this->legacyPayload(['zip' => '99999']))
            ->assertStatus(403)->assertJsonPath('message', translate('Delivery_unavailable_for_this_zip_code_area'));

        $this->postJson(self::ADD, ['guest_id' => 7001] + $this->legacyPayload(['zip' => '12211']))->assertOk();
        $address = $this->onlyAddress();
        $this->assertSame('12211', $address->zip);
        $this->assertNull($address->city_id);
    }

    public function test_the_zip_restriction_keeps_requiring_a_zip_from_an_old_payload(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        DB::table('business_settings')->insert(['type' => 'delivery_zip_code_area_restriction', 'value' => '1']);
        clearWebConfigCacheKeys();

        $response = $this->postJson(self::ADD, ['guest_id' => 7001] + $this->legacyPayload(['zip' => null]));

        $response->assertStatus(403);
        $this->assertSame(['zip'], $this->errorCodes($response));
    }

    public function test_a_customer_cannot_update_or_delete_an_address_that_is_not_theirs(): void
    {
        $owner = $this->makeCustomer();
        $stranger = $this->makeCustomer();
        $address = $this->makeLegacyAddress($owner->id);

        $this->asCustomer($stranger)->postJson(self::UPDATE, ['id' => $address->id, 'address' => 'Hijacked'])->assertNotFound();
        $this->asCustomer($stranger)->deleteJson(self::DELETE, ['address_id' => $address->id])->assertNotFound();

        $this->assertSame('King Fahd Rd 12', $address->fresh()->address);
    }

    public function test_a_guest_whose_id_equals_a_customers_id_cannot_reach_the_customers_address(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeLegacyAddress($customer->id);

        $this->postJson(self::UPDATE, ['guest_id' => $customer->id, 'id' => $address->id, 'address' => 'Hijacked'])->assertNotFound();
        $this->deleteJson(self::DELETE, ['guest_id' => $customer->id, 'address_id' => $address->id])->assertNotFound();
        $this->assertSame([], $this->getJson(self::LIST . '?guest_id=' . $customer->id)->assertOk()->json());

        $fresh = $address->fresh();
        $this->assertSame('King Fahd Rd 12', $fresh->address);
        $this->assertFalse($fresh->is_guest, 'the address is not turned into a guest address');
        $this->assertEquals($customer->id, $fresh->customer_id);
    }

    public function test_a_customer_cannot_reach_a_guest_address_through_the_same_number(): void
    {
        $customer = $this->makeCustomer();
        $guestAddress = $this->makeLegacyAddress($customer->id, isGuest: true);

        $this->asCustomer($customer)->postJson(self::UPDATE, ['id' => $guestAddress->id, 'address' => 'Hijacked'])->assertNotFound();
        $this->asCustomer($customer)->deleteJson(self::DELETE, ['address_id' => $guestAddress->id])->assertNotFound();
        $this->assertSame([], $this->asCustomer($customer)->getJson(self::LIST)->assertOk()->json());
        $this->assertSame('King Fahd Rd 12', $guestAddress->fresh()->address);
    }

    public function test_with_the_mode_off_an_old_update_that_moves_a_structured_address_does_not_leave_its_old_ids_behind(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeStructuredAddress($this->ids, $customer->id);

        $this->asCustomer($customer)->postJson(self::UPDATE, ['id' => $address->id, 'city' => 'جدة', 'country' => 'Saudi Arabia'])->assertOk();

        $moved = $address->fresh();
        $this->assertSame(['جدة', null, null, null, null, null], [$moved->city, $moved->state, $moved->region_id, $moved->city_id, $moved->district, $moved->national_address]);

        $kept = $this->makeStructuredAddress($this->ids, $customer->id);
        $this->asCustomer($customer)->postJson(self::UPDATE, ['id' => $kept->id, 'city' => $kept->city, 'address' => 'Tahlia St 9'])->assertOk();
        $this->assertSame([$this->ids['riyadh'], 'RAGI2929'], [$kept->fresh()->city_id, $kept->fresh()->national_address], 'the same city keeps its ids');
    }

    public function test_an_update_that_leaves_out_the_zip_keeps_it_and_one_that_sends_it_changes_it(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeLegacyAddress($customer->id, overrides: ['zip' => '12211']);

        $this->asCustomer($customer)->postJson(self::UPDATE, ['id' => $address->id, 'contact_person_name' => 'Sara', 'address' => 'Olaya St 3'])->assertOk();
        $this->assertSame('12211', $address->fresh()->zip);
        $this->assertSame('Olaya St 3', $address->fresh()->address);

        $this->asCustomer($customer)->postJson(self::UPDATE, ['id' => $address->id, 'zip' => '12345'])->assertOk();
        $this->assertSame('12345', $address->fresh()->zip);

        $this->asCustomer($customer)->postJson(self::UPDATE, ['id' => $address->id, 'zip' => null])->assertOk();
        $this->assertNull($address->fresh()->zip, 'a client that explicitly sends no ZIP clears it');
    }

    public function test_the_full_old_update_payload_still_replaces_every_field(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeLegacyAddress($customer->id);

        $this->asCustomer($customer)->postJson(self::UPDATE, $this->legacyPayload([
            'id' => $address->id, 'city' => 'جدة', 'zip' => '21589', 'address' => 'Tahlia St 9', 'address_type' => 'office', 'is_billing' => 1,
        ]))->assertOk();

        $fresh = $address->fresh();
        $this->assertSame(['جدة', '21589', 'Tahlia St 9', 'office', true], [$fresh->city, $fresh->zip, $fresh->address, $fresh->address_type, $fresh->is_billing]);
    }

    public function test_an_update_of_a_missing_address_is_not_found(): void
    {
        $this->asCustomer($this->makeCustomer())->postJson(self::UPDATE, ['id' => 999, 'address' => 'x'])->assertNotFound();
    }

    public function test_an_old_app_cannot_overwrite_a_structured_address_with_free_text(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->makeCustomer();
        $address = $this->makeStructuredAddress($this->ids, $customer->id);

        $response = $this->asCustomer($customer)->postJson(self::UPDATE, $this->legacyPayload(['id' => $address->id, 'city' => 'Somewhere']));

        $response->assertStatus(403);
        $this->assertSame(['address'], $this->errorCodes($response));
        $fresh = $address->fresh();
        $this->assertSame('الرياض', $fresh->city);
        $this->assertSame($this->ids['riyadh'], $fresh->city_id);
        $this->assertSame('RAGI2929', $fresh->national_address);
    }

    public function test_an_old_app_may_still_change_what_is_not_the_place(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->makeCustomer();
        $address = $this->makeStructuredAddress($this->ids, $customer->id);

        $this->asCustomer($customer)->postJson(self::UPDATE, ['id' => $address->id, 'address_type' => 'office', 'phone' => '0559999999'])->assertOk();

        $fresh = $address->fresh();
        $this->assertSame(['office', '0559999999'], [$fresh->address_type, $fresh->phone]);
        $this->assertSame([$this->ids['riyadh'], $this->ids['region_RUH'], 'RAGI2929'], [$fresh->city_id, $fresh->region_id, $fresh->national_address]);
    }

    public function test_an_old_app_may_still_edit_an_address_that_only_a_backfill_gave_a_city(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->makeCustomer();
        $address = $this->makeStructuredAddress($this->ids, $customer->id, overrides: ['national_address' => null, 'district' => null, 'state' => null]);

        $this->asCustomer($customer)->postJson(self::UPDATE, $this->legacyPayload(['id' => $address->id, 'phone' => '0559999999']))->assertOk();

        $fresh = $address->fresh();
        $this->assertSame('0559999999', $fresh->phone);
        $this->assertSame([$this->ids['riyadh'], $this->ids['region_RUH']], [$fresh->city_id, $fresh->region_id], 'the same city: its ids stay');

        $this->asCustomer($customer)->postJson(self::UPDATE, $this->legacyPayload(['id' => $address->id, 'city' => 'جدة']))->assertOk();

        $moved = $address->fresh();
        $this->assertSame('جدة', $moved->city);
        $this->assertSame([null, null, null, null], [$moved->city_id, $moved->region_id, $moved->national_address, $moved->state], 'another city: the ids no longer name where the address is');
    }

    public function test_a_new_app_correcting_only_the_national_address_keeps_the_district(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->makeCustomer();
        $address = $this->makeStructuredAddress($this->ids, $customer->id);
        $payload = $this->structuredPayload(['id' => $address->id, 'national_address' => 'RAGI2930']);
        unset($payload['district']);

        $this->asCustomer($customer)->postJson(self::UPDATE, $payload)->assertOk();

        $fresh = $address->fresh();
        $this->assertSame(['RAGI2930', 'Al Olaya'], [$fresh->national_address, $fresh->district]);

        $this->asCustomer($customer)->postJson(self::UPDATE, $this->structuredPayload(['id' => $address->id, 'district' => '']))->assertOk();
        $this->assertNull($address->fresh()->district, 'sent empty, it is cleared');
    }

    public function test_a_new_app_changes_the_place_of_a_structured_address_and_the_old_text_follows(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->makeCustomer();
        $address = $this->makeStructuredAddress($this->ids, $customer->id, overrides: ['zip' => '12211']);

        $this->asCustomer($customer)->postJson(self::UPDATE, $this->structuredPayload([
            'id' => $address->id, 'region_id' => $this->ids['region_EAS'], 'city_id' => $this->ids['khobar'], 'national_address' => 'KHBR1234',
        ]))->assertOk();

        $fresh = $address->fresh();
        $this->assertSame([$this->ids['region_EAS'], $this->ids['khobar'], 'KHBR1234'], [$fresh->region_id, $fresh->city_id, $fresh->national_address]);
        $this->assertSame(['الخبر', 'المنطقة الشرقية'], [$fresh->city, $fresh->state]);
        $this->assertSame('12211', $fresh->zip, 'the postal code the customer once typed is not touched');
    }

    public function test_a_new_app_completes_an_old_address_and_its_zip_survives(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->makeCustomer();
        $address = $this->makeLegacyAddress($customer->id, overrides: ['zip' => '12211']);

        $this->asCustomer($customer)->postJson(self::UPDATE, $this->structuredPayload(['id' => $address->id]))->assertOk();

        $fresh = $address->fresh();
        $this->assertSame($this->ids['riyadh'], $fresh->city_id);
        $this->assertSame('RAGI2929', $fresh->national_address);
        $this->assertSame('12211', $fresh->zip);
    }

    public function test_a_structured_update_with_a_bad_value_changes_nothing(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->makeCustomer();
        $address = $this->makeStructuredAddress($this->ids, $customer->id);

        $response = $this->asCustomer($customer)->postJson(self::UPDATE, $this->structuredPayload(['id' => $address->id, 'national_address' => 'NOPE']));

        $response->assertStatus(403);
        $this->assertSame(['national_address'], $this->errorCodes($response));
        $this->assertSame('RAGI2929', $address->fresh()->national_address);
    }

    public function test_moving_a_structured_address_abroad_clears_the_structured_columns(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->makeCustomer();
        $address = $this->makeStructuredAddress($this->ids, $customer->id);

        $this->asCustomer($customer)->postJson(self::UPDATE, ['id' => $address->id, 'country' => 'Egypt', 'city' => 'Cairo', 'zip' => '11511'])->assertOk();

        $fresh = $address->fresh();
        $this->assertSame(['Egypt', 'Cairo', '11511'], [$fresh->country, $fresh->city, $fresh->zip]);
        $this->assertSame([null, null, null, null], [$fresh->region_id, $fresh->city_id, $fresh->district, $fresh->national_address]);
    }

    public function test_the_list_keeps_every_old_key_and_adds_names_and_the_schema_once_the_mode_is_on(): void
    {
        $customer = $this->makeCustomer();
        $this->makeLegacyAddress($customer->id);
        $this->makeStructuredAddress($this->ids, $customer->id);

        $off = $this->asCustomer($customer)->getJson(self::LIST)->assertOk()->json();
        $this->assertCount(2, $off);
        $this->assertArrayNotHasKey('city_name_ar', $off[0], 'while the mode is off the list is what it always was');

        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $on = $this->asCustomer($customer)->getJson(self::LIST)->assertOk()->json();

        foreach ($on as $index => $row) {
            foreach (array_keys($off[$index]) as $oldKey) {
                $this->assertArrayHasKey($oldKey, $row, "the list lost the key {$oldKey}");
                $this->assertSame($off[$index][$oldKey], $row[$oldKey], "the list changed the value of {$oldKey}");
            }
        }
        $this->assertSame(['legacy', null, null, null, null], [$on[0]['address_schema'], $on[0]['city_id'], $on[0]['city_name_ar'], $on[0]['region_name_en'], $on[0]['national_address']]);
        $this->assertSame(['unified', 'الرياض', 'Riyadh', 'منطقة الرياض', 'Riyadh', 'RAGI2929', 'Al Olaya'], [
            $on[1]['address_schema'], $on[1]['city_name_ar'], $on[1]['city_name_en'], $on[1]['region_name_ar'], $on[1]['region_name_en'], $on[1]['national_address'], $on[1]['district'],
        ]);
        $this->assertSame('12211', $on[0]['zip'], 'the ZIP code is listed as typed');
        $this->assertNull($on[1]['zip'], 'a structured address has no ZIP code unless the customer typed one');
    }

    public function test_the_list_is_enriched_in_strict_mode_too_and_shows_a_legacy_address_as_legacy(): void
    {
        $this->setAddressMode(AddressSchema::MODE_STRICT);
        $customer = $this->makeCustomer();
        $this->makeLegacyAddress($customer->id);

        $row = $this->asCustomer($customer)->getJson(self::LIST)->assertOk()->json(0);

        $this->assertSame(['legacy', null, 'الرياض'], [$row['address_schema'], $row['city_name_ar'], $row['city']], 'an old address stays in the book and says that it is old');
    }

    public function test_the_list_of_a_guest_holds_only_that_guests_addresses(): void
    {
        $this->makeLegacyAddress(7001, isGuest: true);
        $this->makeLegacyAddress(7002, isGuest: true, overrides: ['address' => 'Other guest']);

        $list = $this->getJson(self::LIST . '?guest_id=7001')->assertOk()->json();

        $this->assertCount(1, $list);
        $this->assertSame('King Fahd Rd 12', $list[0]['address']);
    }

    public function test_deleting_an_address_removes_only_that_one(): void
    {
        $customer = $this->makeCustomer();
        $kept = $this->makeLegacyAddress($customer->id);
        $removed = $this->makeLegacyAddress($customer->id);

        $this->asCustomer($customer)->deleteJson(self::DELETE, ['address_id' => $removed->id])->assertOk();

        $this->assertSame([$kept->id], ShippingAddress::pluck('id')->all());
    }
}
