<?php

namespace Tests\Feature\Address;

use App\Enums\Address\AddressChannel;
use App\Enums\Address\CityMatchStatus;
use App\Models\AddressCity;
use App\Models\AddressRegion;
use App\Models\ShippingAddress;
use App\Services\Address\AddressSchema;
use App\Services\Address\AddressSnapshot;
use App\Services\Address\CheckoutAddressGuard;
use App\Services\Address\OrderAddressService;
use App\Services\Address\LegacyCityMatcher;
use App\Services\Address\AddressText;
use App\Services\ShippingAddressService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

class AddressServicesTest extends AddressTestCase
{
    /** @var array<string, int> */
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ids = $this->seedAddressCatalog();
    }

    private function service(): ShippingAddressService
    {
        return app(ShippingAddressService::class);
    }

    /** @return array<string, mixed> */
    private function submitted(array $overrides = []): array
    {
        return $overrides + [
            'country' => 'SA',
            'region_id' => $this->ids['region_EAS'],
            'city_id' => $this->ids['khobar'],
            'national_address' => 'RAGI 2929',
            'district' => '  Al Rakah   Al Janubiyah ',
            'phone' => '+966501234567',
        ];
    }

    /** @return array<string, list<string>> */
    private function errorsOf(callable $attempt): array
    {
        try {
            $attempt();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('A ValidationException was expected.');
    }

    public function test_nothing_changes_while_the_mode_is_off(): void
    {
        AddressSchema::setMode(AddressSchema::MODE_OFF);

        $this->assertSame([], $this->service()->structuredAttributes($this->submitted(), AddressChannel::Web));
        $this->assertFalse($this->service()->requiresStructured('SA', AddressChannel::Web));
    }

    public function test_with_the_mode_off_an_old_form_edit_that_moves_a_structured_address_takes_its_ids_with_it(): void
    {
        $existing = (object) ['city_id' => $this->ids['riyadh'], 'region_id' => $this->ids['region_RUH'], 'city' => 'الرياض', 'country' => 'Saudi Arabia', 'national_address' => 'RAGI2929'];
        $service = app(ShippingAddressService::class);
        $reset = array_fill_keys(['region_id', 'city_id', 'district', 'national_address'], null);

        $this->assertSame($reset, $service->structuredAttributes(['city' => 'جدة'], AddressChannel::Web, $existing), 'another city');
        $this->assertSame($reset, $service->structuredAttributes(['country' => 'Egypt', 'city' => 'الرياض'], AddressChannel::Web, $existing), 'another country');
        $this->assertSame([], $service->structuredAttributes(['city' => 'رياض', 'country' => 'SA'], AddressChannel::Web, $existing), 'the same city spelled differently');
        $this->assertSame([], $service->structuredAttributes(['phone' => '0551234567'], AddressChannel::Web, $existing), 'something else about it');
        $this->assertSame([], $service->structuredAttributes(['city' => 'جدة'], AddressChannel::Web, (object) ['city' => 'الرياض', 'country' => 'Saudi Arabia']), 'an old address has nothing to reset');
        $this->assertSame([], $service->structuredAttributes(['city' => 'جدة'], AddressChannel::Web, null), 'a new address has nothing to reset');
    }

    public function test_with_the_mode_off_a_staff_edit_of_an_order_copy_that_moves_it_leaves_no_stale_ids_in_the_copy(): void
    {
        $revised = app(OrderAddressService::class)->revise(
            snapshot: AddressSnapshot::build(['city_id' => $this->ids['riyadh'], 'region_id' => $this->ids['region_RUH'], 'city' => 'الرياض', 'country' => 'Saudi Arabia', 'national_address' => 'RAGI2929', 'zip' => '12211', 'address' => 'King Fahd Rd 12']),
            changes: ['city' => 'جدة'],
            structuredInput: [],
            channel: AddressChannel::Panel,
        );

        $this->assertSame(['جدة', null, null, null, null, 'legacy', '12211'], [$revised['city'], $revised['city_id'], $revised['region_id'], $revised['national_address'], $revised['city_name_ar'] ?? null, $revised['address_schema'], $revised['zip']]);
    }

    public function test_a_valid_saudi_address_gets_the_structured_columns_and_matching_legacy_text(): void
    {
        AddressSchema::setMode(AddressSchema::MODE_SOFT);

        $attributes = $this->service()->structuredAttributes($this->submitted(), AddressChannel::Web);

        $this->assertSame([
            'region_id' => $this->ids['region_EAS'],
            'city_id' => $this->ids['khobar'],
            'district' => 'Al Rakah Al Janubiyah',
            'national_address' => 'RAGI2929',
            'city' => 'الخبر',
            'country' => 'Saudi Arabia',
        ], $attributes);
        $this->assertArrayNotHasKey('zip', $attributes, 'a postal code is never derived from anything');
    }

    public function test_every_missing_or_wrong_field_is_reported_together(): void
    {
        AddressSchema::setMode(AddressSchema::MODE_SOFT);

        $errors = $this->errorsOf(fn () => $this->service()->structuredAttributes(['country' => 'Saudi Arabia', 'phone' => '12345', 'district' => str_repeat('ح', 192)], AddressChannel::Web));

        $this->assertEqualsCanonicalizing(['region_id', 'city_id', 'national_address', 'district', 'phone'], array_keys($errors));
    }

    #[DataProvider('tamperedPairs')]
    public function test_a_city_that_does_not_belong_to_the_region_is_refused(string $cityHandle, string $regionHandle): void
    {
        AddressSchema::setMode(AddressSchema::MODE_STRICT);

        $errors = $this->errorsOf(fn () => $this->service()->structuredAttributes(
            $this->submitted(['city_id' => $this->ids[$cityHandle], 'region_id' => $this->ids[$regionHandle]]),
            AddressChannel::Web,
        ));

        $this->assertArrayHasKey('city_id', $errors);
        $this->assertArrayNotHasKey('region_id', $errors);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function tamperedPairs(): array
    {
        return ['riyadh in the eastern region' => ['riyadh', 'region_EAS'], 'jeddah in riyadh region' => ['jeddah', 'region_RUH']];
    }

    #[DataProvider('hostileIds')]
    public function test_ids_that_are_not_plain_positive_integers_are_refused(mixed $regionId, mixed $cityId): void
    {
        AddressSchema::setMode(AddressSchema::MODE_STRICT);

        $errors = $this->errorsOf(fn () => $this->service()->structuredAttributes($this->submitted(['region_id' => $regionId, 'city_id' => $cityId]), AddressChannel::Web));

        $this->assertNotEmpty($errors);
    }

    /** @return array<string, array{0: mixed, 1: mixed}> */
    public static function hostileIds(): array
    {
        return [
            'sql' => ['1 OR 1=1', '1 OR 1=1'],
            'array' => [[1], [2]],
            'negative' => [-1, -5],
            'zero' => [0, 0],
            'float' => ['1.5', '2.5'],
            'huge' => ['99999999999999999999999', '99999999999999999999999'],
            'unknown' => [999999, 999999],
            'provider id' => ['36189', '230194'],
        ];
    }

    public function test_inactive_places_are_not_accepted_for_new_addresses(): void
    {
        AddressSchema::setMode(AddressSchema::MODE_STRICT);
        AddressCity::whereKey($this->ids['khobar'])->update(['is_active' => false]);
        $this->assertArrayHasKey('city_id', $this->errorsOf(fn () => $this->service()->structuredAttributes($this->submitted(), AddressChannel::Web)));

        AddressCity::whereKey($this->ids['khobar'])->update(['is_active' => true]);
        AddressRegion::whereKey($this->ids['region_EAS'])->update(['is_active' => false]);
        $this->assertArrayHasKey('region_id', $this->errorsOf(fn () => $this->service()->structuredAttributes($this->submitted(), AddressChannel::Web)));
    }

    public function test_provider_identifiers_sent_by_a_client_are_never_read(): void
    {
        AddressSchema::setMode(AddressSchema::MODE_STRICT);

        $attributes = $this->service()->structuredAttributes($this->submitted(['torod_city_id' => '36189', 'cityId' => '230194', 'external_city_id' => '41125', 'regionId' => '1204']), AddressChannel::Web);

        $this->assertSame($this->ids['khobar'], $attributes['city_id']);
        $this->assertEqualsCanonicalizing(['region_id', 'city_id', 'district', 'national_address', 'city', 'country'], array_keys($attributes), 'no provider id, and not the state: the shipping zones read it');
    }

    #[DataProvider('districts')]
    public function test_district_is_cleaned_or_refused(mixed $typed, string|null|false $stored): void
    {
        AddressSchema::setMode(AddressSchema::MODE_SOFT);

        if ($stored === false) {
            $this->assertArrayHasKey('district', $this->errorsOf(fn () => $this->service()->structuredAttributes($this->submitted(['district' => $typed]), AddressChannel::Web)));

            return;
        }

        $this->assertSame($stored, $this->service()->structuredAttributes($this->submitted(['district' => $typed]), AddressChannel::Web)['district']);
    }

    /** @return array<string, array{0: mixed, 1: string|null|false}> */
    public static function districts(): array
    {
        return [
            'blank' => ['', null],
            'spaces' => ['   ', null],
            'missing' => [null, null],
            'arabic' => ['حي الياسمين', 'حي الياسمين'],
            'control characters' => ["Al\u{0000}Malqa\n", 'Al Malqa'],
            'markup' => ['<script>alert(1)</script>', false],
            'array' => [['x'], false],
            'too long' => [str_repeat('x', 192), false],
        ];
    }

    public function test_a_country_other_than_saudi_arabia_keeps_the_old_free_text_address(): void
    {
        AddressSchema::setMode(AddressSchema::MODE_STRICT);

        $this->assertSame([], $this->service()->structuredAttributes(['country' => 'United Arab Emirates', 'city' => 'Dubai', 'zip_code' => '00000'], AddressChannel::Web));
    }

    public function test_moving_a_structured_address_to_another_country_clears_the_structured_columns(): void
    {
        AddressSchema::setMode(AddressSchema::MODE_STRICT);
        $saved = (object) ['country' => 'Saudi Arabia', 'city_id' => $this->ids['khobar']];

        $attributes = $this->service()->structuredAttributes(['country' => 'Kuwait'], AddressChannel::Web, $saved);

        $this->assertSame(['region_id' => null, 'city_id' => null, 'district' => null, 'national_address' => null], $attributes);
    }

    public function test_an_old_client_may_edit_an_address_that_only_has_a_city_for_as_long_as_it_keeps_naming_that_city(): void
    {
        AddressSchema::setMode(AddressSchema::MODE_SOFT);
        $matchedOnly = (object) ['city_id' => $this->ids['riyadh'], 'region_id' => $this->ids['region_RUH'], 'city' => 'الرياض', 'country' => 'Saudi Arabia', 'national_address' => null];
        $reset = array_fill_keys(['region_id', 'city_id', 'district', 'national_address'], null);

        $this->assertSame([], $this->service()->structuredAttributes(['city' => 'الرياض', 'country' => 'Saudi Arabia', 'phone' => '0551234567'], AddressChannel::Api, $matchedOnly), 'the same city');
        $this->assertSame([], $this->service()->structuredAttributes(['city' => 'رياض', 'country' => 'SA'], AddressChannel::Api, $matchedOnly), 'the same city, spelled differently');
        $this->assertSame($reset, $this->service()->structuredAttributes(['city' => 'جدة', 'country' => 'Saudi Arabia'], AddressChannel::Api, $matchedOnly), 'another city takes the ids away');
        $this->assertSame($reset, $this->service()->structuredAttributes(['city' => 'الرياض', 'country' => 'Egypt'], AddressChannel::Api, $matchedOnly), 'another country takes the ids away');
    }

    public function test_an_old_client_cannot_edit_an_address_that_has_a_national_address(): void
    {
        AddressSchema::setMode(AddressSchema::MODE_SOFT);
        $complete = (object) ['city_id' => $this->ids['riyadh'], 'region_id' => $this->ids['region_RUH'], 'city' => 'الرياض', 'country' => 'Saudi Arabia', 'national_address' => 'RAGI2929'];

        $errors = $this->errorsOf(fn () => $this->service()->structuredAttributes(['city' => 'الرياض', 'country' => 'Saudi Arabia'], AddressChannel::Api, $complete));

        $this->assertSame([translate('address_update_app_required')], $errors['address']);
    }

    public function test_a_district_that_was_not_sent_is_kept_unless_the_address_moves_to_another_city(): void
    {
        AddressSchema::setMode(AddressSchema::MODE_SOFT);
        $saved = (object) ['city_id' => $this->ids['khobar'], 'region_id' => $this->ids['region_EAS'], 'country' => 'Saudi Arabia', 'district' => 'Al Rakah'];
        $withoutDistrict = $this->submitted();
        unset($withoutDistrict['district']);

        $this->assertArrayNotHasKey('district', $this->service()->structuredAttributes($withoutDistrict, AddressChannel::Api, $saved), 'a correction of the National Address alone leaves the district');
        $this->assertNull($this->service()->structuredAttributes($withoutDistrict, AddressChannel::Api)['district'], 'a new address has none');
        $this->assertNull($this->service()->structuredAttributes([...$withoutDistrict, 'city_id' => $this->ids['dammam']], AddressChannel::Api, $saved)['district'], 'the district belonged to the old city');
        $this->assertNull($this->service()->structuredAttributes([...$withoutDistrict, 'district' => ''], AddressChannel::Api, $saved)['district'], 'sent empty means cleared');
        $this->assertSame('Olaya', $this->service()->structuredAttributes([...$withoutDistrict, 'district' => 'Olaya'], AddressChannel::Api, $saved)['district']);
    }

    public function test_the_copy_an_order_holds_may_keep_a_place_that_was_switched_off_but_nothing_else_may_choose_it(): void
    {
        AddressSchema::setMode(AddressSchema::MODE_SOFT);
        DB::table('address_cities')->whereIn('id', [$this->ids['khobar'], $this->ids['dammam']])->update(['is_active' => false]);
        $copy = (object) ['city_id' => $this->ids['khobar'], 'region_id' => $this->ids['region_EAS'], 'country' => 'Saudi Arabia', 'national_address' => 'RAGI2929'];

        $kept = $this->service()->structuredAttributes($this->submitted(), AddressChannel::Panel, $copy, mayKeepSwitchedOffPlace: true);
        $this->assertSame($this->ids['khobar'], $kept['city_id'], 'a phone number is corrected without moving the delivery');

        $this->assertArrayHasKey('city_id', $this->errorsOf(fn () => $this->service()->structuredAttributes($this->submitted(), AddressChannel::Panel, $copy)), 'a saved address may not');
        $this->assertArrayHasKey('city_id', $this->errorsOf(fn () => $this->service()->structuredAttributes($this->submitted(['city_id' => $this->ids['dammam']]), AddressChannel::Panel, $copy, mayKeepSwitchedOffPlace: true)), 'moving to another switched-off city is a new choice');
        $this->assertArrayHasKey('city_id', $this->errorsOf(fn () => $this->service()->structuredAttributes($this->submitted(), AddressChannel::Panel, (object) ['city_id' => $this->ids['dammam'], 'region_id' => $this->ids['region_EAS']], mayKeepSwitchedOffPlace: true)), 'only the place the copy already has');
    }

    public function test_a_text_that_names_a_region_is_not_read_as_a_city_of_that_name_elsewhere(): void
    {
        DB::table('address_cities')->insert([
            'region_id' => $this->ids['region_MAK'], 'name_ar' => 'الشرقية', 'name_en' => 'Ash Sharqiyyah',
            'normalized_ar' => AddressText::key('الشرقية'), 'normalized_en' => AddressText::key('Ash Sharqiyyah'),
            'is_active' => true, 'source' => 'test', 'source_ref' => 'sharqiyyah', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $matcher = app(LegacyCityMatcher::class);

        $conflict = $matcher->match('الشرقية');
        $this->assertSame([CityMatchStatus::Conflict, null, 'city_text_names_another_region'], [$conflict->status, $conflict->city, $conflict->reason], 'the Eastern Province is not a village of Makkah');
        $this->assertCount(1, $conflict->candidates, 'a person still sees the coincidence');
        $this->assertSame('region_typed_as_city', $matcher->match('المنطقة الشرقية')->reason);
        $this->assertSame('region_typed_as_city', $matcher->match('Eastern Province')->reason);
        $this->assertSame('region_typed_as_city', $matcher->match('منطقة الرياض')->reason);
        $this->assertSame('region_typed_as_city', $matcher->match('Riyadh Region')->reason);

        $riyadh = $matcher->match('الرياض');
        $this->assertSame([CityMatchStatus::Matched, $this->ids['riyadh']], [$riyadh->status, $riyadh->city?->id], 'a city that shares the name of its own region is still the city');
        $this->assertSame($this->ids['makkah'], $matcher->match('مكة المكرمة')->city?->id);

        DB::table('address_city_aliases')->insert(['city_id' => $this->ids['dammam'], 'alias' => 'الشرقية', 'normalized_alias' => AddressText::key('الشرقية'), 'source' => 'admin', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame($this->ids['dammam'], $matcher->match('الشرقية')->city?->id, 'what an administrator decided about the text wins');
    }

    #[DataProvider('regionWords')]
    public function test_a_text_that_calls_itself_a_region_is_recognised(?string $text, bool $names): void
    {
        $this->assertSame($names, AddressText::namesRegion($text));
    }

    /** @return array<string, array{0: ?string, 1: bool}> */
    public static function regionWords(): array
    {
        return [
            'region in arabic' => ['منطقة الرياض', true],
            'region with the article' => ['المنطقة الشرقية', true],
            'region in english' => ['Riyadh Region', true],
            'province in english' => ['Eastern Province', true],
            'a city' => ['الرياض', false],
            'a city called so' => ['مدينة الرياض', false],
            'a governorate is a city level' => ['محافظة جدة', false],
            'a word that only starts like it' => ['Provincetown', false],
            'blank' => ['  ', false],
            'nothing' => [null, false],
        ];
    }

    public function test_the_country_may_be_written_as_a_code_or_in_arabic(): void
    {
        AddressSchema::setMode(AddressSchema::MODE_STRICT);

        foreach (['SA', 'KSA', 'Saudi Arabia', 'saudi arabia', 'السعودية', 'المملكة العربية السعودية'] as $country) {
            $this->assertArrayHasKey('city_id', $this->errorsOf(fn () => $this->service()->structuredAttributes(['country' => $country], AddressChannel::Web)), $country);
        }
    }

    public function test_soft_mode_lets_the_api_keep_sending_the_old_fields_but_not_the_website(): void
    {
        AddressSchema::setMode(AddressSchema::MODE_SOFT);
        $legacy = ['country' => 'Saudi Arabia', 'city' => 'Riyadh', 'zip_code' => '12345'];

        $this->assertSame([], $this->service()->structuredAttributes($legacy, AddressChannel::Api));
        $this->assertArrayHasKey('city_id', $this->errorsOf(fn () => $this->service()->structuredAttributes($legacy, AddressChannel::Web)));
        $this->assertArrayHasKey('city_id', $this->errorsOf(fn () => $this->service()->structuredAttributes($legacy, AddressChannel::Panel)));
        $this->assertSame($this->ids['khobar'], $this->service()->structuredAttributes($this->submitted(), AddressChannel::Api)['city_id'], 'a client that sends the structured fields is validated like any other');
    }

    public function test_strict_mode_requires_the_structured_address_from_every_client(): void
    {
        AddressSchema::setMode(AddressSchema::MODE_STRICT);

        $this->assertArrayHasKey('city_id', $this->errorsOf(fn () => $this->service()->structuredAttributes(['country' => 'Saudi Arabia', 'city' => 'Riyadh'], AddressChannel::Api)));
    }

    public function test_an_old_client_cannot_overwrite_a_structured_address_with_free_text(): void
    {
        AddressSchema::setMode(AddressSchema::MODE_SOFT);
        $saved = (object) ['country' => 'Saudi Arabia', 'city_id' => $this->ids['jeddah'], 'national_address' => 'JEDA1234'];

        $errors = $this->errorsOf(fn () => $this->service()->structuredAttributes(['country' => 'Saudi Arabia', 'city' => 'Mecca'], AddressChannel::Api, $saved));

        $this->assertArrayHasKey('address', $errors);
    }

    public function test_adding_a_customer_from_a_panel_stores_the_structured_address_without_inventing_a_zip(): void
    {
        AddressSchema::setMode(AddressSchema::MODE_SOFT);

        $data = $this->service()->getAddAddressData($this->submitted(['f_name' => 'Sara', 'l_name' => 'Ali', 'address' => 'Olaya Street, building 4', 'city' => 'typed text']), 7, 'home');

        $this->assertSame('الخبر', $data['city'], 'the typed city text is replaced by the chosen city');
        $this->assertSame($this->ids['khobar'], $data['city_id']);
        $this->assertSame('RAGI2929', $data['national_address']);
        $this->assertNull($data['zip']);
        $this->assertSame('Sara Ali', $data['contact_person_name']);

        $address = ShippingAddress::create($data);
        $this->assertSame('RAGI2929', $address->fresh()->national_address);
    }

    public function test_adding_a_customer_with_the_mode_off_is_exactly_what_it_was(): void
    {
        $data = $this->service()->getAddAddressData(['f_name' => 'Sara', 'l_name' => 'Ali', 'address' => 'Olaya', 'city' => 'Riyadh', 'zip_code' => '12345', 'country' => 'SA', 'phone' => '0501234567'], 7, 'home');

        $this->assertSame(['customer_id', 'contact_person_name', 'address_type', 'address', 'city', 'zip', 'country', 'phone', 'is_billing', 'latitude', 'longitude'], array_keys($data));
        $this->assertSame('12345', $data['zip']);
    }

    #[DataProvider('legacyCities')]
    public function test_old_free_text_cities_are_matched_only_when_certain(string $city, ?string $state, CityMatchStatus $status, ?string $handle, string $reason): void
    {
        $match = app(LegacyCityMatcher::class)->match($city, $state);

        $this->assertSame($status, $match->status, $reason);
        $this->assertSame($reason, $match->reason);
        $this->assertSame($handle === null ? null : $this->ids[$handle], $match->city?->id);
    }

    /** @return array<string, array{0: string, 1: ?string, 2: CityMatchStatus, 3: ?string, 4: string}> */
    public static function legacyCities(): array
    {
        return [
            'arabic exact' => ['الدمام', null, CityMatchStatus::Matched, 'dammam', 'unique_name'],
            'english with article' => ['Al Khobar', null, CityMatchStatus::Matched, 'khobar', 'unique_name'],
            'prefixed' => ['مدينة جدة', null, CityMatchStatus::Matched, 'jeddah', 'unique_name'],
            'a name in two regions' => ['الهفوف', null, CityMatchStatus::Ambiguous, null, 'ambiguous_name'],
            'a name twice in one region' => ['أم سليم', 'الرياض', CityMatchStatus::Ambiguous, null, 'ambiguous_name'],
            'the region narrows a shared name' => ['الهفوف', 'المنطقة الشرقية', CityMatchStatus::Matched, 'hafuf_eas', 'unique_name_in_region'],
            'a region that contradicts the city' => ['الدمام', 'منطقة الرياض', CityMatchStatus::Conflict, null, 'region_conflict'],
            'unknown town' => ['قرية غير موجودة', null, CityMatchStatus::None, null, 'no_candidate'],
            'blank' => ['   ', null, CityMatchStatus::None, null, 'empty_city'],
            'not a similar-sounding match' => ['Damam', null, CityMatchStatus::None, null, 'no_candidate'],
        ];
    }

    public function test_an_administrator_alias_settles_an_otherwise_unknown_spelling(): void
    {
        DB::table('address_city_aliases')->insert(['city_id' => $this->ids['jeddah'], 'alias' => 'Jiddah', 'normalized_alias' => AddressText::key('Jiddah'), 'source' => 'admin', 'created_at' => now(), 'updated_at' => now()]);

        $match = app(LegacyCityMatcher::class)->match('Jiddah');

        $this->assertTrue($match->matched());
        $this->assertSame($this->ids['jeddah'], $match->city->id);
        $this->assertSame('alias', $match->reason);
    }

    public function test_a_snapshot_keeps_every_old_key_and_freezes_the_names(): void
    {
        $address = ShippingAddress::create([
            'contact_person_name' => 'Sara Ali', 'address_type' => 'home', 'address' => 'Olaya', 'city' => 'الخبر', 'state' => 'المنطقة الشرقية', 'country' => 'Saudi Arabia', 'phone' => '0501234567',
            'region_id' => $this->ids['region_EAS'], 'city_id' => $this->ids['khobar'], 'national_address' => 'RAGI2929', 'district' => 'Al Rakah',
        ]);

        $snapshot = AddressSnapshot::build($address);

        $this->assertArrayHasKey('zip', $snapshot);
        $this->assertNull($snapshot['zip']);
        $this->assertSame('unified', $snapshot['address_schema']);
        $this->assertSame(['Al Khobar', 'الخبر', 'Eastern Province', 'المنطقة الشرقية'], [$snapshot['city_name_en'], $snapshot['city_name_ar'], $snapshot['region_name_en'], $snapshot['region_name_ar']]);

        AddressCity::whereKey($this->ids['khobar'])->update(['name_ar' => 'الخبر الجديدة', 'is_active' => false]);
        $decoded = json_decode(json_encode($snapshot));
        $this->assertSame('الخبر', AddressSnapshot::cityName($decoded, 'ar'), 'an order keeps the name it was placed with');
        $this->assertSame('Al Khobar', AddressSnapshot::cityName($decoded, 'en'));
        $this->assertSame('RAGI2929', AddressSnapshot::nationalAddress($decoded));
        $this->assertTrue(AddressSnapshot::isUnified($decoded));
    }

    public function test_a_legacy_address_snapshots_as_legacy_and_reads_back_its_old_text(): void
    {
        $snapshot = AddressSnapshot::build(['city' => 'Riyadh', 'state' => null, 'zip' => '12345', 'address' => 'Olaya', 'country' => 'Saudi Arabia', 'city_id' => 999999, 'national_address' => 'RAGI2929']);

        $this->assertSame('legacy', $snapshot['address_schema']);
        $this->assertNull($snapshot['city_id'], 'an id that is not in the list is not remembered');
        $this->assertNull($snapshot['national_address'], 'a National Address without a verified city is not presented as structured data');
        $this->assertSame('Riyadh', AddressSnapshot::cityName($snapshot, 'ar'));
        $this->assertSame('12345', AddressSnapshot::zip($snapshot));
        $this->assertNull(AddressSnapshot::zip(AddressSnapshot::build(['city' => 'Riyadh'])));
    }

    /** @param array<string, mixed> $attributes */
    private function savedAddress(array $attributes): ShippingAddress
    {
        return ShippingAddress::query()->create($attributes + [
            'customer_id' => '7', 'is_guest' => 0, 'contact_person_name' => 'Sara Alharbi', 'address_type' => 'home', 'address' => 'King Fahd Rd 12',
            'country' => 'Saudi Arabia', 'phone' => '0551234567',
        ]);
    }

    public function test_an_old_form_edit_decides_from_the_row_as_it_is_when_written_not_as_it_was_read(): void
    {
        AddressSchema::setMode(AddressSchema::MODE_SOFT);
        $address = $this->savedAddress(['city' => 'الرياض']);
        $staleRead = ShippingAddress::query()->findOrFail($address->id);

        // A backfill, or a person confirming the spelling, gives the address its city after the app read it.
        DB::table('shipping_addresses')->where('id', $address->id)->update(['region_id' => $this->ids['region_RUH'], 'city_id' => $this->ids['riyadh']]);

        $this->service()->amend(
            address: $staleRead,
            attributes: ['city' => 'جدة'],
            structuredFor: fn (ShippingAddress $current): array => $this->service()->structuredAttributes(input: ['country' => 'Saudi Arabia', 'city' => 'جدة'], channel: AddressChannel::Api, existing: $current),
        );

        $row = ShippingAddress::query()->findOrFail($address->id);
        $this->assertSame(['جدة', null, null], [$row->city, $row->city_id, $row->region_id], 'the ids of the city the customer no longer says must not stay beside the new text');
    }

    public function test_two_edits_of_one_saved_address_made_from_stale_reads_do_not_mix_their_columns(): void
    {
        AddressSchema::setMode(AddressSchema::MODE_SOFT);
        $address = $this->savedAddress(['city' => 'الرياض', 'region_id' => $this->ids['region_RUH'], 'city_id' => $this->ids['riyadh'], 'national_address' => 'RAGI2929', 'district' => 'Al Olaya']);
        $firstRead = ShippingAddress::query()->findOrFail($address->id);
        $secondRead = ShippingAddress::query()->findOrFail($address->id);
        $edit = fn (string $region, string $city): array => $this->submitted(['region_id' => $this->ids[$region], 'city_id' => $this->ids[$city], 'national_address' => 'RAGI2929']);

        $this->service()->amend($firstRead, [], fn (ShippingAddress $current): array => $this->service()->structuredAttributes(input: $edit('region_MAK', 'jeddah'), channel: AddressChannel::Web, existing: $current));
        $this->service()->amend($secondRead, [], fn (ShippingAddress $current): array => $this->service()->structuredAttributes(input: $edit('region_RUH', 'diriyah'), channel: AddressChannel::Web, existing: $current));

        $row = ShippingAddress::query()->findOrFail($address->id);
        $this->assertSame([$this->ids['region_RUH'], $this->ids['diriyah'], 'الدرعية'], [$row->region_id, $row->city_id, $row->city], 'the last edit wins as a whole: its region goes with its city');
        $this->assertSame([], app(CheckoutAddressGuard::class)->problems($row, AddressChannel::Web));
    }

    public function test_the_checkout_guard_passes_complete_addresses_and_stops_old_ones(): void
    {
        $guard = app(CheckoutAddressGuard::class);
        $complete = ['country' => 'Saudi Arabia', 'region_id' => $this->ids['region_MAK'], 'city_id' => $this->ids['jeddah'], 'national_address' => 'JEDA1234'];
        $old = ['country' => 'Saudi Arabia', 'city' => 'Jeddah', 'zip' => '21589'];

        AddressSchema::setMode(AddressSchema::MODE_OFF);
        $this->assertSame([], $guard->problems($old, AddressChannel::Web));

        AddressSchema::setMode(AddressSchema::MODE_SOFT);
        $this->assertSame([], $guard->problems($complete, AddressChannel::Web));
        $this->assertSame(['address_complete_required'], $guard->problems($old, AddressChannel::Web));
        $this->assertSame([], $guard->problems($old, AddressChannel::Api), 'the app may still send an old address in soft mode');
        $this->assertSame([], $guard->problems(['country' => 'Kuwait', 'city' => 'Kuwait City'], AddressChannel::Web));
        $this->assertSame(['address_national_address_invalid'], $guard->problems(['national_address' => null] + $complete, AddressChannel::Web));
        $this->assertSame(['address_city_invalid'], $guard->problems(['region_id' => $this->ids['region_RUH']] + $complete, AddressChannel::Web));

        AddressSchema::setMode(AddressSchema::MODE_STRICT);
        $this->assertSame(['address_complete_required'], $guard->problems($old, AddressChannel::Api));

        $this->expectException(ValidationException::class);
        $guard->assertUsable($old, AddressChannel::Web);
    }
}
