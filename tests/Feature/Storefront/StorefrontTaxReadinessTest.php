<?php

namespace Tests\Feature\Storefront;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\TaxModule\app\Models\SystemTaxSetup;
use Tests\Support\BuildsStorefrontMoneyWorld;
use Tests\Support\BuildsTaxModuleWorld;
use Tests\TestCase;

/**
 * GCC-05 and D1-07: the readiness command says, for each storefront that is switched on, which tax setup its orders are taxed with: its own, the platform's, or none.
 * It is a warning and never a failure, because what the rates must be is the owner's decision; it reads and writes nothing.
 */
class StorefrontTaxReadinessTest extends TestCase
{
    use BuildsStorefrontMoneyWorld;
    use BuildsTaxModuleWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontMoneyWorld();
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

    public function test_a_store_with_no_tax_setup_at_all_says_that_no_tax_is_charged(): void
    {
        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain('KW   tax: no setup of its own and none on the platform, so no tax is charged.')
            ->expectsOutputToContain('AE   tax: no setup of its own and none on the platform, so no tax is charged.')
            ->doesntExpectOutputToContain('SA   tax')
            ->assertExitCode(0);
    }

    public function test_a_storefront_without_a_setup_of_its_own_is_told_it_is_taxed_with_the_platforms(): void
    {
        $this->openTheTaxSetup();

        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain("KW   tax: no setup of its own, so its orders are taxed with the platform's (order wise, tax added to the prices): VAT 15%, Municipality fee 5%. Give it one with gcc:storefront:tax.")
            ->expectsOutputToContain("QA   tax: no setup of its own, so its orders are taxed with the platform's")
            ->assertExitCode(0);
    }

    public function test_a_storefront_with_its_own_setup_is_told_which_rates_it_has_and_the_others_keep_the_warning(): void
    {
        $this->openTheTaxSetup();
        $this->openTheTaxSetup(taxIds: [4], country: 'KW');

        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain('KW   tax: its own setup (order wise, tax added to the prices): VAT of Kuwait 5%.')
            ->expectsOutputToContain("AE   tax: no setup of its own, so its orders are taxed with the platform's")
            ->assertExitCode(0);
    }

    public function test_the_included_flag_the_product_wise_type_and_an_empty_setup_are_each_named(): void
    {
        $this->openTheTaxSetup();
        $this->openTheTaxSetup(taxIds: [4], included: true, country: 'KW');
        $this->openTheTaxSetup(type: 'product_wise', taxIds: [], country: 'AE');
        $this->openTheTaxSetup(taxIds: [], country: 'QA');

        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain('KW   tax: its own setup (order wise, tax included in the prices): VAT of Kuwait 5%.')
            ->expectsOutputToContain('AE   tax: its own setup (product wise, tax added to the prices): the rates are set on each product.')
            ->expectsOutputToContain('QA   tax: its own setup (order wise, tax added to the prices): no rate, so no tax is charged.')
            ->assertExitCode(0);
    }

    public function test_a_setup_that_is_switched_off_is_not_the_storefronts(): void
    {
        $this->openTheTaxSetup();
        $kuwait = $this->openTheTaxSetup(taxIds: [4], country: 'KW');
        $kuwait->update(['is_active' => 0]);

        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain("KW   tax: no setup of its own, so its orders are taxed with the platform's")
            ->assertExitCode(0);
    }

    public function test_a_world_without_the_tax_tables_says_nothing_about_tax(): void
    {
        Schema::dropIfExists('system_tax_setups');

        $this->artisan('gcc:storefront:currency-readiness')->doesntExpectOutputToContain(' tax: ')->assertExitCode(0);
    }

    public function test_it_changes_nothing(): void
    {
        $this->openTheTaxSetup();
        $this->openTheTaxSetup(taxIds: [4], country: 'KW');
        $before = [SystemTaxSetup::query()->orderBy('id')->get()->toArray(), DB::table('taxes')->get()->all()];

        $this->artisan('gcc:storefront:currency-readiness')->assertExitCode(0);

        $this->assertEquals($before, [SystemTaxSetup::query()->orderBy('id')->get()->toArray(), DB::table('taxes')->get()->all()]);
    }
}
