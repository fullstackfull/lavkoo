<?php

namespace Tests\Feature\Storefront;

use App\Models\LegalEntity;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsLegalEntityWorld;
use Tests\TestCase;

/**
 * The two commands that fill and read the registry of selling companies: `gcc:legal-entity:upsert` writes it and `gcc:legal-entity:list` reports it,
 * with an exit code that says whether a storefront that is switched on lacks its company.
 */
class LegalEntityCommandsTest extends TestCase
{
    use BuildsLegalEntityWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildLegalEntityWorld();
    }

    protected function tearDown(): void
    {
        $this->tearDownLegalEntityWorld();

        parent::tearDown();
    }

    /** @return array{0: int, 1: string} */
    private function execute(string $command, array $options = []): array
    {
        $exit = Artisan::call($command, $options);

        return [$exit, Artisan::output()];
    }

    // ── upsert ──────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_company_is_registered_with_the_facts_it_is_given_and_nothing_else(): void
    {
        [$exit, $output] = $this->execute('gcc:legal-entity:upsert', [
            'country' => 'kw',
            '--legal-name' => 'Example Kuwait W.L.L.',
            '--tax-number' => 'TAX-123',
            '--registration-number' => 'CR-9',
            '--invoice-series' => 'KW-2026',
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Registered KW: Example Kuwait W.L.L. (active).', $output);
        $entity = LegalEntity::where('country', 'KW')->firstOrFail();
        $this->assertSame(['TAX-123', 'CR-9', 'KW-2026', null, null], [$entity->tax_registration_number, $entity->commercial_registration_number, $entity->invoice_series_key, $entity->trade_name, $entity->address]);
        $this->assertTrue($entity->status);
    }

    public function test_a_second_run_changes_only_what_is_given_and_an_empty_value_clears_an_optional_field(): void
    {
        $this->execute('gcc:legal-entity:upsert', ['country' => 'QA', '--legal-name' => 'Example Qatar', '--tax-number' => 'T1', '--trade-name' => 'Qatar Shop']);

        [$exit, $output] = $this->execute('gcc:legal-entity:upsert', ['country' => 'QA', '--tax-number' => 'T2', '--trade-name' => '']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Updated QA: Example Qatar (active).', $output);
        $entity = LegalEntity::where('country', 'QA')->firstOrFail();
        $this->assertSame(['Example Qatar', 'T2', null], [$entity->legal_name, $entity->tax_registration_number, $entity->trade_name]);
        $this->assertSame(1, LegalEntity::count(), 'one company for a country');
    }

    public function test_a_company_is_deactivated_and_activated_without_losing_its_facts(): void
    {
        $this->execute('gcc:legal-entity:upsert', ['country' => 'BH', '--legal-name' => 'Example Bahrain']);

        [, $off] = $this->execute('gcc:legal-entity:upsert', ['country' => 'BH', '--deactivate' => true]);
        $this->assertStringContainsString('(inactive)', $off);
        $this->assertFalse(LegalEntity::where('country', 'BH')->firstOrFail()->status);

        [, $on] = $this->execute('gcc:legal-entity:upsert', ['country' => 'BH', '--activate' => true]);
        $this->assertStringContainsString('(active)', $on);
        $this->assertSame('Example Bahrain', LegalEntity::where('country', 'BH')->firstOrFail()->legal_name);
    }

    public function test_the_registry_the_commands_write_is_the_one_the_application_reads(): void
    {
        $this->execute('gcc:legal-entity:upsert', ['country' => 'OM', '--legal-name' => 'Example Oman']);

        $this->assertSame('Example Oman', app(\App\Services\Storefront\LegalEntityRegistry::class)->forCountry('OM')?->legal_name);

        $this->execute('gcc:legal-entity:upsert', ['country' => 'OM', '--deactivate' => true]);

        $this->assertNull(app(\App\Services\Storefront\LegalEntityRegistry::class)->forCountry('OM'), 'the registry is flushed by the write');
    }

    public function test_the_command_refuses_what_it_cannot_do_and_writes_nothing(): void
    {
        $cases = [
            [['country' => 'XX', '--legal-name' => 'Nobody'], 'XX is not one of the configured storefront countries'],
            [['country' => 'KW'], 'KW has no company yet: --legal-name is required.'],
            [['country' => 'KW', '--legal-name' => '   '], '--legal-name cannot be empty.'],
            [['country' => 'KW', '--legal-name' => str_repeat('a', 192)], '--legal-name is longer than 191 characters.'],
            [['country' => 'KW', '--legal-name' => 'X', '--tax-number' => str_repeat('1', 65)], '--tax-number is longer than 64 characters.'],
            [['country' => 'KW', '--legal-name' => 'X', '--invoice-series' => 'bad series!'], '--invoice-series takes letters, digits and dashes only.'],
            [['country' => 'KW', '--legal-name' => 'X', '--activate' => true, '--deactivate' => true], '--activate and --deactivate cannot be given together.'],
        ];

        foreach ($cases as [$arguments, $message]) {
            [$exit, $output] = $this->execute('gcc:legal-entity:upsert', $arguments);

            $this->assertSame(1, $exit, $message);
            $this->assertStringContainsString($message, $output);
        }

        $this->assertSame(0, LegalEntity::count());
    }

    public function test_the_command_says_to_run_the_migrations_when_the_table_is_missing(): void
    {
        Schema::drop('legal_entities');
        app(\App\Services\Storefront\LegalEntityRegistry::class)->flush();

        [$exit, $output] = $this->execute('gcc:legal-entity:upsert', ['country' => 'KW', '--legal-name' => 'X']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('run the migrations first', $output);
    }

    // ── list ────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_list_names_every_country_and_exits_1_while_a_storefront_that_is_on_has_no_company(): void
    {
        $this->registerEntity('KW');

        [$exit, $output] = $this->execute('gcc:legal-entity:list');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Legal entities: on', $output);
        $this->assertStringContainsString('KW   storefront on', $output);
        $this->assertStringContainsString('Lavkoo KW Trading Co. (tax TAX-KW-001)', $output);
        $this->assertStringContainsString('Not ready: a storefront that is switched on has no active company', $output);
    }

    public function test_the_list_exits_0_when_every_storefront_that_is_on_has_its_company_and_the_default_one_may_have_none(): void
    {
        foreach (['AE', 'KW', 'QA', 'BH', 'OM'] as $country) {
            $this->registerEntity($country);
        }

        [$exit, $output] = $this->execute('gcc:legal-entity:list');

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Nothing is missing for a storefront that is switched on.', $output);
        $this->assertStringContainsString('SA   storefront on missing', $output);
    }

    public function test_the_list_is_informational_while_the_switch_is_off(): void
    {
        config(['gcc.storefronts.legal_entities.enabled' => false]);

        [$exit, $output] = $this->execute('gcc:legal-entity:list');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Legal entities: off', $output);
    }

    public function test_the_list_as_json_carries_the_flags_and_one_row_per_country(): void
    {
        $this->registerEntity('QA', ['status' => false]);
        config(['gcc.storefronts.legal_entities.required_to_sell' => true]);

        [, $output] = $this->execute('gcc:legal-entity:list', ['--json' => true]);
        $report = json_decode($output, true);

        $this->assertTrue($report['enabled']);
        $this->assertTrue($report['required_to_sell']);
        $this->assertSame(['SA', 'AE', 'KW', 'QA', 'BH', 'OM'], array_column($report['entities'], 'country'));
        $this->assertSame('inactive', array_column($report['entities'], 'status', 'country')['QA']);
    }

    public function test_the_list_says_to_run_the_migrations_when_the_table_is_missing(): void
    {
        Schema::drop('legal_entities');
        app(\App\Services\Storefront\LegalEntityRegistry::class)->flush();

        [$exit, $output] = $this->execute('gcc:legal-entity:list');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('The legal_entities table does not exist yet', $output);
    }
}
