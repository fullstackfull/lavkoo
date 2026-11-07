<?php

namespace Tests\Feature\Address;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderCity;
use App\Models\ProviderCityMapping;
use App\Models\ProviderCitySyncRun;
use App\Models\ProviderSyncState;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The refresh of Torod's city list: read completely or said to be partial, never deleting, never calling a short list a success.
 * The ten-city reading that the first version stored and reported as "completed" is the case every test here guards against.
 */
class TorodCitySyncTest extends TorodAddressTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ProviderCity::query()->delete();
    }

    /** @return array<string, mixed> */
    private function sync(): array
    {
        return $this->adapter()->syncCities();
    }

    private function lastRun(): ProviderCitySyncRun
    {
        return ProviderCitySyncRun::query()->orderByDesc('id')->firstOrFail();
    }

    private function state(): ProviderSyncState
    {
        return ProviderSyncState::where(['provider' => 'torod_point', 'capability' => 'cities'])->firstOrFail();
    }

    /** @return array<string, array<string, mixed>> the queries of every city-list request sent, in order */
    private function queries(): array
    {
        return collect(Http::recorded())->map(static function (array $pair): array {
            parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

            return $query;
        })->values()->all();
    }

    // ---- complete readings ------------------------------------------------------------------------------------------------

    public function test_a_list_long_enough_to_be_a_national_one_that_states_its_total_and_reaches_it_is_full_in_one_request_and_is_a_success(): void
    {
        config(['fulfillment.providers.torod_point.cities.complete_floor' => 20]);
        $this->fakeLastmileList($this->lastmileDirectory(25), ['total' => true]);

        $result = $this->sync();

        $this->assertTrue($result['ok']);
        $this->assertSame(25, $result['count']);
        $this->assertSame('full', $result['verdict']);
        $this->assertSame('envelope_total', $result['evidence']);
        $this->assertTrue($this->lastRun()->completenessProven(), 'the provider\'s own total was reached');
        Http::assertSentCount(1);
        $run = $this->lastRun();
        $this->assertSame(['full', 1, 25, 25, 25, 4], [$run->verdict, $run->pages, $run->fetched, $run->distinct_ids, $run->expected_total, $run->regions_seen]);
        $this->assertNull($run->reason);
        $this->assertNotNull($this->state()->last_success_at);
        $this->assertNull($this->state()->last_error);
        $this->assertSame('full', $this->state()->stats['verdict']);
    }

    public function test_a_short_list_is_full_only_when_paging_confirms_it_to_the_end(): void
    {
        $this->fakeLastmileList($this->lastmileDirectory(10), ['total' => true]);

        $result = $this->sync();

        $this->assertSame(['full', 'empty_page'], [$result['verdict'], $result['evidence']], 'its own total (ten of ten) is not what proves it');
        $this->assertTrue($this->lastRun()->completenessProven());
        Http::assertSentCount(3);
    }

    /** A truncated answer states its own total, its own last page and its own page count truthfully, or leaves the count at zero. */
    #[DataProvider('envelopesOfATruncatedList')]
    public function test_what_a_short_list_says_about_itself_proves_nothing_when_paging_does_not_confirm_it(array $envelope): void
    {
        $this->torodCity('36189', 'الرياض', 'Riyadh', '1204');
        $this->torodCity('230194', 'الرياض', 'Riyadh', '1265');
        Http::fake([self::LASTMILE_CITIES => Http::response(['data' => $this->lastmileDirectory(10)] + $envelope)]);

        $result = $this->sync();

        $this->assertSame('partial', $result['verdict']);
        $this->assertSame('10 returned, paging not honoured', $result['reason']);
        $this->assertFalse($this->lastRun()->completenessProven());
        $this->assertSame(0, $result['approved'], 'a city that looks unique in a truncated list is not approved');
        $this->assertSame(0, ProviderCity::whereNotNull('missing_since')->count(), 'and the cities that are not in it are not marked missing');
        $this->assertNull($this->state()->last_success_at);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function envelopesOfATruncatedList(): array
    {
        return [
            'the total it returned' => [['totalElements' => 10]],
            'a total left at zero' => [['totalElements' => 0]],
            'flagged as the last page' => [['last' => true]],
            'one page' => [['totalPages' => 1]],
        ];
    }

    public function test_what_the_provider_says_about_each_city_is_promoted_out_of_the_raw_record(): void
    {
        $this->fakeLastmileList($this->lastmileDirectory(2), ['total' => true]);

        $this->sync();

        $first = ProviderCity::where('external_id', '1000')->firstOrFail();
        $this->assertSame(['City 1000', 'مدينة 1000', '1300', 'Region 1300', 'منطقة 1300', 'C0', true], [
            $first->name_en, $first->name_ar, $first->region_external_id, $first->region_name_en, $first->region_name_ar, $first->prefix, $first->is_selected,
        ]);
        $this->assertFalse(ProviderCity::where('external_id', '1001')->firstOrFail()->is_selected);
        $this->assertNotNull($first->last_seen_at);
        $this->assertNull($first->missing_since);
        $this->assertSame('City 1000', $first->raw['englishName'], 'the whole record is still kept');
        $this->assertSame('city 1000', $first->normalized_en);
    }

    public function test_ten_cities_and_a_larger_total_are_walked_page_by_page_until_the_total_is_reached(): void
    {
        $this->fakeLastmileList($this->lastmileDirectory(25), ['first' => 10, 'total' => true]);

        $result = $this->sync();

        $this->assertSame('full', $result['verdict']);
        $this->assertSame(25, ProviderCity::count());
        $this->assertSame([
            ['returnAll' => 'true'],
            ['returnAll' => 'true', 'page' => '1', 'pageSize' => '100'],
            ['returnAll' => 'true', 'page' => '2', 'pageSize' => '100'],
        ], $this->queries(), 'the first request is as before; the walk then asks for page and pageSize from the first page');
    }

    public function test_a_server_that_ignores_the_page_size_is_walked_with_its_own_until_a_page_comes_back_empty(): void
    {
        $this->fakeLastmileList($this->lastmileDirectory(25), ['first' => 10, 'size' => 10]);

        $result = $this->sync();

        $this->assertSame('full', $result['verdict']);
        $this->assertSame('empty_page', $result['evidence']);
        $this->assertSame(25, ProviderCity::count());
        Http::assertSentCount(5);
        $this->assertNotNull($this->state()->last_success_at);
    }

    public function test_a_list_that_gives_no_total_is_full_only_at_the_floor_and_a_shorter_one_is_walked_first(): void
    {
        config(['fulfillment.providers.torod_point.cities.complete_floor' => 20]);
        $this->torodCity('36189', 'الرياض', 'Riyadh', '1204');
        $this->fakeLastmileList($this->lastmileDirectory(25), ['pages' => false]);

        $result = $this->sync();

        $this->assertSame(['full', 'distinct_floor'], [$result['verdict'], $result['evidence']]);
        $this->assertSame('distinct_floor', $this->lastRun()->request['evidence']);
        $this->assertFalse($this->lastRun()->completenessProven(), 'long enough to look complete is not proof, so nothing is approved from it automatically');
        $this->assertSame(0, ProviderCity::whereNotNull('missing_since')->count(), 'and nothing is marked missing from it: a provider that caps its answer would put every city beyond the cap out of reach');
        Http::assertSentCount(1);

        config(['fulfillment.providers.torod_point.cities.complete_floor' => 30]);
        $result = $this->sync();

        $this->assertSame('partial', $result['verdict']);
        $this->assertSame('25 returned, paging not honoured', $result['reason']);
        Http::assertSentCount(4);
    }

    public function test_a_response_that_flags_itself_as_the_last_page_ends_the_reading_once_it_is_long_enough_to_be_a_national_list(): void
    {
        config(['fulfillment.providers.torod_point.cities.complete_floor' => 3]);
        Http::fake([self::LASTMILE_CITIES => Http::response(['content' => $this->lastmileDirectory(3), 'last' => true])]);

        $result = $this->sync();

        $this->assertSame('full', $result['verdict']);
        $this->assertSame('envelope_last_page', $result['evidence']);
        Http::assertSentCount(1);
    }

    // ---- partial readings ---------------------------------------------------------------------------------------------------------

    public function test_ten_cities_from_a_server_that_ignores_paging_is_partial_and_never_a_success(): void
    {
        $this->fakeLastmileList($this->lastmileDirectory(25), ['first' => 10, 'pages' => false]);

        $result = $this->sync();

        $this->assertTrue($result['ok'], 'the cities that were read are kept');
        $this->assertSame('partial', $result['verdict']);
        $this->assertSame('10 returned, paging not honoured', $result['reason']);
        $this->assertSame(10, ProviderCity::count());
        $run = $this->lastRun();
        $this->assertSame(['partial', 10, '10 returned, paging not honoured'], [$run->verdict, $run->distinct_ids, $run->reason]);
        $state = $this->state();
        $this->assertNull($state->last_success_at, 'a partial reading is not a success');
        $this->assertNotNull($state->last_failure_at);
        $this->assertSame('partial: 10 returned, paging not honoured', $state->last_error);
        $this->assertSame('partial', $state->stats['verdict']);
    }

    public function test_a_page_that_repeats_the_one_before_stops_the_walk_and_proves_nothing(): void
    {
        $directory = $this->lastmileDirectory(30);
        Http::fake([self::LASTMILE_CITIES => function (Request $request) use ($directory) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $page = isset($query['page']) ? min((int) $query['page'], 2) : null;

            return Http::response(['data' => $page === null ? array_slice($directory, 0, 10) : array_slice($directory, ($page - 1) * 10, 10)]);
        }]);

        $result = $this->sync();

        $this->assertSame('partial', $result['verdict']);
        $this->assertSame('20 returned, paging not honoured', $result['reason']);
        $this->assertSame(20, ProviderCity::count());
        Http::assertSentCount(4);
    }

    public function test_pages_that_do_not_contain_the_first_response_cannot_confirm_it_is_complete(): void
    {
        $directory = $this->lastmileDirectory(25);
        Http::fake([self::LASTMILE_CITIES => function (Request $request) use ($directory) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response(['data' => isset($query['page']) ? array_slice($directory, (int) $query['page'] * 10, 10) : array_slice($directory, 0, 10)]);
        }]);

        $result = $this->sync();

        $this->assertSame('partial', $result['verdict'], 'a server that counts pages from zero would leave a gap the walk cannot see');
        $this->assertStringContainsString('do not contain the first response', $result['reason']);
        $this->assertSame(25, ProviderCity::count(), 'what was read is kept');
        $this->assertNull($this->state()->last_success_at);
    }

    public function test_a_failure_in_the_middle_keeps_what_was_read_and_is_never_a_success(): void
    {
        $this->torodCity('36189', 'الرياض', 'Riyadh', '1204');
        $this->fakeLastmileList($this->lastmileDirectory(35), ['first' => 10, 'size' => 10, 'fail_page' => 3]);

        $result = $this->sync();

        $this->assertSame('partial', $result['verdict']);
        $this->assertStringContainsString('page 3 failed', $result['reason']);
        $this->assertSame(21, ProviderCity::count(), 'the twenty read are added to the one that was there');
        $this->assertNotNull(ProviderCity::where('external_id', '36189')->first(), 'nothing was removed');
        $this->assertNull($this->state()->last_success_at);
        $this->assertSame('partial', $this->lastRun()->verdict);
    }

    public function test_an_empty_list_changes_nothing_and_is_partial(): void
    {
        $this->torodCity('36189', 'الرياض', 'Riyadh', '1204');
        $this->torodCity('41126', 'جدة', 'Jeddah', '1336');
        Http::fake([self::LASTMILE_CITIES => Http::response(['data' => []])]);

        $result = $this->sync();

        $this->assertSame('partial', $result['verdict']);
        $this->assertSame('the provider returned an empty list', $result['reason']);
        $this->assertSame(2, ProviderCity::count());
        $this->assertSame(0, ProviderCity::whereNotNull('missing_since')->count(), 'an empty answer proves nothing about which cities are gone');
        $this->assertNull($this->state()->last_success_at);
    }

    public function test_the_page_ceiling_stops_a_directory_that_never_ends(): void
    {
        config(['fulfillment.providers.torod_point.cities.max_pages' => 3]);
        $this->fakeLastmileList($this->lastmileDirectory(500), ['first' => 10, 'size' => 10]);

        $result = $this->sync();

        $this->assertSame('partial', $result['verdict']);
        $this->assertStringContainsString('3-page ceiling', $result['reason']);
        $this->assertSame(30, ProviderCity::count());
        Http::assertSentCount(4);
    }

    public function test_the_time_budget_ends_the_walk_as_partial(): void
    {
        config(['fulfillment.providers.torod_point.cities.max_seconds' => 0]);
        $this->fakeLastmileList($this->lastmileDirectory(25), ['first' => 10, 'total' => true]);

        $result = $this->sync();

        $this->assertSame('partial', $result['verdict']);
        $this->assertStringContainsString('time budget', $result['reason']);
        Http::assertSentCount(1);
    }

    public function test_a_total_that_is_not_reached_is_partial_with_the_count_in_the_reason(): void
    {
        $directory = $this->lastmileDirectory(10);
        Http::fake([self::LASTMILE_CITIES => Http::response(['data' => $directory, 'totalElements' => 3000])]);

        $result = $this->sync();

        $this->assertSame('partial', $result['verdict']);
        $this->assertSame('10 of 3000 returned', $result['reason']);
        $this->assertSame(3000, $this->lastRun()->expected_total);
        $this->assertNull($this->state()->last_success_at);
        $this->assertSame(10, ProviderCity::count());
    }

    public function test_a_partial_reading_deletes_nothing_that_was_stored_before(): void
    {
        $this->torodCity('36189', 'الرياض', 'Riyadh', '1204');
        $this->torodCity('230194', 'الرياض', 'Riyadh', '1265');
        Http::fake([self::LASTMILE_CITIES => Http::response(['data' => $this->lastmileDirectory(10), 'totalElements' => 3000])]);

        $this->sync();

        $this->assertSame(12, ProviderCity::count());
        $this->assertSame(0, ProviderCity::whereNotNull('missing_since')->count());
        $this->assertSame(['36189', '230194'], ProviderCity::whereIn('external_id', ['36189', '230194'])->pluck('external_id')->sort()->values()->all());
    }

    // ---- failed readings ---------------------------------------------------------------------------------------------------------

    public function test_a_failing_first_request_is_a_failed_run_and_changes_nothing(): void
    {
        $this->torodCity('36189', 'الرياض', 'Riyadh', '1204');
        Http::fake([self::LASTMILE_CITIES => Http::response(['message' => 'Invalid company'], 403)]);

        $result = $this->sync();

        $this->assertFalse($result['ok']);
        $this->assertSame('failed', $result['verdict']);
        $this->assertStringContainsString('Invalid company', $result['error']);
        $this->assertSame('failed', $this->lastRun()->verdict);
        $this->assertSame(1, ProviderCity::count());
        $this->assertNotNull($this->state()->last_failure_at);
        $this->assertNull($this->state()->last_success_at);
    }

    public function test_a_response_that_holds_no_list_is_a_failed_run(): void
    {
        Http::fake([self::LASTMILE_CITIES => Http::response(['cities' => [['id' => 1]]])]);

        $result = $this->sync();

        $this->assertFalse($result['ok']);
        $this->assertSame('The city list response contains no list.', $result['error']);
    }

    // ---- never deleting -------------------------------------------------------------------------------------------------------------

    public function test_a_complete_reading_marks_the_cities_it_no_longer_lists_and_a_partial_one_marks_none(): void
    {
        $this->fakeLastmileList($this->lastmileDirectory(4), ['total' => true]);
        $this->sync();
        $this->assertSame(0, ProviderCity::whereNotNull('missing_since')->count());

        $this->fakeLastmileList($this->lastmileDirectory(3), ['total' => true]);
        $this->assertSame('full', $this->sync()['verdict']);
        $this->assertSame(['1003'], ProviderCity::whereNotNull('missing_since')->pluck('external_id')->all(), 'marked, not deleted');
        $this->assertSame(4, ProviderCity::count());

        $this->fakeLastmileList($this->lastmileDirectory(2), ['first' => 2, 'pages' => false]);
        $this->assertSame('partial', $this->sync()['verdict']);
        $this->assertSame(['1003'], ProviderCity::whereNotNull('missing_since')->pluck('external_id')->all(), 'a partial reading marks nothing new');

        $this->fakeLastmileList($this->lastmileDirectory(4), ['total' => true]);
        $this->sync();
        $this->assertSame(0, ProviderCity::whereNotNull('missing_since')->count(), 'a city that is listed again is no longer missing');
    }

    public function test_a_mapping_to_a_city_that_vanished_is_kept_not_removed(): void
    {
        $this->fakeLastmileList($this->lastmileDirectory(4), ['total' => true]);
        $this->sync();
        DB::table('address_cities')->insert(['id' => 900, 'region_id' => $this->ids['region_RUH'], 'name_ar' => 'مدينة 1003', 'name_en' => 'City 1003', 'normalized_ar' => 'x', 'normalized_en' => 'y', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        app(\App\Services\Fulfillment\ProviderCityMappingService::class)->approve(provider: 'torod_point', cityId: 900, externalCityId: '1003');

        $this->fakeLastmileList($this->lastmileDirectory(3), ['total' => true]);
        $this->sync();

        $this->assertSame('approved', ProviderCityMapping::where('address_city_id', 900)->first()->status->value);
        $this->assertNotNull(ProviderCity::where('external_id', '1003')->first()->missing_since);
    }

    // ---- what is asked ---------------------------------------------------------------------------------------------------------------

    public function test_the_sync_sends_the_company_header_to_the_documented_endpoint_and_nothing_else(): void
    {
        $this->fakeLastmileList($this->lastmileDirectory(25), ['first' => 10, 'total' => true]);

        $this->sync();

        foreach (Http::recorded() as [$request]) {
            $this->assertSame('GET', $request->method());
            $this->assertStringStartsWith(self::V2 . '/addresses/cities', $request->url());
            $this->assertSame('714', $request->header('company-id')[0]);
        }
    }

    // ---- the mappings that follow a reading ----------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> Torod's records for the directory the fixtures describe */
    private function fixtureDirectory(): array
    {
        $records = [];
        foreach ($this->fixtureRows() as $row) {
            $records[] = [
                'id' => (int) $row[0], 'name' => $row[2], 'arabicName' => $row[1], 'englishName' => $row[2], 'regionId' => (int) $row[3],
                'regionName' => $row[4], 'arabicRegionName' => $row[5], 'isSelected' => $row[6], 'prefix' => $row[7],
            ];
        }

        return $records;
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string, 4: ?string, 5: ?string, 6: ?bool, 7: ?string}> */
    private function fixtureRows(): array
    {
        return [
            ['36189', 'الرياض', 'Riyadh', '1204', 'Riyadh', 'الرياض', true, 'RYH'], ['230194', 'الرياض', 'Riyadh', '1265', 'Riyadh', 'الرياض', true, 'RYH'],
            ['41126', 'جدة', 'Jeddah', '1336', 'Makkah', 'مكة المكرمة', true, 'JED'], ['41127', 'الدمام', 'Dammam', '1337', 'Eastern Province', 'المنطقة الشرقية', false, 'DMM'],
            ['50001', 'الخبر', 'Khobar', '1337', null, null, null, null], ['50002', 'الهفوف', 'Hofuf', '1337', 'Eastern Province', 'المنطقة الشرقية', true, 'HOF'],
            ['50003', 'الهفوف', 'Hofuf', '1204', 'Riyadh', 'الرياض', true, 'HOF'], ['50004', 'الطائف', 'Taif', '1999', 'Region 77', null, null, null],
            ['50005', 'أم سليم', 'Umm Sulaym', '1204', 'Riyadh', 'الرياض', null, null], ['50006', 'مكة المكرمة', 'Makkah', '1336', 'Makkah', 'مكة المكرمة', true, 'MAK'],
        ];
    }

    public function test_after_a_complete_reading_the_unique_cities_are_approved_and_the_others_wait_for_a_person(): void
    {
        $this->fakeLastmileList($this->fixtureDirectory(), ['total' => true]);

        $result = $this->sync();

        $this->assertSame('full', $result['verdict']);
        $this->assertSame(3, $result['approved']);
        $this->assertSame(8, $result['proposed'], 'Riyadh twice, Taif, the two cities that share one Umm Sulaym, the Dammam Torod does not select, and the two Hofufs Torod lists under one name');
        $this->assertSame(3, ProviderCityMapping::query()->approved()->where('method', 'auto_unique')->count());
        $this->assertSame(['36189', '41127', '50002', '50003', '50004', '50005', '50005', '230194'], ProviderCityMapping::query()->where('status', 'pending')->pluck('external_city_id')->map(static fn ($id): string => (string) $id)->sort()->values()->all());
    }

    public function test_after_a_partial_reading_nothing_is_approved_and_every_candidate_waits_for_a_person(): void
    {
        $this->fakeLastmileList($this->fixtureDirectory(), ['pages' => false]);

        $result = $this->sync();

        $this->assertSame('partial', $result['verdict']);
        $this->assertSame(0, $result['approved']);
        $this->assertSame(11, $result['proposed']);
        $this->assertSame(0, ProviderCityMapping::query()->approved()->count());
    }

    public function test_without_a_catalog_of_our_own_the_directory_is_still_stored_and_nothing_is_matched(): void
    {
        DB::table('address_cities')->delete();
        $this->fakeLastmileList($this->fixtureDirectory(), ['total' => true]);

        $result = $this->sync();

        $this->assertSame(['full', 10, 0, 0], [$result['verdict'], $result['count'], $result['approved'], $result['proposed']]);
    }

    // ---- the command ------------------------------------------------------------------------------------------------------------------

    public function test_the_weekly_command_succeeds_on_a_full_reading_and_fails_on_a_partial_one(): void
    {
        $this->fakeLastmileList($this->lastmileDirectory(25), ['total' => true]);
        $this->assertSame(0, Artisan::call('fulfillment:sync', ['provider' => 'torod_point', '--only' => 'cities']));
        $this->assertStringContainsString('torod_point cities: ok 25 (0 approved automatically, 0 proposed)', Artisan::output());

        $this->fakeLastmileList($this->lastmileDirectory(25), ['first' => 10, 'pages' => false]);
        $this->assertSame(1, Artisan::call('fulfillment:sync', ['provider' => 'torod_point', '--only' => 'cities']));
        $output = Artisan::output();
        $this->assertStringContainsString('PARTIAL', $output);
        $this->assertStringContainsString('10 returned, paging not honoured', $output);
    }

    public function test_a_provider_without_a_city_list_is_skipped_by_the_weekly_command_instead_of_failing_it(): void
    {
        $this->enableProvider(ProviderCode::M5azn, [], ['email' => 'seller@example.test', 'password' => 'pw-very-secret-1']);
        $this->fakeLastmileList($this->lastmileDirectory(25), ['total' => true]);

        $this->assertSame(0, Artisan::call('fulfillment:sync', ['--only' => 'cities']));

        $output = Artisan::output();
        $this->assertStringContainsString('m5azn cities: not applicable to this provider, skipped', $output);
        $this->assertStringContainsString('torod_point cities: ok', $output);
    }
}
