<?php

namespace Tests\Feature\Concurrency;

use App\Models\InventoryLocation;
use App\Models\InventoryPool;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Inventory\InventoryLocationMarket;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Inventory\Allocation\PoolAllocationService;
use App\Services\SalesChannels\Inventory\PoolKey;
use App\Services\SalesChannels\Support\ChannelOperations;
use App\Services\Storefront\StorefrontMarketRows;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\BuildsStockPools;
use Tests\Support\Concurrency\ConcurrentRunner;
use Tests\Support\CreatesChannelAccounts;
use Tests\TestCase;

/**
 * One SKU in two markets, bought and synced AT THE SAME INSTANT in two PHP processes on two connections, against a real MySQL or MariaDB:
 * neither market's units ever cross into the other's pool (M2-08, M2-12).
 *
 * `PoolCountryIdentityTest` proves the identity -- a pool belongs to one market, and two markets' twins of one SKU are two pools. It proves
 * it SEQUENTIALLY, in one process, and the two tracker criteria this file answers both ask for the race: "one SKU in two countries is bought
 * and synced at once with stock never crossing, on both engines", and "two countries' accounts publishing one SKU never exceed each stock".
 * A sequential test cannot tell a pool keyed by a market from one keyed by three columns and divided twice in a row, because the second
 * division simply overwrites the first and the arithmetic comes out right either way. Two processes meeting in the single writer can.
 *
 * The world, which is `PoolCountryIdentityTest`'s: the Torod warehouse is the Saudi shelf and names no market, as every shelf of this
 * platform does today; the M5AZN supplier stock is the Kuwaiti shelf. Product 50 is the Saudi twin of the SKU with 40 units and product 51
 * the Kuwaiti twin with 10, and BOTH accounts are linked to BOTH products -- which is what an operator typing SKUs into two accounts does,
 * and the only world in which crossing is even possible.
 *
 * **What makes this a measurement and not a coincidence.** The two markets' pools are two DIFFERENT rows, so the pool lock cannot serialise
 * the two processes and nothing else between them can: if the market were not part of the pool's identity they would be one row, one lock
 * and one division, and the figures would be the sum of both markets. So the assertion that each market's net is exactly its own units is
 * the assertion that the identity held under a race -- and `assertTwoPoolRows()` says out loud which of the two worlds the database is in.
 *
 * **A trap this file was written around, because it cost two failures that looked like defects.** `travelTo()` moves the PARENT's clock and
 * reaches no worker: each worker boots its own application and its `now()` is the real one. Both figures the division reads have a freshness
 * window -- the provider's stock (`provider_stock_synced_at`) and the account's order sweep (`sales_channel_sync_states.last_success_at`) --
 * so a world stamped at a travelled time reads as sixteen days stale in the worker, the pool comes back blocked with a `null` net, and the
 * publisher sends nothing. Both symptoms point at the market rather than at the clock, which is why it is written here: this world is built
 * on the real clock, and any later freshness window has to be too.
 *
 * Needs a real engine: SQLite in memory is private to one process and locks nothing, so every test here skips there (and a CI job that
 * declares `EXPECT_DB_DRIVER=mysql` fails instead of skipping).
 */
class CountryPoolPublishTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use BuildsStockPools;
    use CreatesChannelAccounts;

    private const SAUDI_PRODUCT = 50;

    private const KUWAITI_PRODUCT = 51;

    private const SAUDI_UNITS = 40;

    private const KUWAITI_UNITS = 10;

    private SalesChannelAccount $saudi;

    private SalesChannelAccount $kuwaiti;

    protected function setUp(): void
    {
        parent::setUp();

        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process and locks nothing.');
        }

        $this->buildSalesChannelSchema();
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();

        $this->saudi = $this->accountOf('1234567', 'SA');
        $this->kuwaiti = $this->accountOf('7654321', 'KW');
        // Both freshness windows the division reads are measured against the REAL clock in the workers (see the note in this file's docblock).
        $this->completeOrderSweep($this->saudi, $this->kuwaiti);

        $this->storeProduct(self::SAUDI_PRODUCT);
        $this->storeProduct(self::KUWAITI_PRODUCT, provider: 'm5azn');
        $this->providerFigure(self::SAUDI_PRODUCT, self::SAUDI_UNITS, now()->subMinutes(30));
        $this->providerFigure(self::KUWAITI_PRODUCT, self::KUWAITI_UNITS, now()->subMinutes(30), provider: 'm5azn');

        Http::fake(['apigw.trendyol.com/*' => Http::response(['batchRequestId' => 'batch-1'])]);
    }

    // ---- the world ----------------------------------------------------------------------------------------------------------------

    /** A Trendyol account of one market: the storefront code is the setting Trendyol keeps its own storefront under. */
    private function accountOf(string $sellerId, string $storefrontCode): SalesChannelAccount
    {
        $account = $this->trendyolAccount(sellerId: $sellerId);
        $account->forceFill(['settings' => ['storefront_code' => $storefrontCode, 'integration_name' => 'PharmacyHub']])->save();

        return $account->fresh();
    }

    /** The markets the products and the shelves carry, and the pool table keyed by one. Run per test, because the dormant case needs the schema without them. */
    private function marketsExist(): void
    {
        (require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php'))->up();
        (require database_path('migrations/2027_01_07_100000_add_market_to_inventory_pools.php'))->up();
        DB::table('products')->where('id', self::KUWAITI_PRODUCT)->update(['storefront_country' => 'KW']);
        $this->kuwaitiShelf()->forceFill(['storefront_country' => 'KW'])->save();
        // On in the PARENT too, not only in the workers: `poolsExist()` creates the rows through the same factory, and with the switch off
        // it would create the Kuwaiti twin's pool under the DEFAULT market and leave the workers to make a third row beside it.
        config(['gcc.storefronts.enabled' => true]);
        $this->forgetMarkets();
    }

    /** The Kuwaiti shelf. A shelf belongs to a market of its own (E11-F19), so two markets' twins of one SKU are one pool each, not two pools of one shelf. */
    private function kuwaitiShelf(): InventoryLocation
    {
        return app(InventoryLocationService::class)->ensureDefaults()[1];
    }

    private function shelfOf(int $productId): InventoryLocation
    {
        return $productId === self::KUWAITI_PRODUCT ? $this->kuwaitiShelf() : $this->torodLocation();
    }

    private function forgetMarkets(): void
    {
        app(StorefrontMarketRows::class)->forget();
        app(InventoryLocationMarket::class)->forget();
    }

    /** Both accounts linked to both products: the only world in which one market's units could reach the other's channel. */
    private function listEverythingOnBothAccounts(): void
    {
        foreach ([$this->saudi, $this->kuwaiti] as $account) {
            foreach ([self::SAUDI_PRODUCT, self::KUWAITI_PRODUCT] as $productId) {
                $this->stockListing($account, $productId, $this->shelfOf($productId), 'L-' . $account->external_account_id . '-' . $productId);
            }
        }
    }

    /**
     * Both markets' pool rows, created the way the single writer creates them, BEFORE a race writes to them.
     *
     * Not tidying-up: the first mark of two markets' pools of one shelf at the same instant is a race of two INSERTs into a nearly empty
     * unique index, and it was measured to lose a deadlock here (`SQLSTATE[40001] ... insert into inventory_pools ... location_id 1,
     * product_id 50`, MariaDB 10.11.14, the two workers inserting two DIFFERENT rows). That is the safe direction -- the transaction rolls
     * back and nothing is half written -- and it is a property of creating a row, not of dividing one, so it is recorded here rather than
     * silently absorbed. The state this file is about is the one an installation is in every moment after the first publish: the rows exist
     * and the race is over what they hold.
     */
    private function poolsExist(): void
    {
        foreach ([self::SAUDI_PRODUCT, self::KUWAITI_PRODUCT] as $productId) {
            app(PoolAllocationService::class)->allocate(PoolKey::ofListing(
                (new SalesChannelProductMapping())->forceFill(['product_id' => $productId, 'variant_key' => '']),
                (int) $this->shelfOf($productId)->id,
            ));
        }
    }

    /** @param array{product: int, storefronts?: bool} ...$extra @return array<string, mixed> the arguments of one worker, with its own market's shelf filled in */
    private function job(string $operation, int $productId, array $extra = []): array
    {
        return ['operation' => $operation, 'arguments' => $extra + ['product' => $productId, 'location' => (int) $this->shelfOf($productId)->id]];
    }

    /** @param list<array<string, mixed>> $jobs @return list<array<string, mixed>> */
    private function runTogether(array $jobs): array
    {
        // The harness runs one operation name for all its processes, and `stock.mixed` is the generic dispatcher that tells each one what to run.
        $reports = ConcurrentRunner::run('stock.mixed', $jobs);

        foreach ($reports as $index => $report) {
            $this->assertTrue($report['ok'], "worker {$index} failed: " . ($report['error'] ?? '') . ' ' . ($report['message'] ?? ''));
        }
        $this->assertTrue(ConcurrentRunner::ranAtTheSameTime($reports), 'the processes were not alive at the same time, so nothing was raced');

        return $reports;
    }

    /**
     * Which of the two worlds the database is in, said out loud: two pool rows, one per market, or one row holding both markets' units.
     *
     * This is the assertion that gives the rest of the file its meaning. Without it a green run is consistent with a single shared pool
     * whose arithmetic happened to come out right.
     */
    private function assertTwoPoolRows(): void
    {
        $rows = InventoryPool::orderBy('product_id')->get();

        $this->assertCount(2, $rows, 'one pool per market: a single row would mean the two markets met in it');
        $this->assertSame(
            [PoolKey::DEFAULT_MARKET, 'KW'],
            $rows->map(static fn (InventoryPool $pool): string => (string) ($pool->{PoolKey::MARKET_COLUMN} ?? ''))->all(),
            'the Saudi twin is the default market\'s pool (every pool that exists today is) and the Kuwaiti twin names KW',
        );
    }

    // ---- dormant: with the storefronts off, two processes divide the pools this platform has today ---------------------------------

    /**
     * The first test of the switch, as every switch here gets: with the storefronts OFF, two processes dividing the two products at once
     * behave exactly as they do on the platform today -- each pool keyed by the three columns it has always been keyed by, its text
     * carrying no market, and `inventory_pools` holding no market at all.
     */
    public function test_with_the_storefronts_off_two_processes_divide_the_pools_this_platform_has_today(): void
    {
        $this->listEverythingOnBothAccounts();

        $reports = $this->runTogether([
            $this->job('country_pool.divide', self::SAUDI_PRODUCT, ['storefronts' => false]),
            $this->job('country_pool.divide', self::KUWAITI_PRODUCT, ['storefronts' => false]),
        ]);

        $this->assertSame(
            [$this->torodLocation()->id . ':' . self::SAUDI_PRODUCT . ':', $this->kuwaitiShelf()->id . ':' . self::KUWAITI_PRODUCT . ':'],
            array_column(array_column($reports, 'result'), 'pool'),
            'with the switch off a pool key is byte for byte the one it has always been, which is what every listing has persisted',
        );
        $this->assertFalse(
            app(StorefrontMarketRows::class)->tableHasTheColumn('inventory_pools'),
            'and before its migration the pool table is not keyed by a market, so there is nothing for the switch to read',
        );
    }

    // ---- the criterion: bought in one market, synced in the other, at the same instant --------------------------------------------

    /**
     * Saudi Arabia buys six of its own units while Kuwait divides and publishes its twin of the same SKU, in two processes at once.
     *
     * What must hold: Kuwait's net is its own 10, untouched by a sale that happened in Saudi Arabia in the same instant; Saudi Arabia's is
     * its own 40 less the six it sold; and each process addressed its own market's row.
     */
    public function test_a_sale_in_one_market_and_a_division_in_the_other_at_once_leave_each_markets_stock_to_itself(): void
    {
        $this->marketsExist();
        $this->listEverythingOnBothAccounts();
        $this->poolsExist();

        $reports = $this->runTogether([
            $this->job('country_pool.buy', self::SAUDI_PRODUCT, ['storefronts' => true, 'quantity' => 6, 'reference' => 'sa-sale', 'account' => (int) $this->saudi->id]),
            $this->job('country_pool.divide', self::KUWAITI_PRODUCT, ['storefronts' => true]),
        ]);

        [$sale, $kuwait] = array_column($reports, 'result');

        $this->assertSame($this->torodLocation()->id . ':' . self::SAUDI_PRODUCT . ':', $sale['pool'], 'the Saudi twin is the default market\'s pool');
        $this->assertSame($this->kuwaitiShelf()->id . ':' . self::KUWAITI_PRODUCT . ':@KW', $kuwait['pool'], 'and the Kuwaiti twin is a pool of its own');
        $this->assertSame(self::SAUDI_UNITS - 6, $sale['net'], 'Saudi Arabia is left with its own 40 less the 6 it sold');
        $this->assertSame(self::KUWAITI_UNITS, $kuwait['net'], 'Kuwait keeps all 10 of its own: the Saudi sale never reached it');
        $this->assertSame([self::KUWAITI_UNITS], array_values($kuwait['publish']), 'and the whole of Kuwait\'s stock went to the one Kuwaiti listing');
        $this->assertTwoPoolRows();
    }

    /**
     * Both markets selling and both dividing at once, four processes off the common barrier, asserting the invariant over whatever
     * interleaving the machine picks: no market is ever shown a unit more than it holds, and the holds of one never land on the other.
     */
    public function test_four_processes_buying_and_dividing_in_two_markets_never_show_a_market_more_than_it_holds(): void
    {
        $this->marketsExist();
        $this->listEverythingOnBothAccounts();
        $this->poolsExist();

        $reports = $this->runTogether([
            $this->job('country_pool.buy', self::SAUDI_PRODUCT, ['storefronts' => true, 'quantity' => 2, 'times' => 5, 'reference' => 'sa', 'account' => (int) $this->saudi->id, 'pause_microseconds' => 2000]),
            $this->job('country_pool.buy', self::KUWAITI_PRODUCT, ['storefronts' => true, 'quantity' => 1, 'times' => 4, 'reference' => 'kw', 'account' => (int) $this->kuwaiti->id, 'pause_microseconds' => 2000]),
            $this->job('country_pool.divide', self::SAUDI_PRODUCT, ['storefronts' => true]),
            $this->job('country_pool.divide', self::KUWAITI_PRODUCT, ['storefronts' => true]),
        ]);

        [$saudiSale, $kuwaitiSale] = array_column($reports, 'result');

        $this->assertSame(10, $saudiSale['held'], 'five Saudi sales of two units each');
        $this->assertSame(4, $kuwaitiSale['held'], 'four Kuwaiti sales of one');
        $this->assertGreaterThanOrEqual(self::SAUDI_UNITS - 10, $saudiSale['net'], 'the Saudi net is its own 40 less its own holds, and never less');
        $this->assertLessThanOrEqual(self::SAUDI_UNITS, (int) $saudiSale['net']);
        $this->assertGreaterThanOrEqual(self::KUWAITI_UNITS - 4, $kuwaitiSale['net'], 'and the Kuwaiti net its own 10 less its own four: Saudi Arabia\'s ten holds are not among them');
        $this->assertLessThanOrEqual(self::KUWAITI_UNITS, (int) $kuwaitiSale['net']);

        $this->assertSame(
            [self::SAUDI_UNITS - 10, self::KUWAITI_UNITS - 4],
            [
                $this->netOf(self::SAUDI_PRODUCT),
                $this->netOf(self::KUWAITI_PRODUCT),
            ],
            'once every process has committed, each market holds exactly what it started with less what it sold',
        );
        $this->assertTwoPoolRows();
    }

    /**
     * On the wire, in the same instant: the Saudi account is sent its own market's figure for its own product and nothing of the Kuwaiti
     * twin, while Kuwait's pool is being divided beside it.
     *
     * The Kuwaiti account sends nothing here for a reason of its own, and it is asserted rather than worked around: Kuwait is not open for
     * sale, so `ChannelAccountMarketStop` makes no write call for it at all (H2-12), BEFORE the pool is consulted. That is a second,
     * independent protection, and a test that could not tell it apart from a pool that gave the account no units would prove neither.
     */
    public function test_each_markets_account_is_sent_its_own_markets_figure_while_the_other_market_is_being_divided(): void
    {
        $this->marketsExist();
        $this->listEverythingOnBothAccounts();

        $reports = $this->runTogether([
            ['operation' => 'country_pool.publish', 'arguments' => ['account' => (int) $this->saudi->id, 'storefronts' => true]],
            ['operation' => 'country_pool.publish', 'arguments' => ['account' => (int) $this->kuwaiti->id, 'storefronts' => true]],
            $this->job('country_pool.divide', self::KUWAITI_PRODUCT, ['storefronts' => true]),
        ]);

        [$saudi, $kuwait] = array_column($reports, 'result');

        $this->assertSame(
            ['L-' . $this->saudi->external_account_id . '-' . self::SAUDI_PRODUCT => self::SAUDI_UNITS],
            $saudi['sent'],
            'the Saudi account is sent its own product and nothing of the Kuwaiti one, whose listing it also holds',
        );
        $this->assertSame([], $kuwait['sent'], 'and the Kuwaiti account sends nothing');
        $this->assertSame('market_stopped', $kuwait['detail'], 'because Kuwait is not open for sale, which is a separate refusal from the pool\'s market');
        $this->assertTwoPoolRows();
    }

    private function netOf(int $productId): int
    {
        return (int) app(\App\Services\SalesChannels\Inventory\PoolNet::class)->for($this->shelfOf($productId), $productId)->net();
    }
}
