<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\StorefrontTaxApproval;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ApprovesStorefrontTax;
use Tests\Support\BuildsStorefrontMoneyWorld;
use Tests\Support\BuildsTaxModuleWorld;
use Tests\Support\RecordsStorefrontLegalReview;
use Tests\TestCase;

/**
 * GCC-05 and D1-07: the readiness command says, for each storefront that is switched on, which tax setup its orders are taxed with, its own or none (the platform's Saudi
 * setup is never used for it), and whether a person with the authority approved that tax or decided it is none. A storefront without such a decision cannot sell, so
 * the report marks it UNSAFE and fails; what the rates must be is the owner's decision. It reads and writes nothing.
 */
class StorefrontTaxReadinessTest extends TestCase
{
    use ApprovesStorefrontTax;
    use BuildsStorefrontMoneyWorld;
    use BuildsTaxModuleWorld;
    use RecordsStorefrontLegalReview;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontMoneyWorld();
        $this->leaveTheLegalTextsOutOfThisTest();
        $this->dropTaxTables();
        foreach (['products', 'coupons', 'shipping_types'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('taxes', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->decimal('tax_rate', 8, 3)->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
        $this->createTaxModuleTables();
        $this->seedTheTaxRates();
        DB::table('taxes')->insert(['id' => 4, 'name' => 'VAT of Kuwait', 'tax_rate' => 5, 'is_active' => 1, 'is_default' => 1]);
    }

    protected function tearDown(): void
    {
        $this->dropTaxTables();
        $this->dropStorefrontMoneyWorld();

        parent::tearDown();
    }

    /** A real server keeps the tables another test class made, so each class starts from none of its own. */
    private function dropTaxTables(): void
    {
        foreach (['order_taxes', 'tax_additional_setups', 'system_tax_setups', 'taxables', 'taxes'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function approveEveryStorefrontExcept(string ...$left): void
    {
        $this->approveNoTaxFor(...array_values(array_diff(['AE', 'KW', 'QA', 'BH', 'OM'], $left)));
    }

    public function test_a_store_with_no_tax_setup_at_all_says_that_its_storefronts_carry_no_tax_and_cannot_sell(): void
    {
        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain("KW   tax: no setup of its own, so its orders carry no tax (the platform's Saudi setup is never used for it). Give it one with gcc:storefront:tax.")
            ->expectsOutputToContain('KW   UNSAFE: no approved tax decision, so it cannot sell')
            ->expectsOutputToContain('AE   UNSAFE: no approved tax decision, so it cannot sell')
            ->expectsOutputToContain('Not ready: what is marked UNSAFE above has to be fixed first')
            ->doesntExpectOutputToContain('SA   tax')
            ->assertExitCode(1);
    }

    public function test_the_platforms_setup_is_never_offered_to_a_storefront_that_has_none(): void
    {
        $this->openTheTaxSetup();

        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain("KW   tax: no setup of its own, so its orders carry no tax (the platform's Saudi setup is never used for it)")
            ->doesntExpectOutputToContain('VAT 15%')
            ->doesntExpectOutputToContain("taxed with the platform's")
            ->assertExitCode(1);
    }

    public function test_a_storefront_with_its_own_approved_setup_says_which_rates_it_has_and_which_decision_holds(): void
    {
        $this->openTheTaxSetup();
        $this->openTheTaxSetup(taxIds: [4], country: 'KW');
        $this->approveTheTaxSetupOf('KW');
        $this->approveEveryStorefrontExcept('KW');

        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain('KW   tax: its own setup (order wise, tax added to the prices): VAT of Kuwait 5%.')
            ->expectsOutputToContain('KW   tax decision: approved (setup, approved by The tax adviser, Tax opinion of the test world,')
            ->expectsOutputToContain('AE   tax decision: approved (no_tax, approved by The finance director, Tax decision of the test world,')
            ->expectsOutputToContain('Ready: every storefront that is switched on has the currency it trades in.')
            ->assertExitCode(0);
    }

    public function test_the_included_flag_the_product_wise_type_the_shipping_tax_and_an_empty_setup_are_each_named(): void
    {
        $this->openTheTaxSetup();
        $this->openTheTaxSetup(taxIds: [4], included: true, country: 'KW');
        $this->openTheTaxSetup(type: 'product_wise', taxIds: [], country: 'AE');
        $this->openTheTaxSetup(taxIds: [], country: 'QA');
        $bahrain = $this->openTheTaxSetup(taxIds: [4], country: 'BH');
        DB::table('tax_additional_setups')->insert(['name' => 'shipping', 'system_tax_setup_id' => $bahrain->id, 'tax_ids' => json_encode([self::VAT]), 'is_active' => 1]);
        $this->approveTheTaxSetupOf('KW', 'AE', 'BH');
        $this->approveNoTaxFor('QA', 'OM');

        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain('KW   tax: its own setup (order wise, tax included in the prices): VAT of Kuwait 5%.')
            ->expectsOutputToContain('AE   tax: its own setup (product wise, tax added to the prices): the rates are set on each product.')
            ->expectsOutputToContain('QA   tax: its own setup (order wise, tax added to the prices): no rate, so no tax is charged.')
            ->expectsOutputToContain('BH   tax: its own setup (order wise, tax added to the prices): VAT of Kuwait 5%; on shipping VAT 15%.')
            ->assertExitCode(0);
    }

    public function test_a_decision_goes_stale_when_the_rates_change_after_the_approval(): void
    {
        $this->openTheTaxSetup(taxIds: [4], country: 'KW');
        $this->approveTheTaxSetupOf('KW');
        $this->approveEveryStorefrontExcept('KW');
        DB::table('taxes')->where('id', 4)->update(['tax_rate' => 7]);

        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain('KW   tax: its own setup (order wise, tax added to the prices): VAT of Kuwait 7%.')
            ->expectsOutputToContain('KW   UNSAFE: the tax decision on record no longer describes what it is taxed with (setup, approved by The tax adviser,')
            ->expectsOutputToContain('AE   tax decision: approved')
            ->assertExitCode(1);
    }

    public function test_a_decision_of_no_tax_goes_stale_when_the_storefront_gets_a_rate(): void
    {
        $this->approveEveryStorefrontExcept();
        $this->openTheTaxSetup(taxIds: [4], country: 'KW');

        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain('KW   UNSAFE: the tax decision on record no longer describes what it is taxed with (no_tax, approved by The finance director,')
            ->assertExitCode(1);
    }

    public function test_a_decision_of_no_tax_survives_a_setup_with_no_rate(): void
    {
        $this->approveEveryStorefrontExcept();
        $this->openTheTaxSetup(taxIds: [], included: true, country: 'KW');

        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain('KW   tax decision: approved (no_tax')
            ->assertExitCode(0);
    }

    public function test_a_setup_that_is_switched_off_is_not_the_storefronts(): void
    {
        $this->openTheTaxSetup();
        $kuwait = $this->openTheTaxSetup(taxIds: [4], country: 'KW');
        $kuwait->update(['is_active' => 0]);
        $this->approveEveryStorefrontExcept();

        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain("KW   tax: no setup of its own, so its orders carry no tax (the platform's Saudi setup is never used for it)")
            ->assertExitCode(0);
    }

    public function test_a_world_without_the_tax_tables_says_nothing_about_tax_rates_and_still_wants_a_decision(): void
    {
        Schema::dropIfExists('system_tax_setups');

        $this->artisan('gcc:storefront:currency-readiness')->doesntExpectOutputToContain(' tax: ')->expectsOutputToContain('KW   UNSAFE: no approved tax decision')->assertExitCode(1);

        $this->approveEveryStorefrontExcept();

        $this->artisan('gcc:storefront:currency-readiness')->doesntExpectOutputToContain(' tax: ')->assertExitCode(0);
    }

    public function test_a_row_that_is_not_a_decision_counts_for_nothing(): void
    {
        $this->approveEveryStorefrontExcept('KW');
        DB::table('business_settings')->insert(['type' => StorefrontTaxApproval::settingName('KW'), 'value' => json_encode(['decision' => 'no_tax'])]);
        clearWebConfigCacheKeys();

        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain('KW   UNSAFE: the tax decision on record does not say what was decided, by whom, where or when')
            ->assertExitCode(1);
    }

    public function test_it_changes_nothing(): void
    {
        $this->openTheTaxSetup();
        $this->openTheTaxSetup(taxIds: [4], country: 'KW');
        $this->approveTheTaxSetupOf('KW');
        $tables = ['system_tax_setups', 'taxes', 'business_settings', 'audit_logs'];
        $before = array_map(static fn (string $table): array => DB::table($table)->orderBy('id')->get()->all(), $tables);

        $this->artisan('gcc:storefront:currency-readiness')->assertExitCode(1);

        $this->assertEquals($before, array_map(static fn (string $table): array => DB::table($table)->orderBy('id')->get()->all(), $tables));
    }
}
