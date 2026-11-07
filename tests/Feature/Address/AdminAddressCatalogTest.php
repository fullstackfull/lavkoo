<?php

namespace Tests\Feature\Address;

use App\Models\AddressCity;
use App\Models\AddressRegion;
use App\Services\Address\AddressCatalog;
use App\Services\Address\AddressSchema;
use App\Services\Address\AddressSnapshot;
use App\Services\Address\AddressText;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Administering the lists: regions are renamed and switched on or off; cities are searched, added, edited and switched on or off.
 * Nothing is ever deleted, and switching a place off never changes a saved address or the copy an order keeps.
 */
class AdminAddressCatalogTest extends AdminAddressTestCase
{
    /** @return array<string, mixed> */
    private function cityForm(array $overrides = []): array
    {
        return $overrides + ['region_id' => $this->ids['region_RUH'], 'name_ar' => 'الخرج', 'name_en' => 'Al Kharj', 'latitude' => '24.15', 'longitude' => '47.3', 'sort_order' => 5];
    }

    // ---- regions -----------------------------------------------------------------------------------------------------------------

    public function test_the_regions_page_lists_every_region_with_its_city_counts_and_state(): void
    {
        $this->signInWithTheCatalog();
        AddressCity::whereKey($this->ids['dammam'])->update(['is_active' => false]);
        AddressRegion::whereKey($this->ids['region_MAK'])->update(['is_active' => false]);

        $html = $this->get(route('admin.address-system.regions.index'))->assertOk()->getContent();

        foreach (['SA-RUH', 'SA-MAK', 'SA-EAS', 'منطقة الرياض', 'Eastern Province'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        $this->assertStringContainsString('5 / 5', $html, 'Riyadh region: five cities, all active');
        $this->assertStringContainsString('3 / 3', $html, 'Makkah region: switched off, its three cities are not');
        $this->assertStringContainsString('2 / 3', $html, 'Eastern province: three cities, one switched off');
    }

    public function test_a_region_is_renamed_in_both_languages_and_its_search_keys_follow(): void
    {
        $this->signInWithTheCatalog();
        $region = $this->ids['region_EAS'];

        $this->post(route('admin.address-system.regions.update', $region), ['name_ar' => '  المنطقة   الشرقية الكبرى ', 'name_en' => 'Eastern   Region'])
            ->assertRedirect();

        $saved = AddressRegion::findOrFail($region);
        $this->assertSame('المنطقة الشرقية الكبرى', $saved->name_ar);
        $this->assertSame('Eastern Region', $saved->name_en);
        $this->assertSame(AddressText::key('Eastern Region'), $saved->normalized_en);
        $this->assertSame(AddressText::key('المنطقة الشرقية الكبرى'), $saved->normalized_ar);
        $this->assertSame('SA-EAS', $saved->code, 'the code comes from the source list and is not edited here');
        $this->assertSame(['address.region_updated'], $this->auditActions());
        $audit = DB::table('audit_logs')->first();
        $this->assertSame('Eastern Province', json_decode($audit->before, true)['name_en']);
        $this->assertSame('Eastern Region', json_decode($audit->after, true)['name_en']);
    }

    public function test_saving_a_region_without_a_change_records_nothing(): void
    {
        $this->signInWithTheCatalog();
        $region = AddressRegion::findOrFail($this->ids['region_RUH']);

        $this->post(route('admin.address-system.regions.update', $region->id), ['name_ar' => $region->name_ar, 'name_en' => $region->name_en])->assertRedirect();

        $this->assertSame([], $this->auditActions());
    }

    public function test_a_region_needs_both_names_and_they_must_be_plain_text(): void
    {
        $this->signInWithTheCatalog();
        $region = $this->ids['region_RUH'];
        $before = AddressRegion::findOrFail($region)->only(['name_ar', 'name_en']);

        $bad = [
            'missing arabic' => ['name_ar' => '', 'name_en' => 'Riyadh'],
            'missing english' => ['name_ar' => 'الرياض', 'name_en' => ''],
            'nothing sent' => [],
            'too long' => ['name_ar' => str_repeat('م', 192), 'name_en' => 'Riyadh'],
            'markup' => ['name_ar' => 'الرياض', 'name_en' => '<script>alert(1)</script>'],
            'only punctuation' => ['name_ar' => '---', 'name_en' => 'Riyadh'],
            'a list' => ['name_ar' => ['الرياض'], 'name_en' => 'Riyadh'],
            'a line break' => ['name_ar' => "الرياض\nالكبرى", 'name_en' => 'Riyadh'],
        ];
        foreach ($bad as $label => $input) {
            $this->post(route('admin.address-system.regions.update', $region), $input)->assertSessionHasErrors(null, null, $label);
            $this->assertSame($before, AddressRegion::findOrFail($region)->only(['name_ar', 'name_en']), $label);
        }
        $this->assertSame([], $this->auditActions());
    }

    public function test_a_region_that_does_not_exist_cannot_be_renamed_or_switched(): void
    {
        $this->signInWithTheCatalog();

        $this->post(route('admin.address-system.regions.update', 9999), ['name_ar' => 'س', 'name_en' => 'S'])->assertNotFound();
        $this->post(route('admin.address-system.regions.status', 9999), ['active' => 0])->assertNotFound();
    }

    public function test_a_region_is_switched_off_and_on_by_an_explicit_state_and_repeating_it_changes_nothing(): void
    {
        $this->signInWithTheCatalog();
        $region = $this->ids['region_MAK'];

        $this->post(route('admin.address-system.regions.status', $region), ['active' => 0])->assertRedirect();
        $this->post(route('admin.address-system.regions.status', $region), ['active' => 0])->assertRedirect();
        $this->assertFalse((bool) AddressRegion::findOrFail($region)->is_active);
        $this->assertSame(['address.region_status_changed'], $this->auditActions(), 'the second request found nothing to change');

        $this->post(route('admin.address-system.regions.status', $region), ['active' => 1])->assertRedirect();
        $this->assertTrue((bool) AddressRegion::findOrFail($region)->is_active);
        $this->assertSame(['address.region_status_changed', 'address.region_status_changed'], $this->auditActions());
    }

    public function test_the_status_of_a_region_or_city_must_be_an_explicit_boolean(): void
    {
        $this->signInWithTheCatalog();

        $this->post(route('admin.address-system.regions.status', $this->ids['region_MAK']))->assertSessionHasErrors('active');
        $this->post(route('admin.address-system.cities.status', $this->ids['jeddah']), ['active' => 'maybe'])->assertSessionHasErrors('active');
        $this->assertTrue((bool) AddressRegion::findOrFail($this->ids['region_MAK'])->is_active);
        $this->assertTrue((bool) AddressCity::findOrFail($this->ids['jeddah'])->is_active);
    }

    public function test_switching_a_region_or_city_off_leaves_saved_addresses_and_order_snapshots_exactly_as_they_were(): void
    {
        $this->signInWithTheCatalog();
        $city = $this->city('jeddah');
        $address = $this->address([
            'city' => $city->name_ar, 'state' => $city->region->name_ar, 'region_id' => $city->region_id, 'city_id' => $city->id, 'national_address' => 'JEDD1234', 'district' => 'الروضة',
        ]);
        $snapshot = (string) json_encode(AddressSnapshot::build($this->addressRow($address)), JSON_UNESCAPED_UNICODE);
        DB::table('orders')->insert(['id' => 500, 'customer_id' => 7, 'shipping_address_data' => $snapshot, 'created_at' => now(), 'updated_at' => now()]);
        $savedRow = $this->addressRow($address);

        $this->post(route('admin.address-system.cities.status', $city->id), ['active' => 0])->assertRedirect();
        $this->post(route('admin.address-system.regions.status', $city->region_id), ['active' => 0])->assertRedirect();

        $this->assertSame($savedRow, $this->addressRow($address), 'the address keeps its region, city and National Address');
        $this->assertSame($snapshot, DB::table('orders')->where('id', 500)->value('shipping_address_data'), 'the order keeps its copy');
        $this->assertNull(app(AddressCatalog::class)->activeCityInRegion($city->id, $city->region_id), 'but nobody can choose it any more');
        $decoded = json_decode($snapshot, true);
        $this->assertSame('جدة', AddressSnapshot::cityName($decoded, 'ar'));
        $this->assertSame('Jeddah', AddressSnapshot::cityName($decoded, 'en'));
        $this->assertSame(AddressCity::count(), count($this->ids) - 3, 'no place was removed');
    }

    public function test_switching_off_the_last_active_city_returns_the_store_to_the_old_address_until_one_is_switched_on(): void
    {
        $this->signInWithTheCatalog();
        $this->post(route('admin.address-system.mode.update'), ['mode' => 'soft']);
        $this->assertSame(AddressSchema::MODE_SOFT, AddressSchema::mode());

        foreach (AddressCity::query()->where('is_active', true)->pluck('id') as $id) {
            $this->post(route('admin.address-system.cities.status', $id), ['active' => 0]);
        }
        $this->assertSame(AddressSchema::MODE_OFF, AddressSchema::mode(), 'an empty list can never lock customers out');
        $this->assertSame(AddressSchema::MODE_SOFT, AddressSchema::configuredMode(), 'the choice itself is kept');

        $this->post(route('admin.address-system.cities.status', $this->ids['riyadh']), ['active' => 1]);
        $this->assertSame(AddressSchema::MODE_SOFT, AddressSchema::mode());
    }

    // ---- cities: search and filters ----------------------------------------------------------------------------------------------

    public function test_cities_are_found_in_arabic_and_english_including_switched_off_ones(): void
    {
        $this->signInWithTheCatalog();
        AddressCity::whereKey($this->ids['khobar'])->update(['is_active' => false]);

        foreach (['الخبر', 'Al Khobar', 'khob', 'Alkhobar'] as $query) {
            $html = $this->get(route('admin.address-system.cities.index', ['q' => $query]))->assertOk()->getContent();

            $this->assertStringContainsString(route('admin.address-system.cities.edit', $this->ids['khobar']), $html, $query);
            $this->assertStringNotContainsString(route('admin.address-system.cities.edit', $this->ids['jeddah']), $html, $query);
        }
    }

    public function test_the_list_filters_by_region_and_by_status(): void
    {
        $this->signInWithTheCatalog();
        AddressCity::whereKey($this->ids['taif'])->update(['is_active' => false]);
        $edit = fn (string $handle): string => route('admin.address-system.cities.edit', $this->ids[$handle]);

        $html = $this->get(route('admin.address-system.cities.index', ['region_id' => $this->ids['region_MAK']]))->assertOk()->getContent();
        foreach (['jeddah', 'makkah', 'taif'] as $handle) {
            $this->assertStringContainsString($edit($handle), $html);
        }
        $this->assertStringNotContainsString($edit('riyadh'), $html);

        $html = $this->get(route('admin.address-system.cities.index', ['region_id' => $this->ids['region_MAK'], 'status' => 'inactive']))->assertOk()->getContent();
        $this->assertStringContainsString($edit('taif'), $html);
        $this->assertStringNotContainsString($edit('jeddah'), $html);

        $html = $this->get(route('admin.address-system.cities.index', ['status' => 'active', 'q' => 'طائف']))->assertOk()->getContent();
        $this->assertStringNotContainsString($edit('taif'), $html, 'search and status filter combine');
    }

    public function test_the_list_is_paged_and_shows_where_each_city_came_from(): void
    {
        $this->signInWithTheCatalog();
        $region = $this->ids['region_RUH'];
        foreach (range(1, 30) as $number) {
            DB::table('address_cities')->insert([
                'region_id' => $region, 'name_ar' => "قرية {$number}", 'name_en' => "Village {$number}", 'normalized_ar' => AddressText::key("قرية {$number}"), 'normalized_en' => "village{$number}",
                'is_active' => true, 'source' => 'nad_snapshot_2022', 'source_ref' => (string) (1000 + $number), 'source_updated_at' => '2022-01-11 00:00:00', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $first = $this->get(route('admin.address-system.cities.index'))->assertOk()->getContent();
        $second = $this->get(route('admin.address-system.cities.index', ['page' => 2]))->assertOk()->getContent();

        $this->assertStringContainsString('nad_snapshot_2022', $first . $second);
        $this->assertStringContainsString('2022-01-11', $first . $second);
        $this->assertStringContainsString('page=2', $first, 'a link to the next page');
        $this->assertNotSame($first, $second);
    }

    public function test_an_empty_search_says_so_and_offers_the_way_forward(): void
    {
        $this->signInWithTheCatalog();

        $html = $this->get(route('admin.address-system.cities.index', ['q' => 'nowhere-at-all']))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('address_no_cities_found')), $html);
        $this->assertStringContainsString(route('admin.address-system.cities.create'), $html);
    }

    public function test_bad_filters_are_refused_not_trusted(): void
    {
        $this->signInWithTheCatalog();

        $this->get(route('admin.address-system.cities.index', ['status' => 'deleted']))->assertSessionHasErrors('status');
        $this->get(route('admin.address-system.cities.index', ['region_id' => 'abc']))->assertSessionHasErrors('region_id');
        $this->get(route('admin.address-system.cities.index', ['q' => ['a']]))->assertSessionHasErrors('q');
    }

    // ---- cities: add --------------------------------------------------------------------------------------------------------------

    public function test_a_city_is_added_by_an_administrator_and_found_by_the_address_forms(): void
    {
        $this->signInWithTheCatalog();

        $this->post(route('admin.address-system.cities.store'), $this->cityForm())->assertRedirect();

        $city = AddressCity::query()->where('name_en', 'Al Kharj')->firstOrFail();
        $this->assertSame($this->ids['region_RUH'], $city->region_id);
        $this->assertTrue($city->is_active);
        $this->assertSame('admin', $city->source);
        $this->assertNull($city->source_ref);
        $this->assertNotNull($city->source_updated_at);
        $this->assertSame(24.15, $city->latitude);
        $this->assertSame(47.3, $city->longitude);
        $this->assertSame(5, $city->sort_order);
        $this->assertSame(AddressText::key('الخرج'), $city->normalized_ar);
        $this->assertSame(AddressText::key('Al Kharj'), $city->normalized_en);
        $this->assertSame([$city->id], app(AddressCatalog::class)->searchCities(regionId: $this->ids['region_RUH'], query: 'Kharj')->pluck('id')->all());
        $this->assertSame(['address.city_created'], $this->auditActions());
    }

    public function test_two_cities_can_be_added_by_hand_without_a_source_reference_clash(): void
    {
        $this->signInWithTheCatalog();

        $this->post(route('admin.address-system.cities.store'), $this->cityForm())->assertRedirect();
        $this->post(route('admin.address-system.cities.store'), $this->cityForm(['name_ar' => 'المزاحمية', 'name_en' => 'Al Muzahimiyah']))->assertRedirect();

        $this->assertSame(2, AddressCity::query()->where('source', 'admin')->count());
    }

    public function test_adding_a_city_holds_its_region_until_the_city_is_written_so_two_people_adding_one_name_take_turns(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('Row locks need a real MySQL or MariaDB: SQLite has none.');
        }
        $this->signInWithTheCatalog();

        $otherWaited = null;
        DB::beforeExecuting(function (string $query) use (&$otherWaited): void {
            if ($otherWaited !== null || preg_match('/^insert\s+into\s+[`"]address_cities[`"]/i', $query) !== 1) {
                return;
            }

            $otherWaited = $this->anotherPersonAddingToTheSameRegionHadToWait(regionId: $this->ids['region_RUH']);
        });

        $this->post(route('admin.address-system.cities.store'), $this->cityForm())->assertSessionHasNoErrors();

        $this->assertTrue($otherWaited, 'a second person checking the same region waits until the first city is written');
        $this->assertSame(1, AddressCity::query()->where('name_en', 'Al Kharj')->count());
    }

    /** Takes the lock a second "add a city" takes on the region, from another connection that gives up after a second. */
    private function anotherPersonAddingToTheSameRegionHadToWait(int $regionId): bool
    {
        config(['database.connections.second_connection' => config('database.connections.' . config('database.default'))]);
        DB::purge('second_connection');
        $other = DB::connection('second_connection');
        $other->statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $other->beginTransaction();
            $other->table('address_regions')->where('id', $regionId)->lockForUpdate()->pluck('id');

            return false;
        } catch (QueryException $exception) {
            return str_contains($exception->getMessage(), 'Lock wait timeout');
        } finally {
            DB::disconnect('second_connection');
        }
    }

    public function test_a_city_needs_names_an_existing_region_and_sensible_numbers(): void
    {
        $this->signInWithTheCatalog();
        $cities = AddressCity::count();

        $bad = [
            'no region' => ['region_id' => ''],
            'a region that does not exist' => ['region_id' => 987654],
            'a region that is not a number' => ['region_id' => 'riyadh'],
            'no arabic name' => ['name_ar' => ''],
            'no english name' => ['name_en' => ''],
            'markup in a name' => ['name_en' => '<b>Kharj</b>'],
            'a name of punctuation only' => ['name_ar' => '...'],
            'latitude out of range' => ['latitude' => '91'],
            'longitude out of range' => ['longitude' => '-181'],
            'latitude is not a number' => ['latitude' => 'north'],
            'negative rank' => ['sort_order' => '-1'],
            'huge rank' => ['sort_order' => '99999999'],
            'rank is not a number' => ['sort_order' => 'first'],
        ];
        foreach ($bad as $label => $override) {
            $this->post(route('admin.address-system.cities.store'), $this->cityForm($override))->assertSessionHasErrors(null, null, $label);
            $this->assertSame($cities, AddressCity::count(), $label);
        }
        $this->assertSame([], $this->auditActions());
    }

    public function test_coordinates_and_rank_are_optional(): void
    {
        $this->signInWithTheCatalog();

        $this->post(route('admin.address-system.cities.store'), $this->cityForm(['latitude' => '', 'longitude' => '', 'sort_order' => '']))->assertSessionHasNoErrors();

        $city = AddressCity::query()->where('name_en', 'Al Kharj')->firstOrFail();
        $this->assertNull($city->latitude);
        $this->assertNull($city->longitude);
        $this->assertSame(0, $city->sort_order);
    }

    public function test_the_same_name_cannot_be_added_twice_to_one_region_but_may_exist_in_another(): void
    {
        $this->signInWithTheCatalog();

        $this->post(route('admin.address-system.cities.store'), $this->cityForm(['name_ar' => 'الدرعية', 'name_en' => 'Another']))->assertSessionHasErrors('name_ar');
        $this->post(route('admin.address-system.cities.store'), $this->cityForm(['name_ar' => 'جديدة', 'name_en' => 'ad diriyah']))->assertSessionHasErrors('name_ar');
        $this->assertSame(0, AddressCity::query()->where('source', 'admin')->count());

        $this->post(route('admin.address-system.cities.store'), $this->cityForm(['region_id' => $this->ids['region_MAK'], 'name_ar' => 'الدرعية', 'name_en' => 'Ad Diriyah']))->assertSessionHasNoErrors();
        $this->assertSame(1, AddressCity::query()->where('source', 'admin')->count());
    }

    // ---- cities: edit -------------------------------------------------------------------------------------------------------------

    public function test_a_city_is_edited_and_the_change_is_recorded_with_what_it_was(): void
    {
        $this->signInWithTheCatalog();
        $city = $this->ids['jeddah'];

        $this->post(route('admin.address-system.cities.update', $city), $this->cityForm(['region_id' => $this->ids['region_MAK'], 'name_ar' => 'جدة (المدينة)', 'name_en' => 'Jeddah City', 'latitude' => '21.5', 'longitude' => '39.2', 'sort_order' => 90]))
            ->assertRedirect(route('admin.address-system.cities.edit', $city));

        $saved = AddressCity::findOrFail($city);
        $this->assertSame('جدة (المدينة)', $saved->name_ar);
        $this->assertSame('Jeddah City', $saved->name_en);
        $this->assertSame(90, $saved->sort_order);
        $this->assertSame('test', $saved->source, 'an edit does not change where the city came from');
        $audit = DB::table('audit_logs')->where('action', 'address.city_updated')->first();
        $this->assertSame('Jeddah', json_decode($audit->before, true)['name_en']);
        $this->assertSame('Jeddah City', json_decode($audit->after, true)['name_en']);
    }

    public function test_an_edit_that_changes_nothing_records_nothing(): void
    {
        $this->signInWithTheCatalog();
        $city = $this->city('jeddah');

        $this->post(route('admin.address-system.cities.update', $city->id), $this->cityForm([
            'region_id' => $city->region_id, 'name_ar' => $city->name_ar, 'name_en' => $city->name_en, 'latitude' => '', 'longitude' => '', 'sort_order' => 0,
        ]))->assertRedirect();

        $this->assertSame([], $this->auditActions());
    }

    public function test_an_edit_validates_like_an_addition(): void
    {
        $this->signInWithTheCatalog();
        $city = $this->ids['jeddah'];

        $this->post(route('admin.address-system.cities.update', $city), $this->cityForm(['region_id' => 987654]))->assertSessionHasErrors('region_id');
        $this->post(route('admin.address-system.cities.update', $city), $this->cityForm(['name_ar' => '']))->assertSessionHasErrors('name_ar');
        $this->post(route('admin.address-system.cities.update', 987654), $this->cityForm())->assertNotFound();
        $this->assertSame('Jeddah', AddressCity::findOrFail($city)->name_en);
    }

    public function test_editing_the_coordinates_of_one_of_two_same_named_imported_cities_is_not_blocked(): void
    {
        $this->signInWithTheCatalog();
        $city = $this->city('umm_a');

        $this->post(route('admin.address-system.cities.update', $city->id), $this->cityForm([
            'region_id' => $city->region_id, 'name_ar' => $city->name_ar, 'name_en' => $city->name_en, 'latitude' => '24.9', 'longitude' => '46.1', 'sort_order' => 3,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(24.9, AddressCity::findOrFail($city->id)->latitude);
    }

    public function test_renaming_a_city_to_the_name_of_another_city_of_its_region_is_refused(): void
    {
        $this->signInWithTheCatalog();

        $this->post(route('admin.address-system.cities.update', $this->ids['riyadh']), $this->cityForm(['name_ar' => 'الدرعية', 'name_en' => 'Ad Diriyah']))->assertSessionHasErrors('name_ar');

        $this->assertSame('الرياض', AddressCity::findOrFail($this->ids['riyadh'])->name_ar);
    }

    public function test_moving_a_city_to_another_region_takes_its_saved_addresses_along_but_not_the_orders(): void
    {
        $this->signInWithTheCatalog();
        $city = $this->city('taif');
        $address = $this->address(['city' => $city->name_ar, 'state' => 'منطقة مكة المكرمة', 'region_id' => $city->region_id, 'city_id' => $city->id, 'national_address' => 'TAIF1234']);
        $other = $this->address(['city' => 'جدة', 'region_id' => $this->ids['region_MAK'], 'city_id' => $this->ids['jeddah'], 'national_address' => 'JEDD1234']);
        $snapshot = (string) json_encode(AddressSnapshot::build($this->addressRow($address)), JSON_UNESCAPED_UNICODE);
        DB::table('orders')->insert(['id' => 501, 'customer_id' => 7, 'shipping_address_data' => $snapshot, 'created_at' => now(), 'updated_at' => now()]);
        $otherRow = $this->addressRow($other);
        $stamp = $this->addressRow($address)['updated_at'];

        $this->post(route('admin.address-system.cities.update', $city->id), $this->cityForm(['region_id' => $this->ids['region_RUH'], 'name_ar' => $city->name_ar, 'name_en' => $city->name_en]))->assertSessionHasNoErrors();

        $moved = $this->addressRow($address);
        $this->assertSame($this->ids['region_RUH'], (int) $moved['region_id'], 'the address follows its city');
        $this->assertSame($city->id, (int) $moved['city_id']);
        $this->assertSame('TAIF1234', $moved['national_address']);
        $this->assertSame('منطقة مكة المكرمة', $moved['state'], 'the text the customer saw is not rewritten');
        $this->assertSame($stamp, $moved['updated_at']);
        $this->assertSame($otherRow, $this->addressRow($other), 'other cities are untouched');
        $this->assertSame($snapshot, DB::table('orders')->where('id', 501)->value('shipping_address_data'), 'the order keeps the region it was placed with');
        $this->assertNotNull(app(AddressCatalog::class)->activeCityInRegion($city->id, $this->ids['region_RUH']), 'and the pair still validates');
    }

    public function test_the_edit_form_remembers_what_was_typed_when_a_save_is_refused(): void
    {
        $this->signInWithTheCatalog();
        $city = $this->ids['jeddah'];

        $this->from(route('admin.address-system.cities.edit', $city))
            ->post(route('admin.address-system.cities.update', $city), $this->cityForm(['name_ar' => 'جدة الكبرى', 'latitude' => '999']))
            ->assertRedirect(route('admin.address-system.cities.edit', $city))
            ->assertSessionHasErrors('latitude')
            ->assertSessionHasInput('name_ar', 'جدة الكبرى');
    }

    // ---- cities: switching off and on ---------------------------------------------------------------------------------------------

    public function test_a_city_is_switched_off_and_on_and_both_are_recorded(): void
    {
        $this->signInWithTheCatalog();
        $city = $this->ids['makkah'];

        $this->post(route('admin.address-system.cities.status', $city), ['active' => 0])->assertRedirect();
        $this->assertFalse((bool) AddressCity::findOrFail($city)->is_active);
        $this->post(route('admin.address-system.cities.status', $city), ['active' => 1])->assertRedirect();
        $this->assertTrue((bool) AddressCity::findOrFail($city)->is_active);

        $this->assertSame(['address.city_status_changed', 'address.city_status_changed'], $this->auditActions());
        $this->post(route('admin.address-system.cities.status', 987654), ['active' => 0])->assertNotFound();
    }
}
