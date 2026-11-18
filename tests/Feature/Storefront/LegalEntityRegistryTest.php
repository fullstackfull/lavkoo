<?php

namespace Tests\Feature\Storefront;

use App\Models\LegalEntity;
use App\Services\Storefront\LegalEntityRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BuildsLegalEntityWorld;
use Tests\TestCase;

/**
 * GCC-13: one selling company for each storefront country, in a registry that ships empty and does nothing until it is switched on. Nothing here
 * invents a company: the rows are fixtures with an obvious "Example" address.
 */
class LegalEntityRegistryTest extends TestCase
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

    private function registry(): LegalEntityRegistry
    {
        return app(LegalEntityRegistry::class);
    }

    public function test_the_registry_ships_empty_and_every_country_has_no_company(): void
    {
        foreach (['SA', 'AE', 'KW', 'QA', 'BH', 'OM'] as $country) {
            $this->assertNull($this->registry()->forCountry($country), $country);
        }
        $this->assertSame(0, LegalEntity::count());
    }

    #[DataProvider('countries')]
    public function test_each_country_finds_its_own_active_company(string $country): void
    {
        foreach (['SA', 'AE', 'KW', 'QA', 'BH', 'OM'] as $code) {
            $this->registerEntity($code);
        }

        $entity = $this->registry()->forCountry($country);

        $this->assertNotNull($entity);
        $this->assertSame($country, $entity->country);
        $this->assertSame("Lavkoo {$country} Trading Co.", $entity->legal_name);
    }

    /** @return array<string, array{string}> */
    public static function countries(): array
    {
        return array_combine(['SA', 'AE', 'KW', 'QA', 'BH', 'OM'], array_map(static fn (string $code): array => [$code], ['SA', 'AE', 'KW', 'QA', 'BH', 'OM']));
    }

    public function test_the_country_is_found_whatever_the_case_it_is_asked_in(): void
    {
        $this->registerEntity('KW');

        $this->assertSame('KW', $this->registry()->forCountry(' kw ')?->country);
    }

    public function test_an_inactive_company_is_not_found(): void
    {
        $this->registerEntity('KW', ['status' => false]);

        $this->assertNull($this->registry()->forCountry('KW'));
    }

    public function test_nothing_is_found_while_the_switch_is_off_or_storefronts_are_off(): void
    {
        $this->registerEntity('KW');

        config(['gcc.storefronts.legal_entities.enabled' => false]);
        $this->registry()->flush();
        $this->assertNull($this->registry()->forCountry('KW'), 'the switch is off');

        config(['gcc.storefronts.legal_entities.enabled' => true, 'gcc.storefronts.enabled' => false]);
        $this->registry()->flush();
        $this->assertNull($this->registry()->forCountry('KW'), 'storefronts are off');
    }

    public function test_nothing_is_found_and_nothing_throws_before_the_migration_has_run(): void
    {
        Schema::dropIfExists('legal_entities');
        $this->registry()->flush();

        $this->assertNull($this->registry()->forCountry('KW'));
        $this->assertFalse($this->registry()->isMigrated());
        $this->assertSame('missing', $this->registry()->report()[2]['status']);
    }

    public function test_a_country_has_one_company_and_a_second_row_for_it_is_refused(): void
    {
        $this->registerEntity('KW');

        $this->expectException(QueryException::class);
        $this->registerEntity('KW', ['legal_name' => 'Another Co.']);
    }

    public function test_the_frozen_identity_carries_the_fields_an_order_must_keep(): void
    {
        $entity = $this->registerEntity('QA');

        $this->assertSame([
            'legal_entity_id' => $entity->id,
            'country' => 'QA',
            'legal_name' => 'Lavkoo QA Trading Co.',
            'trade_name' => 'Lavkoo QA',
            'tax_registration_number' => 'TAX-QA-001',
            'commercial_registration_number' => 'CR-QA-777',
            'invoice_series_key' => 'INV-QA',
            'address' => '1 Example Street, QA',
        ], $this->registry()->identityOf($entity));
    }

    public function test_a_change_in_this_process_is_seen_after_the_registry_is_flushed_and_not_before(): void
    {
        $entity = $this->registerEntity('OM');
        $this->assertNotNull($this->registry()->forCountry('OM'));

        $entity->update(['status' => false]);
        $this->assertNotNull($this->registry()->forCountry('OM'), 'remembered for a moment, so a request asks the table once');

        $this->registry()->flush();
        $this->assertNull($this->registry()->forCountry('OM'));
    }

    public function test_a_company_may_be_required_to_sell_except_in_the_default_storefront(): void
    {
        $this->assertTrue($this->registry()->isReadyToSell('KW'), 'not required: nothing is refused');

        config(['gcc.storefronts.legal_entities.required_to_sell' => true]);

        $this->assertFalse($this->registry()->isReadyToSell('KW'), 'required, none registered');
        $this->assertTrue($this->registry()->isReadyToSell('SA'), 'the default storefront is never refused');

        $this->registerEntity('KW');
        $this->assertTrue($this->registry()->isReadyToSell('KW'));

        $this->registerEntity('BH', ['status' => false]);
        $this->assertFalse($this->registry()->isReadyToSell('BH'), 'an inactive company does not count');
    }

    public function test_the_requirement_does_nothing_while_the_registry_is_off(): void
    {
        config(['gcc.storefronts.legal_entities.enabled' => false, 'gcc.storefronts.legal_entities.required_to_sell' => true]);

        $this->assertTrue($this->registry()->isReadyToSell('KW'));
        $this->assertFalse($this->registry()->isRequiredToSell());
    }

    public function test_the_report_lists_every_configured_country_with_what_the_registry_holds(): void
    {
        $this->registerEntity('KW');
        $this->registerEntity('QA', ['status' => false]);

        $rows = array_column($this->registry()->report(), null, 'country');

        $this->assertSame(['SA', 'AE', 'KW', 'QA', 'BH', 'OM'], array_keys($rows));
        $this->assertSame('missing', $rows['SA']['status']);
        $this->assertTrue($rows['SA']['is_default']);
        $this->assertSame('active', $rows['KW']['status']);
        $this->assertSame('Lavkoo KW Trading Co.', $rows['KW']['legal_name']);
        $this->assertSame('inactive', $rows['QA']['status']);
        $this->assertTrue($rows['KW']['storefront_active']);
    }

    // ── the migrations ──────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_migrations_add_a_table_and_two_nullable_columns_and_can_be_run_twice(): void
    {
        $this->runLegalEntityMigrations();

        $this->assertTrue(Schema::hasColumns('legal_entities', ['country', 'legal_name', 'tax_registration_number', 'status']));
        $this->assertTrue(Schema::hasColumns('orders', ['legal_entity_id', 'entity_snapshot']));

        DB::table('orders')->insert(['id' => 1, 'order_amount' => 5]);
        $this->assertNull(DB::table('orders')->where('id', 1)->value('legal_entity_id'), 'an order that exists today keeps the columns empty');
    }

    public function test_the_registry_table_cannot_be_rolled_back_while_it_holds_a_company(): void
    {
        $this->registerEntity('KW');
        $migration = require database_path('migrations/2026_11_27_100000_create_legal_entities_table.php');

        try {
            $migration->down();
            $this->fail('the rollback ran');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('holds companies', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('legal_entities'));

        LegalEntity::query()->delete();
        $migration->down();
        $this->assertFalse(Schema::hasTable('legal_entities'));
    }

    public function test_the_orders_columns_cannot_be_rolled_back_while_an_order_carries_a_company(): void
    {
        $entity = $this->registerEntity('KW');
        DB::table('orders')->insert(['id' => 2, 'order_amount' => 5, 'legal_entity_id' => $entity->id, 'entity_snapshot' => '{}']);
        $migration = require database_path('migrations/2026_11_27_100100_add_legal_entity_to_orders_table.php');

        try {
            $migration->down();
            $this->fail('the rollback ran');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Orders carry a selling company', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('orders', 'legal_entity_id'));

        DB::table('orders')->where('id', 2)->delete();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('orders', 'legal_entity_id'));
        $this->assertFalse(Schema::hasColumn('orders', 'entity_snapshot'));
    }
}
