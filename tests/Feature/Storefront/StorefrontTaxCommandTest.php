<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\StorefrontCurrency;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\TaxModule\app\Models\SystemTaxSetup;
use Modules\TaxModule\app\Traits\VatTaxManagement;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ApprovesStorefrontTax;
use Tests\Support\BuildsStorefrontMoneyWorld;
use Tests\Support\BuildsTaxModuleWorld;
use Tests\TestCase;

/**
 * GCC-05 and D1-07: gcc:storefront:tax records the tax setup a storefront is taxed with, in place of nothing: a storefront with none is never taxed with the platform's.
 * It writes the taxes it names (reusing a tax of the same name and rate) and one setup row for the storefront, changes that row in place, switches it off instead of
 * deleting it, never touches the platform's setup, and says whether the tax approval on record still describes the storefront.
 */
class StorefrontTaxCommandTest extends TestCase
{
    use ApprovesStorefrontTax;
    use BuildsStorefrontMoneyWorld;
    use BuildsTaxModuleWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontMoneyWorld();
        $this->dropTaxTables();
        Schema::create('taxes', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->double('tax_rate', 23, 8)->default(0);
            $table->string('country_code', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
        $this->createTaxModuleTables();
        $this->seedTheTaxRates();
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

    private function setupOf(string $country): ?SystemTaxSetup
    {
        return SystemTaxSetup::query()->where('country_code', $country)->orderByDesc('id')->first();
    }

    /** @return list<array{string, float}> */
    private function ratesOfSetup(SystemTaxSetup $setup): array
    {
        return DB::table('taxes')->whereIn('id', (array) $setup->tax_ids)->orderBy('id')->get()->map(fn (object $row): array => [$row->name, (float) $row->tax_rate])->all();
    }

    public function test_it_gives_a_storefront_a_setup_with_the_taxes_it_names(): void
    {
        $this->artisan('gcc:storefront:tax', ['country' => 'AE', '--rate' => ['VAT:5', 'Tourism fee:2.5']])
            ->expectsOutputToContain('AE: its own tax setup is order wise, tax added to the prices: VAT 5%, Tourism fee 2.5%.')
            ->expectsOutputToContain('The storefront reads this setup now')
            ->assertExitCode(0);

        $setup = $this->setupOf('AE');
        $this->assertSame(['order_wise', 0, 1, 1, 'vendor'], [$setup->tax_type, $setup->is_included, $setup->is_default, $setup->is_active, $setup->tax_payer]);
        $this->assertSame([['VAT', 5.0], ['Tourism fee', 2.5]], $this->ratesOfSetup($setup));
    }

    public function test_it_says_when_storefronts_are_off_that_nothing_reads_the_setup_yet(): void
    {
        config(['gcc.storefronts.enabled' => false]);

        $this->artisan('gcc:storefront:tax', ['country' => 'KW', '--no-tax' => true])
            ->expectsOutputToContain('The storefronts are switched off, so nothing reads this setup yet.')
            ->assertExitCode(0);
    }

    public function test_the_storefront_reads_its_setup_whether_or_not_its_currency_switch_is_on(): void
    {
        config(['gcc.storefronts.currency.enabled' => false]);

        $this->artisan('gcc:storefront:tax', ['country' => 'KW', '--no-tax' => true])
            ->expectsOutputToContain('The storefront reads this setup now')
            ->assertExitCode(0);
    }

    public function test_it_says_the_storefront_cannot_sell_until_its_tax_is_approved(): void
    {
        $this->artisan('gcc:storefront:tax', ['country' => 'KW', '--rate' => ['VAT:5']])
            ->expectsOutputToContain('KW: it cannot sell until its tax is approved (gcc:storefront:tax-approve KW --decision=setup, or --decision=no_tax for a documented decision that it charges none).')
            ->assertExitCode(0);
    }

    public function test_it_says_when_the_approval_on_record_no_longer_describes_the_storefront_and_when_it_still_does(): void
    {
        $this->artisan('gcc:storefront:tax', ['country' => 'KW', '--rate' => ['VAT:5']])->assertExitCode(0);
        $this->approveTheTaxSetupOf('KW');

        $this->artisan('gcc:storefront:tax', ['country' => 'KW', '--rate' => ['VAT:5']])
            ->expectsOutputToContain('KW: the tax approval on record still describes it.')
            ->assertExitCode(0);

        $this->artisan('gcc:storefront:tax', ['country' => 'KW', '--rate' => ['VAT:6']])
            ->expectsOutputToContain('KW: the tax approval on record no longer describes what it is taxed with, so it cannot sell until the tax is approved again (gcc:storefront:tax-approve KW).')
            ->assertExitCode(0);
    }

    public function test_a_tax_of_the_same_name_and_rate_is_reused_and_one_that_is_not_active_is_not(): void
    {
        DB::table('taxes')->insert(['id' => 10, 'name' => 'VAT', 'tax_rate' => 5, 'is_active' => 0, 'is_default' => 1]);
        $taxesBefore = DB::table('taxes')->count();

        $this->artisan('gcc:storefront:tax', ['country' => 'AE', '--rate' => ['VAT:15']])->assertExitCode(0);
        $this->assertSame($taxesBefore, DB::table('taxes')->count(), 'the active VAT of 15 of the platform is reused');

        $this->artisan('gcc:storefront:tax', ['country' => 'OM', '--rate' => ['VAT:5']])->assertExitCode(0);
        $this->assertSame($taxesBefore + 1, DB::table('taxes')->count(), 'the VAT of 5 that is switched off is not used: a new one is made');
        $this->assertSame([['VAT', 5.0]], $this->ratesOfSetup($this->setupOf('OM')));
        $this->assertSame(1, (int) DB::table('taxes')->orderByDesc('id')->value('is_active'));
    }

    public function test_a_second_run_changes_the_setup_in_place_and_keeps_its_id(): void
    {
        $this->artisan('gcc:storefront:tax', ['country' => 'KW', '--rate' => ['VAT:5']])->assertExitCode(0);
        $id = $this->setupOf('KW')->id;

        $this->artisan('gcc:storefront:tax', ['country' => 'KW', '--rate' => ['VAT:10'], '--included' => true])->assertExitCode(0);

        $this->assertSame(1, SystemTaxSetup::query()->where('country_code', 'KW')->count());
        $setup = $this->setupOf('KW');
        $this->assertSame($id, $setup->id);
        $this->assertSame(1, $setup->is_included);
        $this->assertSame([['VAT', 10.0]], $this->ratesOfSetup($setup));
    }

    public function test_a_storefront_that_charges_no_tax_has_a_setup_with_no_rate(): void
    {
        $this->artisan('gcc:storefront:tax', ['country' => 'QA', '--no-tax' => true])
            ->expectsOutputToContain('QA: its own tax setup is order wise, tax added to the prices: no rate, so no tax is charged.')
            ->assertExitCode(0);

        $this->assertSame([], (array) $this->setupOf('QA')->tax_ids);
    }

    public function test_a_product_wise_setup_has_no_rate_of_its_own(): void
    {
        $this->artisan('gcc:storefront:tax', ['country' => 'BH', '--type' => 'product_wise'])
            ->expectsOutputToContain('BH: its own tax setup is product wise, tax added to the prices: the rates are set on each product.')
            ->assertExitCode(0);

        $this->assertSame(['product_wise', []], [$this->setupOf('BH')->tax_type, (array) $this->setupOf('BH')->tax_ids]);
    }

    public function test_unsetting_switches_the_setup_off_and_keeps_the_row_the_orders_name(): void
    {
        $this->openTheTaxSetup();
        $this->artisan('gcc:storefront:tax', ['country' => 'KW', '--rate' => ['VAT:5']])->assertExitCode(0);
        $id = $this->setupOf('KW')->id;

        $this->artisan('gcc:storefront:tax', ['country' => 'KW', '--unset' => true])
            ->expectsOutputToContain("KW: its own tax setup is switched off: its orders carry no tax now, and it sells only on a recorded decision that it charges none (gcc:storefront:tax-approve).")
            ->assertExitCode(0);

        $this->assertSame([$id, 0], [$this->setupOf('KW')->id, $this->setupOf('KW')->is_active]);

        $this->artisan('gcc:storefront:tax', ['country' => 'KW', '--unset' => true])
            ->expectsOutputToContain('KW has no tax setup of its own: nothing to switch off.')
            ->assertExitCode(0);
    }

    public function test_a_storefront_that_was_switched_off_is_switched_on_again_by_giving_it_a_setup(): void
    {
        $this->artisan('gcc:storefront:tax', ['country' => 'KW', '--rate' => ['VAT:5']])->assertExitCode(0);
        $this->artisan('gcc:storefront:tax', ['country' => 'KW', '--unset' => true])->assertExitCode(0);

        $this->artisan('gcc:storefront:tax', ['country' => 'KW', '--rate' => ['VAT:6']])->assertExitCode(0);

        $this->assertSame(1, SystemTaxSetup::query()->where('country_code', 'KW')->count());
        $this->assertSame([1, [['VAT', 6.0]]], [$this->setupOf('KW')->is_active, $this->ratesOfSetup($this->setupOf('KW'))]);
    }

    public function test_it_never_touches_the_platform_setup_and_the_default_storefront_keeps_reading_it(): void
    {
        $platform = $this->openTheTaxSetup();
        $before = $platform->fresh()->toArray();

        $this->artisan('gcc:storefront:tax', ['country' => 'AE', '--rate' => ['VAT:5']])->assertExitCode(0);
        $this->artisan('gcc:storefront:tax', ['country' => 'AE', '--unset' => true])->assertExitCode(0);

        $this->assertSame($before, $platform->fresh()->toArray());
        $this->assertSame(1, SystemTaxSetup::query()->whereNull('country_code')->count());
    }

    public function test_the_storefront_reads_the_setup_the_command_wrote_at_once(): void
    {
        $platform = $this->openTheTaxSetup();
        $read = fn (string $country): array => app(StorefrontCurrency::class)->within(country: $country, callback: fn () => $this->taxConfig());
        $this->assertNull($read('AE')['SystemTaxVat']->id, 'a storefront with no setup of its own is taxed with nothing, never with the platform\'s');

        $this->artisan('gcc:storefront:tax', ['country' => 'AE', '--rate' => ['VAT:5'], '--included' => true])->assertExitCode(0);

        $this->assertSame([$this->setupOf('AE')->id, 1], [$read('AE')['SystemTaxVat']->id, $read('AE')['is_included']]);
        $this->assertSame($platform->id, $read('SA')['SystemTaxVat']->id);
    }

    /** @return array<string, mixed> */
    private function taxConfig(): array
    {
        return (new class {
            use VatTaxManagement;
        })::getTaxSystemType();
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function refusedInvocations(): array
    {
        return [
            'the default storefront' => [['country' => 'SA', '--rate' => ['VAT:15']], 'SA is the default storefront: its tax is the platform\'s, set in the admin panel.'],
            'an unknown storefront' => [['country' => 'XX', '--rate' => ['VAT:5']], 'XX is not a configured storefront.'],
            'a type that does not exist' => [['country' => 'AE', '--rate' => ['VAT:5'], '--type' => 'country_wise'], 'The type must be order_wise or product_wise.'],
            'nothing saying what the storefront is taxed with' => [['country' => 'AE'], 'Give --rate name:percent for each tax, --no-tax, or --type=product_wise.'],
            'a rate and no tax together' => [['country' => 'AE', '--rate' => ['VAT:5'], '--no-tax' => true], 'Give --rate or --no-tax, not both.'],
            'a rate on a product-wise setup' => [['country' => 'AE', '--rate' => ['VAT:5'], '--type' => 'product_wise'], 'A product-wise setup has its rates on each product'],
            'a rate with no colon' => [['country' => 'AE', '--rate' => ['VAT5']], 'Each --rate is name:percent'],
            'a rate with no name' => [['country' => 'AE', '--rate' => [':5']], 'Each --rate is name:percent'],
            'a negative percent' => [['country' => 'AE', '--rate' => ['VAT:-5']], 'The percent of VAT must be a plain number from 0 to 100 with at most three decimals.'],
            'a percent above a hundred' => [['country' => 'AE', '--rate' => ['VAT:100.001']], 'The percent of VAT must be a plain number from 0 to 100'],
            'a percent with four decimals' => [['country' => 'AE', '--rate' => ['VAT:5.0001']], 'The percent of VAT must be a plain number from 0 to 100'],
            'the same tax twice' => [['country' => 'AE', '--rate' => ['VAT:5', 'vat:6']], 'vat is given twice.'],
            'a setup and unset together' => [['country' => 'AE', '--rate' => ['VAT:5'], '--unset' => true], 'Give the options of a setup or --unset, not both.'],
        ];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('refusedInvocations')]
    public function test_a_refused_invocation_says_why_and_writes_nothing(array $arguments, string $message): void
    {
        $before = [DB::table('taxes')->get()->all(), DB::table('system_tax_setups')->get()->all()];

        $this->artisan('gcc:storefront:tax', $arguments)->expectsOutputToContain($message)->assertExitCode(1);

        $this->assertEquals($before, [DB::table('taxes')->get()->all(), DB::table('system_tax_setups')->get()->all()]);
    }

    public function test_a_store_without_the_tax_module_tables_is_refused(): void
    {
        Schema::dropIfExists('system_tax_setups');

        $this->artisan('gcc:storefront:tax', ['country' => 'AE', '--rate' => ['VAT:5']])
            ->expectsOutputToContain('The tax module has no system_tax_setups table.')
            ->assertExitCode(1);
    }
}
