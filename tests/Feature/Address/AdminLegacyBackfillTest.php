<?php

namespace Tests\Feature\Address;

use App\Models\AddressCity;
use App\Models\AddressRegion;
use App\Services\Address\AddressSchema;
use App\Services\Address\AddressText;
use App\Services\Address\LegacyBackfill;
use App\Services\Address\SavedSaudiAddresses;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Giving old addresses their region and city: only where one city, confirmed, is the answer; never touching anything else about an address;
 * and a second run finds nothing to do.
 */
class AdminLegacyBackfillTest extends AdminAddressTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ids = $this->seedAddressCatalog();
    }

    private function backfill(): LegacyBackfill
    {
        return app(LegacyBackfill::class);
    }

    /** @return array<int, array<string, mixed>> every saved address by id */
    private function allRows(): array
    {
        return DB::table('shipping_addresses')->orderBy('id')->get()->mapWithKeys(static fn (object $row): array => [(int) $row->id => (array) $row])->all();
    }

    /** The mix of cases that matters, as one list of ids by what the matcher is expected to say. @return array<string, int> */
    private function mixedAddresses(): array
    {
        return [
            'riyadh' => $this->address(['city' => 'الرياض', 'zip' => '11564', 'national_address' => null]),
            'riyadh with a space' => $this->address(['city' => 'الرياض ']),
            'jeddah in english' => $this->address(['city' => 'Jeddah', 'zip' => null]),
            'hofuf in english' => $this->address(['city' => 'Hofuf']),
            'hafuf' => $this->address(['city' => 'الهفوف']),
            'umm sulaym' => $this->address(['city' => 'أم سليم', 'state' => 'منطقة الرياض']),
            'riyadh in makkah' => $this->address(['city' => 'الرياض', 'state' => 'منطقة مكة المكرمة']),
            'a city nobody listed' => $this->address(['city' => 'Gotham']),
            'no city' => $this->address(['city' => '']),
            'null city' => $this->address(['city' => null]),
        ];
    }

    public function test_a_dry_run_counts_each_outcome_and_writes_nothing(): void
    {
        $this->mixedAddresses();
        $before = $this->allRows();

        $report = $this->backfill()->run(apply: false);

        $this->assertFalse($report['applied']);
        $this->assertSame(['matched' => 4, 'ambiguous' => 2, 'conflict' => 1, 'none' => 3, 'inactive' => 0], $report['rows']);
        $this->assertSame(['matched' => 3, 'ambiguous' => 2, 'conflict' => 1, 'none' => 2, 'inactive' => 0], $report['texts'], 'distinct typed texts; the two blank cities are one text');
        $this->assertSame(0, $report['updated']);
        $this->assertSame($before, $this->allRows());
        $this->assertSame([], $this->auditActions());
    }

    public function test_applying_writes_only_the_unique_confirmed_matches_and_changes_nothing_else_about_them(): void
    {
        $ids = $this->mixedAddresses();
        $before = $this->allRows();

        $report = $this->backfill()->run(apply: true);

        $this->assertSame(4, $report['updated']);
        $after = $this->allRows();
        $expected = ['riyadh' => 'riyadh', 'riyadh with a space' => 'riyadh', 'jeddah in english' => 'jeddah', 'hofuf in english' => 'hafuf_eas'];
        foreach ($ids as $label => $id) {
            if (isset($expected[$label])) {
                $city = $this->city($expected[$label]);
                $this->assertSame($city->id, (int) $after[$id]['city_id'], $label);
                $this->assertSame($city->region_id, (int) $after[$id]['region_id'], $label);
                $changed = array_keys(array_diff_assoc($after[$id], $before[$id]));
                sort($changed);
                $this->assertSame(['city_id', 'region_id'], $changed, "{$label}: only the two ids are written; the typed city, state, ZIP and National Address stay");
            } else {
                $this->assertSame($before[$id], $after[$id], "{$label} is left for a person");
            }
        }
    }

    public function test_a_second_run_finds_nothing_to_do(): void
    {
        $this->mixedAddresses();
        $this->backfill()->run(apply: true);
        $afterFirst = $this->allRows();

        $second = $this->backfill()->run(apply: true);

        $this->assertSame(0, $second['updated']);
        $this->assertSame(0, $second['rows']['matched']);
        $this->assertSame($afterFirst, $this->allRows());
    }

    public function test_a_state_text_settles_a_name_that_two_regions_share(): void
    {
        $east = $this->address(['city' => 'الهفوف', 'state' => 'المنطقة الشرقية']);
        $riyadh = $this->address(['city' => 'الهفوف', 'state' => 'منطقة الرياض']);
        $bare = $this->address(['city' => 'الهفوف']);

        $this->backfill()->run(apply: true);

        $this->assertSame($this->ids['hafuf_eas'], (int) $this->addressRow($east)['city_id']);
        $this->assertSame($this->ids['hafuf_ruh'], (int) $this->addressRow($riyadh)['city_id']);
        $this->assertNull($this->addressRow($bare)['city_id'], 'no state, two cities of that name: never guessed');
    }

    public function test_an_ambiguous_name_inside_one_region_is_never_applied_even_with_a_state(): void
    {
        $address = $this->address(['city' => 'أم سليم', 'state' => 'منطقة الرياض']);

        $report = $this->backfill()->run(apply: true);

        $this->assertSame(1, $report['rows']['ambiguous']);
        $this->assertNull($this->addressRow($address)['city_id']);
    }

    public function test_a_state_that_points_at_another_region_is_a_conflict_and_is_not_applied(): void
    {
        $address = $this->address(['city' => 'الرياض', 'state' => 'المنطقة الشرقية']);

        $report = $this->backfill()->run(apply: true);

        $this->assertSame(1, $report['rows']['conflict']);
        $this->assertSame(0, $report['updated']);
        $this->assertNull($this->addressRow($address)['city_id']);
    }

    public function test_an_alias_an_administrator_confirmed_settles_a_repeated_name(): void
    {
        DB::table('address_city_aliases')->insert(['city_id' => $this->ids['hafuf_eas'], 'alias' => 'الهفوف', 'normalized_alias' => AddressText::key('الهفوف'), 'source' => 'admin', 'created_at' => now(), 'updated_at' => now()]);
        $confirmed = $this->address(['city' => 'الهفوف']);
        $elsewhere = $this->address(['city' => 'الهفوف', 'state' => 'منطقة الرياض']);

        $report = $this->backfill()->run(apply: true);

        $this->assertSame($this->ids['hafuf_eas'], (int) $this->addressRow($confirmed)['city_id']);
        $this->assertNull($this->addressRow($elsewhere)['city_id'], 'the state says Riyadh region, the alias says Eastern: a conflict, left to a person');
        $this->assertSame(1, $report['rows']['conflict']);
    }

    public function test_a_match_with_a_city_or_region_that_is_switched_off_is_counted_and_not_applied(): void
    {
        AddressCity::whereKey($this->ids['riyadh'])->update(['is_active' => false]);
        AddressRegion::whereKey($this->ids['region_MAK'])->update(['is_active' => false]);
        $riyadh = $this->address(['city' => 'الرياض']);
        $jeddah = $this->address(['city' => 'Jeddah']);
        $khobar = $this->address(['city' => 'Al Khobar']);

        $report = $this->backfill()->run(apply: true);

        $this->assertSame(['matched' => 1, 'ambiguous' => 0, 'conflict' => 0, 'none' => 0, 'inactive' => 2], $report['rows']);
        $this->assertNull($this->addressRow($riyadh)['city_id']);
        $this->assertNull($this->addressRow($jeddah)['city_id']);
        $this->assertSame($this->ids['khobar'], (int) $this->addressRow($khobar)['city_id']);
    }

    public function test_only_saudi_addresses_are_read_whatever_way_the_country_was_written(): void
    {
        $saudi = [];
        foreach (['Saudi Arabia', 'SA', 'KSA', 'saudi arabia', 'المملكة العربية السعودية', 'السعودية'] as $country) {
            $saudi[] = $this->address(['city' => 'الرياض', 'country' => $country]);
        }
        $others = [
            $this->address(['city' => 'الرياض', 'country' => 'United Arab Emirates']),
            $this->address(['city' => 'الرياض', 'country' => null]),
            $this->address(['city' => 'الرياض', 'country' => '']),
            $this->address(['city' => 'الرياض', 'country' => 'Syria']),
        ];
        $before = array_intersect_key($this->allRows(), array_flip($others));

        $report = $this->backfill()->run(apply: true);

        $this->assertSame(6, $report['updated']);
        foreach ($saudi as $id) {
            $this->assertSame($this->ids['riyadh'], (int) $this->addressRow($id)['city_id']);
        }
        $this->assertSame($before, array_intersect_key($this->allRows(), array_flip($others)), 'other countries keep their free-text city');
    }

    public function test_an_address_that_already_has_a_city_keeps_it(): void
    {
        $address = $this->address(['city' => 'الرياض', 'city_id' => $this->ids['jeddah'], 'region_id' => $this->ids['region_MAK'], 'national_address' => 'JEDD1234']);
        $before = $this->addressRow($address);

        $report = $this->backfill()->run(apply: true);

        $this->assertSame(0, $report['updated']);
        $this->assertSame($before, $this->addressRow($address));
    }

    public function test_an_address_that_was_given_a_city_in_the_meantime_is_never_overwritten(): void
    {
        $free = $this->address(['city' => 'الرياض']);
        $taken = $this->address(['city' => 'الرياض', 'city_id' => $this->ids['jeddah'], 'region_id' => $this->ids['region_MAK']]);

        $written = app(SavedSaudiAddresses::class)->assignTypedAs(city: 'الرياض', state: null, target: $this->city('riyadh'));

        $this->assertSame(1, $written);
        $this->assertSame($this->ids['riyadh'], (int) $this->addressRow($free)['city_id']);
        $this->assertSame($this->ids['jeddah'], (int) $this->addressRow($taken)['city_id'], 'a decision someone else made first stands');
    }

    public function test_an_address_its_owner_retyped_between_reading_and_writing_is_left_alone(): void
    {
        $stays = $this->address(['city' => 'الرياض']);
        $retyped = $this->address(['city' => 'الرياض']);
        $this->retypeBeforeTheNextAddressUpdate(addressId: $retyped, changes: ['city' => 'جدة']);

        $written = app(SavedSaudiAddresses::class)->assignTypedAs(city: 'الرياض', state: null, target: $this->city('riyadh'));

        $this->assertSame(1, $written);
        $this->assertSame($this->ids['riyadh'], (int) $this->addressRow($stays)['city_id']);
        $this->assertNull($this->addressRow($retyped)['city_id'], 'the row no longer says Riyadh, so Riyadh is not written to it');
        $this->assertSame('جدة', $this->addressRow($retyped)['city']);
    }

    public function test_a_state_changed_between_reading_and_writing_is_left_alone_too(): void
    {
        $retyped = $this->address(['city' => 'الهفوف', 'state' => 'المنطقة الشرقية']);
        $this->retypeBeforeTheNextAddressUpdate(addressId: $retyped, changes: ['state' => 'منطقة الرياض']);

        $written = app(SavedSaudiAddresses::class)->assignTypedAs(city: 'الهفوف', state: 'المنطقة الشرقية', target: $this->city('hafuf_eas'));

        $this->assertSame(0, $written, 'the match was certain only together with the state it was made for');
        $this->assertNull($this->addressRow($retyped)['city_id']);
    }

    public function test_a_long_list_is_written_in_chunks_without_missing_a_row(): void
    {
        $rows = [];
        foreach (range(1, 1250) as $number) {
            $rows[] = ['customer_id' => (string) $number, 'is_guest' => 0, 'city' => 'الرياض', 'country' => 'Saudi Arabia', 'zip' => '12345', 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-01 10:00:00'];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('shipping_addresses')->insert($chunk);
        }

        $report = $this->backfill()->run(apply: true);

        $this->assertSame(1250, $report['updated']);
        $this->assertSame(0, DB::table('shipping_addresses')->whereNull('city_id')->count());
        $this->assertSame(0, DB::table('shipping_addresses')->where('updated_at', '!=', '2026-01-01 10:00:00')->count(), 'no timestamp moved');
    }

    public function test_an_apply_that_wrote_something_is_audited_with_counts_only(): void
    {
        $this->mixedAddresses();
        $this->backfill()->run(apply: false);
        $this->assertSame([], $this->auditActions());

        $this->backfill()->run(apply: true);

        $this->assertSame(['address.legacy_backfilled'], $this->auditActions());
        $audit = DB::table('audit_logs')->first();
        $this->assertSame(4, json_decode($audit->after, true)['updated']);
        $this->assertStringNotContainsString('Quillfeather', (string) json_encode($audit), 'no name, phone or street reaches the trail');

        $this->backfill()->run(apply: true);
        $this->assertSame(['address.legacy_backfilled'], $this->auditActions(), 'a run that found nothing writes no line');
    }

    public function test_the_plan_is_withheld_when_there_are_too_many_different_texts_to_read_on_a_page(): void
    {
        foreach (range(1, 12) as $number) {
            $this->address(['city' => "مدينة رقم {$number}"]);
        }

        $this->assertNull($this->backfill()->plan(textLimit: 5));
        $this->assertSame(12, $this->backfill()->plan(textLimit: 12)['rows']['none']);
        $this->assertSame(12, $this->backfill()->plan()['rows']['none']);
    }

    public function test_before_the_migrations_a_run_is_empty_and_harmless(): void
    {
        $this->address(['city' => 'الرياض']);
        foreach (['address_city_aliases', 'address_cities', 'address_regions'] as $table) {
            Schema::drop($table);
        }
        AddressSchema::flush();

        $report = $this->backfill()->run(apply: true);

        $this->assertSame(0, $report['updated']);
        $this->assertSame(0, array_sum($report['rows']));
        $this->assertNull($this->addressRow(1)['city_id'] ?? null);
    }

    public function test_the_reader_finds_saudi_addresses_without_a_city_and_the_ones_that_need_completing(): void
    {
        $this->address(['city' => 'الرياض']);
        $this->address(['city' => 'الرياض', 'city_id' => $this->ids['riyadh'], 'region_id' => $this->ids['region_RUH']]);
        $this->address(['city' => 'الرياض', 'city_id' => $this->ids['riyadh'], 'region_id' => $this->ids['region_RUH'], 'national_address' => 'RAGI2929', 'zip' => '  ']);
        $this->address(['city' => 'Dubai', 'country' => 'United Arab Emirates', 'zip' => null]);
        $saudi = app(SavedSaudiAddresses::class);

        $this->assertSame(3, $saudi->saudi()->count());
        $this->assertSame(1, $saudi->withoutCity()->count());
        $this->assertSame(2, $saudi->needingCompletion()->count(), 'no city, or a city without a National Address');
        $this->assertSame(1, $saudi->withoutZip()->count(), 'a ZIP of spaces is blank; another country is not counted');
    }

    public function test_the_command_reports_a_dry_run_then_applies(): void
    {
        $ids = $this->mixedAddresses();

        $this->artisan('address:backfill-legacy')
            ->expectsOutputToContain('Dry run: nothing was written')
            ->expectsOutputToContain('4 address(es) would be given a region and city.')
            ->assertExitCode(0);
        $this->assertNull($this->addressRow($ids['riyadh'])['city_id']);

        $this->artisan('address:backfill-legacy', ['--apply' => true])
            ->expectsOutputToContain('Applied.')
            ->expectsOutputToContain('Addresses given a region and city: 4.')
            ->assertExitCode(0);
        $this->assertSame($this->ids['riyadh'], (int) $this->addressRow($ids['riyadh'])['city_id']);
        $this->assertNull($this->addressRow($ids['hafuf'])['city_id']);

        $this->artisan('address:backfill-legacy', ['--apply' => true])->expectsOutputToContain('Addresses given a region and city: 0.')->assertExitCode(0);
    }

    public function test_the_command_prints_counts_and_never_a_typed_text(): void
    {
        $this->address(['city' => 'Quillfeather Heights, 12 Quillfeather Lane', 'contact_person_name' => 'Zeta Quillfeather']);

        $this->artisan('address:backfill-legacy')
            ->doesntExpectOutputToContain('Quillfeather')
            ->assertExitCode(0);
    }

    public function test_the_command_skips_a_store_that_has_not_been_migrated(): void
    {
        foreach (['address_city_aliases', 'address_cities', 'address_regions'] as $table) {
            Schema::drop($table);
        }
        AddressSchema::flush();

        $this->artisan('address:backfill-legacy', ['--apply' => true])
            ->expectsOutputToContain('The address migrations have not been run yet; nothing to do.')
            ->assertExitCode(0);
    }
}
