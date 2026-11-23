<?php

namespace Tests\Feature\Storefront;

use App\Services\Commerce\SegmentResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsProductCatalog;
use Tests\TestCase;

/**
 * What the numbers behind a customer segment are read over, now that an order records the storefront it was sold in and the currency it was
 * priced in (GCC-05, the storefront snapshot of the orders table).
 *
 * SegmentResolver::metricsFor caches those numbers under the id of the customer alone, which ThemeCacheKeysAreStorefrontAwareTest declares a
 * global cache: one customer identity serves the six storefronts. Three of the four numbers are a count, a recency and an age, and are the
 * customer's own whichever storefront took the order. The fourth, total_spent, is a plain SUM of order_amount over orders that each carry
 * their own currency, so it is a figure in no one currency — an admin rule that compares it against a threshold is comparing riyals added to
 * dirhams. This holds that fact where the declaration can be checked against it: the day the metrics are narrowed per storefront or converted
 * through the rate the order stores, this fails and the declaration must be written again.
 */
class CustomerSegmentMetricsTest extends TestCase
{
    use BuildsProductCatalog;

    private const CUSTOMER = 51;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildTables();
        $this->runOrderSnapshotMigration();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        foreach (['orders', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function buildTables(): void
    {
        $this->refuseToDropCatalogTablesOutsideATestDatabase(connection: DB::connection());

        foreach (['orders', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->decimal('order_amount', 16, 3)->default(0);
            $table->timestamps();
        });
    }

    private function runOrderSnapshotMigration(): void
    {
        $migration = require database_path('migrations/2026_11_26_100000_add_storefront_snapshot_to_orders_table.php');
        $migration->up();
    }

    /** One customer, who bought four hundred riyals on the Saudi storefront and four hundred dirhams on the Emirati one. */
    private function giveTheCustomerAnOrderInTwoCurrencies(): void
    {
        DB::table('users')->insert(['id' => self::CUSTOMER, 'name' => 'A customer of two countries', 'created_at' => now()->subYear(), 'updated_at' => now()]);

        foreach ([['SA', 'SAR', 1], ['AE', 'AED', 1]] as $index => [$country, $currency, $rate]) {
            DB::table('orders')->insert([
                'id' => 700 + $index, 'customer_id' => self::CUSTOMER, 'is_guest' => 0, 'order_amount' => 400,
                'storefront_country' => $country, 'currency_code' => $currency, 'currency_rate' => $rate,
                'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3),
            ]);
        }
    }

    public function test_the_orders_a_customers_metrics_are_read_over_each_carry_the_storefront_and_the_currency_they_were_sold_in(): void
    {
        $this->giveTheCustomerAnOrderInTwoCurrencies();

        $sold = DB::table('orders')->where('customer_id', self::CUSTOMER)->orderBy('id')->get(['storefront_country', 'currency_code']);

        $this->assertSame(['SA', 'AE'], $sold->pluck('storefront_country')->all(), 'the orders of one customer belong to two storefronts');
        $this->assertSame(['SAR', 'AED'], $sold->pluck('currency_code')->all(), 'and are priced in two currencies');
    }

    public function test_the_money_a_segment_rule_reads_is_summed_over_the_currencies_the_orders_were_taken_in(): void
    {
        $this->giveTheCustomerAnOrderInTwoCurrencies();

        $metrics = app(SegmentResolver::class)->metricsFor(self::CUSTOMER);

        $this->assertSame(2, $metrics['orders_count'], 'both orders are counted, which is the customer over every storefront and is right');
        $this->assertSame(
            800.0,
            $metrics['total_spent'],
            'four hundred riyals and four hundred dirhams are added up to eight hundred of nothing: total_spent is a figure in no one currency, and a rule that compares it against a threshold is comparing them added together. Narrowing or converting the sum is an owner decision; until it is taken, the declaration of commerce_segment_ctx_* in ThemeCacheKeysAreStorefrontAwareTest must keep saying so.',
        );
    }

    public function test_the_metrics_are_cached_under_the_id_of_the_customer_alone_so_the_six_storefronts_read_the_one_figure(): void
    {
        $this->giveTheCustomerAnOrderInTwoCurrencies();

        app(SegmentResolver::class)->metricsFor(self::CUSTOMER);

        $this->assertTrue(Cache::has('commerce_segment_ctx_' . self::CUSTOMER), 'the key the cache lint declares global is the one the resolver writes under');
    }
}
