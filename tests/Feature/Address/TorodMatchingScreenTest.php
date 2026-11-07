<?php

namespace Tests\Feature\Address;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\ModulePermissionMiddleware;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Admin;
use App\Models\ProviderCity;
use App\Models\ProviderCityMapping;
use App\Models\ProviderCitySyncRun;
use App\Services\Address\AddressSchema;
use App\Services\Fulfillment\ProviderCityReview;
use App\Services\Fulfillment\ProviderCityMappingService;
use App\Services\Fulfillment\ProviderOrderDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;

/**
 * The Torod Point matching page, as an administrator uses it to settle cities: read the last synchronisation, see the held orders'
 * cities with Torod's candidates side by side, approve or reject one, retire a match, match by hand, ask Torod for candidates.
 *
 * The assertions stay on data (ids, names, routes) and on the toast texts the page itself translates, so they hold whichever
 * language the store is in.
 */
class TorodMatchingScreenTest extends TorodAddressTestCase
{
    /** The phrases this page added (lang/request_wp-e.json): until they are merged into the language files, rendering it would write them there. */
    private const NEW_LANGUAGE_KEYS = [
        'city_directory', 'city_sync_never_run', 'city_sync_full', 'city_sync_partial', 'city_sync_failed', 'city_sync_cities_read', 'city_sync_provider_total',
        'city_sync_run', 'city_sync_incomplete_help', 'city_directory_cached', 'city_directory_selected', 'city_directory_missing', 'sync_incomplete',
        'cities_waiting_for_a_decision', 'city_orders_waiting', 'city_has_several_candidates', 'city_hold_code_no_mapping', 'city_hold_code_pending_review',
        'city_hold_code_ambiguous', 'city_hold_code_provider_city_missing', 'city_find_candidates_at_torod', 'city_no_candidates_known', 'city_torod_region',
        'city_region_check', 'city_region_confirmed', 'city_region_unknown', 'city_region_unplaceable', 'city_replace_with_this', 'city_nothing_waiting',
        'city_nothing_waiting_help', 'city_approved_matches', 'city_approved', 'city_method_manual', 'city_method_auto_unique', 'city_manual_match',
        'city_manual_match_help', 'city_search_our_cities', 'city_alias_legacy_note', 'city_match_approved', 'city_candidate_rejected', 'city_discovery_done',
        'city_discovery_failed',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipWhileTheLanguageKeysAreNotMerged();
        View::getFinder()->prependLocation(base_path('tests/Support/views'));
        View::addLocation(resource_path('themes/default'));
        $this->withoutMiddleware([
            AdminMiddleware::class, ActivationCheckMiddleware::class, ModulePermissionMiddleware::class,
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
        $this->actingAs(Admin::findOrFail(1), 'admin');
    }

    /**
     * translate() writes a key it does not know into the shared language files. A page that is waiting for its phrases to be merged must
     * not do that from a test run: it would dirty files other runs compare, so the page is tested once the phrases are there.
     */
    private function skipWhileTheLanguageKeysAreNotMerged(): void
    {
        if (defined('TRANSLATE_NEVER_WRITES')) {
            return;
        }

        foreach (['en', 'sa', 'ae'] as $locale) {
            $known = array_merge(include base_path("resources/lang/{$locale}/messages.php"), include base_path("resources/lang/{$locale}/new-messages.php"));
            $missing = array_values(array_filter(self::NEW_LANGUAGE_KEYS, static fn (string $key): bool => !array_key_exists($key, $known)));
            if ($missing !== []) {
                $this->markTestSkipped("resources/lang/{$locale} lacks " . count($missing) . ' phrase(s) of this page (e.g. ' . $missing[0] . '); merge lang/request_wp-e.json first.');
            }
        }
    }

    private function mappings(): ProviderCityMappingService
    {
        return app(ProviderCityMappingService::class);
    }

    private function page(): string
    {
        return $this->get(route('admin.fulfillment-providers.torod.matching'))->assertOk()->getContent();
    }

    private function toasts(): string
    {
        return (string) json_encode(session('laravel-toaster-magic::messages') ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Riyadh's order held, with both of Torod's Riyadh proposed. */
    private function heldRiyadh(): int
    {
        $this->mappings()->proposeCandidates('torod_point', collect([\App\Models\AddressCity::findOrFail($this->ids['riyadh'])]));
        $fulfillment = $this->torodFulfillment(['shipping_address_data' => $this->snapshotJson('riyadh')]);
        app(ProviderOrderDispatcher::class)->dispatch($fulfillment->id);
        $this->assertSame(FulfillmentStatus::Held, $fulfillment->refresh()->status);

        return $fulfillment->id;
    }

    // ---- reading it --------------------------------------------------------------------------------------------------------------

    public function test_it_shows_the_last_reading_with_its_verdict_counts_and_reason(): void
    {
        ProviderCitySyncRun::create(['provider' => 'torod_point', 'verdict' => 'full', 'pages' => 3, 'fetched' => 25, 'distinct_ids' => 25, 'expected_total' => 25, 'finished_at' => now()->subDay()]);
        ProviderCitySyncRun::create(['provider' => 'torod_point', 'verdict' => 'partial', 'pages' => 2, 'fetched' => 20, 'distinct_ids' => 10, 'reason' => '10 returned, paging not honoured', 'finished_at' => now()]);

        $html = $this->page();

        $this->assertStringContainsString('10 returned, paging not honoured', $html);
        $this->assertStringContainsString(translate('city_sync_partial'), $html);
        $this->assertStringContainsString(translate('city_sync_full'), $html, 'the earlier run is in the history');
        $this->assertStringContainsString(route('admin.fulfillment-providers.sync', ['torod-point', 'cities']), $html, 'the sync button sits with the verdict');
    }

    public function test_held_orders_show_both_riyadh_candidates_side_by_side_with_what_tells_them_apart(): void
    {
        $shipment = $this->heldRiyadh();

        $html = $this->page();

        foreach (['36189', '230194', '1204', '1265', 'RYH'] as $tellsThemApart) {
            $this->assertStringContainsString($tellsThemApart, $html, $tellsThemApart);
        }
        $this->assertGreaterThan(0, preg_match_all('/name="external_city_id" value="36189"/', $html));
        $this->assertGreaterThan(0, preg_match_all('/name="external_city_id" value="230194"/', $html));
        $this->assertStringContainsString(route('admin.fulfillment-providers.fulfillments.show', $shipment), $html, 'the order is one click away');
        $this->assertStringContainsString('k-badge--danger', $html, 'the conflict is marked');
        $this->assertStringContainsString(route('admin.fulfillment-providers.torod.cities.discover'), $html);
    }

    public function test_an_order_placed_before_the_structured_address_counts_as_waiting_on_the_city_its_typed_text_certainly_is(): void
    {
        $this->mappings()->proposeCandidates('torod_point', collect([\App\Models\AddressCity::findOrFail($this->ids['riyadh'])]));
        $old = $this->torodFulfillment(['shipping_address_data' => json_encode(['contact_person_name' => 'A B', 'phone' => '0551234567', 'address' => 'x street', 'city' => 'الرياض'])]);
        app(ProviderOrderDispatcher::class)->dispatch($old->id);
        $this->assertSame(FulfillmentStatus::Held, $old->refresh()->status);

        $review = app(ProviderCityReview::class);
        $block = collect($review->overview('torod_point')['cities'])->first(fn (array $block): bool => $block['city']->id === $this->ids['riyadh']);

        $this->assertSame([$this->ids['riyadh']], $review->heldCityIds('torod_point'), 'the discovery command can find the city the order waits on');
        $this->assertSame(1, $block['orders'], 'the block says an order is waiting, as it does for an order with a structured copy');
        $this->assertSame([], $review->overview('torod_point')['typed'], 'a text that certainly means one of our cities is not left for a person to find a city for');
    }

    public function test_the_legacy_card_lists_only_the_cities_typed_as_text_and_the_blocks_only_the_cities_of_ours(): void
    {
        $this->heldRiyadh();
        $typed = $this->torodFulfillment(['shipping_address_data' => json_encode(['contact_person_name' => 'A B', 'phone' => '0551234567', 'address' => 'x street', 'city' => 'Nowhereville'])], orderId: 101);
        app(ProviderOrderDispatcher::class)->dispatch($typed->id);

        $html = $this->page();

        $this->assertSame(1, substr_count($html, 'Nowhereville'), 'once, as a badge of the alias card');
        $this->assertStringContainsString('name="alias"', $html);
    }

    public function test_text_from_the_provider_is_escaped(): void
    {
        $this->torodCity('777', '<script>alert(1)</script>', 'Riyadh', '1204', 'Riyadh', 'الرياض', true, 'RYH');
        $this->heldRiyadh();

        $html = $this->page();

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    // ---- deciding ------------------------------------------------------------------------------------------------------------------

    public function test_an_administrator_approves_one_candidate_and_it_is_audited_under_their_name(): void
    {
        $this->heldRiyadh();

        $this->post(route('admin.fulfillment-providers.torod.cities.approve'), ['address_city_id' => $this->ids['riyadh'], 'external_city_id' => '230194'])->assertRedirect();

        $mapping = $this->mappings()->approvedFor('torod_point', $this->ids['riyadh']);
        $this->assertSame('230194', (string) $mapping->external_city_id);
        $this->assertSame(1, $mapping->approved_by);
        $this->assertSame('Admin', $mapping->evidence['decisions'][0]['by']['name']);
        $audit = DB::table('audit_logs')->where('action', 'provider_city_mapping.approved')->first();
        $this->assertSame('admin', $audit->actor_type);
        $this->assertStringContainsString(translate('city_match_approved'), $this->toasts());

        $page = $this->page();
        $this->assertStringContainsString(route('admin.fulfillment-providers.torod.cities.retire', $mapping->id), $page, 'the approved list can retire it');
    }

    public function test_a_second_approval_is_refused_with_a_message_and_replacing_is_explicit(): void
    {
        $this->heldRiyadh();
        $first = $this->mappings()->approve(provider: 'torod_point', cityId: $this->ids['riyadh'], externalCityId: '36189');

        $this->post(route('admin.fulfillment-providers.torod.cities.approve'), ['address_city_id' => $this->ids['riyadh'], 'external_city_id' => '230194'])->assertRedirect();
        $this->assertStringContainsString(translate('city_mapping_city_already_approved'), $this->toasts());
        $this->assertSame('36189', (string) $this->mappings()->approvedFor('torod_point', $this->ids['riyadh'])->external_city_id);

        $page = $this->page();
        $this->assertStringContainsString('name="replacing" value="' . $first->id . '"', $page, 'the page offers the replacement knowingly');

        $this->post(route('admin.fulfillment-providers.torod.cities.approve'), ['address_city_id' => $this->ids['riyadh'], 'external_city_id' => '230194', 'replacing' => $first->id])->assertRedirect();
        $this->assertSame('230194', (string) $this->mappings()->approvedFor('torod_point', $this->ids['riyadh'])->external_city_id);
    }

    public function test_rejecting_a_candidate_and_retiring_a_match(): void
    {
        $this->heldRiyadh();

        $this->post(route('admin.fulfillment-providers.torod.cities.reject'), ['address_city_id' => $this->ids['riyadh'], 'external_city_id' => '230194'])->assertRedirect();
        $this->assertSame('rejected', ProviderCityMapping::where('external_city_id', '230194')->first()->status->value);
        $this->assertStringContainsString(translate('city_candidate_rejected'), $this->toasts());

        $mapping = $this->mappings()->approve(provider: 'torod_point', cityId: $this->ids['riyadh'], externalCityId: '36189');
        $this->post(route('admin.fulfillment-providers.torod.cities.retire', $mapping->id))->assertRedirect();
        $this->assertNull($this->mappings()->approvedFor('torod_point', $this->ids['riyadh']));

        $this->post(route('admin.fulfillment-providers.torod.cities.retire', $mapping->id))->assertRedirect();
        $this->assertStringContainsString(translate('city_mapping_only_approved_can_be_retired'), $this->toasts());
    }

    public function test_a_manual_match_picks_one_of_our_cities_and_one_cached_provider_city(): void
    {
        $ours = $this->getJson(route('admin.fulfillment-providers.torod.cities.internal-search', ['q' => 'dammam']))->assertOk()->json();
        $theirs = $this->getJson(route('admin.fulfillment-providers.torod.cities.search', ['q' => 'riyadh']))->assertOk()->json();

        $this->assertSame([$this->ids['dammam']], array_column($ours, 'id'));
        $this->assertSame(['الدمام', 'Ad Dammam'], [$ours[0]['name_ar'], $ours[0]['name_en']]);
        $this->assertStringContainsString('المنطقة الشرقية', $ours[0]['region']);
        $this->assertEqualsCanonicalizing(['36189', '230194'], array_column($theirs, 'id'));
        $first = collect($theirs)->firstWhere('id', '36189');
        $this->assertSame(['1204', 'RYH', true], [$first['region_id'], $first['prefix'], $first['selected']]);

        $this->post(route('admin.fulfillment-providers.torod.cities.approve'), ['address_city_id' => $ours[0]['id'], 'external_city_id' => '41127'])->assertRedirect();

        $this->assertSame('41127', (string) $this->mappings()->approvedFor('torod_point', $this->ids['dammam'])->external_city_id);
        $this->assertStringContainsString('name="address_city_id" id="manual-city-id"', $this->page());
    }

    public function test_tampered_or_invented_ids_change_nothing(): void
    {
        $url = route('admin.fulfillment-providers.torod.cities.approve');

        $this->post($url, ['address_city_id' => 'abc', 'external_city_id' => '36189'])->assertSessionHasErrors('address_city_id');
        $this->post($url, ['address_city_id' => $this->ids['riyadh'], 'external_city_id' => str_repeat('9', 33)])->assertSessionHasErrors('external_city_id');
        $this->post($url, ['address_city_id' => $this->ids['riyadh'], 'external_city_id' => '41127; DROP TABLE'])->assertRedirect();
        $this->assertStringContainsString('provider', $this->toasts());
        $this->post($url, ['address_city_id' => 99999, 'external_city_id' => '36189'])->assertRedirect();
        $this->post($url, [])->assertSessionHasErrors(['address_city_id', 'external_city_id']);

        $this->assertSame(0, ProviderCityMapping::count());
    }

    public function test_asking_torod_for_candidates_proposes_the_exact_names_and_is_throttled(): void
    {
        ProviderCity::query()->delete();
        Http::fake([self::LASTMILE_CITIES => Http::response(['data' => [
            ['id' => 36189, 'arabicName' => 'الرياض', 'englishName' => 'Riyadh', 'regionId' => 1204, 'regionName' => 'Riyadh', 'isSelected' => true, 'prefix' => 'RYH'],
            ['id' => 230194, 'arabicName' => 'الرياض', 'englishName' => 'Riyadh', 'regionId' => 1265, 'regionName' => 'Riyadh', 'isSelected' => true, 'prefix' => 'RYH'],
        ]])]);

        $this->post(route('admin.fulfillment-providers.torod.cities.discover'), ['address_city_id' => $this->ids['riyadh']])->assertRedirect();

        $this->assertSame(2, ProviderCityMapping::query()->where('status', 'pending')->count());
        $this->assertSame(2, ProviderCity::count());
        $this->assertStringContainsString(translate('city_discovery_done'), $this->toasts());
        $middleware = Route::getRoutes()->getByName('admin.fulfillment-providers.torod.cities.discover')->gatherMiddleware();
        $this->assertContains('throttle:6,1,torod-city-discover', $middleware);
        $this->assertContains('admin', $middleware);
    }

    public function test_the_city_decisions_each_have_a_rate_limit_bucket_of_their_own(): void
    {
        $prefixes = [];
        foreach (['approve', 'reject', 'retire', 'discover'] as $action) {
            $declared = (array) Route::getRoutes()->getByName("admin.fulfillment-providers.torod.cities.{$action}")->middleware();
            $throttle = array_values(array_filter($declared, static fn ($name): bool => is_string($name) && str_starts_with($name, 'throttle:')));

            $this->assertCount(1, $throttle, $action);
            $parts = explode(',', substr($throttle[0], strlen('throttle:')));
            $this->assertCount(3, $parts, "{$action}: a numeric throttle without a name shares one counter with every other one of the client");
            $prefixes[] = $parts[2];
        }

        $this->assertSame($prefixes, array_values(array_unique($prefixes)), 'no two decisions share a bucket');
    }

    public function test_the_sync_button_says_partial_instead_of_completed(): void
    {
        Http::fake([self::LASTMILE_CITIES => Http::response(['data' => $this->lastmileDirectory(10)])]);

        $this->post(route('admin.fulfillment-providers.sync', ['torod-point', 'cities']))->assertRedirect();

        $toasts = $this->toasts();
        $this->assertStringContainsString(translate('sync_incomplete'), $toasts);
        $this->assertStringContainsString('10 returned, paging not honoured', $toasts);
        $this->assertStringNotContainsString(translate('sync_completed'), $toasts);
    }

    public function test_the_sync_button_still_says_completed_for_a_complete_reading(): void
    {
        config(['fulfillment.providers.torod_point.cities.complete_floor' => 10]);
        Http::fake([self::LASTMILE_CITIES => Http::response(['data' => $this->lastmileDirectory(10), 'totalElements' => 10])]);

        $this->post(route('admin.fulfillment-providers.sync', ['torod-point', 'cities']))->assertRedirect();

        $this->assertStringContainsString(translate('sync_completed'), $this->toasts());
    }

    // ---- before the migration ------------------------------------------------------------------------------------------------------

    public function test_before_the_address_migration_the_page_is_the_old_one_and_the_new_actions_are_not_there(): void
    {
        Schema::drop('provider_city_sync_runs');
        Schema::drop('provider_city_mappings');
        AddressSchema::flush();

        $html = $this->page();

        $this->assertStringContainsString('name="alias"', $html);
        $this->assertStringNotContainsString('manual-city-match', $html);
        $this->assertStringNotContainsString(route('admin.fulfillment-providers.torod.cities.discover'), $html);
        $this->post(route('admin.fulfillment-providers.torod.cities.approve'), ['address_city_id' => 1, 'external_city_id' => '1'])->assertNotFound();
        $this->post(route('admin.fulfillment-providers.torod.cities.discover'), ['address_city_id' => 1])->assertNotFound();
        $this->getJson(route('admin.fulfillment-providers.torod.cities.internal-search', ['q' => 'x']))->assertNotFound();
    }
}
