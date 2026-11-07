<?php

namespace Tests\Feature\Address;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\AddressCity;
use App\Models\ProviderApiLog;
use App\Models\ProviderCity;
use App\Models\ProviderCityMapping;
use App\Services\Address\AddressSchema;
use App\Services\Fulfillment\Exceptions\ProviderUnavailableException;
use App\Services\Fulfillment\FulfillmentSchema;
use App\Services\Fulfillment\ProviderCityMappingService;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\ProviderOrderDispatcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Finding Torod's candidates for one of our cities on demand, with the one filter its documentation promises: `search`.
 */
class TorodCityDiscoveryTest extends TorodAddressTestCase
{
    /** @return array<string, mixed> */
    private function record(int $id, string $arabic, string $english, int $region, string $regionEnglish, bool $selected = true, ?string $prefix = null): array
    {
        return ['id' => $id, 'name' => $english, 'arabicName' => $arabic, 'englishName' => $english, 'regionId' => $region, 'regionName' => $regionEnglish, 'arabicRegionName' => $regionEnglish, 'isSelected' => $selected, 'prefix' => $prefix];
    }

    /** @param array<string, list<array<string, mixed>>> $answers search text => the records Torod answers with */
    private function fakeSearch(array $answers): void
    {
        Http::fake([self::LASTMILE_CITIES => function (Request $request) use ($answers) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response(['data' => $answers[$query['search'] ?? ''] ?? []]);
        }]);
    }

    private function riyadhAnswers(): array
    {
        $riyadh = [$this->record(36189, 'الرياض', 'Riyadh', 1204, 'Riyadh', true, 'RYH'), $this->record(230194, 'الرياض', 'Riyadh', 1265, 'Riyadh', true, 'RYH')];

        return ['Riyadh' => [...$riyadh, $this->record(70001, 'الرياض الخبراء', 'Riyadh Al Khabra', 1204, 'Riyadh', false)], 'الرياض' => $riyadh];
    }

    private function city(string $handle): AddressCity
    {
        return AddressCity::query()->with('region')->findOrFail($this->ids[$handle]);
    }

    public function test_it_asks_for_the_english_and_the_arabic_name_with_only_the_documented_filter(): void
    {
        $this->fakeSearch($this->riyadhAnswers());

        $this->adapter()->discoverCities($this->city('riyadh'));

        $sent = collect(Http::recorded())->map(static fn (array $pair): Request => $pair[0]);
        $this->assertCount(2, $sent);
        $this->assertSame(['Riyadh', 'الرياض'], $sent->map(static function (Request $request): string {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['search'];
        })->all());
        foreach ($sent as $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->assertSame(['search'], array_keys($query), 'no returnAll, page or pageSize: only what the documentation names');
            $this->assertSame('GET', $request->method());
            $this->assertSame('714', $request->header('company-id')[0]);
            $this->assertStringStartsWith(self::V2 . '/addresses/cities', $request->url());
        }
        $this->assertSame(['lastmile_cities'], ProviderApiLog::query()->pluck('operation')->unique()->values()->all(), 'the operation the write guard classes as a read');
    }

    public function test_what_is_found_is_cached_and_only_the_exact_names_are_proposed(): void
    {
        ProviderCity::query()->delete();
        $this->fakeSearch($this->riyadhAnswers());

        $result = $this->adapter()->discoverCities($this->city('riyadh'));

        $this->assertSame(['ok' => true, 'found' => 3, 'matches' => ['36189', '230194'], 'proposed' => 2, 'applied' => true], $result);
        $cached = ProviderCity::where('provider', 'torod_point')->orderBy('external_id')->get();
        $this->assertSame(['36189', '70001', '230194'], $cached->pluck('external_id')->sort()->values()->all());
        $second = $cached->firstWhere('external_id', '230194');
        $this->assertSame(['1265', 'Riyadh', 'RYH', true], [$second->region_external_id, $second->region_name_en, $second->prefix, $second->is_selected]);
        $this->assertNotNull($second->last_seen_at);
        $pending = ProviderCityMapping::query()->where('status', 'pending')->get();
        $this->assertSame(['36189', '230194'], $pending->pluck('external_city_id')->map(static fn ($id): string => (string) $id)->sort()->values()->all());
        $this->assertSame(['discovery'], $pending->pluck('method')->unique()->values()->all());
        $this->assertSame(0, ProviderCityMapping::query()->approved()->count(), 'discovery proposes; a person approves');
        $this->assertSame(0, ProviderCityMapping::where('external_city_id', '70001')->count(), 'a name that only contains ours is cached but never proposed');
    }

    public function test_finding_candidates_while_a_sync_runs_is_refused_and_writes_nothing(): void
    {
        $this->fakeSearch($this->riyadhAnswers());
        ProviderCity::query()->delete();
        $running = Cache::lock('provider-city-sync:torod_point', 60);
        $this->assertTrue($running->get());

        $result = $this->adapter()->discoverCities($this->city('riyadh'));

        $this->assertSame(['ok' => false, 'error' => 'sync_already_running'], $result);
        $this->assertSame(0, ProviderCity::count());
        $this->assertSame(0, ProviderCityMapping::query()->where('status', 'pending')->count());

        $this->assertSame(true, $this->adapter()->discoverCities($this->city('riyadh'), apply: false)['ok'], 'only asking is a read and needs no lock');
        $running->release();
    }

    public function test_asking_only_reports_what_would_be_found_and_writes_nothing(): void
    {
        ProviderCity::query()->delete();
        $this->fakeSearch($this->riyadhAnswers());

        $result = $this->adapter()->discoverCities($this->city('riyadh'), apply: false);

        $this->assertSame(['ok' => true, 'found' => 3, 'matches' => ['36189', '230194'], 'proposed' => null, 'applied' => false], $result);
        $this->assertSame(0, ProviderCity::count());
        $this->assertSame(0, ProviderCityMapping::count());
        Http::assertSentCount(2);
    }

    public function test_a_mapped_city_keeps_its_mapping_and_gets_no_new_proposals(): void
    {
        $mapping = app(ProviderCityMappingService::class)->approve(provider: 'torod_point', cityId: $this->ids['riyadh'], externalCityId: '36189');
        $this->fakeSearch($this->riyadhAnswers());

        $result = $this->adapter()->discoverCities($this->city('riyadh'));

        $this->assertSame(0, $result['proposed']);
        $this->assertSame('approved', $mapping->refresh()->status->value);
        $this->assertSame(1, ProviderCityMapping::count());
    }

    public function test_when_one_search_fails_the_other_still_counts_and_when_both_fail_nothing_is_written(): void
    {
        ProviderCity::query()->delete();
        $bothFail = false;
        Http::fake([self::LASTMILE_CITIES => function (Request $request) use (&$bothFail) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if ($bothFail) {
                return Http::response(['message' => 'Not allowed'], 403);
            }

            return $query['search'] === 'Riyadh' ? Http::response(['message' => 'boom'], 500) : Http::response(['data' => [$this->record(36189, 'الرياض', 'Riyadh', 1204, 'Riyadh')]]);
        }]);

        $this->assertSame(1, $this->adapter()->discoverCities($this->city('riyadh'))['found']);

        ProviderCity::query()->delete();
        ProviderCityMapping::query()->delete();
        $bothFail = true;
        $result = $this->adapter()->discoverCities($this->city('riyadh'));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Not allowed', $result['error']);
        $this->assertSame(0, ProviderCity::count());
    }

    public function test_it_is_a_read_and_works_while_external_writes_are_off_but_not_past_the_kill_switch(): void
    {
        config(['fulfillment.external_writes_enabled' => false]);
        $this->fakeSearch($this->riyadhAnswers());

        $this->assertTrue($this->adapter()->discoverCities($this->city('riyadh'))['ok']);

        config(['fulfillment.enabled' => false]);
        Http::fake();
        try {
            $this->adapter()->discoverCities($this->city('riyadh'));
            $this->fail('the kill switch did not stop the search');
        } catch (ProviderUnavailableException) {
            Http::assertNothingSent();
        }
    }

    // ---- the command ---------------------------------------------------------------------------------------------------------

    /** @return array{0: int, 1: string} */
    private function command(array $arguments): array
    {
        $exit = Artisan::call('address:discover-provider-cities', ['provider' => 'torod_point'] + $arguments);

        return [$exit, Artisan::output()];
    }

    public function test_the_command_only_asks_unless_it_is_told_to_apply(): void
    {
        ProviderCity::query()->delete();
        $this->fakeSearch($this->riyadhAnswers());

        [$exit, $output] = $this->command(['--city' => [$this->ids['riyadh']]]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Dry run', $output);
        $this->assertStringContainsString('3 found, exact name: [36189, 230194]', $output);
        $this->assertSame(0, ProviderCity::count());
        Http::assertSentCount(2);

        [$exit, $output] = $this->command(['--city' => [$this->ids['riyadh']], '--apply' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('2 proposed', $output);
        $this->assertSame(3, ProviderCity::count());
        $this->assertSame(2, ProviderCityMapping::query()->where('status', 'pending')->count());
    }

    public function test_held_looks_for_the_cities_the_held_orders_are_waiting_on_and_nothing_else(): void
    {
        $riyadh = $this->torodFulfillment(['shipping_address_data' => $this->snapshotJson('riyadh')], orderId: 100);
        $jeddah = $this->torodFulfillment(['shipping_address_data' => $this->snapshotJson('jeddah')], orderId: 101);
        $settled = $this->torodFulfillment(['shipping_address_data' => $this->snapshotJson('khobar')], orderId: 102);
        app(ProviderCityMappingService::class)->approve(provider: 'torod_point', cityId: $this->ids['khobar'], externalCityId: '50001');
        foreach ([$riyadh, $jeddah, $settled] as $fulfillment) {
            app(ProviderOrderDispatcher::class)->dispatch($fulfillment->id);
        }
        $this->fakeSearch($this->riyadhAnswers());

        [$exit, $output] = $this->command(['--held' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('#' . $this->ids['riyadh'] . ' Riyadh', $output);
        $this->assertStringContainsString('#' . $this->ids['jeddah'] . ' Jeddah', $output);
        $this->assertStringNotContainsString('Khobar', $output);
        Http::assertSentCount(4);
    }

    public function test_held_finds_the_city_an_old_order_with_only_a_typed_city_waits_on(): void
    {
        $old = $this->torodFulfillment(['shipping_address_data' => json_encode(['contact_person_name' => 'A B', 'phone' => '0551234567', 'address' => 'x street', 'city' => 'الرياض'])], orderId: 100);
        app(ProviderOrderDispatcher::class)->dispatch($old->id);
        $this->fakeSearch($this->riyadhAnswers());

        [$exit, $output] = $this->command(['--held' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('#' . $this->ids['riyadh'] . ' Riyadh', $output);
        Http::assertSentCount(2);
    }

    public function test_the_command_says_so_and_sends_nothing_when_there_is_nothing_to_look_for_or_it_may_not_run(): void
    {
        Http::fake();

        [$exit, $output] = $this->command([]);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('no city to look for', $output);

        [$exit, $output] = $this->command(['--city' => [999999]]);
        $this->assertStringContainsString('no city to look for', $output);

        Artisan::call('address:discover-provider-cities', ['provider' => 'm5azn', '--city' => [$this->ids['riyadh']]]);
        $this->assertStringContainsString('Only torod_point', Artisan::output());

        config(['fulfillment.enabled' => false]);
        [$exit, $output] = $this->command(['--city' => [$this->ids['riyadh']], '--apply' => true]);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('switched off', $output);
        Http::assertNothingSent();
    }

    public function test_the_command_skips_until_the_address_tables_exist(): void
    {
        Http::fake();
        Schema::drop('provider_city_sync_runs');
        Schema::drop('provider_city_mappings');
        AddressSchema::flush();

        [$exit, $output] = $this->command(['--city' => [$this->ids['riyadh']], '--apply' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('address migrations have not been run', $output);
        Http::assertNothingSent();
    }

    public function test_the_command_skips_on_a_store_without_the_fulfillment_tables_before_it_reads_any_of_them(): void
    {
        Http::fake();
        config(['fulfillment.enabled' => true]);
        Schema::drop('provider_fulfillment_items');
        Schema::drop('provider_fulfillments');
        Schema::drop('integration_settings');
        FulfillmentSchema::flush();

        [$exit, $output] = $this->command(['--city' => [$this->ids['riyadh']], '--apply' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('fulfillment migrations have not been run', $output);
        Http::assertNothingSent();
    }

    public function test_the_command_skips_while_the_provider_columns_of_the_city_list_are_missing_too(): void
    {
        Http::fake();
        Schema::table('provider_cities', static function ($table): void {
            $table->dropColumn('prefix');
        });
        AddressSchema::flush();

        [$exit, $output] = $this->command(['--city' => [$this->ids['riyadh']], '--apply' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('address migrations have not been run', $output);
        Http::assertNothingSent();
    }

    public function test_the_command_skips_while_the_provider_is_disabled(): void
    {
        Http::fake();
        app(ProviderConnectionManager::class)->save(provider: ProviderCode::TorodPoint, enabled: false, baseUrl: null, settings: ['company_id' => '714'], secrets: []);

        [$exit, $output] = $this->command(['--city' => [$this->ids['riyadh']]]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('switched off', $output);
        Http::assertNothingSent();
    }
}
