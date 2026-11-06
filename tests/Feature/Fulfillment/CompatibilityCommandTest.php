<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\ProviderCode;
use App\Services\Fulfillment\Readiness\Compatibility\CheckoutSection;
use App\Services\Fulfillment\Readiness\Compatibility\ReadOnlyProbe;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The read-only compatibility validation of a restored database: what it finds in a good and in a broken schema, what it
 * does about odd data, that it only ever reads, and that nothing personal or secret can reach its report.
 */
class CompatibilityCommandTest extends FulfillmentTestCase
{
    /** Values that must never appear anywhere in the output. */
    private const CANARIES = [
        'CANARY-NAME-Qx7', '0599900111', 'canary.customer@leak.test', 'CANARY-STREET-9', 'CANARY-CITY-Zz', 'CANARY-NOTE-77',
        'CANARY-PRODUCT-NAME', 'CANARY-DESCRIPTION', 'CANARY-SKU-7', 'CANARY-BARCODE-8', 'CANARY-GATEWAY-SECRET', 'CANARY-SMTP-PASSWORD',
        'CANARY-M5-PASSWORD', 'seller-secret@canary.test', 'CANARY-TORO-PASSWORD', 'master@example.test',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['fulfillment.external_writes_enabled' => false]);
        $this->completeTheRestore();
    }

    /** The inferred test schema holds the tables the integration touches; a real restore also carries the ones the storefront boots from. */
    private function completeTheRestore(): void
    {
        foreach (['login_setups', 'addon_settings', 'social_medias', 'sellers', 'brands', 'banners', 'flash_deals', 'business_pages', 'robots_meta_contents', 'analytic_scripts', 'stock_clearance_setups', 'stock_clearance_products'] as $table) {
            Schema::create($table, static fn (Blueprint $blueprint) => $blueprint->id());
        }
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: string} exit code, decoded report, raw output */
    private function compat(array $options = []): array
    {
        $exit = Artisan::call('fulfillment:compatibility', ['--json' => true] + $options);
        $output = Artisan::output();

        return [$exit, (array) json_decode($output, true), $output];
    }

    /** @return array<string, mixed> */
    private function check(array $report, string $section, string $name): array
    {
        foreach ($report['checks'] ?? [] as $check) {
            if ($check['section'] === $section && $check['name'] === $name) {
                return $check;
            }
        }

        $this->fail("no check '{$name}' in section '{$section}'; the report has: " . implode(', ', array_map(static fn (array $check): string => $check['section'] . '/' . $check['name'], $report['checks'] ?? [])));
    }

    private function replaceColumn(string $table, string $column, Closure $definition): void
    {
        Schema::table($table, static fn (Blueprint $blueprint) => $blueprint->dropColumn($column));
        Schema::table($table, static fn (Blueprint $blueprint) => $definition($blueprint));
    }

    /** @return array<string, int> */
    private function countEverything(): array
    {
        $counts = [];
        foreach (['orders', 'order_details', 'products', 'business_settings', 'currencies', 'integration_settings', 'provider_fulfillments', 'provider_api_logs'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    // ── a healthy schema ──────────────────────────────────────────────────────────────────────────────────────

    public function test_a_healthy_schema_has_no_failure_and_the_run_changes_nothing(): void
    {
        $this->makeProduct(1);
        $this->makeOrder(100);
        $this->addLine(100, 1, 2, 60);
        $before = $this->countEverything();

        [$exit, $report] = $this->compat();

        $this->assertSame(0, $exit, json_encode(array_filter($report['checks'], static fn (array $check): bool => $check['status'] === 'fail')));
        $this->assertSame(0, $report['counts']['fail']);
        $this->assertContains($report['verdict'], ['compatible', 'compatible_with_warnings']);
        $this->assertSame($before, $this->countEverything());
        foreach (['orders', 'order_details', 'products', 'business_settings', 'currencies', 'admins', 'admin_roles', 'categories', 'carts', 'attributes'] as $table) {
            $this->assertSame('pass', $this->check($report, 'structure', "{$table} column types")['status'], $table);
        }
    }

    public function test_the_table_output_names_the_verdict_and_what_it_does_not_prove(): void
    {
        $this->artisan('fulfillment:compatibility')
            ->expectsOutputToContain('Verdict:')
            ->expectsOutputToContain('does not prove that checkout, routing or dispatch work')
            ->assertSuccessful();
    }

    public function test_the_connection_the_application_uses_afterwards_is_unchanged_and_writable_again(): void
    {
        $default = DB::getDefaultConnection();

        $this->compat();

        $this->assertSame($default, DB::getDefaultConnection());
        DB::table('currencies')->insert(['id' => 99, 'name' => 'Test', 'code' => 'TST', 'symbol' => 'T', 'exchange_rate' => 1, 'status' => 1]);
        $this->assertSame(1, DB::table('currencies')->where('id', 99)->count(), 'the session is writable again after the run');
    }

    // ── it only reads ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_session_refuses_a_write_while_the_probe_is_in_use_and_accepts_it_afterwards(): void
    {
        $probe = new ReadOnlyProbe(DB::connection());

        $probe->run(function (): void {
            try {
                DB::table('currencies')->insert(['id' => 98, 'name' => 'Blocked', 'code' => 'BLK', 'symbol' => 'B', 'exchange_rate' => 1, 'status' => 1]);
                $this->fail('a write succeeded while the session was read-only');
            } catch (QueryException $exception) {
                if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                    $this->assertSame(1792, $exception->errorInfo[1] ?? null, 'MySQL error 1792: cannot execute in a READ ONLY transaction');
                }
            }
        });

        $this->assertSame(0, DB::table('currencies')->where('id', 98)->count());
        DB::table('currencies')->insert(['id' => 98, 'name' => 'Allowed', 'code' => 'ALW', 'symbol' => 'A', 'exchange_rate' => 1, 'status' => 1]);
        $this->assertSame(1, DB::table('currencies')->where('id', 98)->count());
    }

    public function test_a_write_that_got_past_the_lock_would_be_detected_and_fail_the_report(): void
    {
        $probe = new ReadOnlyProbe(DB::connection());
        $unlock = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) ? 'SET SESSION TRANSACTION READ WRITE' : 'PRAGMA query_only = 0';

        $probe->run(function () use ($unlock): void {
            DB::statement($unlock);
            DB::table('currencies')->insert(['id' => 97, 'name' => 'Sneaky', 'code' => 'SNK', 'symbol' => 'S', 'exchange_rate' => 1, 'status' => 1]);
        });

        $this->assertCount(1, $probe->writeStatements(), 'the insert is recorded as a write');
        $this->assertStringContainsStringIgnoringCase('insert into', $probe->writeStatements()[0]);
    }

    public function test_the_report_proves_every_statement_was_a_read(): void
    {
        $this->makeOrder(100);

        [, $report] = $this->compat();

        $this->assertTrue($report['read_only_proof']['session_locked']);
        $this->assertGreaterThan(10, $report['read_only_proof']['statements']);
        $this->assertSame(0, $report['read_only_proof']['write_statements']);
        $this->assertSame('pass', $this->check($report, 'read_only', 'nothing was written')['status']);
    }

    public function test_no_statement_issued_by_the_run_is_anything_but_a_read(): void
    {
        $this->makeOrder(100);
        $statements = [];
        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->compat();

        $this->assertNotEmpty($statements);
        foreach ($statements as $sql) {
            $this->assertMatchesRegularExpression('/^\s*(select|show|describe|desc|explain|with\b|set\s+session\s+transaction\s+read\s+(only|write)|pragma\s+query_only)/i', $sql);
        }
    }

    // ── it never reads what is personal or secret ─────────────────────────────────────────────────────────────

    public function test_nothing_personal_or_secret_reaches_the_report(): void
    {
        $this->seedCanaries();
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        });

        [, , $json] = $this->compat();
        Artisan::call('fulfillment:compatibility');
        $table = Artisan::output();

        foreach (self::CANARIES as $canary) {
            $this->assertStringNotContainsString($canary, $json, "'{$canary}' leaked into the JSON report");
            $this->assertStringNotContainsString($canary, $table, "'{$canary}' leaked into the printed report");
        }

        foreach ($queries as $query) {
            if (preg_match('/from\s+["`]?business_settings["`]?/i', $query['sql']) === 1) {
                $bindings = array_filter($query['bindings'], 'is_string');
                $this->assertSame([], array_values(array_diff($bindings, CheckoutSection::READABLE_SETTINGS)), 'the run read a business setting it has no business with: ' . $query['sql']);
            }
        }
    }

    public function test_values_that_are_not_code_words_are_counted_and_never_shown(): void
    {
        $this->makeProduct(1);
        $this->makeOrder(100, ['payment_method' => 'canary.customer@leak.test', 'order_status' => 'CANARY-NAME-Qx7 shipped']);
        $this->makeOrder(101, ['payment_method' => 'cash_on_delivery', 'payment_status' => 'unpaid']);
        $this->addLine(100, 1);
        $this->addLine(101, 1);

        [, $report, $json] = $this->compat();

        $this->assertStringNotContainsString('canary.customer@leak.test', $json);
        $this->assertStringNotContainsString('CANARY-NAME-Qx7', $json);
        $this->assertSame(1, $this->check($report, 'payment_states', 'payment methods')['facts']['not_shown_values']);
        $this->assertSame(1, $this->check($report, 'orders', 'order status values')['facts']['other_values']);
        $this->assertSame('warn', $this->check($report, 'orders', 'order status values')['status']);
    }

    // ── a broken schema, column by column ─────────────────────────────────────────────────────────────────────

    public function test_a_column_stored_with_the_wrong_type_is_named_and_fails_the_report(): void
    {
        $this->replaceColumn('orders', 'order_amount', static fn (Blueprint $table) => $table->string('order_amount', 20)->nullable());
        $this->replaceColumn('orders', 'created_at', static fn (Blueprint $table) => $table->string('created_at', 30)->nullable());
        $this->replaceColumn('order_details', 'price', static fn (Blueprint $table) => $table->integer('price')->default(0));

        [$exit, $report] = $this->compat();

        $this->assertSame(1, $exit);
        $this->assertSame('not_compatible', $report['verdict']);
        $orders = $this->check($report, 'structure', 'orders column types');
        $this->assertSame('fail', $orders['status']);
        $this->assertStringContainsString('orders.order_amount is varchar', $orders['detail']);
        $this->assertStringContainsString('orders.created_at is varchar', $orders['detail']);
        $details = $this->check($report, 'structure', 'order_details column types');
        $this->assertSame('fail', $details['status']);
        $this->assertStringContainsString('cannot hold cents', $details['detail']);
    }

    public function test_a_guest_reference_held_as_text_is_accepted(): void
    {
        $this->replaceColumn('orders', 'customer_id', static fn (Blueprint $table) => $table->string('customer_id', 15)->nullable());

        [, $report] = $this->compat();

        $this->assertStringNotContainsString('orders.customer_id', $this->check($report, 'structure', 'orders column types')['detail']);
    }

    public function test_on_mysql_a_varchar_too_short_for_what_the_integration_stores_is_reported(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MySQL only: SQLite reports no varchar length.');
        }
        $this->replaceColumn('orders', 'order_status', static fn (Blueprint $table) => $table->string('order_status', 10)->default('pending'));
        $this->replaceColumn('orders', 'shipping_address_data', static fn (Blueprint $table) => $table->string('shipping_address_data', 100)->nullable());

        [$exit, $report] = $this->compat();

        $detail = $this->check($report, 'structure', 'orders column types')['detail'];
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('orders.order_status is varchar(10), shorter than the 20 characters', $detail);
        $this->assertStringContainsString('orders.shipping_address_data is varchar(100), too short for the JSON', $detail);
    }

    public function test_a_missing_column_is_reported_once_by_the_schema_check_and_not_again_by_the_type_check(): void
    {
        Schema::table('orders', static fn (Blueprint $table) => $table->dropColumn('order_note'));

        [$exit, $report] = $this->compat();

        $this->assertSame(1, $exit);
        $this->assertSame('fail', $this->check($report, 'schema', 'orders')['status']);
        $this->assertStringNotContainsString('order_note', $this->check($report, 'structure', 'orders column types')['detail']);
    }

    public function test_a_missing_core_table_is_reported_by_the_schema_check_and_skipped_by_the_data_checks(): void
    {
        Schema::drop('order_details');

        [$exit, $report] = $this->compat();

        $this->assertSame(1, $exit);
        $this->assertSame('fail', $this->check($report, 'schema', 'order_details')['status']);
        $this->assertSame('info', $this->check($report, 'order_details', 'order lines')['status']);
        $this->assertSame('info', $this->check($report, 'structure', 'order_details column types')['status']);
    }

    public function test_the_optional_stock_ledger_is_a_warning_when_absent_and_checked_when_present(): void
    {
        [, $without] = $this->compat();
        $this->assertSame('warn', $this->check($without, 'structure', 'stock_movements column types')['status']);

        Schema::create('stock_movements', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('type');
            $table->integer('qty_change');
            $table->integer('balance_after')->nullable();
            $table->string('reason')->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
        DB::table('stock_movements')->insert([['product_id' => 1, 'type' => 'sale', 'qty_change' => -1], ['product_id' => 1, 'type' => 'adjustment', 'qty_change' => 5]]);

        [, $with] = $this->compat();

        $this->assertSame('pass', $this->check($with, 'structure', 'stock_movements column types')['status']);
        $this->assertSame(2, $this->check($with, 'inventory', 'stock movement history')['facts']['movements']);
    }

    // ── odd data, as counts ───────────────────────────────────────────────────────────────────────────────────

    public function test_odd_orders_are_counted_by_kind(): void
    {
        $this->makeProduct(1);
        $good = json_encode(['contact_person_name' => 'Sara', 'phone' => '0551234567', 'address' => 'Street', 'city' => 'الرياض']);
        $noCity = json_encode(['contact_person_name' => 'Sara', 'phone' => '0551234567', 'address' => 'Street']);
        foreach ([
            100 => ['shipping_address_data' => $good, 'order_status' => 'confirmed'],
            101 => ['shipping_address_data' => $noCity, 'order_status' => 'shipped_weird', 'payment_status' => 'partial'],
            102 => ['shipping_address_data' => '{bad json', 'order_status' => 'canceled'],
            103 => ['shipping_address_data' => null, 'order_status' => 'processing'],
            104 => ['shipping_address_data' => '"just a string"', 'order_status' => 'delivered', 'order_type' => 'POS'],
            105 => ['shipping_address_data' => json_encode(json_encode(['city' => 'x', 'phone' => '1'])), 'order_status' => 'pending', 'order_type' => 'strange_type'],
            106 => ['shipping_address_data' => $good, 'order_amount' => -5, 'order_status' => 'pending'],
        ] as $id => $overrides) {
            $this->makeOrder($id, $overrides);
            $id === 106 ?: $this->addLine($id, 1);
        }

        [$exit, $report] = $this->compat();

        $this->assertSame(0, $exit, 'odd data is a warning, never a failure');
        $status = $this->check($report, 'orders', 'order status values');
        $this->assertSame('warn', $status['status']);
        $this->assertSame(['shipped_weird'], $status['facts']['unknown']);
        $this->assertSame(2, $status['facts']['orders_in_dispatchable_statuses'], 'confirmed and processing');
        $this->assertSame(['strange_type'], $this->check($report, 'orders', 'order type values')['facts']['unknown']);

        $address = $this->check($report, 'orders', 'shipping address shape');
        $this->assertSame('warn', $address['status']);
        $this->assertSame(7, $address['facts']['sampled']);
        $this->assertSame(1, $address['facts']['invalid_json']);
        $this->assertSame(1, $address['facts']['empty']);
        $this->assertSame(1, $address['facts']['not_an_object']);
        $this->assertSame(1, $address['facts']['double_encoded']);
        $this->assertSame(1, $this->check($report, 'orders', 'orders without lines')['facts']['without_lines']);
        $this->assertSame(1, $this->check($report, 'orders', 'order amounts')['facts']['negative_total']);
        $this->assertSame('warn', $this->check($report, 'orders', 'order amounts')['status']);
    }

    public function test_a_store_whose_orders_never_reach_a_dispatchable_status_is_warned(): void
    {
        $this->makeOrder(100, ['order_status' => 'pending']);
        $this->makeOrder(101, ['order_status' => 'delivered']);

        [, $report] = $this->compat();

        $check = $this->check($report, 'orders', 'order status values');
        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('nothing would be sent', $check['detail']);
    }

    public function test_order_lines_are_checked_for_orphans_bad_quantities_and_unreadable_product_copies(): void
    {
        $this->makeProduct(1);
        $this->makeOrder(100);
        $this->addLine(100, 1, 2, 60);
        $now = now();
        DB::table('order_details')->insert([
            ['order_id' => 999, 'product_id' => 1, 'qty' => 1, 'price' => 5, 'product_details' => json_encode(['id' => 1]), 'variant' => null, 'created_at' => $now, 'updated_at' => $now],
            ['order_id' => 100, 'product_id' => 555, 'qty' => 0, 'price' => -1, 'product_details' => '{not json', 'variant' => null, 'created_at' => $now, 'updated_at' => $now],
            ['order_id' => 100, 'product_id' => 1, 'qty' => 1, 'price' => 5, 'product_details' => json_encode(['name' => 'no id']), 'variant' => 'Red', 'created_at' => $now, 'updated_at' => $now],
            ['order_id' => 100, 'product_id' => 1, 'qty' => 1, 'price' => 5, 'product_details' => json_encode(['id' => 7]), 'variant' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);

        [$exit, $report] = $this->compat();

        $this->assertSame(0, $exit);
        $quality = $this->check($report, 'order_details', 'quantities and prices');
        $this->assertSame('warn', $quality['status']);
        $this->assertSame(1, $quality['facts']['quantity_below_one']);
        $this->assertSame(1, $quality['facts']['negative_price']);
        $this->assertSame(1, $quality['facts']['with_variant']);
        $copy = $this->check($report, 'order_details', 'product copy on the line');
        $this->assertSame(1, $copy['facts']['not_json']);
        $this->assertSame(1, $copy['facts']['without_id']);
        $this->assertSame(1, $copy['facts']['id_differs_from_product_id']);
        $this->assertSame(1, $this->check($report, 'order_details', 'lines whose order is gone')['facts']['orders_missing']);
        $this->assertSame(1, $this->check($report, 'order_details', 'lines whose product is gone')['facts']['products_missing']);
    }

    public function test_the_sample_is_limited_to_the_newest_rows(): void
    {
        $this->makeProduct(1);
        foreach (range(1, 130) as $id) {
            $this->makeOrder($id, ['shipping_address_data' => $id <= 20 ? '{old and broken' : json_encode(['city' => 'x', 'phone' => '1', 'contact_person_name' => 'n', 'address' => 'a'])]);
            $this->addLine($id, 1);
        }

        [, $report] = $this->compat(['--sample' => 100]);

        $address = $this->check($report, 'orders', 'shipping address shape');
        $this->assertSame(100, $report['sample_rows']);
        $this->assertSame(100, $address['facts']['sampled']);
        $this->assertSame(0, $address['facts']['invalid_json'], 'the 20 oldest orders are outside the sample of the 100 newest');
        $this->assertSame(130, $this->check($report, 'orders', 'volume')['facts']['orders']);
    }

    public function test_the_catalogue_is_checked_for_shared_skus_stock_and_variants(): void
    {
        $this->makeProduct(1, null, ['code' => 'DUP', 'barcode' => 'B-1']);
        $this->makeProduct(2, null, ['code' => 'DUP', 'barcode' => '']);
        $this->makeProduct(3, null, ['code' => '', 'barcode' => 'B-1', 'current_stock' => -3]);
        $this->makeProduct(4, null, ['code' => 'V4', 'barcode' => 'B-4', 'current_stock' => 10, 'variation' => json_encode([
            ['type' => 'Red', 'price' => 5, 'sku' => 'V4-R', 'qty' => 4],
            ['type' => 'Blue', 'price' => 5, 'sku' => 'V4-B'],
        ])]);
        $this->makeProduct(5, null, ['code' => 'V5', 'barcode' => 'B-5', 'current_stock' => 7, 'variation' => '{broken']);
        $this->makeProduct(6, null, ['code' => 'V6', 'barcode' => 'B-6', 'current_stock' => 8, 'variation' => json_encode([['type' => 'A', 'price' => 1, 'qty' => 3], ['type' => 'B', 'price' => 1, 'qty' => 5]])]);
        $this->makeProduct(7, null, ['added_by' => 'seller', 'code' => 'DUP', 'barcode' => 'B-1']);
        $this->makeProduct(8, null, ['product_type' => 'digital', 'code' => 'DUP']);
        $this->makeProduct(9, null, ['code' => 'FREE', 'barcode' => 'B-9', 'unit_price' => 0]);

        [$exit, $report] = $this->compat();

        $this->assertSame(0, $exit);
        $catalogue = $this->check($report, 'products', 'catalogue')['facts'];
        $this->assertSame(9, $catalogue['products']);
        $this->assertSame(7, $catalogue['assignable'], 'physical products added by the admin');
        $identifiers = $this->check($report, 'products', 'SKUs and barcodes');
        $this->assertSame('warn', $identifiers['status']);
        $this->assertSame(1, $identifiers['facts']['duplicated_sku'], 'DUP is shared by products 1 and 2 (the seller and digital ones are not assignable)');
        $this->assertSame(1, $identifiers['facts']['duplicated_barcode'], 'B-1 is shared by products 1 and 3');
        $this->assertSame(1, $identifiers['facts']['blank_sku']);
        $this->assertSame(1, $identifiers['facts']['blank_barcode']);
        $this->assertSame(1, $this->check($report, 'products', 'published products without a price')['facts']['published_without_price']);

        $stock = $this->check($report, 'inventory', 'stock levels');
        $this->assertSame('warn', $stock['status']);
        $this->assertSame(1, $stock['facts']['negative_stock']);
        $variants = $this->check($report, 'inventory', 'variant stock');
        $this->assertSame('warn', $variants['status']);
        $this->assertSame(3, $variants['facts']['sampled'], 'products 4, 5 and 6 have variants');
        $this->assertSame(1, $variants['facts']['not_json']);
        $this->assertSame(1, $variants['facts']['without_numeric_qty']);
        $this->assertSame(1, $variants['facts']['stock_differs_from_variant_sum'], 'product 4 says 10 but its variants add up to 4; 6 says 8 and adds up to 8');
    }

    public function test_provider_assignments_are_counted_per_provider(): void
    {
        $this->makeProduct(1);
        $this->makeProduct(2, ProviderCode::M5azn);
        $this->makeProduct(3, ProviderCode::TorodPoint);
        $this->makeProduct(4, ProviderCode::TorodPoint);

        [, $report] = $this->compat();

        $assignments = $this->check($report, 'products', 'provider assignments')['facts'];
        $this->assertSame(1, $assignments['in_house']);
        $this->assertSame(['torod_point' => 2, 'm5azn' => 1], $assignments['assigned']);
    }

    public function test_payment_states_are_mapped_onto_prepaid_and_cash_collection(): void
    {
        foreach ([
            [100, 'cash_on_delivery', 'unpaid', 100, 0],
            [101, 'cash_on_delivery', 'unpaid', 100, 40],
            [102, 'cash_on_delivery', 'paid', 100, 100],
            [103, 'ssl_commerz', 'paid', 100, 100],
            [104, 'ssl_commerz', 'unpaid', 100, 0],
            [105, 'offline_payment', 'partial', 100, 0],
        ] as [$id, $method, $status, $total, $paid]) {
            $this->makeOrder($id, ['payment_method' => $method, 'payment_status' => $status, 'order_amount' => $total, 'paid_amount' => $paid]);
        }

        [, $report] = $this->compat();

        $facts = $this->check($report, 'payment_states', 'how orders would be paid at a provider')['facts'];
        $this->assertSame(2, $facts['cash_on_delivery_unpaid']);
        $this->assertSame(1, $facts['cash_on_delivery_already_paid']);
        $this->assertSame(1, $facts['prepaid_and_paid']);
        $this->assertSame(2, $facts['prepaid_not_yet_paid'], 'unpaid and the unknown "partial" status both count as unpaid');
        $this->assertSame(1, $facts['cash_on_delivery_part_paid']);
        $statuses = $this->check($report, 'payment_states', 'payment status values');
        $this->assertSame('warn', $statuses['status']);
        $this->assertSame(['partial'], $statuses['facts']['unknown']);
        $this->assertSame(3, $this->check($report, 'payment_states', 'payment methods')['facts']['cash_on_delivery']);
    }

    // ── checkout configuration ────────────────────────────────────────────────────────────────────────────────

    public function test_a_single_currency_store_must_keep_the_provider_currency_as_its_default(): void
    {
        [, $sar] = $this->compat();
        $this->assertSame('pass', $this->check($sar, 'checkout', 'currency conversion')['status']);

        DB::table('currencies')->insert(['id' => 2, 'name' => 'Dollar', 'code' => 'USD', 'symbol' => '$', 'exchange_rate' => 1, 'status' => 1]);
        DB::table('business_settings')->where('type', 'system_default_currency')->update(['value' => json_encode(2)]);

        [, $usd] = $this->compat();

        $check = $this->check($usd, 'checkout', 'currency conversion');
        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('currency unconvertible', $check['detail']);
        $this->assertFalse($check['facts']['convertible']);
    }

    public function test_a_multi_currency_store_needs_usable_rates_for_usd_and_the_provider_currency(): void
    {
        $this->multiCurrencyUsdBase();
        [, $withRates] = $this->compat();
        $this->assertSame('pass', $this->check($withRates, 'checkout', 'currency conversion')['status']);

        DB::table('currencies')->where('code', 'USD')->delete();
        [, $withoutUsd] = $this->compat();

        $this->assertSame('warn', $this->check($withoutUsd, 'checkout', 'currency conversion')['status']);
    }

    public function test_it_warns_when_nobody_can_open_the_integration_center(): void
    {
        [, $fine] = $this->compat();
        $this->assertSame('pass', $this->check($fine, 'checkout', 'who can open the Integration Center')['status']);

        DB::table('admins')->update(['admin_role_id' => 2]);

        [, $nobody] = $this->compat();

        $this->assertSame('warn', $this->check($nobody, 'checkout', 'who can open the Integration Center')['status']);
    }

    // ── was the dump restored completely ──────────────────────────────────────────────────────────────────────

    public function test_a_partial_restore_is_a_failure_naming_the_missing_boot_tables(): void
    {
        Schema::drop('login_setups');
        Schema::drop('banners');

        [$exit, $report] = $this->compat();

        $check = $this->check($report, 'restore', 'tables the application boots from');
        $this->assertSame(1, $exit);
        $this->assertSame('fail', $check['status']);
        $this->assertSame(['login_setups', 'banners'], $check['facts']['missing']);
        $this->assertStringContainsString('never run php artisan migrate to fill the gap', $check['detail']);
    }

    public function test_a_restore_that_left_the_core_settings_empty_is_a_failure(): void
    {
        DB::table('business_settings')->delete();
        DB::table('shops')->delete();

        [, $report] = $this->compat();

        $check = $this->check($report, 'restore', 'boot tables that must hold data');
        $this->assertSame('fail', $check['status']);
        $this->assertSame(['business_settings', 'shops'], $check['facts']['empty']);
        $this->assertTrue($check['facts']['in_house_shop_missing']);
    }

    public function test_tables_that_only_the_dump_creates_are_listed_when_missing(): void
    {
        [, $report] = $this->compat();

        $check = $this->check($report, 'restore', 'tables created outside the migrations');
        $this->assertSame('warn', $check['status'], 'the inferred test schema lacks most of them');
        $this->assertContains('stock_movements', $check['facts']['missing']);
        $this->assertStringContainsString('migrate will not create them', $check['detail']);
    }

    // ── what migrate would do ─────────────────────────────────────────────────────────────────────────────────

    public function test_a_database_without_a_migrations_table_is_not_a_restored_platform_database(): void
    {
        [, $report] = $this->compat();

        $check = $this->check($report, 'migrations', 'migrations table');
        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('never run `php artisan migrate`', $check['detail']);
        $this->assertGreaterThan(100, $check['facts']['migration_files']);
    }

    public function test_pending_migrations_are_listed_the_integrations_own_apart_from_the_platforms(): void
    {
        Schema::create('migrations', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
        DB::table('migrations')->insert([['migration' => '2014_10_12_000000_create_users_table', 'batch' => 1]]);

        [, $report] = $this->compat();

        $pending = $this->check($report, 'migrations', 'pending migrations');
        $this->assertSame('warn', $pending['status']);
        $this->assertSame([
            '2026_11_05_100000_create_provider_fulfillment_tables', '2026_11_05_100100_add_fulfillment_provider_to_products_table',
            '2026_11_06_100000_widen_order_details_delivery_status_for_fulfillment', '2026_11_06_100100_widen_order_details_variation_for_fulfillment',
        ], $pending['facts']['pending_integration'], 'the two column-widening migrations are the integration\'s own, not the platform\'s');
        $this->assertGreaterThan(100, $pending['facts']['pending_platform'], 'migrate would run the platform\'s own migrations too');
        $this->assertLessThanOrEqual(40, count($pending['facts']['pending_platform_listed']));
        $this->assertStringContainsString('Rehearse the whole set', $pending['detail']);

        DB::table('migrations')->insert([
            ['migration' => '2026_11_06_100000_widen_order_details_delivery_status_for_fulfillment', 'batch' => 2],
            ['migration' => '2026_11_06_100100_widen_order_details_variation_for_fulfillment', 'batch' => 2],
            ['migration' => '2026_11_05_100000_create_provider_fulfillment_tables', 'batch' => 3],
            ['migration' => '2026_11_05_100100_add_fulfillment_provider_to_products_table', 'batch' => 3],
        ]);
        [, $after] = $this->compat();

        $this->assertSame([], $this->check($after, 'migrations', 'pending migrations')['facts']['pending_integration']);
    }

    public function test_a_pending_platform_migration_that_changes_a_checkout_table_is_called_out(): void
    {
        Schema::create('migrations', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
        $existing = array_keys(app('migrator')->getMigrationFiles([...app('migrator')->paths(), database_path('migrations')]));
        foreach (array_chunk($existing, 100) as $chunk) {
            DB::table('migrations')->insert(array_map(static fn (string $name): array => ['migration' => $name, 'batch' => 1], $chunk));
        }
        $directory = sys_get_temp_dir() . '/compat-migrations-' . bin2hex(random_bytes(4));
        mkdir($directory);
        $file = '2099_01_01_000000_alter_orders_for_the_compatibility_test';
        file_put_contents("{$directory}/{$file}.php", "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Support\\Facades\\Schema;\nreturn new class extends Migration { public function up(): void { Schema::table('orders', function (\$table) {}); } };\n");
        app('migrator')->path($directory);

        try {
            [, $report] = $this->compat();
        } finally {
            unlink("{$directory}/{$file}.php");
            rmdir($directory);
        }

        $check = $this->check($report, 'migrations', 'pending platform migrations that change checkout tables');
        $this->assertSame('warn', $check['status']);
        $this->assertSame([$file], $check['facts']['migrations']);
        $this->assertSame([$file], $this->check($report, 'migrations', 'pending migrations')['facts']['pending_platform_listed']);
    }

    public function test_the_platform_migration_that_rebuilds_the_amount_columns_of_the_checkout_tables_is_called_out(): void
    {
        Schema::create('migrations', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
        $decimal = '2026_05_07_000001_update_all_decimal_columns_to_40_20';
        $everythingElse = array_values(array_diff(array_keys(app('migrator')->getMigrationFiles([...app('migrator')->paths(), database_path('migrations')])), [$decimal]));
        foreach (array_chunk($everythingElse, 100) as $chunk) {
            DB::table('migrations')->insert(array_map(static fn (string $name): array => ['migration' => $name, 'batch' => 1], $chunk));
        }

        [, $report] = $this->compat();

        $check = $this->check($report, 'migrations', 'pending platform migrations that change checkout tables');
        $this->assertSame('warn', $check['status']);
        $this->assertSame([$decimal], $check['facts']['migrations'], '180 ALTER TABLE ... MODIFY statements on orders, order_details and products');
    }

    // ── refusals ──────────────────────────────────────────────────────────────────────────────────────────────

    public function test_in_production_it_refuses_before_reading_anything(): void
    {
        $this->app['env'] = 'production';
        DB::enableQueryLog();

        [$exit, $report] = $this->compat();

        $this->assertSame(1, $exit);
        $this->assertSame('refused', $report['verdict']);
        $this->assertStringContainsString('APP_ENV is production', $report['checks'][0]['detail']);
        $this->assertSame([], DB::getQueryLog(), 'a refused run reads nothing');
    }

    public function test_production_is_allowed_only_with_the_explicit_read_only_flag(): void
    {
        $this->app['env'] = 'production';
        config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);

        [, $report] = $this->compat(['--allow-production-read-only' => true]);

        $this->assertNotSame('refused', $report['verdict']);
        $this->assertSame(0, $report['read_only_proof']['write_statements']);
    }

    public function test_it_refuses_a_database_other_than_the_one_expected(): void
    {
        $actual = (string) config('database.connections.' . config('database.default') . '.database');
        DB::enableQueryLog();

        [$exit, $report] = $this->compat(['--expect-database' => 'pharmacy_production']);

        $this->assertSame(1, $exit);
        $this->assertSame('refused', $report['verdict']);
        $this->assertStringContainsString('different database', $report['checks'][0]['detail']);
        $this->assertSame([], DB::getQueryLog());

        [, $matching] = $this->compat(['--expect-database' => $actual]);
        $this->assertNotSame('refused', $matching['verdict']);
    }

    public function test_it_refuses_an_unknown_connection_and_a_driver_it_cannot_lock(): void
    {
        config(['database.connections.somewhere_else' => ['driver' => 'pgsql', 'database' => 'x']]);

        [$unknownExit, $unknown] = $this->compat(['--connection' => 'does_not_exist']);
        [$pgsqlExit, $pgsql] = $this->compat(['--connection' => 'somewhere_else']);

        $this->assertSame(1, $unknownExit);
        $this->assertStringContainsString("no database connection named 'does_not_exist'", $unknown['checks'][0]['detail']);
        $this->assertSame(1, $pgsqlExit);
        $this->assertStringContainsString('cannot be locked read-only', $pgsql['checks'][0]['detail']);
    }

    public function test_the_output_is_valid_json_and_the_exit_code_follows_the_failures(): void
    {
        [$healthyExit, $healthy] = $this->compat();
        Schema::table('orders', static fn (Blueprint $table) => $table->dropColumn('order_note'));
        [$brokenExit, $broken] = $this->compat();

        $this->assertSame(0, $healthyExit);
        $this->assertSame(0, $healthy['counts']['fail']);
        $this->assertSame(1, $brokenExit);
        $this->assertGreaterThan(0, $broken['counts']['fail']);
        $this->assertArrayHasKey('scope', $broken);
    }

    // ── MySQL ─────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_on_mysql_the_real_column_types_map_to_the_expected_families(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MySQL only: SQLite types are covered by the family unit tests.');
        }

        DB::statement('CREATE TABLE compat_types (a bigint unsigned, b decimal(40,20), c longtext, d json, e varchar(255), f tinyint(1), g timestamp NULL, h double, i int)');
        try {
            $families = array_map(static fn ($facts): string => $facts->family, (new ReadOnlyProbe(DB::connection()))->columns('compat_types'));
        } finally {
            DB::statement('DROP TABLE compat_types');
        }

        $this->assertSame(['a' => 'integer', 'b' => 'decimal', 'c' => 'text', 'd' => 'json', 'e' => 'string', 'f' => 'integer', 'g' => 'datetime', 'h' => 'float', 'i' => 'integer'], $families);
    }

    public function test_on_mysql_the_whole_run_passes_the_engine_checks_and_stays_read_only(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MySQL only.');
        }
        $this->makeProduct(1);
        $this->makeOrder(100);
        $this->addLine(100, 1);

        [, $report] = $this->compat();

        $this->assertSame('pass', $this->check($report, 'schema', 'storage engine')['status']);
        $this->assertSame(0, $report['read_only_proof']['write_statements']);
        $this->assertSame(0, $report['counts']['fail'], json_encode(array_filter($report['checks'], static fn (array $check): bool => $check['status'] === 'fail')));
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────────────────────────────

    private function seedCanaries(): void
    {
        $this->makeProduct(1, null, ['name' => 'CANARY-PRODUCT-NAME', 'details' => 'CANARY-DESCRIPTION', 'code' => 'CANARY-SKU-7', 'barcode' => 'CANARY-BARCODE-8']);
        $this->makeProduct(2, null, ['name' => 'CANARY-PRODUCT-NAME', 'code' => 'CANARY-SKU-7', 'barcode' => 'CANARY-BARCODE-8']);
        $this->makeOrder(100, [
            'order_note' => 'CANARY-NOTE-77',
            'shipping_address_data' => json_encode(['contact_person_name' => 'CANARY-NAME-Qx7', 'phone' => '0599900111', 'address' => 'CANARY-STREET-9', 'city' => 'CANARY-CITY-Zz', 'email' => 'canary.customer@leak.test']),
        ]);
        DB::table('order_details')->insert([
            'order_id' => 100, 'product_id' => 1, 'qty' => 1, 'price' => 5, 'created_at' => now(), 'updated_at' => now(),
            'product_details' => json_encode(['id' => 1, 'name' => 'CANARY-PRODUCT-NAME', 'details' => 'CANARY-DESCRIPTION']),
        ]);
        DB::table('business_settings')->insert([
            ['type' => 'ssl_commerz', 'value' => json_encode(['store_password' => 'CANARY-GATEWAY-SECRET'])],
            ['type' => 'mail_config', 'value' => json_encode(['password' => 'CANARY-SMTP-PASSWORD'])],
        ]);
        $this->enableProvider(ProviderCode::M5azn, [], ['email' => 'seller-secret@canary.test', 'password' => 'CANARY-M5-PASSWORD']);
        $this->enableProvider(ProviderCode::TorodPoint, ['company_id' => '714'], ['fulfillment_username' => 'seller-secret@canary.test', 'fulfillment_password' => 'CANARY-TORO-PASSWORD']);
    }
}
