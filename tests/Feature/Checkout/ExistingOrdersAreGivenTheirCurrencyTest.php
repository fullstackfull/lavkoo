<?php

namespace Tests\Feature\Checkout;

use App\Services\Checkout\OrderCurrencyBackfill;
use App\Services\Storefront\OrderStorefrontSnapshot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The orders placed before an order recorded what it was sold in are given that record, and not one amount moves (D1-06).
 *
 * Every order on this platform carries no currency: the snapshot columns arrived after them, so a reader falls back to
 * today's settings and today's rate -- fine for one market, wrong the day there are two, because an invoice reprinted
 * next year would be read against next year's settings.
 *
 * The thing being proved is not that a column gets filled. It is the two claims that make filling it safe:
 *
 *  - **no amount changes.** The sum of every order's amount is read before and after and the two are identical to the
 *    digit, and the run reports that it reconciled. The test reads the amounts itself as well, row by row;
 *  - **nothing is guessed.** Under `multi_currency` the currency a customer was shown came from their session and no
 *    row remembers it, so the run REFUSES and names the reason instead of stamping a currency somebody could reprint
 *    an invoice from. That refusal is a feature of the backfill and has three cases here.
 */
class ExistingOrdersAreGivenTheirCurrencyTest extends TestCase
{
    use RestoresLanguageFiles;

    /** The riyal's row, which the platform's `system_default_currency` names. */
    private const DEFAULT_CURRENCY_ID = 2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildTheOrdersOfAPlatformWithNoCurrencyRecord();
        $this->runTheRealSnapshotMigrations();
        $this->aSingleCurrencyPlatform();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** The tables an order's money lives in, with no snapshot column on any of them: the platform before GCC-05. */
    private function buildTheOrdersOfAPlatformWithNoCurrencyRecord(): void
    {
        foreach (['orders', 'order_details', 'order_transactions', 'refund_requests', 'refund_transactions', 'currencies', 'business_settings'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->decimal('order_amount', 40, 20)->default(0);
            $table->string('order_status')->default('pending');
            $table->timestamps();
        });

        foreach (['order_details', 'order_transactions', 'refund_requests', 'refund_transactions'] as $table) {
            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->id();
                $blueprint->unsignedBigInteger('order_id')->nullable();
                $blueprint->decimal('amount', 40, 20)->default(0);
                $blueprint->timestamps();
            });
        }

        Schema::create('currencies', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('symbol')->nullable();
            $table->string('code')->nullable();
            $table->decimal('exchange_rate', 20, 8)->default(1);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        DB::table('currencies')->insert([
            ['id' => 1, 'name' => 'USD', 'symbol' => '$', 'code' => 'USD', 'exchange_rate' => 1, 'status' => 1],
            ['id' => self::DEFAULT_CURRENCY_ID, 'name' => 'SAR', 'symbol' => 'SAR', 'code' => 'SAR', 'exchange_rate' => 3.75, 'status' => 1],
        ]);

        Schema::create('business_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    /** The REAL migrations, never a shape this test invented: the order's own record, and the money rows'. */
    private function runTheRealSnapshotMigrations(): void
    {
        (require database_path('migrations/2026_11_26_100000_add_storefront_snapshot_to_orders_table.php'))->up();
        (require database_path('migrations/2026_11_27_100000_add_order_currency_to_order_money_rows.php'))->up();
    }

    private function aSingleCurrencyPlatform(): void
    {
        $this->setting('currency_model', 'single_currency');
        $this->setting('system_default_currency', (string) self::DEFAULT_CURRENCY_ID);
        config(['gcc.storefronts.default_country' => 'SA']);
    }

    private function setting(string $type, string $value): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => $type], ['value' => $value, 'updated_at' => now()]);
        clearWebConfigCacheKeys();
    }

    /** An order of the platform's history: an amount, lines and money rows, and no record of anything. */
    private function anOldOrder(int $id, float $amount): void
    {
        DB::table('orders')->insert(['id' => $id, 'customer_id' => 1, 'order_amount' => $amount, 'created_at' => now()->subYear(), 'updated_at' => now()->subYear()]);

        foreach (OrderStorefrontSnapshot::MONEY_ROW_TABLES as $table) {
            DB::table($table)->insert(['order_id' => $id, 'amount' => $amount, 'created_at' => now()->subYear(), 'updated_at' => now()->subYear()]);
        }
    }

    private function backfill(): OrderCurrencyBackfill
    {
        return app(OrderCurrencyBackfill::class);
    }

    /** @return array<int, array{country: ?string, currency: ?string, rate: ?string, amount: string}> */
    private function theOrders(): array
    {
        return DB::table('orders')->orderBy('id')->get()->mapWithKeys(static fn (object $row): array => [
            (int) $row->id => [
                'country' => $row->storefront_country,
                'currency' => $row->currency_code,
                'rate' => $row->currency_rate === null ? null : (string) $row->currency_rate,
                'amount' => (string) $row->order_amount,
            ],
        ])->all();
    }

    // ---------------------------------------------------------------- it refuses rather than guesses

    public function test_it_refuses_on_a_platform_that_converts_from_a_base_currency_and_names_the_reason(): void
    {
        $this->setting('currency_model', 'multi_currency');
        $this->anOldOrder(1, 100.0);

        $this->assertSame(OrderCurrencyBackfill::REFUSED_MULTI_CURRENCY, $this->backfill()->refusal());
        $this->assertSame(0, $this->backfill()->apply()['orders']);
        $this->assertNull($this->theOrders()[1]['currency'], 'a currency was stamped on a platform where it cannot be known');
    }

    public function test_it_refuses_before_the_snapshot_migration_has_run(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['storefront_country', 'currency_code', 'currency_rate']));
        $this->anOldOrder(1, 100.0);

        $this->assertSame(OrderCurrencyBackfill::REFUSED_NO_COLUMNS, $this->backfill()->refusal());
        $this->assertSame(OrderCurrencyBackfill::REFUSED_NO_COLUMNS, $this->backfill()->plan()['refusal']);
    }

    public function test_it_refuses_when_the_default_currency_row_is_gone(): void
    {
        DB::table('currencies')->where('id', self::DEFAULT_CURRENCY_ID)->delete();
        $this->anOldOrder(1, 100.0);

        $this->assertSame(OrderCurrencyBackfill::REFUSED_NO_DEFAULT_CURRENCY, $this->backfill()->refusal());
        $this->assertNull($this->theOrders()[1]['currency']);
    }

    // ---------------------------------------------------------------- a read until somebody says otherwise

    public function test_a_plan_reports_what_it_would_do_and_writes_nothing(): void
    {
        $this->anOldOrder(1, 100.0);
        $this->anOldOrder(2, 50.5);

        $plan = $this->backfill()->plan();

        $this->assertSame(2, $plan['orders']);
        $this->assertSame('SAR', $plan['currency']);
        $this->assertSame('SA', $plan['country']);
        // Two orders, one row of each table each: four tables waiting on two rows apiece.
        $this->assertSame([2, 2, 2, 2], array_values($plan['money_rows']));
        $this->assertNull($this->theOrders()[1]['currency'], 'a plan wrote to the orders');
    }

    public function test_the_command_reports_and_writes_nothing_without_the_write_option(): void
    {
        $this->anOldOrder(1, 100.0);

        $this->artisan('orders:backfill-currency')->assertExitCode(0);

        $this->assertNull($this->theOrders()[1]['currency']);
    }

    // ---------------------------------------------------------------- the record, and not one amount

    public function test_it_records_the_default_market_and_currency_and_moves_no_amount(): void
    {
        $this->anOldOrder(1, 100.0);
        $this->anOldOrder(2, 50.5);
        $amountsBefore = array_map(static fn (array $order): string => $order['amount'], $this->theOrders());

        $done = $this->backfill()->apply();

        $this->assertSame(2, $done['orders']);
        $this->assertTrue($done['balances'], 'the run did not reconcile');
        $this->assertSame($done['amount_before'], $done['amount_after'], 'the total of the orders moved');

        foreach ($this->theOrders() as $id => $order) {
            $this->assertSame('SA', $order['country']);
            $this->assertSame('SAR', $order['currency']);
            $this->assertEqualsWithDelta(1.0, (float) $order['rate'], 0.0, 'the rate must be one: the stored amount is already in that currency');
            $this->assertSame($amountsBefore[$id], $order['amount'], "order {$id}'s amount was rewritten");
        }
    }

    public function test_the_money_rows_of_a_stamped_order_carry_the_same_currency(): void
    {
        $this->anOldOrder(1, 100.0);

        $done = $this->backfill()->apply();

        $this->assertSame([1, 1, 1, 1], array_values($done['money_rows']));
        foreach (OrderStorefrontSnapshot::MONEY_ROW_TABLES as $table) {
            $row = DB::table($table)->where('order_id', 1)->first();
            $this->assertSame('SAR', $row->currency_code, $table . ' did not take the order\'s currency');
            $this->assertEqualsWithDelta(1.0, (float) $row->currency_rate, 0.0, $table);
            $this->assertEqualsWithDelta(100.0, (float) $row->amount, 0.0, $table . "'s amount was rewritten");
        }
    }

    /** An order that already says what it was sold in knows better than this does, and is never rewritten. */
    public function test_an_order_that_already_carries_a_record_is_left_alone(): void
    {
        $this->anOldOrder(1, 100.0);
        DB::table('orders')->where('id', 1)->update(['storefront_country' => 'KW', 'currency_code' => 'KWD', 'currency_rate' => 1]);
        $this->anOldOrder(2, 50.0);

        $done = $this->backfill()->apply();

        $this->assertSame(1, $done['orders'], 'an order that already had a record was counted');
        $this->assertSame('KWD', $this->theOrders()[1]['currency'], "a Kuwaiti order was rewritten as the default market's");
        $this->assertSame('SAR', $this->theOrders()[2]['currency']);
    }

    public function test_a_second_run_records_nothing_further(): void
    {
        $this->anOldOrder(1, 100.0);

        $this->backfill()->apply();
        $second = $this->backfill()->apply();

        $this->assertSame(0, $second['orders']);
        $this->assertSame([0, 0, 0, 0], array_values($second['money_rows']));
        $this->assertTrue($second['balances']);
    }

    public function test_a_limit_records_that_many_oldest_first(): void
    {
        foreach ([1, 2, 3] as $id) {
            $this->anOldOrder($id, 10.0 * $id);
        }

        $done = $this->backfill()->apply(limit: 2);

        $this->assertSame(2, $done['orders']);
        $orders = $this->theOrders();
        $this->assertSame(['SAR', 'SAR', null], [$orders[1]['currency'], $orders[2]['currency'], $orders[3]['currency']]);
    }

    public function test_the_command_writes_the_record_and_says_it_reconciled(): void
    {
        $this->anOldOrder(1, 100.0);

        $this->artisan('orders:backfill-currency', ['--write' => true])
            ->expectsOutputToContain('Reconciled')
            ->assertExitCode(0);

        $this->assertSame('SAR', $this->theOrders()[1]['currency']);
    }

    public function test_the_command_exits_failure_when_it_refuses(): void
    {
        $this->setting('currency_model', 'multi_currency');
        $this->anOldOrder(1, 100.0);

        $this->artisan('orders:backfill-currency', ['--write' => true])->assertExitCode(1);

        $this->assertNull($this->theOrders()[1]['currency']);
    }
}
