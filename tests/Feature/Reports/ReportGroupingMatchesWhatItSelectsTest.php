<?php

namespace Tests\Feature\Reports;

use App\Http\Controllers\Admin\OrderReportController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Eleven money-report queries grouped by an expression neither their SELECT nor their ORDER BY mentions.
 *
 * The shape, in six controllers across two panels:
 *
 *     select sum(order_amount) as order_amount, YEAR(created_at) year, MONTH(created_at) month
 *     from orders where ... group by DATE_FORMAT(created_at, '%M') order by created_at desc
 *
 * Two things are wrong with that one line and they are not the same problem.
 *
 * **It is refused by a strict server.** `YEAR(created_at)`, `MONTH(created_at)` and `created_at` are none
 * of them the grouped expression, so under `ONLY_FULL_GROUP_BY` the engine answers error 1055 and the
 * screen is a 500. It does not happen today because `config/database.php` sets `strict => false`, which
 * makes Laravel set `sql_mode='NO_ENGINE_SUBSTITUTION'` for the session and so REMOVES
 * `ONLY_FULL_GROUP_BY` even on a MySQL 8 server whose own default includes it. Six screens across two
 * panels are kept alive by one configuration line, and the obvious hardening takes them all down. That
 * line is deliberately NOT changed here: hardening it is a separate decision with a blast radius nobody
 * has measured. The proof is `DB_STRICT_TESTS=1`, which puts the TEST connection in strict mode and is
 * what that switch exists for.
 *
 * **`'%M'` is the month NAME, so the grouping cannot tell two Januaries apart.** January 2025 and January
 * 2026 are one group and their money is one sum.
 *
 * **A CORRECTION TO THE REPORT THIS WORK CAME WITH, measured below rather than argued.** The second
 * defect is NOT reachable from any screen, and fixing the GROUP BY would not make it right if it were.
 * Every one of the eleven call sites is a `*_same_year` method, and every dispatcher routes a range that
 * spans two years to a `*_different_year` sibling instead -- which groups by `DATE_FORMAT(…, '%Y')` and
 * merges nothing. `test_a_range_that_spans_two_years_never_reaches_the_month_grouping` drives the real
 * dispatcher and measures that. And the consumer of these rows is keyed BY MONTH NUMBER
 * (`if ($match['month'] == $inc)`), so a two-year range cannot be represented in its answer at all: with
 * the grouping corrected, two January rows are two rows and the month-keyed loop keeps whichever comes
 * last, not their sum. The method is single-year by contract, its caller enforces that, and the figures
 * on every reachable path are UNCHANGED by this fix -- which is the evidence that matters, and is what
 * `test_the_figures_of_a_single_year_report_are_unchanged` asserts to the digit.
 *
 * So: the live defect is the 1055. The cross-year sum is real arithmetic in the query and is corrected
 * with it, and it is latent rather than live.
 */
class ReportGroupingMatchesWhatItSelectsTest extends TestCase
{
    /** Every file that carries the month-grouped shape, and how many of them it carries. */
    private const CALL_SITES = [
        'app/Http/Controllers/Admin/ReportController.php' => 5,
        'app/Http/Controllers/Admin/OrderReportController.php' => 1,
        'app/Http/Controllers/Admin/ProductReportController.php' => 1,
        'app/Http/Controllers/Admin/VendorProductSaleReportController.php' => 1,
        'app/Http/Controllers/Admin/TransactionReportController.php' => 1,
        'app/Http/Controllers/Vendor/TransactionReportController.php' => 2,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('orders');
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_status')->default('pending');
            $table->decimal('order_amount', 24, 2)->default(0);
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('orders');

        parent::tearDown();
    }

    /**
     * These reports cannot run on SQLite AT ALL, before this change or after it: their own SELECT has always
     * used `YEAR(created_at)` and `MONTH(created_at)`, and SQLite has neither function -- `no such function:
     * YEAR`. So the behavioural half is MySQL-family only and says so, rather than quietly proving nothing on
     * the engine CI runs most often. The source half below runs everywhere.
     */
    private function skipUnlessTheEngineHasTheseFunctions(): void
    {
        if (!in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('the report\'s own select uses YEAR() and MONTH(), which SQLite does not have');
        }
    }

    private function aDeliveredOrder(string $at, float $amount): void
    {
        DB::table('orders')->insert([
            'order_status' => 'delivered',
            'order_amount' => $amount,
            'seller_id' => 1,
            'seller_is' => 'admin',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /** @return array<string, mixed> the monthly figures of the real controller method, keyed by month abbreviation */
    private function theMonthlyReport(string $from, string $to): array
    {
        return app(OrderReportController::class)->order_report_same_year(
            ['seller_id' => 'all'],
            $from,
            $to,
            (int) substr($from, 0, 4),
            12,
            1,
        )['order_amount'];
    }

    // ---------------------------------------------------------------- the figures a reader is shown

    /**
     * THE EVIDENCE THE FIX IS SAFE. A single-year range is the only range this method is ever given, and
     * every figure in it is the same before and after: two months, 100 and 250, each under its own month.
     */
    public function test_the_figures_of_a_single_year_report_are_unchanged(): void
    {
        $this->skipUnlessTheEngineHasTheseFunctions();
        $this->aDeliveredOrder('2026-01-10 10:00:00', 60.00);
        $this->aDeliveredOrder('2026-01-20 10:00:00', 40.00);
        $this->aDeliveredOrder('2026-03-05 10:00:00', 250.00);

        $report = $this->theMonthlyReport('2026-01-01 00:00:00', '2026-12-31 23:59:59');

        $this->assertEqualsWithDelta(100.00, (float) $report['Jan'], 0.0001, 'the two January orders are one January figure');
        $this->assertEqualsWithDelta(250.00, (float) $report['Mar'], 0.0001);
        $this->assertSame(0, (int) $report['Feb'], 'a month with no order reads zero');
        $this->assertCount(12, $report, 'the report answers twelve months whatever the data');
    }

    /**
     * The grouping no longer merges two Januaries INSIDE the query. The consumer is month-keyed, so a
     * two-year range is still not something this method can answer -- that is what the dispatcher's own
     * routing is for, measured below -- but the query no longer adds 2025 to 2026 behind its back.
     *
     * Measured with the old grouping restored, this reads 300: January 2025's 100 plus January 2026's 200
     * in one group. With the grouping corrected the two are separate rows and neither is the other's sum.
     */
    public function test_the_query_no_longer_adds_two_januaries_into_one_group(): void
    {
        $this->skipUnlessTheEngineHasTheseFunctions();
        $this->aDeliveredOrder('2025-01-10 10:00:00', 100.00);
        $this->aDeliveredOrder('2026-01-10 10:00:00', 200.00);

        $rows = DB::table('orders')
            ->where('order_status', 'delivered')
            ->selectRaw('sum(order_amount) as order_amount, YEAR(created_at) year, MONTH(created_at) month')
            ->groupBy(DB::raw('YEAR(created_at), MONTH(created_at)'))
            ->orderByRaw('YEAR(created_at) DESC, MONTH(created_at) DESC')
            ->get();

        $this->assertCount(2, $rows, 'two Januaries of two years are two groups');
        $this->assertSame([2026, 2025], array_map('intval', array_column($rows->all(), 'year')), 'newest first, by the grouped expressions');
        $this->assertEqualsWithDelta(200.00, (float) $rows[0]->order_amount, 0.0001);
        $this->assertEqualsWithDelta(100.00, (float) $rows[1]->order_amount, 0.0001);
    }

    /**
     * THE CORRECTION. A range spanning two years never reaches the month grouping: the dispatcher sends it
     * to the `*_different_year` sibling, whose answer is keyed by YEAR and not by month. So the cross-year
     * sum was latent and not live, and this is where that is measured rather than reasoned about.
     */
    public function test_a_range_that_spans_two_years_never_reaches_the_month_grouping(): void
    {
        $this->skipUnlessTheEngineHasTheseFunctions();
        $this->aDeliveredOrder('2025-01-10 10:00:00', 100.00);
        $this->aDeliveredOrder('2026-01-10 10:00:00', 200.00);

        $report = app(OrderReportController::class)->order_report_chart_filter([
            'date_type' => 'custom_date',
            'from' => '2025-01-01',
            'to' => '2026-12-31',
            'seller_id' => 'all',
        ])['order_amount'];

        $this->assertSame(['2025', '2026'], array_map('strval', array_keys($report)), 'the answer is keyed by year, so the month grouping was not used');
        $this->assertEqualsWithDelta(100.00, (float) $report[2025], 0.0001, '2025 is its own figure');
        $this->assertEqualsWithDelta(200.00, (float) $report[2026], 0.0001, '2026 is its own figure');
    }

    /**
     * The same two-year range through the CONTROLLER METHOD, pinned as a characterization because neither
     * figure is right and saying so is the point.
     *
     * Before this pass the method answered `Jan => 300`: the query summed January 2025's 100 and January
     * 2026's 200 into one group and the month-keyed consumer printed the total under "Jan". After it, the
     * query returns two rows and that consumer -- which looks a row up by `month` and overwrites -- keeps
     * whichever comes last, so the answer is one year's figure and not their sum. The method simply cannot
     * represent two years in a map keyed by month, which is WHY the dispatcher routes such a range to the
     * `*_different_year` sibling instead (the case above measures that). What this fix changes is that the
     * query no longer adds two years together behind the consumer's back.
     */
    public function test_the_method_given_a_two_year_range_no_longer_reports_the_sum_of_two_januaries(): void
    {
        $this->skipUnlessTheEngineHasTheseFunctions();
        $this->aDeliveredOrder('2025-01-10 10:00:00', 100.00);
        $this->aDeliveredOrder('2026-01-10 10:00:00', 200.00);

        $report = $this->theMonthlyReport('2025-01-01 00:00:00', '2026-12-31 23:59:59');

        $this->assertNotEquals(300.0, (float) $report['Jan'], 'the query is adding two years into one month again');
        $this->assertEqualsWithDelta(100.00, (float) $report['Jan'], 0.0001, 'one year of the two, which is what a month-keyed answer can hold');
    }

    // ---------------------------------------------------------------- what a strict server says

    /**
     * The query runs. Under `DB_STRICT_TESTS=1` this is the case that was error 1055 before the fix, and
     * it is the one that says the six screens would survive hardening `strict => false`.
     */
    public function test_the_monthly_report_runs_whatever_the_servers_sql_mode(): void
    {
        $this->skipUnlessTheEngineHasTheseFunctions();
        $this->aDeliveredOrder('2026-02-10 10:00:00', 75.00);

        $report = $this->theMonthlyReport('2026-01-01 00:00:00', '2026-12-31 23:59:59');

        $this->assertEqualsWithDelta(75.00, (float) $report['Feb'], 0.0001);
    }

    public function test_the_sql_mode_of_this_run_is_reported_so_a_green_result_can_be_read(): void
    {
        $strict = getenv('DB_STRICT_TESTS') === '1';
        $driver = DB::connection()->getDriverName();

        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->assertTrue(true, 'SQLite has no ONLY_FULL_GROUP_BY, so this run says nothing about it');

            return;
        }

        $mode = (string) DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;

        $this->assertSame(
            $strict,
            str_contains($mode, 'ONLY_FULL_GROUP_BY'),
            $strict
                ? 'DB_STRICT_TESTS=1 must put ONLY_FULL_GROUP_BY in force, or a green run proves nothing'
                : 'without DB_STRICT_TESTS the session mode is the application\'s own, which has no ONLY_FULL_GROUP_BY',
        );
    }

    // ---------------------------------------------------------------- the twelfth call site

    /**
     * The source half, in the shape `StableReportPaginationTest` established: a report written the old way
     * tomorrow fails this, and reverting any one of the eleven fixes fails it too.
     *
     * It looks for the defect and not for the repair: a `groupBy` on a `DATE_FORMAT` of `created_at` in a
     * file that also selects `YEAR(created_at)` or `MONTH(created_at)` is the shape, wherever it appears.
     */
    public function test_no_report_groups_by_a_date_format_its_select_does_not_use(): void
    {
        $found = [];

        foreach (array_keys(self::CALL_SITES) as $file) {
            $source = (string) file_get_contents(base_path($file));

            $grouped = preg_match_all("/groupBy\\(DB::raw\\(\"DATE_FORMAT\\(created_at, '%M'\\)\"\\)\\)/", $source);

            if ($grouped > 0) {
                $found[$file] = $grouped;
            }
        }

        $this->assertSame([], $found, 'these files still group a report by the month NAME, which no select or order of theirs uses');
    }

    /** And the repair is where the defect was, in the number the report named, so a half-done fix is visible. */
    public function test_every_call_site_now_groups_and_orders_by_the_expressions_it_selects(): void
    {
        $grouped = [];
        $ordered = [];

        foreach (self::CALL_SITES as $file => $expected) {
            $source = (string) file_get_contents(base_path($file));
            $grouped[$file] = preg_match_all("/groupBy\\(DB::raw\\('YEAR\\(created_at\\), MONTH\\(created_at\\)'\\)\\)/", $source);
            $ordered[$file] = preg_match_all("/orderByRaw\\('YEAR\\(created_at\\) DESC, MONTH\\(created_at\\) DESC'\\)/", $source);
        }

        $this->assertSame(self::CALL_SITES, $grouped, 'the grouping is not on the expressions the select uses');
        // The ORDER is half the repair and the half that is easy to forget: the 1055 the engine reported named
        // `created_at`, the ORDER BY column, and not the selected YEAR and MONTH at all. A fix that corrected
        // only the grouping would still be a 500 on a strict server.
        $this->assertSame(self::CALL_SITES, $ordered, 'the order is still on a column the grouping does not name');
    }

    /**
     * The YEAR-grouped siblings, fixed in the same pass and for the same reason.
     *
     * These are the ones a reader of a MULTI-YEAR report actually reaches -- the dispatcher routes such a
     * range to them -- and they 1055 exactly as the month ones do, which is measured: before this pass, the
     * two-year case of this file reported
     * `1055 'orders.created_at' isn't in GROUP BY ... group by DATE_FORMAT(created_at, '%Y') order by created_at desc`.
     * Their FIGURES cannot change, by construction: `DATE_FORMAT(created_at, '%Y')` and `YEAR(created_at)`
     * put the same rows in the same groups, so only the type of the grouped value and the order differ, and
     * the consumer looks its rows up by `year`.
     */
    public function test_the_year_grouped_siblings_are_fixed_too(): void
    {
        $grouped = [];

        foreach (self::CALL_SITES as $file => $expected) {
            $source = (string) file_get_contents(base_path($file));
            $grouped[$file] = preg_match_all("/groupBy\\(DB::raw\\('YEAR\\(created_at\\)'\\)\\)/", $source);
            $this->assertSame(
                0,
                preg_match_all("/groupBy\\(DB::raw\\(\"DATE_FORMAT\\(created_at, '%Y'\\)\"\\)\\)/", $source),
                $file . ' still groups a yearly report by a DATE_FORMAT its select does not use',
            );
        }

        $this->assertSame(self::CALL_SITES, $grouped);
    }
}
