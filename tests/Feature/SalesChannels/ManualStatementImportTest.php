<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\OperationalCosts\CostState;
use App\Models\ExternalOrderMapping;
use App\Models\OperationalCostEntry;
use App\Models\SalesChannelEvent;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\SalesChannels\Finance\ManualStatementImporter;
use InvalidArgumentException;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\Support\FakeCurrencyRates;
use Tests\TestCase;

/** A statement a person exports from the seller portal: all or nothing, once, and never as revenue. */
class ManualStatementImportTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    private const HEADER = "order_id,package_id,posted_at,category,component,amount,currency,description\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR']);
    }

    public function test_a_statement_becomes_costs_once_and_a_payout_is_never_revenue(): void
    {
        $account = $this->trendyolAccount();
        ExternalOrderMapping::create(['account_id' => $account->id, 'sales_channel' => 'trendyol', 'seller_id' => 0, 'external_order_id' => '111', 'external_package_id' => '900', 'status' => 'delivered', 'currency' => 'SAR']);
        $csv = self::HEADER
            . "111,900,2026-11-05,fee,marketplace_commission,25.00,SAR,Commission\n"
            . "111,900,2026-11-05,fee,marketplace_commission,-10.00,SAR,Part returned\n"
            . ",,2026-11-06,payout,other,900.00,SAR,Weekly payment\n"
            . ",,2026-11-06,unclassified,other,40.00,SAR,Unknown deduction\n";
        $importer = app(ManualStatementImporter::class);

        $first = $importer->import($account, $csv);
        $second = $importer->import($account, $csv);

        $this->assertSame([4, 4, 0, 1, 1], [$first['rows'], $first['new'], $first['duplicates'], $first['costs_recorded'], $first['needs_review']]);
        $this->assertSame([4, 4], [$second['rows'], $second['duplicates']]);
        $effective = OperationalCostEntry::where('is_effective', true)->get();
        $this->assertCount(1, $effective);
        $this->assertSame([CostState::Actual, '15.000000'], [$effective[0]->state, $effective[0]->amount], 'Netted like an API statement; the payout is not a cost.');
        $this->assertSame(4, SalesChannelEvent::count());
    }

    public function test_a_bad_row_refuses_the_whole_file_and_names_the_line(): void
    {
        $account = $this->trendyolAccount();
        $csv = self::HEADER . "111,900,2026-11-05,fee,marketplace_commission,25.00,SAR,ok\n" . "111,900,2026-11-05,fee,not_a_component,10.00,SAR,bad\n";

        try {
            app(ManualStatementImporter::class)->import($account, $csv);
            $this->fail('A file with a bad row was imported.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Line 3', $exception->getMessage());
        }
        $this->assertSame(0, SalesChannelEvent::count(), 'Nothing from the good row either.');
    }

    public function test_the_columns_amounts_dates_and_currencies_are_validated(): void
    {
        $account = $this->trendyolAccount();
        $importer = app(ManualStatementImporter::class);
        $cases = [
            "order_id,amount\n1,2\n" => 'lacks',
            self::HEADER . "1,2,2026-11-05,fee,shipping,12,5,SAR,x\n" => 'currency',
            self::HEADER . "1,2,2026-11-05,fee,shipping,abc,SAR,x\n" => 'decimal',
            self::HEADER . "1,2,not a date,fee,shipping,5.00,SAR,x\n" => 'not a date',
            self::HEADER . "1,2,2026-11-05,spend,shipping,5.00,SAR,x\n" => 'category',
            '' => 'empty',
        ];
        foreach ($cases as $csv => $needle) {
            try {
                $importer->import($account, (string) $csv);
                $this->fail("Accepted: {$needle}");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString($needle, $exception->getMessage());
            }
        }
    }

    public function test_dates_are_read_strictly_and_an_absurd_amount_is_refused(): void
    {
        $account = $this->trendyolAccount();
        $importer = app(ManualStatementImporter::class);
        $cases = [
            self::HEADER . "1,2,today,fee,shipping,5.00,SAR,x\n" => 'not a date',
            self::HEADER . "1,2,2026-02-31,fee,shipping,5.00,SAR,x\n" => 'not a day of the calendar',
            self::HEADER . "1,2,0000-00-00,fee,shipping,5.00,SAR,x\n" => 'calendar',
            self::HEADER . "1,2,05/11/2026,fee,shipping,5.00,SAR,x\n" => 'not a date',
            self::HEADER . "1,2,2026-11-05,fee,shipping,99999999999999999999.5,SAR,x\n" => 'at most 14 digits',
        ];
        foreach ($cases as $csv => $needle) {
            try {
                $importer->import($account, (string) $csv);
                $this->fail("Accepted: {$needle}");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString($needle, $exception->getMessage());
            }
        }

        $good = $importer->parse($account, self::HEADER . "1,2,2026-11-05 14:30:00,fee,shipping,5.00,SAR,x\n1,2,2026-11-05T14:30:00+03:00,fee,shipping,5.00,SAR,y\n");
        $this->assertCount(2, $good);
    }

    public function test_two_identical_lines_in_one_file_are_two_charges_and_importing_the_file_again_still_adds_nothing(): void
    {
        $account = $this->trendyolAccount();
        ExternalOrderMapping::create(['account_id' => $account->id, 'sales_channel' => 'trendyol', 'seller_id' => 0, 'external_order_id' => '111', 'external_package_id' => '900', 'status' => 'delivered', 'currency' => 'SAR']);
        $csv = self::HEADER
            . "111,900,2026-11-05,fee,marketplace_service_fee,5.00,SAR,Per unit fee\n"
            . "111,900,2026-11-05,fee,marketplace_service_fee,5.00,SAR,Per unit fee\n";
        $importer = app(ManualStatementImporter::class);

        $first = $importer->import($account, $csv);
        $second = $importer->import($account, $csv);

        $this->assertSame([2, 0], [$first['new'], $first['duplicates']], 'Two fees of 5.00 are two facts.');
        $this->assertSame([0, 2], [$second['new'], $second['duplicates']], 'The same file again is the same two facts.');
        $this->assertSame('10.000000', OperationalCostEntry::where('is_effective', true)->where('cost_component', 'marketplace_service_fee')->value('amount'));
    }

    public function test_semicolon_files_and_a_byte_order_mark_are_read(): void
    {
        $account = $this->trendyolAccount();
        $csv = "\xEF\xBB\xBF" . str_replace(',', ';', self::HEADER) . "111;900;2026-11-05;fee;shipping;7.50;SAR;Carrier invoice\n";

        $result = app(ManualStatementImporter::class)->import($account, $csv);

        $this->assertSame(1, $result['rows']);
    }

    public function test_the_command_imports_a_file(): void
    {
        $account = $this->trendyolAccount();
        $path = tempnam(sys_get_temp_dir(), 'stmt');
        file_put_contents($path, self::HEADER . ",,2026-11-06,payout,other,900.00,SAR,Weekly payment\n");

        $this->artisan('channels:import-statement', ['account' => $account->id, 'file' => $path])->expectsOutputToContain('1 rows: 1 new')->assertSuccessful();
        $this->artisan('channels:import-statement', ['account' => $account->id, 'file' => $path])->expectsOutputToContain('1 already imported')->assertSuccessful();
        $this->artisan('channels:import-statement', ['account' => 999, 'file' => $path])->assertFailed();
        unlink($path);
    }
}
