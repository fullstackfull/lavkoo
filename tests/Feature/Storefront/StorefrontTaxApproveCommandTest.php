<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\StorefrontTaxApproval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\TaxModule\app\Models\SystemTaxSetup;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ApprovesStorefrontTax;
use Tests\Support\BuildsStorefrontMoneyWorld;
use Tests\Support\BuildsTaxModuleWorld;
use Tests\TestCase;

/**
 * D1-07: gcc:storefront:tax-approve records the decision of a person with the authority that the tax of a storefront is approved, or that it was decided, in a document,
 * that it charges none; withdraws it; and says what is on record. It records what it is told: it approves nothing by itself, writes nothing it was not asked to, and never
 * touches a tax setup or a rate.
 */
class StorefrontTaxApproveCommandTest extends TestCase
{
    use ApprovesStorefrontTax;
    use BuildsStorefrontMoneyWorld;
    use BuildsTaxModuleWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontMoneyWorld();
        foreach (['order_taxes', 'tax_additional_setups', 'system_tax_setups', 'taxables', 'taxes'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('taxes', function ($table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->decimal('tax_rate', 8, 3)->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
        $this->createTaxModuleTables();
        $this->seedTheTaxRates();
        $this->buildTheTablesOfATaxDecision();
    }

    protected function tearDown(): void
    {
        foreach (['order_taxes', 'tax_additional_setups', 'system_tax_setups', 'taxables', 'taxes'] as $table) {
            Schema::dropIfExists($table);
        }
        $this->dropStorefrontMoneyWorld();

        parent::tearDown();
    }

    private function recordOf(string $country): ?array
    {
        $value = DB::table('business_settings')->where('type', StorefrontTaxApproval::settingName($country))->value('value');

        return $value === null ? null : json_decode($value, true);
    }

    public function test_it_records_a_decision_of_no_tax_with_who_and_where_and_says_what_the_storefront_may_do(): void
    {
        $this->artisan('gcc:storefront:tax-approve', ['country' => 'kw', '--decision' => 'no_tax', '--by' => 'The finance director', '--reference' => 'Tax opinion KW-2026-11'])
            ->expectsOutputToContain('KW: the decision that it charges no tax is recorded, approved by The finance director (Tax opinion KW-2026-11) on ')
            ->expectsOutputToContain('make it stale and the storefront stops selling until it is approved again.')
            ->assertExitCode(0);

        $record = $this->recordOf('KW');
        $this->assertSame(['no_tax', 'The finance director', 'Tax opinion KW-2026-11', null], [$record['decision'], $record['approved_by'], $record['reference'], $record['fingerprint']]);
        $this->assertTrue(app(StorefrontTaxApproval::class)->isApproved('KW'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'storefront.tax_decision.approved')->count());
    }

    public function test_it_approves_the_setup_the_storefront_has_now(): void
    {
        $this->openTheTaxSetup(taxIds: [self::VAT], country: 'AE');

        $this->artisan('gcc:storefront:tax-approve', ['country' => 'AE', '--decision' => 'setup', '--by' => 'The tax adviser', '--reference' => 'Opinion AE-1'])
            ->expectsOutputToContain('AE: the tax setup it has now is approved, approved by The tax adviser (Opinion AE-1)')
            ->assertExitCode(0);

        $this->assertSame('setup', $this->recordOf('AE')['decision']);
        $this->assertSame(64, strlen((string) $this->recordOf('AE')['fingerprint']));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function refusedInvocations(): array
    {
        $valid = ['country' => 'KW', '--decision' => 'no_tax', '--by' => 'The finance director', '--reference' => 'Opinion'];

        return [
            'no decision' => [['country' => 'KW', '--by' => 'The finance director', '--reference' => 'Opinion'], 'Give --decision=setup or --decision=no_tax, or --revoke, or --status.'],
            'an unknown decision' => [['--decision' => 'later'] + $valid, 'The decision must be setup'],
            'nobody' => [['--by' => ''] + $valid, 'Say who approved it'],
            'no document' => [['--reference' => ''] + $valid, 'Say where the decision is written down'],
            'an assistant' => [['--by' => 'Claude'] + $valid, 'not a tool or an assistant'],
            'the default storefront' => [['country' => 'SA'] + $valid, 'SA is the default storefront'],
            'a country that is no storefront' => [['country' => 'EG'] + $valid, 'EG is not a configured storefront.'],
            'a setup with no rates' => [['--decision' => 'setup'] + $valid, 'KW has no tax setup of its own with a rate'],
        ];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('refusedInvocations')]
    public function test_a_refused_invocation_says_why_and_writes_nothing(array $arguments, string $message): void
    {
        $this->artisan('gcc:storefront:tax-approve', $arguments)->expectsOutputToContain($message)->assertExitCode(1);

        $this->assertSame(0, DB::table('business_settings')->where('type', 'like', 'storefront_tax_approval_%')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_a_decision_of_no_tax_is_refused_for_a_storefront_that_charges_a_tax_and_the_setup_is_left_alone(): void
    {
        $setup = $this->openTheTaxSetup(taxIds: [self::VAT], country: 'KW');
        $before = $setup->fresh()->toArray();

        $this->artisan('gcc:storefront:tax-approve', ['country' => 'KW', '--decision' => 'no_tax', '--by' => 'The owner', '--reference' => 'Minutes'])
            ->expectsOutputToContain('KW has a tax setup that charges a tax')
            ->assertExitCode(1);

        $this->assertSame($before, $setup->fresh()->toArray());
        $this->assertNull($this->recordOf('KW'));
    }

    public function test_it_withdraws_a_decision_and_says_so_when_there_is_none(): void
    {
        $this->artisan('gcc:storefront:tax-approve', ['country' => 'KW', '--decision' => 'no_tax', '--by' => 'The owner', '--reference' => 'Minutes'])->assertExitCode(0);

        $this->artisan('gcc:storefront:tax-approve', ['country' => 'KW', '--revoke' => true, '--by' => 'The owner'])
            ->expectsOutputToContain('KW: the tax approval is withdrawn: the storefront cannot sell until its tax is approved again.')
            ->assertExitCode(0);
        $this->assertNull($this->recordOf('KW'));

        $this->artisan('gcc:storefront:tax-approve', ['country' => 'KW', '--revoke' => true])
            ->expectsOutputToContain('KW has no tax approval on record: nothing to withdraw.')
            ->assertExitCode(0);
    }

    public function test_the_status_says_what_is_on_record_and_fails_while_the_storefront_cannot_sell(): void
    {
        $this->artisan('gcc:storefront:tax-approve', ['country' => 'KW', '--status' => true])
            ->expectsOutputToContain('KW: missing')
            ->expectsOutputToContain('Taxed with: nothing.')
            ->assertExitCode(1);

        $this->artisan('gcc:storefront:tax-approve', ['country' => 'KW', '--decision' => 'no_tax', '--by' => 'The owner', '--reference' => 'Minutes'])->assertExitCode(0);

        $this->artisan('gcc:storefront:tax-approve', ['country' => 'KW', '--status' => true])
            ->expectsOutputToContain('KW: approved (no_tax, approved by The owner, Minutes,')
            ->assertExitCode(0);

        $this->openTheTaxSetup(taxIds: [self::VAT], country: 'KW');

        $this->artisan('gcc:storefront:tax-approve', ['country' => 'KW', '--status' => true])
            ->expectsOutputToContain('KW: stale (no_tax,')
            ->expectsOutputToContain('Taxed with: a tax setup of its own')
            ->assertExitCode(1);

        $this->artisan('gcc:storefront:tax-approve', ['country' => 'SA', '--status' => true])->expectsOutputToContain('SA: not_required')->assertExitCode(0);
    }

    public function test_the_status_changes_nothing(): void
    {
        $this->openTheTaxSetup(taxIds: [self::VAT], country: 'KW');
        $tables = ['system_tax_setups', 'taxes', 'business_settings', 'audit_logs'];
        $before = array_map(static fn (string $table): array => DB::table($table)->orderBy('id')->get()->all(), $tables);

        $this->artisan('gcc:storefront:tax-approve', ['country' => 'KW', '--status' => true])->assertExitCode(1);

        $this->assertEquals($before, array_map(static fn (string $table): array => DB::table($table)->orderBy('id')->get()->all(), $tables));
        $this->assertSame(1, SystemTaxSetup::query()->count());
    }

    public function test_it_records_nothing_when_the_audit_trail_cannot_be_written(): void
    {
        Schema::drop('audit_logs');

        $this->artisan('gcc:storefront:tax-approve', ['country' => 'KW', '--decision' => 'no_tax', '--by' => 'The owner', '--reference' => 'Minutes'])
            ->expectsOutputToContain('audit_logs has not been migrated')
            ->assertExitCode(1);

        $this->assertNull($this->recordOf('KW'));
    }

    public function test_it_works_while_the_switches_are_off_so_a_decision_can_be_prepared_before_the_launch(): void
    {
        config(['gcc.storefronts.enabled' => false, 'gcc.storefronts.currency.enabled' => false]);

        $this->artisan('gcc:storefront:tax-approve', ['country' => 'KW', '--decision' => 'no_tax', '--by' => 'The owner', '--reference' => 'Minutes'])->assertExitCode(0);

        $this->assertSame('no_tax', $this->recordOf('KW')['decision']);
    }
}
