<?php

namespace Tests\Feature\Address;

use App\Models\AddressCity;
use App\Models\AddressRegion;
use App\Services\Address\AddressMigrations;
use App\Services\Address\AddressSchema;
use App\Services\Address\AddressStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `address:status` and the overview page read the same report: migrations, mode, list sizes, old addresses, provider mappings. Counts and
 * dates only, so it can be pasted into a ticket.
 */
class AdminAddressStatusTest extends AdminAddressTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->signInWithTheCatalog();
    }

    private function seedAddresses(): void
    {
        $this->address(['city' => 'x', 'city_id' => $this->ids['riyadh'], 'region_id' => $this->ids['region_RUH'], 'national_address' => 'RAGI2929', 'zip' => '12345']);
        $this->address(['city' => 'x', 'city_id' => $this->ids['jeddah'], 'region_id' => $this->ids['region_MAK'], 'national_address' => null, 'zip' => '21589']);
        $this->address(['city' => 'الرياض', 'zip' => null]);
        $this->address(['city' => 'الرياض', 'zip' => '']);
        $this->address(['city' => 'الهفوف']);
        $this->address(['city' => 'جدة', 'state' => 'منطقة الرياض']);
        $this->address(['city' => 'Gotham']);
        $this->address(['city' => 'Dubai', 'country' => 'United Arab Emirates', 'zip' => null]);
    }

    private function seedMappings(): void
    {
        $row = fn (string $provider, string $city, string $status, ?int $slot = null, string $external = '1'): array => [
            'provider' => $provider, 'address_city_id' => $this->ids[$city], 'external_city_id' => $external, 'status' => $status, 'approved_slot' => $slot, 'country_code' => 'SA', 'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('provider_city_mappings')->insert([
            $row('torod_point', 'riyadh', 'approved', 1, '36189'),
            $row('torod_point', 'riyadh', 'rejected', null, '230194'),
            $row('torod_point', 'jeddah', 'pending', null, '41126'),
            $row('torod_point', 'makkah', 'pending', null, '50006'),
            $row('torod_point', 'taif', 'retired', null, '50004'),
            $row('future_courier', 'riyadh', 'pending', null, 'f1'),
        ]);
        DB::table('provider_city_sync_runs')->insert(['provider' => 'torod_point', 'verdict' => 'partial', 'finished_at' => '2026-11-06 09:00:00', 'created_at' => '2026-11-06 09:00:00']);
    }

    private function statusOutput(): string
    {
        $this->assertSame(0, Artisan::call('address:status'));

        return Artisan::output();
    }

    public function test_the_report_counts_addresses_by_what_a_backfill_could_do_with_them(): void
    {
        $this->seedAddresses();

        $addresses = app(AddressStatus::class)->report()['addresses'];

        $this->assertSame(7, $addresses['saudi']);
        $this->assertSame(2, $addresses['unified']);
        $this->assertSame(5, $addresses['legacy']);
        $this->assertSame(2, $addresses['legacy_matched']);
        $this->assertSame(3, $addresses['legacy_unmatched']);
        $this->assertSame(['matched' => 2, 'unconfirmed' => 0, 'ambiguous' => 1, 'conflict' => 1, 'none' => 1, 'inactive' => 0], $addresses['legacy_by_outcome']);
        $this->assertSame(6, $addresses['needing_completion'], 'five without a city, one with a city but no National Address');
        $this->assertSame(2, $addresses['without_zip'], 'a blank ZIP, in any form, of a Saudi address');
    }

    public function test_the_command_prints_the_whole_picture_in_counts(): void
    {
        $this->seedAddresses();
        $this->seedMappings();
        AddressSchema::setMode(AddressSchema::MODE_SOFT);
        AddressCity::query()->update(['source' => 'nad_snapshot_2022', 'source_updated_at' => '2022-01-11 00:00:00', 'created_at' => '2026-11-07 08:00:00']);
        DB::table('address_cities')->insert(['region_id' => $this->ids['region_RUH'], 'name_ar' => 'بلدة', 'name_en' => 'Town', 'normalized_ar' => 'بلده', 'normalized_en' => 'town', 'source' => 'admin', 'created_at' => '2026-11-09 08:00:00', 'updated_at' => now()]);
        DB::table('address_city_aliases')->insert(['city_id' => $this->ids['riyadh'], 'alias' => 'العاصمة', 'normalized_alias' => 'عاصمه', 'source' => 'legacy_review', 'created_at' => now(), 'updated_at' => now()]);

        $output = $this->statusOutput();

        foreach (AddressMigrations::MIGRATIONS as $migration) {
            $this->assertMatchesRegularExpression('/done\s+' . preg_quote($migration, '/') . '/', $output);
        }
        $this->assertStringContainsString('Mode: soft configured, soft in force. ZIP delivery restriction: off.', $output);
        $this->assertStringContainsString('Regions: 3 (3 active). Cities: 12 (12 active). Aliases: 1.', $output);
        $this->assertStringContainsString('Data: nad_snapshot_2022, 11 cities, snapshot 2022-01-11, loaded 2026-11-07.', $output, 'the last import, not the city an administrator added later');
        $this->assertStringContainsString('Saved Saudi addresses: 7. With a region and city: 2. Old (typed city only): 5.', $output);
        $this->assertStringContainsString('Old addresses a backfill would match: 2. Left for a person: 3.', $output);
        $this->assertStringContainsString('Addresses to complete (no city of the list, or no National Address): 6. Without a ZIP code: 2.', $output);
        $this->assertMatchesRegularExpression('/Provider .+: 1 approved, 2 pending, 1 rejected, 1 retired; active cities without an approved mapping: 11; last city sync: partial 2026-11-06\./', $output);
        $this->assertStringContainsString('Provider future_courier: 0 approved, 1 pending, 0 rejected, 0 retired; active cities without an approved mapping: 12; last city sync: never.', $output);
    }

    public function test_the_command_never_prints_a_name_a_phone_an_address_or_a_typed_city(): void
    {
        $this->seedAddresses();
        $this->address(['city' => 'Quillfeather Heights', 'contact_person_name' => 'Zeta Quillfeather', 'phone' => '0559876543', 'address' => '99 Secret Lane', 'national_address' => 'SECR9999', 'zip' => '99999']);
        $this->seedMappings();

        $output = $this->statusOutput();

        foreach (['Quillfeather', '0559876543', '0551234567', '99 Secret Lane', 'SECR9999', '99999', 'RAGI2929', 'الرياض', 'الهفوف', 'Gotham', 'Zeta'] as $personal) {
            $this->assertStringNotContainsString($personal, $output, $personal);
        }
    }

    public function test_the_command_is_read_only(): void
    {
        $this->seedAddresses();
        $this->seedMappings();
        $tables = ['shipping_addresses', 'address_regions', 'address_cities', 'address_city_aliases', 'provider_city_mappings', 'provider_city_sync_runs', 'business_settings', 'audit_logs'];
        $before = array_map(fn (string $table): array => DB::table($table)->orderBy('id')->get()->all(), $tables);

        $this->statusOutput();

        $this->assertEquals($before, array_map(fn (string $table): array => DB::table($table)->orderBy('id')->get()->all(), $tables));
    }

    public function test_the_report_says_which_migrations_are_still_to_run_and_stops_there(): void
    {
        foreach (['address_city_aliases', 'address_cities', 'address_regions'] as $table) {
            Schema::drop($table);
        }
        AddressSchema::flush();

        $output = $this->statusOutput();

        $this->assertMatchesRegularExpression('/pending\s+' . preg_quote(AddressMigrations::MIGRATIONS[0], '/') . '/', $output);
        $this->assertMatchesRegularExpression('/done\s+' . preg_quote(AddressMigrations::MIGRATIONS[1], '/') . '/', $output);
        $this->assertStringContainsString('Mode: off configured, off in force.', $output);
        $this->assertStringContainsString('The address migrations have not been run yet; nothing to report.', $output);
        $this->assertStringNotContainsString('Saved Saudi addresses', $output);
    }

    public function test_the_mode_the_store_actually_uses_is_shown_next_to_the_one_chosen(): void
    {
        AddressSchema::setMode(AddressSchema::MODE_STRICT);
        AddressCity::query()->update(['is_active' => false]);
        AddressSchema::flush();

        $output = $this->statusOutput();

        $this->assertStringContainsString('Mode: strict configured, off in force (the store is held at off until the migrations are run and an active city lies in an active region).', $output);
    }

    public function test_the_mode_is_held_at_off_too_when_every_region_is_switched_off(): void
    {
        AddressSchema::setMode(AddressSchema::MODE_SOFT);
        AddressRegion::query()->update(['is_active' => false]);
        AddressSchema::flush();

        $this->assertStringContainsString('Mode: soft configured, off in force', $this->statusOutput());
    }

    public function test_the_mapping_summary_is_left_out_when_the_mapping_tables_are_not_there(): void
    {
        Schema::drop('provider_city_sync_runs');
        Schema::drop('provider_city_mappings');
        AddressSchema::flush();

        $report = app(AddressStatus::class)->report();
        $output = $this->statusOutput();

        $this->assertFalse($report['mappings_ready']);
        $this->assertSame([], $report['providers']);
        $this->assertStringContainsString('Provider mappings: the mapping migrations have not been run.', $output);
    }

    public function test_the_overview_page_shows_the_same_numbers_and_the_provider_summary(): void
    {
        $this->seedAddresses();
        $this->seedMappings();
        AddressCity::query()->update(['source' => 'nad_snapshot_2022', 'source_updated_at' => '2022-01-11 00:00:00', 'created_at' => '2026-11-07 08:00:00']);

        $html = $this->get(route('admin.address-system.index'))->assertOk()->getContent();

        foreach ([
            'k-stat__value k-num">7<' => 'saved Saudi addresses',
            'k-stat__value k-num">2<' => 'with a region and city, and legacy matched',
            'k-stat__value k-num">3<' => 'legacy unmatched',
            'k-stat__value k-num">6<' => 'needing completion',
            'k-stat__value k-num">11<' => 'active cities',
            e(translate('address_switched_off_count')) . ': 0' => 'the switched-off count',
            'nad_snapshot_2022' => 'the data source',
            '2022-01-11' => 'the snapshot date',
            '2026-11-07' => 'the load date',
        ] as $needle => $what) {
            $this->assertStringContainsString($needle, $html, $what);
        }
        $this->assertStringContainsString(route('admin.address-system.legacy.index'), $html);
        $this->assertStringContainsString('future_courier', $html);
        $this->assertStringContainsString(e(translate('address_sync_partial')), $html);
        $this->assertStringContainsString(route('admin.fulfillment-providers.torod.matching'), $html);
    }

    public function test_the_overview_does_not_read_every_old_city_text_when_there_are_too_many(): void
    {
        $rows = [];
        foreach (range(1, 2002) as $number) {
            $rows[] = ['customer_id' => (string) $number, 'is_guest' => 0, 'city' => 'مدينة ' . $number . 'ا' . str_repeat('ب', $number % 40), 'country' => 'Saudi Arabia', 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('shipping_addresses')->insert($chunk);
        }

        $html = $this->get(route('admin.address-system.index'))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('address_legacy_not_counted')), $html);
        $this->assertNull(app(AddressStatus::class)->report(legacyTextLimit: 2000)['addresses']['legacy_matched']);
        $this->assertSame(0, app(AddressStatus::class)->report()['addresses']['legacy_matched'], 'the command reads them all');
    }
}
