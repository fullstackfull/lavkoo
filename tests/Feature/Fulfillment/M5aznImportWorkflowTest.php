<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderCatalogItem;
use App\Models\ProviderProductMapping;
use App\Models\ProviderSyncState;
use App\Services\Fulfillment\FieldMapStore;
use App\Services\Fulfillment\Providers\M5azn\M5aznAdapter;
use App\Services\Fulfillment\Providers\M5azn\M5aznProductImporter;
use App\Services\Fulfillment\ProviderSyncService;
use App\Services\Fulfillment\Support\RemoteImageFetcher;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * "I select products on M5AZN and press Supply/Import; they appear in Pharmacy KSA by themselves."
 *
 * M5AZN documents no webhook for that, so the store PULLS the seller's product list on a schedule. These tests
 * run the exact path the scheduler and the admin "Sync now" button take (ProviderSyncService), against faked
 * M5AZN responses, and pin down what the pull can and cannot guarantee. The response SHAPES are assumed
 * fixtures - M5AZN publishes no response schema - and must be replaced by captured real samples.
 */
class M5aznImportWorkflowTest extends M5aznTestCase
{
    /** @var array<string, array<int, list<array<string, mixed>>>> product type => page => items */
    private array $sellerList = [];

    /** @var list<string> the request URLs the store made to the seller-list endpoint */
    private array $requests = [];

    /** Http::fake() stubs accumulate and the first match wins, so a test replaces behaviour through this hook. */
    private ?\Closure $respondWith = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(RemoteImageFetcher::class, new class extends RemoteImageFetcher {
            public function fetch(string $url): ?UploadedFile
            {
                return null;
            }
        });

        $this->verifyMap('catalog', $this->catalogPaths(), $this->catalogFixture());
        Http::fake([
            self::BASE . '/api/v1/auth/login' => $this->loginResponse(),
            self::BASE . '/api/v1/seller/myproductlist*' => function (Request $request) {
                $this->requests[] = $request->url();
                if ($this->respondWith !== null) {
                    return ($this->respondWith)($request);
                }
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return Http::response(['success' => true, 'data' => $this->sellerList[$query['product_type'] ?? 'local'][(int) ($query['page'] ?? 1)] ?? []]);
            },
        ]);
    }

    private function sync(): array
    {
        return app(ProviderSyncService::class)->run(ProviderCode::M5azn, [ProviderSyncService::PRODUCTS])[ProviderSyncService::PRODUCTS];
    }

    private function item(int $id, array $overrides = []): array
    {
        return $overrides + ['id' => $id, 'name' => "Product {$id}", 'sku' => "SKU{$id}", 'barcode' => '62810000' . str_pad((string) $id, 5, '0', STR_PAD_LEFT), 'quantity' => 10, 'cost' => 5.0, 'price' => 9.0];
    }

    public function test_selected_products_appear_in_the_store_unpublished_and_owned_by_m5azn_after_one_sync(): void
    {
        $this->sellerList['local'][1] = $this->catalogFixture();

        $result = $this->sync();

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['import']['imported']);
        $this->assertSame(2, DB::table('products')->count());
        $this->assertSame(2, DB::table('products')->where('fulfillment_provider', 'm5azn')->count());
        $this->assertSame(0, DB::table('products')->where('status', 1)->count(), 'nothing goes live without a person publishing it (or the publish switch)');
        $this->assertSame(2, ProviderProductMapping::where('status', MappingStatus::Approved->value)->count());
    }

    public function test_a_product_selected_later_is_added_without_duplicating_or_disturbing_the_first_ones(): void
    {
        $this->sellerList['local'][1] = $this->catalogFixture();
        $this->sync();
        $before = DB::table('products')->pluck('id')->all();

        $this->sellerList['local'][1] = [...$this->catalogFixture(), $this->item(777001)];
        $second = $this->sync();

        $this->assertSame(1, $second['import']['imported']);
        $this->assertSame(0, $second['import']['updated'], 'unchanged products are not touched again');
        $this->assertSame(3, DB::table('products')->count());
        $this->assertSame($before, DB::table('products')->whereIn('id', $before)->pluck('id')->all());
        $this->assertSame(3, ProviderCatalogItem::where('status', ProviderCatalogItem::STATUS_IMPORTED)->count());
    }

    public function test_a_change_on_m5azn_reaches_the_store_on_the_next_sync(): void
    {
        $this->sellerList['local'][1] = $this->catalogFixture();
        $this->sync();

        $changed = $this->catalogFixture();
        $changed[0]['quantity'] = 3;
        $this->sellerList['local'][1] = $changed;
        $result = $this->sync();

        $this->assertSame(1, $result['import']['updated']);
        $this->assertSame(3, (int) DB::table('products')->where('code', 'VC1000')->value('current_stock'));
        $this->assertSame(2, DB::table('products')->count(), 'still two products');
    }

    public function test_every_page_of_a_long_list_is_read(): void
    {
        $this->sellerList['local'] = [1 => [$this->item(1), $this->item(2)], 2 => [$this->item(3), $this->item(4)], 3 => [$this->item(5)]];

        $result = $this->sync();

        $this->assertSame(5, ProviderCatalogItem::count());
        $this->assertSame(4, $result['stats']['pages'], 'pages 1-3 plus the empty page that proves the end');
        $this->assertFalse($result['stats']['truncated']);
        $this->assertSame(5, DB::table('products')->count());
    }

    public function test_an_api_that_ignores_the_page_parameter_is_not_read_forever_or_duplicated(): void
    {
        $this->sellerList['local'] = [1 => $this->catalogFixture(), 2 => $this->catalogFixture(), 3 => $this->catalogFixture()];

        $result = $this->sync();

        $this->assertSame(2, ProviderCatalogItem::count());
        $this->assertSame(2, $result['stats']['pages'], 'page 2 adds nothing new, so reading stops');
    }

    public function test_a_list_that_never_ends_is_cut_off_and_says_so(): void
    {
        for ($page = 1; $page <= 60; $page++) {
            $this->sellerList['local'][$page] = [$this->item($page)];
        }

        $result = $this->sync();

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['stats']['truncated']);
        $this->assertSame(50, $result['stats']['pages']);
        $this->assertSame(50, ProviderCatalogItem::count());
    }

    public function test_a_later_page_that_fails_keeps_what_was_read_and_is_reported_as_partial(): void
    {
        $this->respondWith = fn (Request $request) => str_contains($request->url(), 'page=2')
            ? Http::response(['message' => 'Server error'], 500)
            : Http::response(['success' => true, 'data' => $this->catalogFixture()]);

        $result = $this->sync();

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['stats']['partial']);
        $this->assertSame(2, ProviderCatalogItem::count());
    }

    public function test_a_failing_first_page_changes_nothing_and_records_the_error(): void
    {
        $this->respondWith = fn () => Http::response(['message' => 'Unauthenticated'], 401);

        $result = $this->sync();

        $this->assertFalse($result['ok']);
        $this->assertSame(0, ProviderCatalogItem::count());
        $this->assertNotNull(ProviderSyncState::where('provider', 'm5azn')->where('capability', 'products')->value('last_error'));
    }

    public function test_products_supplied_from_the_global_list_are_found_when_that_type_is_configured(): void
    {
        $this->sellerList['local'][1] = [$this->item(1)];
        $this->sellerList['global'][1] = [$this->item(2)];

        $this->enableProvider(ProviderCode::M5azn, ['catalog_product_type' => 'local'], []);
        $this->sync();
        $this->assertSame(1, ProviderCatalogItem::count(), 'only the default type is asked for unless configured');

        $this->enableProvider(ProviderCode::M5azn, ['catalog_product_type' => 'local, global', 'import_enabled' => true, 'default_category_id' => 5], []);
        $this->sync();

        $this->assertSame(2, ProviderCatalogItem::count());
        $types = collect($this->requests)->map(function (string $url): string {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return $query['product_type'] ?? '';
        })->unique()->sort()->values()->all();
        $this->assertSame(['global', 'local'], $types);
    }

    public function test_a_product_listed_under_two_types_is_one_product(): void
    {
        $this->enableProvider(ProviderCode::M5azn, ['catalog_product_type' => 'local,global', 'import_enabled' => true, 'default_category_id' => 5], []);
        $this->sellerList['local'][1] = [$this->item(1)];
        $this->sellerList['global'][1] = [$this->item(1)];

        $this->sync();

        $this->assertSame(1, ProviderCatalogItem::count());
        $this->assertSame(1, DB::table('products')->count());
    }

    public function test_new_products_are_never_starved_by_a_long_list_of_already_imported_ones(): void
    {
        $this->sellerList['local'][1] = array_map(fn (int $id): array => $this->item($id), range(1, 6));
        $this->sync();
        $this->assertSame(6, DB::table('products')->count());

        $this->sellerList['local'][1] = array_map(fn (int $id): array => $this->item($id), range(1, 8));
        app(M5aznAdapter::class)->syncCatalog();

        // A deliberately tiny batch: with the old "first N by id" rule the six imported items would fill it.
        $stats = app(M5aznProductImporter::class)->importPending(limit: 2);

        $this->assertSame(2, $stats['imported'], 'both new products are processed first');
        $this->assertSame(8, DB::table('products')->count());
    }

    public function test_unchanged_imported_products_are_skipped_but_an_explicit_refresh_re_applies_them(): void
    {
        $this->sellerList['local'][1] = $this->catalogFixture();
        $this->sync();

        $skipped = app(M5aznProductImporter::class)->importPending();
        $refreshed = app(M5aznProductImporter::class)->importPending(refreshAll: true);

        $this->assertSame(0, $skipped['updated'] + $skipped['imported']);
        $this->assertSame(2, $refreshed['updated']);
    }

    public function test_nothing_is_imported_until_the_catalogue_map_is_verified_but_everything_is_staged(): void
    {
        app(FieldMapStore::class)->save(ProviderCode::M5azn, 'catalog', $this->catalogPaths(), null);
        $this->sellerList['local'][1] = $this->catalogFixture();

        $result = $this->sync();

        $this->assertTrue($result['ok']);
        $this->assertSame(2, ProviderCatalogItem::where('status', ProviderCatalogItem::STATUS_NEW)->count());
        $this->assertSame(0, DB::table('products')->count());
        $this->assertSame(2, $result['import']['blocked'], 'blocked, not lost: they import by themselves once the map is verified');
    }

    public function test_the_sync_is_idempotent_across_repeated_runs(): void
    {
        $this->sellerList['local'][1] = $this->catalogFixture();

        for ($run = 0; $run < 4; $run++) {
            $this->sync();
        }

        $this->assertSame(2, DB::table('products')->count());
        $this->assertSame(2, ProviderProductMapping::count());
        $this->assertSame(2, ProviderCatalogItem::count());
    }

    public function test_the_interval_setting_decides_when_a_scheduled_sync_is_due(): void
    {
        $sync = app(ProviderSyncService::class);
        $this->assertTrue($sync->isDue(ProviderCode::M5azn, 'products'), 'never attempted');

        $this->sellerList['local'][1] = $this->catalogFixture();
        $this->sync();
        $this->assertFalse($sync->isDue(ProviderCode::M5azn, 'products'), 'just ran');

        ProviderSyncState::where('provider', 'm5azn')->where('capability', 'products')->update(['last_attempt_at' => now()->subMinutes(29)]);
        $this->assertTrue($sync->isDue(ProviderCode::M5azn, 'products'), 'a 30-minute interval is due at the tick after 28.5 minutes, not 35');

        ProviderSyncState::where('provider', 'm5azn')->where('capability', 'products')->update(['last_attempt_at' => now()->subMinutes(20)]);
        $this->assertFalse($sync->isDue(ProviderCode::M5azn, 'products'));

        $this->enableProvider(ProviderCode::M5azn, ['product_sync_interval_minutes' => 10, 'import_enabled' => true, 'default_category_id' => 5], []);
        $this->assertTrue($sync->isDue(ProviderCode::M5azn, 'products'), 'a shorter interval makes the same attempt due');
    }

    public function test_no_interval_can_be_shorter_than_the_floor(): void
    {
        $this->enableProvider(ProviderCode::M5azn, ['product_sync_interval_minutes' => 1, 'import_enabled' => true, 'default_category_id' => 5], []);
        ProviderSyncState::create(['provider' => 'm5azn', 'capability' => 'products', 'last_attempt_at' => now()->subMinutes(2)]);

        $this->assertFalse(app(ProviderSyncService::class)->isDue(ProviderCode::M5azn, 'products'), 'a provider is never polled harder than every 5 minutes');
    }

    public function test_the_scheduled_command_with_due_runs_only_what_is_due(): void
    {
        $this->sellerList['local'][1] = $this->catalogFixture();

        $this->artisan('fulfillment:sync', ['provider' => 'm5azn', '--only' => 'products', '--due' => true])->assertSuccessful();
        $first = count($this->requests);
        $this->artisan('fulfillment:sync', ['provider' => 'm5azn', '--only' => 'products', '--due' => true])->assertSuccessful();

        $this->assertGreaterThan(0, $first);
        $this->assertSame($first, count($this->requests), 'the second wake-up is not due, so M5AZN is not called again');
        $this->assertSame(2, DB::table('products')->count());
    }
}
