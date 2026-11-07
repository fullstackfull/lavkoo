<?php

namespace Tests\Feature\Address;

use App\Models\AddressCity;
use App\Models\AddressCityAlias;
use App\Models\AddressRegion;
use App\Services\Address\AddressSchema;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;

/**
 * `address:fetch-spl` reads the regions and cities from Saudi Post's Lookups API with the owner's key and hands them to the importer.
 * The service is faked here: no test reaches Saudi Post, and every request the command makes is looked at.
 */
class AddressFetchSplCommandTest extends AdminAddressTestCase
{
    private const KEY = 'test-key-0123456789abcdef';

    private const REGIONS = [
        1 => ['ar' => 'الرياض', 'en' => 'Riyadh'], 2 => ['ar' => 'مكة المكرمة', 'en' => 'Makkah'], 3 => ['ar' => 'المدينة المنورة', 'en' => 'Madinah'],
        4 => ['ar' => 'القصيم', 'en' => 'Qassim'], 5 => ['ar' => 'المنطقة الشرقية', 'en' => 'Eastern Province'], 6 => ['ar' => 'عسير', 'en' => 'Asir'],
        7 => ['ar' => 'تبوك', 'en' => 'Tabuk'], 8 => ['ar' => 'حائل', 'en' => 'Hail'], 9 => ['ar' => 'الحدود الشمالية', 'en' => 'Northern Borders'],
        10 => ['ar' => 'جازان', 'en' => 'Jazan'], 11 => ['ar' => 'نجران', 'en' => 'Najran'], 12 => ['ar' => 'الباحة', 'en' => 'Al Bahah'], 13 => ['ar' => 'الجوف', 'en' => 'Al Jawf'],
    ];

    private string $directory;

    private string $printed = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/spl-' . bin2hex(random_bytes(4));
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        Sleep::fake(false);

        parent::tearDown();
    }

    public function test_a_full_read_makes_28_get_calls_spaced_out_and_writes_the_three_files(): void
    {
        $this->service();

        $this->assertSame(0, $this->execute());

        $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0]);
        $this->assertCount(28, $requests);
        $this->assertTrue($requests->every(fn (Request $request): bool => $request->method() === 'GET'));
        $this->assertTrue($requests->every(fn (Request $request): bool => str_starts_with($request->url(), 'https://apina.address.gov.sa/NationalAddress/v3.1/lookup/')));
        $this->assertTrue($requests->every(fn (Request $request): bool => $this->queryOf($request)['api_key'] === self::KEY && $this->queryOf($request)['encode'] === 'utf8' && $this->queryOf($request)['format'] === 'JSON'));
        Sleep::assertSleptTimes(27);
        Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalSeconds === 13, 27);

        $regions = json_decode((string) file_get_contents("{$this->directory}/regions.json"), true);
        $cities = json_decode((string) file_get_contents("{$this->directory}/cities.json"), true);
        $aliases = json_decode((string) file_get_contents("{$this->directory}/aliases.json"), true);
        $this->assertSame('spl_lookups_v3_1', $regions['_meta']['source']);
        $this->assertCount(13, $regions['regions']);
        $this->assertSame(['ref' => '5', 'code' => 'SA-04', 'name_ar' => 'المنطقة الشرقية', 'name_en' => 'Eastern Province', 'sort' => 5], $regions['regions'][4]);
        $this->assertCount(1300, $cities['cities']);
        $this->assertSame('spl_lookups_v3_1', $aliases['_meta']['city_source']);
        foreach (['regions', 'cities', 'aliases'] as $kind) {
            $this->assertSame('0640', substr(sprintf('%o', fileperms("{$this->directory}/{$kind}.json")), -4));
            $this->assertStringNotContainsString(self::KEY, (string) file_get_contents("{$this->directory}/{$kind}.json"));
        }
    }

    public function test_without_apply_nothing_reaches_the_lists_and_the_import_is_shown_as_a_dry_run(): void
    {
        $this->service();

        $this->assertSame(0, $this->execute());
        $this->assertStringContainsString('Dry run: nothing was written', $this->consoleOutput());

        $this->assertSame(0, AddressRegion::count());
        $this->assertSame(0, AddressCity::count());
    }

    public function test_apply_imports_every_region_and_city_with_ranks_and_only_the_spellings_of_cities_the_service_confirms(): void
    {
        $this->service();

        $this->assertSame(0, $this->execute(['--apply' => true]));
        $this->assertStringContainsString('Applied.', $this->consoleOutput());

        $this->assertSame(13, AddressRegion::count());
        $this->assertSame(1300, AddressCity::count());
        $this->assertSame('SA-14', AddressRegion::where('name_en', 'Asir')->value('code'));
        $this->assertSame(1000, (int) AddressCity::where('name_en', 'Riyadh')->value('sort_order'), 'a city the curated spellings are about is offered first');
        $this->assertSame(1, (int) AddressCity::where('name_ar', 'الواحة')->value('sort_order'), 'the seat of a governorate is a city proper');
        $this->assertSame(0, (int) AddressCity::where('name_en', 'Test Village 4002')->value('sort_order'));
        $this->assertSame('قرية الاختبار 4007', AddressCity::where('name_ar', 'قرية الاختبار 4007')->value('name_en'), 'a blank English name is filled with the Arabic one');
        $this->assertGreaterThan(0, AddressCityAlias::count());
        $this->assertSame(0, AddressCityAlias::whereHas('city', fn ($query) => $query->where('name_en', 'Springfield'))->count(), 'a spelling whose id names another city in the service is left out');
    }

    public function test_no_key_means_no_request(): void
    {
        $this->service();
        putenv('SPL_API_KEY');

        $this->assertSame(1, $this->execute(withKey: false));

        Http::assertNothingSent();
    }

    public function test_the_key_can_come_from_the_environment_and_is_never_printed(): void
    {
        $this->service();
        putenv('SPL_API_KEY=' . self::KEY);

        try {
            $this->assertSame(0, $this->execute(withKey: false));
        } finally {
            putenv('SPL_API_KEY');
        }

        $this->assertStringNotContainsString(self::KEY, $this->consoleOutput());
        Http::assertSentCount(28);
    }

    public function test_apply_before_the_migrations_asks_nothing_of_saudi_post(): void
    {
        $this->service();
        Schema::drop('address_city_aliases');
        Schema::drop('address_cities');
        Schema::drop('address_regions');
        AddressSchema::flush();

        $this->assertSame(1, $this->execute(['--apply' => true]));
        $this->assertStringContainsString('migrations have not been run', $this->consoleOutput());

        Http::assertNothingSent();
    }

    public function test_before_the_migrations_the_files_are_still_written_for_later(): void
    {
        $this->service();
        Schema::drop('address_city_aliases');
        Schema::drop('address_cities');
        Schema::drop('address_regions');
        AddressSchema::flush();

        $this->assertSame(0, $this->execute());

        $this->assertFileExists("{$this->directory}/cities.json");
    }

    public function test_a_refused_key_stops_the_read_and_writes_nothing_and_the_message_holds_no_key(): void
    {
        Http::fake(fn () => Http::response('', 401));
        Http::preventStrayRequests();

        $this->assertSame(1, $this->execute());
        $this->assertStringContainsString('401', $this->consoleOutput());
        $this->assertStringContainsString('API key was refused', $this->consoleOutput());

        Http::assertSentCount(1);
        $this->assertStringNotContainsString(self::KEY, $this->consoleOutput());
        $this->assertSame([], glob("{$this->directory}/*") ?: []);
    }

    public function test_a_failure_in_the_middle_writes_nothing(): void
    {
        $answered = 0;
        Http::fake(function (Request $request) use (&$answered) {
            return ++$answered === 5 ? Http::response('', 403) : Http::response($this->answer($request));
        });

        $this->assertSame(1, $this->execute());
        $this->assertStringContainsString('403', $this->consoleOutput());

        $this->assertSame([], glob("{$this->directory}/*") ?: []);
    }

    public function test_a_failure_reported_inside_a_200_answer_is_an_error_and_its_text_is_cleaned(): void
    {
        Http::fake(fn () => Http::response(['success' => false, 'statusdescription' => 'Invalid key ' . self::KEY . ' for api_key=' . self::KEY, 'Regions' => []]));

        $this->assertSame(1, $this->execute());
        $this->assertStringContainsString('Saudi Post reported a failure', $this->consoleOutput());

        $this->assertStringNotContainsString(self::KEY, $this->consoleOutput());
    }

    public function test_a_connection_error_is_reported_without_the_address_that_holds_the_key(): void
    {
        Http::fake(function (Request $request): never {
            throw new ConnectionException('cURL error 28: Operation timed out for ' . $request->url());
        });

        $this->assertSame(1, $this->execute());
        $this->assertStringContainsString('could not be made', $this->consoleOutput());

        $this->assertStringNotContainsString(self::KEY, $this->consoleOutput());
    }

    public function test_an_answer_in_windows_1256_is_read(): void
    {
        Http::fake(function (Request $request) {
            $body = json_encode($this->answer($request), JSON_UNESCAPED_UNICODE);

            return Http::response((string) iconv('UTF-8', 'Windows-1256//IGNORE', $body), 200);
        });

        $this->assertSame(0, $this->execute());

        $cities = json_decode((string) file_get_contents("{$this->directory}/cities.json"), true);
        $this->assertContains('الرياض', array_column($cities['cities'], 'ar'));
    }

    public function test_a_region_that_is_not_the_one_its_id_stands_for_stops_everything(): void
    {
        $this->service(regions: fn (array $regions): array => array_replace($regions, [5 => ['ar' => 'تبوك', 'en' => 'Tabuk']]));

        $this->assertSame(1, $this->execute());
        $this->assertStringContainsString('not the region this importer takes it for', $this->consoleOutput());

        $this->assertSame([], glob("{$this->directory}/*") ?: []);
    }

    public function test_a_city_listed_under_two_regions_stops_everything(): void
    {
        $this->service(cities: fn (int $regionId, array $cities): array => $regionId === 2 ? [...$cities, ['id' => 3002, 'ar' => 'مكرر', 'en' => 'Duplicate']] : $cities);

        $this->assertSame(1, $this->execute());
        $this->assertStringContainsString('ids of Saudi Post are not unique', $this->consoleOutput());

        $this->assertSame([], glob("{$this->directory}/*") ?: []);
    }

    public function test_a_list_far_too_short_for_the_country_is_not_taken(): void
    {
        $this->service(cities: fn (int $regionId, array $cities): array => array_slice($cities, 0, 5));

        $this->assertSame(1, $this->execute(['--apply' => true]));
        $this->assertStringContainsString('only 65 places', $this->consoleOutput());

        $this->assertSame(0, AddressCity::count());
        $this->assertSame([], glob("{$this->directory}/*") ?: []);
    }

    public function test_a_region_the_service_does_not_list_stops_everything(): void
    {
        $this->service(regions: fn (array $regions): array => array_slice($regions, 0, 12, true));

        $this->assertSame(1, $this->execute());
        $this->assertStringContainsString('did not list region', $this->consoleOutput());
    }

    /** @param array<string, mixed> $options */
    private function execute(array $options = [], bool $withKey = true): int
    {
        $status = Artisan::call('address:fetch-spl', ($withKey ? ['--key' => self::KEY] : []) + ['--output' => $this->directory] + $options);
        $this->printed = Artisan::output();

        return $status;
    }

    /** What the last command printed (the framework hands it over once, so it is kept). */
    private function consoleOutput(): string
    {
        return $this->printed;
    }

    /**
     * @param (Closure(array<int, array{ar: string, en: string}>): array<int, array{ar: string, en: string}>)|null $regions
     * @param (Closure(int, list<array<string, mixed>>): list<array<string, mixed>>)|null $cities
     */
    private function service(?Closure $regions = null, ?Closure $cities = null): void
    {
        Http::fake(fn (Request $request) => Http::response($this->answer($request, $regions, $cities)));
        Http::preventStrayRequests();
    }

    /** @return array<string, mixed> what the service answers to a request */
    private function answer(Request $request, ?Closure $regions = null, ?Closure $cities = null): array
    {
        $query = $this->queryOf($request);
        $language = $query['language'] === 'A' ? 'ar' : 'en';
        $regionList = $regions === null ? self::REGIONS : $regions(self::REGIONS);

        if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/regions')) {
            return ['LookUp' => null, 'Regions' => array_map(fn (int $id, array $region): array => ['Id' => (string) $id, 'Name' => $region[$language]], array_keys($regionList), $regionList), 'success' => true, 'statusdescription' => ''];
        }

        $regionId = (int) $query['regionid'];
        $list = $this->citiesOf($regionId);
        $list = $cities === null ? $list : $cities($regionId, $list);

        return ['LookUp' => null, 'Regions' => [], 'Cities' => array_map(fn (array $city): array => [
            'Id' => (string) $city['id'],
            'Name' => $city[$language],
            'GovernorateName' => $city['governorate_' . $language] ?? null,
        ], $list), 'success' => true, 'statusdescription' => ''];
    }

    /** @return list<array<string, mixed>> a hundred places a region: a few real cities where the curated spellings point, villages for the rest */
    private function citiesOf(int $regionId): array
    {
        $special = match ($regionId) {
            1 => [['id' => 3, 'ar' => 'الرياض', 'en' => 'Riyadh', 'governorate_ar' => 'الرياض', 'governorate_en' => 'Riyadh']],
            2 => [['id' => 18, 'ar' => 'جدة', 'en' => 'Jeddah'], ['id' => 6, 'ar' => 'مكة المكرمة', 'en' => 'Makkah']],
            3 => [['id' => 14, 'ar' => 'المدينة المنورة', 'en' => 'Madinah']],
            6 => [['id' => 10, 'ar' => 'مكان آخر', 'en' => 'Springfield']],
            5 => [['id' => 5001, 'ar' => 'الواحة', 'en' => 'The Oasis', 'governorate_ar' => 'الواحة', 'governorate_en' => 'The Oasis']],
            default => [],
        };

        $villages = [];
        for ($index = 2; count($special) + count($villages) < 100; $index++) {
            $id = $regionId * 1000 + $index;
            $villages[] = ['id' => $id, 'ar' => "قرية الاختبار {$id}", 'en' => $regionId === 4 && $index === 7 ? '' : "Test Village {$id}"];
        }

        return [...$special, ...$villages];
    }

    /** @return array<string, string> */
    private function queryOf(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }
}
