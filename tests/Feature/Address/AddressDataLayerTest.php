<?php

namespace Tests\Feature\Address;

use App\Models\AddressCity;
use App\Models\AddressRegion;
use App\Services\Address\AddressCatalog;
use App\Services\Address\AddressImporter;
use App\Services\Address\AddressSchema;
use App\Services\Address\AddressText;
use App\Services\Address\LegacyCityMatcher;
use App\Services\Address\NationalAddress;
use App\Models\AddressCityAlias;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

class AddressDataLayerTest extends AddressTestCase
{
    public function test_migrations_can_run_twice_and_leave_existing_addresses_untouched(): void
    {
        DB::table('shipping_addresses')->insert(['city' => 'الرياض', 'zip' => '12345', 'country' => 'Saudi Arabia', 'address' => 'Olaya St']);

        $this->runAddressMigrations();

        $row = DB::table('shipping_addresses')->first();
        $this->assertSame('الرياض', $row->city);
        $this->assertSame('12345', $row->zip);
        $this->assertNull($row->city_id);
        $this->assertNull($row->national_address);
        $this->assertTrue(AddressSchema::ready());
        $this->assertTrue(AddressSchema::mappingsReady());
    }

    public function test_rolling_the_column_migration_back_removes_only_its_own_columns(): void
    {
        DB::table('shipping_addresses')->insert(['city' => 'جدة', 'zip' => '21589']);
        $migration = require database_path('migrations/2026_11_07_100100_add_unified_address_columns_to_shipping_addresses_table.php');

        $migration->down();

        $this->assertFalse(Schema::hasColumn('shipping_addresses', 'city_id'));
        $this->assertFalse(Schema::hasColumn('shipping_addresses', 'national_address'));
        $this->assertSame(['جدة', '21589'], [DB::table('shipping_addresses')->value('city'), DB::table('shipping_addresses')->value('zip')]);
    }

    public function test_rolling_back_leaves_a_column_it_did_not_add_and_does_not_stop_half_way(): void
    {
        $migration = require database_path('migrations/2026_11_07_100100_add_unified_address_columns_to_shipping_addresses_table.php');
        $migration->down();
        Schema::table('shipping_addresses', function (Blueprint $table) {
            $table->string('city_id')->nullable();
        });
        DB::table('shipping_addresses')->insert(['city' => 'جدة', 'city_id' => 'foreign-7']);

        $migration->up();
        $migration->down();
        $migration->down();

        $this->assertSame('foreign-7', DB::table('shipping_addresses')->value('city_id'), 'a column the table already had is not ours to drop');
        foreach (['region_id', 'district', 'national_address'] as $column) {
            $this->assertFalse(Schema::hasColumn('shipping_addresses', $column), "{$column} was added by the migration and goes with it");
        }
    }

    public function test_the_address_code_stays_dormant_until_the_tables_exist(): void
    {
        Schema::dropIfExists('address_city_aliases');
        Schema::dropIfExists('address_cities');
        Schema::dropIfExists('address_regions');
        AddressSchema::flush();

        $this->assertFalse(AddressSchema::ready());
        $this->assertSame(AddressSchema::MODE_OFF, AddressSchema::mode());
        $this->assertFalse(AddressSchema::hasCities());
    }

    public function test_mode_is_off_until_a_city_exists_and_an_administrator_turns_it_on(): void
    {
        AddressSchema::setMode(AddressSchema::MODE_STRICT);
        $this->assertSame(AddressSchema::MODE_OFF, AddressSchema::mode(), 'an empty city list must never lock customers out');

        $this->seedAddressCatalog();
        $this->assertSame(AddressSchema::MODE_STRICT, AddressSchema::mode());
        $this->assertTrue(AddressSchema::strict());

        AddressSchema::setMode(AddressSchema::MODE_SOFT);
        $this->assertSame(AddressSchema::MODE_SOFT, AddressSchema::mode());
        $this->assertFalse(AddressSchema::strict());

        AddressSchema::setMode(AddressSchema::MODE_OFF);
        $this->assertFalse(AddressSchema::enabled());
    }

    public function test_an_unknown_mode_is_refused_and_a_corrupt_setting_means_off(): void
    {
        $this->seedAddressCatalog();
        DB::table('business_settings')->insert(['type' => AddressSchema::SETTING, 'value' => json_encode('everything')]);
        clearWebConfigCacheKeys();

        $this->assertSame(AddressSchema::MODE_OFF, AddressSchema::mode());
        $this->expectException(\InvalidArgumentException::class);
        AddressSchema::setMode('everything');
    }

    #[DataProvider('nationalAddresses')]
    public function test_national_address_normalisation_and_validation(?string $typed, ?string $canonical): void
    {
        $this->assertSame($canonical, NationalAddress::canonical($typed));
        $this->assertSame($canonical !== null, NationalAddress::isValid($typed));
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function nationalAddresses(): array
    {
        return [
            'plain' => ['RAGI2929', 'RAGI2929'],
            'lower case' => ['ragi2929', 'RAGI2929'],
            'spaces and hyphen' => [' RAGI 2929 ', 'RAGI2929'],
            'hyphenated' => ['RAGI-2929', 'RAGI2929'],
            'arabic-indic digits' => ['RHMA٣١٨٤', 'RHMA3184'],
            'persian digits' => ['RHMA۳۱۸۴', 'RHMA3184'],
            'full width' => ['ＲＡＧＩ２９２９', 'RAGI2929'],
            'direction marks' => ["\u{200F}RAGI2929\u{200E}", 'RAGI2929'],
            'three letters' => ['RAG2929', null],
            'five letters' => ['RAGIX2929', null],
            'three digits' => ['RAGI292', null],
            'five digits' => ['RAGI29290', null],
            'digits first' => ['2929RAGI', null],
            'arabic letters' => ['راجي2929', null],
            'trailing newline' => ["RAGI2929\n", 'RAGI2929'],
            'sql' => ["RAGI2929'; DROP TABLE x;--", null],
            'blank' => ['   ', null],
            'null' => [null, null],
        ];
    }

    public function test_the_national_address_shape_comes_from_the_configuration(): void
    {
        config(['address.national_address.letters' => 3, 'address.national_address.digits' => 5]);

        $this->assertTrue(NationalAddress::isValid('ABC12345'));
        $this->assertFalse(NationalAddress::isValid('RAGI2929'));
    }

    #[DataProvider('placeNames')]
    public function test_place_name_keys_fold_spelling_variants_without_inventing_matches(string $left, string $right, bool $same): void
    {
        $this->assertSame($same, AddressText::key($left) === AddressText::key($right), "{$left} / {$right}");
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function placeNames(): array
    {
        return [
            'article and diacritics' => ['الرِّيَاض', 'رياض', true],
            'madinat prefix' => ['مدينة الرياض', 'الرياض', true],
            'english article' => ['Al Khobar', 'Khobar', true],
            'hyphenated article' => ['Al-Khobar', 'Khobar', true],
            'sun letter article' => ['Ad Dammam', 'Dammam', true],
            'city of' => ['City of Riyadh', 'Riyadh', true],
            'hamza forms' => ['أبها', 'ابها', true],
            'taa marbuta' => ['مكة المكرمة', 'مكه المكرمه', true],
            'different cities' => ['الرياض', 'جدة', false],
            'arabic vs english stay apart' => ['الرياض', 'Riyadh', false],
            'neighbouring names' => ['Khobar', 'Khafji', false],
        ];
    }

    public function test_search_finds_cities_in_arabic_and_english_inside_a_region(): void
    {
        $ids = $this->seedAddressCatalog();
        $catalog = app(AddressCatalog::class);

        $this->assertSame([$ids['khobar']], $catalog->searchCities(regionId: $ids['region_EAS'], query: 'Al Khobar')->pluck('id')->all());
        $this->assertSame([$ids['khobar']], $catalog->searchCities(regionId: $ids['region_EAS'], query: 'الخبر')->pluck('id')->all());
        $this->assertSame([$ids['khobar']], $catalog->searchCities(regionId: $ids['region_EAS'], query: 'khob')->pluck('id')->all());
        $this->assertSame([$ids['khobar']], $catalog->searchCities(regionId: $ids['region_EAS'], query: 'Alkhobar')->pluck('id')->all(), 'an article typed without its space');
        $this->assertSame([], $catalog->searchCities(regionId: $ids['region_RUH'], query: 'الخبر')->pluck('id')->all(), 'search stays inside the chosen region');
        $this->assertCount(2, $catalog->searchCities(query: 'الهفوف'), 'a name shared across regions finds both');
    }

    public function test_search_ranks_exact_matches_first_and_ignores_inactive_cities(): void
    {
        $ids = $this->seedAddressCatalog();
        AddressCity::whereKey($ids['makkah'])->update(['is_active' => false]);
        $catalog = app(AddressCatalog::class);

        $this->assertSame($ids['riyadh'], $catalog->searchCities(query: 'الرياض')->first()->id);
        $this->assertNotContains($ids['makkah'], $catalog->searchCities(query: 'مكة')->pluck('id')->all());
        $this->assertContains($ids['makkah'], $catalog->searchCities(query: 'مكة', onlyActive: false)->pluck('id')->all());
    }

    public function test_search_finds_a_city_by_an_administrator_confirmed_alias(): void
    {
        $ids = $this->seedAddressCatalog();
        DB::table('address_city_aliases')->insert(['city_id' => $ids['riyadh'], 'alias' => 'العاصمة', 'normalized_alias' => AddressText::key('العاصمة'), 'source' => 'admin', 'created_at' => now(), 'updated_at' => now()]);

        $found = app(AddressCatalog::class)->searchCities(query: 'العاصمة');

        $this->assertSame([$ids['riyadh']], $found->pluck('id')->all());
    }

    public function test_search_treats_wildcards_and_quotes_as_plain_text(): void
    {
        $this->seedAddressCatalog();

        $this->assertCount(0, app(AddressCatalog::class)->searchCities(query: "%'; DROP TABLE address_cities; --"));
        $this->assertTrue(Schema::hasTable('address_cities'));
    }

    public function test_a_city_must_belong_to_an_active_region_to_be_accepted(): void
    {
        $ids = $this->seedAddressCatalog();
        $catalog = app(AddressCatalog::class);

        $this->assertSame($ids['jeddah'], $catalog->activeCityInRegion($ids['jeddah'], $ids['region_MAK'])->id);
        $this->assertNull($catalog->activeCityInRegion($ids['jeddah'], $ids['region_RUH']), 'a city of another region');

        AddressRegion::whereKey($ids['region_MAK'])->update(['is_active' => false]);
        $this->assertNull($catalog->activeCityInRegion($ids['jeddah'], $ids['region_MAK']), 'an inactive region');
    }

    public function test_the_import_reports_a_dry_run_then_creates_then_changes_nothing_the_second_time(): void
    {
        $importer = app(AddressImporter::class);
        $regions = $this->writeDataFile('regions', ['regions' => [
            ['ref' => '1', 'code' => 'SA-01', 'name_ar' => 'منطقة الرياض', 'name_en' => 'Riyadh', 'sort' => 1],
            ['ref' => '2', 'code' => 'SA-02', 'name_ar' => 'منطقة مكة المكرمة', 'name_en' => 'Makkah', 'sort' => 2],
        ]]);
        $cities = $this->writeDataFile('cities', ['cities' => [
            ['ref' => '3', 'region' => '1', 'ar' => 'الرياض', 'en' => 'Riyadh', 'lat' => 24.7, 'lon' => 46.73],
            ['ref' => '18', 'region' => '2', 'ar' => 'جدة', 'en' => 'Jeddah', 'lat' => 21.48, 'lon' => 39.18],
            ['ref' => '99', 'region' => '77', 'ar' => 'مجهولة', 'en' => 'Orphan'],
            ['ref' => '3', 'region' => '1', 'ar' => 'مكررة', 'en' => 'Repeated'],
        ]]);

        $dry = $importer->import($regions, $cities, apply: false);
        $this->assertSame(0, AddressCity::count());
        $this->assertSame(2, $dry['cities']['created']);
        $this->assertSame(2, $dry['cities']['skipped'], 'a region that does not exist and a repeated ref are skipped, not guessed');
        $this->assertSame(2, $dry['regions']['created']);

        $first = $importer->import($regions, $cities, apply: true);
        $this->assertSame(2, $first['cities']['created']);
        $this->assertSame(2, AddressCity::count());
        $this->assertSame('riyadh', AddressCity::where('source_ref', '3')->value('normalized_en'));

        $second = $importer->import($regions, $cities, apply: true);
        $this->assertSame(0, $second['cities']['created'] + $second['cities']['updated']);
        $this->assertSame(2, AddressCity::count());
    }

    public function test_a_later_import_keeps_administrator_choices_and_never_deletes(): void
    {
        $importer = app(AddressImporter::class);
        $regions = $this->writeDataFile('regions', ['regions' => [['ref' => '1', 'code' => 'SA-01', 'name_ar' => 'منطقة الرياض', 'name_en' => 'Riyadh', 'sort' => 1]]]);
        $cities = $this->writeDataFile('cities', ['cities' => [
            ['ref' => '3', 'region' => '1', 'ar' => 'الرياض', 'en' => 'Riyadh'],
            ['ref' => '4', 'region' => '1', 'ar' => 'الدرعية', 'en' => 'Ad Diriyah'],
        ]]);
        $importer->import($regions, $cities, apply: true);

        AddressCity::where('source_ref', '3')->update(['name_ar' => 'الرياض (العاصمة)', 'is_active' => false]);
        $newer = $this->writeDataFile('cities', ['cities' => [['ref' => '3', 'region' => '1', 'ar' => 'الرياض', 'en' => 'Riyadh', 'lat' => 24.7, 'lon' => 46.7]]]);

        $report = $importer->import($regions, $newer, apply: true);

        $this->assertSame(1, $report['cities']['not_in_file'], 'a place the file no longer lists is reported');
        $this->assertSame(2, AddressCity::count(), 'and kept');
        $kept = AddressCity::where('source_ref', '3')->first();
        $this->assertSame('الرياض (العاصمة)', $kept->name_ar);
        $this->assertFalse($kept->is_active);
        $this->assertSame(24.7, $kept->latitude);

        $importer->import($regions, $newer, apply: true, refreshNames: true);
        $this->assertSame('الرياض', AddressCity::where('source_ref', '3')->value('name_ar'));
        $this->assertFalse((bool) AddressCity::where('source_ref', '3')->value('is_active'), 'activation is never an import decision');
    }

    public function test_a_file_without_a_source_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(AddressImporter::class)->import(null, $this->writeDataFile('cities', ['cities' => []], source: ''), apply: true);
    }

    public function test_the_shipped_data_files_are_valid_and_complete(): void
    {
        $report = $this->importShippedData();

        $this->assertSame([], $report['problems']);
        $this->assertSame(57, $report['aliases']['created'] + $report['aliases']['unchanged'], 'every curated spelling is accounted for');
        $this->assertSame($report['aliases']['created'], AddressCityAlias::count(), 'spellings that fold to the same key share one alias row');
        $this->assertSame(0, AddressCityAlias::query()->whereNotIn('city_id', AddressCity::query()->select('id'))->count());
        $this->assertSame(13, AddressRegion::count());
        $this->assertSame(4581, AddressCity::count());
        $this->assertSame(0, AddressCity::query()->whereNotIn('region_id', AddressRegion::query()->select('id'))->count());
        $riyadh = AddressCity::where('name_en', 'Riyadh')->where('name_ar', 'الرياض')->get();
        $this->assertCount(1, $riyadh);
        $this->assertSame('منطقة الرياض', $riyadh->first()->region->name_ar);
        foreach (['الدمام', 'جدة', 'مكة المكرمة', 'المدينة المنورة', 'ابها', 'تبوك', 'بريدة', 'جازان', 'نجران', 'حائل'] as $major) {
            $this->assertGreaterThanOrEqual(1, AddressCity::where('name_ar', $major)->count(), $major);
        }
    }

    public function test_a_dry_run_reports_the_same_alias_counts_as_the_import_that_follows_it(): void
    {
        $dry = app(AddressImporter::class)->import(config('address.seed.regions'), config('address.seed.cities'), apply: false, aliasesPath: config('address.seed.aliases'));
        $applied = $this->importShippedData();

        $this->assertSame(0, AddressCityAlias::count() - $applied['aliases']['created'], 'the dry run itself wrote nothing');
        $this->assertSame($applied['aliases'], $dry['aliases'], 'spellings that share a key are counted once by the preview, as the import does');
        $this->assertGreaterThan(0, $dry['aliases']['unchanged']);
    }

    /** @return array<string, mixed> */
    private function importShippedData(): array
    {
        return app(AddressImporter::class)->import(config('address.seed.regions'), config('address.seed.cities'), apply: true, aliasesPath: config('address.seed.aliases'));
    }

    #[DataProvider('commonSpellings')]
    public function test_curated_aliases_settle_the_common_spellings_against_the_shipped_data(string $typed, ?string $expectedEnglish, ?string $expectedRegion, string $status): void
    {
        $this->importShippedData();

        $match = app(LegacyCityMatcher::class)->match($typed);

        $this->assertSame($status, $match->status->value, $typed);
        $this->assertSame($expectedEnglish, $match->city?->name_en, $typed);
        $this->assertSame($expectedRegion, $match->city?->region->name_en, $typed);
    }

    /** @return array<string, array{0: string, 1: ?string, 2: ?string, 3: string}> */
    public static function commonSpellings(): array
    {
        return [
            'riyadh arabic' => ['الرياض', 'Riyadh', 'Riyadh', 'matched'],
            'riyadh with madinat' => ['مدينة الرياض', 'Riyadh', 'Riyadh', 'matched'],
            'jeddah arabic (a village shares the name)' => ['جدة', 'Jeddah', 'Makkah', 'matched'],
            'jeddah as written by a courier' => ['Jedda', 'Jeddah', 'Makkah', 'matched'],
            'mecca' => ['Mecca', 'Makkah', 'Makkah', 'matched'],
            'makkah short arabic' => ['مكة', 'Makkah', 'Makkah', 'matched'],
            'medina' => ['Medina', 'Madinah', 'Madinah', 'matched'],
            'hofuf arabic (a village shares the name)' => ['الهفوف', 'Al Hafuf', 'Eastern Province', 'matched'],
            'khobar variant' => ['Al Khubar', 'Al Khobar', 'Eastern Province', 'matched'],
            'khamis mushait' => ['Khamis Mushait', 'Khamis Mushayt', 'Asir', 'matched'],
            'al majmaah' => ['المجمعة', "Al Majma'ah", 'Riyadh', 'matched'],
            'al baha' => ['Al Baha', 'Bahah', 'Al Bahah', 'matched'],
            'dhahran' => ['الظهران', 'Dhahran', 'Eastern Province', 'matched'],
            'a spelling nobody has confirmed' => ['Jeddaah', null, null, 'none'],
        ];
    }

    public function test_the_import_never_overrides_an_alias_an_administrator_set(): void
    {
        $importer = app(AddressImporter::class);
        $importer->import(config('address.seed.regions'), config('address.seed.cities'), apply: true);
        $jeddah = AddressCity::where('source_ref', '18')->value('id');
        $makkah = AddressCity::where('source_ref', '6')->value('id');
        AddressCityAlias::create(['city_id' => $makkah, 'alias' => 'Jiddah', 'normalized_alias' => AddressText::key('Jiddah'), 'source' => 'admin']);

        $report = $importer->import(null, null, apply: true, aliasesPath: config('address.seed.aliases'));

        $this->assertSame(1, $report['aliases']['skipped'], 'the clash is reported');
        $this->assertSame($makkah, AddressCityAlias::where('normalized_alias', AddressText::key('Jiddah'))->value('city_id'), 'and the administrator keeps it');
        $this->assertSame($jeddah, AddressCityAlias::where('normalized_alias', AddressText::key('Jedda'))->value('city_id'));
    }

    public function test_an_alias_dry_run_counts_without_writing_and_skips_unknown_cities(): void
    {
        $regions = $this->writeDataFile('regions', ['regions' => [['ref' => '1', 'code' => 'SA-01', 'name_ar' => 'منطقة الرياض', 'name_en' => 'Riyadh', 'sort' => 1]]]);
        $cities = $this->writeDataFile('cities', ['cities' => [['ref' => '3', 'region' => '1', 'ar' => 'الرياض', 'en' => 'Riyadh']]], source: 'src');
        $aliases = tempnam(sys_get_temp_dir(), 'addr_') . '.json';
        file_put_contents($aliases, json_encode(['_meta' => ['source' => 'curated', 'city_source' => 'src'], 'aliases' => [['alias' => 'Riyad', 'city_ref' => '3'], ['alias' => 'Nowhere', 'city_ref' => '999']]]));

        $dry = app(AddressImporter::class)->import($regions, $cities, apply: false, aliasesPath: $aliases);

        $this->assertSame(1, $dry['aliases']['created']);
        $this->assertSame(1, $dry['aliases']['skipped']);
        $this->assertSame(0, AddressCityAlias::count());
    }

    /** @param array<string, mixed> $body */
    private function writeDataFile(string $kind, array $body, string $source = 'test_source'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'addr_') . '.json';
        file_put_contents($path, json_encode(['_meta' => ['source' => $source, 'snapshot_date' => '2026-01-01']] + $body, JSON_UNESCAPED_UNICODE));

        return $path;
    }
}
