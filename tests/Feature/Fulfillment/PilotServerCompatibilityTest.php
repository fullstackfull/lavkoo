<?php

namespace Tests\Feature\Fulfillment;

use App\Services\Fulfillment\Readiness\Compatibility\ColumnExpectation;
use App\Services\Fulfillment\Readiness\Compatibility\ColumnFacts;
use App\Services\Fulfillment\Readiness\Compatibility\ColumnFamily;
use App\Services\Fulfillment\Readiness\Compatibility\CompatibilitySpec;
use App\Services\Fulfillment\Support\LegacyColumnWidener;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsPilotServerSchema;

/**
 * The compatibility command against the schema the owner found on the pilot server. Its first run there reported three failures and four
 * warnings; three of them were the command's own wrong expectations (a table called social_media, a decimal rate column, a currency that
 * is "KSA" and not "SAR"), one was a real column the platform itself needs wider (delivery_status), and the rest were conservative rules.
 */
class PilotServerCompatibilityTest extends FulfillmentTestCase
{
    use BuildsPilotServerSchema;

    protected function setUp(): void
    {
        parent::setUp();

        config(['fulfillment.external_writes_enabled' => false]);
        $this->usePilotServerSchema();
    }

    /** @return array{0: int, 1: array<string, mixed>} exit code and decoded report */
    private function compat(): array
    {
        Cache::flush();
        $exit = Artisan::call('fulfillment:compatibility', ['--json' => true]);

        return [$exit, (array) json_decode(Artisan::output(), true)];
    }

    /** @return array<string, mixed> */
    private function check(array $report, string $section, string $name): array
    {
        foreach ($report['checks'] ?? [] as $check) {
            if ($check['section'] === $section && $check['name'] === $name) {
                return $check;
            }
        }

        $this->fail("no check '{$name}' in section '{$section}'");
    }

    public function test_the_checks_that_were_wrong_about_the_platform_now_pass_on_the_pilot_schema(): void
    {
        [, $report] = $this->compat();

        $this->assertSame('pass', $this->check($report, 'restore', 'tables the application boots from')['status'], 'the platform\'s social media table is social_medias');
        $this->assertSame('pass', $this->check($report, 'structure', 'currencies column types')['status'], 'the platform keeps exchange_rate as text');
        $this->assertSame('pass', $this->check($report, 'checkout', 'exchange rates')['status']);
        $currency = $this->check($report, 'checkout', 'currency conversion');
        $this->assertSame('pass', $currency['status'], $currency['detail']);
        $this->assertStringContainsString('KSA', $currency['detail']);
        $this->assertStringContainsString('SAR', $currency['detail']);
        $this->assertTrue($currency['facts']['convertible']);
        $this->assertSame('SAR', $currency['facts']['provider_currency']);
    }

    public function test_a_column_the_platform_writes_every_order_status_into_must_hold_the_longest_status(): void
    {
        $this->assertSame(16, CompatibilitySpec::longestOrderStatus(), '"out_for_delivery"');
        $expectation = $this->expectation('order_details', 'delivery_status');
        $column = static fn (int $length): ColumnFacts => new ColumnFacts(name: 'delivery_status', family: ColumnFamily::STRING, type: "varchar({$length})", nullable: false, length: $length, unsigned: false, autoIncrement: false);

        $this->assertSame(16, $expectation->minLength);
        $this->assertStringContainsString('varchar(15), shorter than the 16 characters', (string) $expectation->problem($column(15)));
        $this->assertNull($expectation->problem($column(16)));
        $this->assertNull($expectation->problem($column(30)));
    }

    public function test_order_details_variation_stays_a_conservative_warning_that_says_who_writes_it(): void
    {
        $expectation = $this->expectation('order_details', 'variation');
        $varchar = new ColumnFacts(name: 'variation', family: ColumnFamily::STRING, type: 'varchar(255)', nullable: true, length: 255, unsigned: false, autoIncrement: false);
        $text = new ColumnFacts(name: 'variation', family: ColumnFamily::TEXT, type: 'text', nullable: true, length: null, unsigned: false, autoIncrement: false);

        $this->assertSame('warn', $expectation->severity);
        $this->assertNotNull($expectation->problem($varchar));
        $this->assertStringContainsString('The platform writes the options', (string) $expectation->problem($varchar));
        $this->assertStringContainsString('reads the variant from "variant"', (string) $expectation->problem($varchar));
        $this->assertNull($expectation->problem($text));
    }

    public function test_on_a_server_that_enforces_lengths_the_pilot_schema_fails_only_on_the_status_column_until_the_migrations_run(): void
    {
        if (!$this->engineEnforcesColumnLengths()) {
            $this->markTestSkipped('SQLite reports no column length: the length checks are proved on MySQL.');
        }

        [$exit, $report] = $this->compat();

        $this->assertSame(1, $exit);
        $this->assertSame(1, $report['counts']['fail'], json_encode(array_values(array_filter($report['checks'], static fn (array $check): bool => $check['status'] === 'fail'))));
        $orderDetails = $this->check($report, 'structure', 'order_details column types');
        $this->assertSame('fail', $orderDetails['status']);
        $this->assertStringContainsString('order_details.delivery_status is varchar(15)', $orderDetails['detail']);
        $this->assertStringContainsString('order_details.variation is varchar(255)', $orderDetails['detail']);
        $this->assertStringContainsString('2026_11_06_100000', $orderDetails['detail']);
    }

    public function test_once_the_two_column_migrations_have_run_the_pilot_schema_has_no_failure_at_all(): void
    {
        if (!$this->engineEnforcesColumnLengths()) {
            $this->markTestSkipped('The migrations change MySQL and MariaDB columns only.');
        }
        $this->runCompatibilityMigrations();

        [$exit, $report] = $this->compat();

        $this->assertSame(0, $exit, json_encode(array_values(array_filter($report['checks'], static fn (array $check): bool => $check['status'] === 'fail'))));
        $this->assertSame(0, $report['counts']['fail']);
        $this->assertSame('pass', $this->check($report, 'structure', 'order_details column types')['status']);
    }

    public function test_the_report_shows_the_exact_statements_the_migrations_would_run_and_changes_nothing(): void
    {
        if (!$this->engineEnforcesColumnLengths()) {
            $this->markTestSkipped('The plan is made for MySQL and MariaDB columns only.');
        }
        $definitions = static fn (): array => [LegacyColumnWidener::describe('order_details', 'delivery_status'), LegacyColumnWidener::describe('order_details', 'variation')];
        $before = $definitions();

        [, $report] = $this->compat();

        $plan = $this->check($report, 'structure', 'column changes the migrations would make');
        $this->assertSame('info', $plan['status']);
        $this->assertStringContainsString('order_details.delivery_status: ALTER TABLE `order_details` MODIFY COLUMN `delivery_status` VARCHAR(30)', $plan['detail']);
        $this->assertStringContainsString("NOT NULL DEFAULT 'pending'", $plan['detail']);
        $this->assertStringContainsString('order_details.variation: ALTER TABLE `order_details` MODIFY COLUMN `variation` TEXT', $plan['detail']);
        $this->assertSame($before, $definitions(), 'looking at the plan altered nothing');
        $this->assertSame('pass', $this->check($report, 'read_only', 'nothing was written')['status'], 'and every statement it issued was a read');
    }

    public function test_once_the_migrations_have_run_the_plan_says_there_is_nothing_left_to_change(): void
    {
        if (!$this->engineEnforcesColumnLengths()) {
            $this->markTestSkipped('The plan is made for MySQL and MariaDB columns only.');
        }
        $this->runCompatibilityMigrations();

        [, $report] = $this->compat();

        $plan = $this->check($report, 'structure', 'column changes the migrations would make');
        $this->assertSame('info', $plan['status']);
        $this->assertSame(2, substr_count($plan['detail'], 'already wide enough, nothing to change'));
        $this->assertStringNotContainsString('ALTER TABLE', $plan['detail']);
    }

    public function test_a_column_the_migration_would_refuse_is_a_warning_in_the_plan_before_anyone_runs_it(): void
    {
        if (!$this->engineEnforcesColumnLengths()) {
            $this->markTestSkipped('The plan is made for MySQL and MariaDB columns only.');
        }
        DB::statement('ALTER TABLE order_details ADD INDEX idx_variation_probe (variation)');

        [, $report] = $this->compat();

        $plan = $this->check($report, 'structure', 'column changes the migrations would make');
        $this->assertSame('warn', $plan['status']);
        $this->assertSame(1, $plan['facts']['refused']);
        $this->assertStringContainsString('THE MIGRATION WOULD REFUSE: order_details.variation was not changed: an index covers it', $plan['detail']);
        $this->assertStringContainsString('VARCHAR(30)', $plan['detail'], 'the other column is still planned');
    }

    public function test_on_a_database_that_is_neither_mysql_nor_mariadb_there_is_no_plan(): void
    {
        if ($this->engineEnforcesColumnLengths()) {
            $this->markTestSkipped('This proves the behaviour on SQLite.');
        }

        [, $report] = $this->compat();

        $this->assertNotContains('column changes the migrations would make', array_column($report['checks'], 'name'));
    }

    public function test_a_database_that_has_the_old_wrong_table_name_but_not_the_platforms_is_still_refused(): void
    {
        Schema::drop('social_medias');
        Schema::create('social_media', static fn (Blueprint $blueprint) => $blueprint->id());

        [, $report] = $this->compat();

        $boot = $this->check($report, 'restore', 'tables the application boots from');
        $this->assertSame('fail', $boot['status']);
        $this->assertStringContainsString('social_medias', $boot['detail']);
        $this->assertSame(['social_medias'], $boot['facts']['missing']);
    }

    public function test_a_rate_that_is_not_a_plain_positive_number_is_reported_by_currency_code_and_never_by_value(): void
    {
        DB::table('currencies')->insert([
            ['id' => 20, 'name' => 'A', 'symbol' => 'a', 'code' => 'AAA', 'exchange_rate' => 'abc', 'status' => 1],
            ['id' => 21, 'name' => 'B', 'symbol' => 'b', 'code' => 'BBB', 'exchange_rate' => '1,5', 'status' => 1],
            ['id' => 22, 'name' => 'C', 'symbol' => 'c', 'code' => 'CCC', 'exchange_rate' => '', 'status' => 1],
            ['id' => 23, 'name' => 'D', 'symbol' => 'd', 'code' => 'DDD', 'exchange_rate' => '0', 'status' => 1],
            ['id' => 24, 'name' => 'E', 'symbol' => 'e', 'code' => 'EEE', 'exchange_rate' => '-2', 'status' => 1],
            ['id' => 25, 'name' => 'F', 'symbol' => 'f', 'code' => 'FFF', 'exchange_rate' => '1e3', 'status' => 1],
            ['id' => 26, 'name' => 'G', 'symbol' => 'g', 'code' => 'GGG', 'exchange_rate' => ' 3.75 ', 'status' => 1],
        ]);

        [, $report] = $this->compat();

        $rates = $this->check($report, 'checkout', 'exchange rates');
        $this->assertSame('warn', $rates['status']);
        $this->assertSame(6, $rates['facts']['unusable_rates']);
        $this->assertSame(9, $rates['facts']['currencies']);
        foreach (['AAA', 'BBB', 'CCC', 'DDD', 'EEE', 'FFF'] as $code) {
            $this->assertStringContainsString($code, $rates['detail']);
        }
        $this->assertStringNotContainsString('GGG', $rates['detail'], 'a rate with spaces around a plain number is usable');
        foreach (['abc', '1,5', '1e3'] as $value) {
            $this->assertStringNotContainsString($value, json_encode($rates), 'the value typed into the column is never printed');
        }
    }

    public function test_a_whole_number_rate_column_is_refused_and_the_old_decimal_one_is_still_accepted(): void
    {
        Schema::table('currencies', static fn (Blueprint $blueprint) => $blueprint->dropColumn('exchange_rate'));
        Schema::table('currencies', static fn (Blueprint $blueprint) => $blueprint->integer('exchange_rate')->default(1));
        [, $integer] = $this->compat();

        Schema::table('currencies', static fn (Blueprint $blueprint) => $blueprint->dropColumn('exchange_rate'));
        Schema::table('currencies', static fn (Blueprint $blueprint) => $blueprint->decimal('exchange_rate', 20, 8)->default(1));
        [, $decimal] = $this->compat();

        $this->assertSame('fail', $this->check($integer, 'structure', 'currencies column types')['status']);
        $this->assertStringContainsString('cannot hold a rate such as 3.75', $this->check($integer, 'structure', 'currencies column types')['detail']);
        $this->assertSame('pass', $this->check($decimal, 'structure', 'currencies column types')['status']);
    }

    public function test_a_single_currency_store_whose_default_is_not_the_riyal_is_still_flagged_and_told_how_to_fix_it(): void
    {
        DB::table('business_settings')->where('type', 'system_default_currency')->update(['value' => json_encode(1)]);

        [, $report] = $this->compat();

        $currency = $this->check($report, 'checkout', 'currency conversion');
        $this->assertSame('warn', $currency['status']);
        $this->assertStringContainsString('USD, not SAR', $currency['detail']);
        $this->assertStringContainsString('currency_aliases', $currency['detail']);
        $this->assertFalse($currency['facts']['convertible']);
    }

    public function test_the_alias_is_what_makes_the_pilot_currency_convertible(): void
    {
        config(['fulfillment.currency_aliases' => []]);

        [, $report] = $this->compat();

        $currency = $this->check($report, 'checkout', 'currency conversion');
        $this->assertSame('warn', $currency['status'], 'without the alias KSA is a different currency from SAR');
        $this->assertStringContainsString('KSA, not SAR', $currency['detail']);
    }

    public function test_a_multi_currency_pilot_store_with_text_rates_is_convertible_and_a_text_rate_nobody_can_read_is_not(): void
    {
        DB::table('business_settings')->where('type', 'currency_model')->update(['value' => json_encode('multi_currency')]);
        DB::table('currencies')->where('id', 8)->update(['exchange_rate' => '3.75']);

        [, $usable] = $this->compat();
        DB::table('currencies')->where('id', 8)->update(['exchange_rate' => '3,75']);
        [, $unusable] = $this->compat();

        $this->assertSame('pass', $this->check($usable, 'checkout', 'currency conversion')['status'], 'SAR is found under the store\'s own spelling, KSA');
        $this->assertSame('warn', $this->check($unusable, 'checkout', 'currency conversion')['status']);
        $this->assertStringContainsString('no usable rate', $this->check($unusable, 'checkout', 'currency conversion')['detail']);
    }

    private function expectation(string $table, string $column): ColumnExpectation
    {
        foreach (CompatibilitySpec::columns() as $expectation) {
            if ($expectation->table === $table && $expectation->column === $column) {
                return $expectation;
            }
        }

        $this->fail("no expectation for {$table}.{$column}");
    }
}
