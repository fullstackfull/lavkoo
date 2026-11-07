<?php

namespace Tests\Feature\Address;

use App\Services\Address\AddressMigrations;
use App\Services\Address\AddressModeGate;
use App\Services\Address\AddressSchema;
use App\Services\Address\AddressStatus;
use App\Services\Address\DeliveryRestrictionNotice;
use App\Services\Address\LegacyBackfill;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * The code is deployed first and migrated later. In between, the address administration must change nothing and fail on nothing: every
 * page says which migrations are still to run, every command says there is nothing to do, and the saved addresses are never touched.
 */
class AdminUnmigratedStoreTest extends AdminAddressTestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $addressesBefore = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->signIn();
        $this->address(['city' => 'الرياض']);
        $this->address(['city' => 'الهفوف', 'state' => 'المنطقة الشرقية']);

        $this->rollBackEveryAddressMigration();
        $this->addressesBefore = $this->savedAddresses();
        Http::preventStrayRequests();
    }

    private function rollBackEveryAddressMigration(): void
    {
        foreach (array_reverse(AddressMigrations::MIGRATIONS) as $migration) {
            (require database_path("migrations/{$migration}.php"))->down();
        }
        AddressSchema::flush();
    }

    /** @return array<int, array<string, mixed>> */
    private function savedAddresses(): array
    {
        return DB::table('shipping_addresses')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
    }

    private function migrate(string $migration): void
    {
        (require database_path("migrations/{$migration}.php"))->up();
        AddressSchema::flush();
    }

    /** @return list<string> */
    private function pages(): array
    {
        return [
            route('admin.address-system.index'),
            route('admin.address-system.regions.index'),
            route('admin.address-system.cities.index'),
            route('admin.address-system.cities.create'),
            route('admin.address-system.cities.edit', 1),
            route('admin.address-system.legacy.index'),
            route('admin.address-system.import.index'),
        ];
    }

    public function test_the_arrangement_really_is_a_store_without_the_address_tables_and_columns(): void
    {
        $this->assertFalse(AddressSchema::ready());
        $this->assertFalse(AddressSchema::mastersReady());
        $this->assertFalse(AddressSchema::columnsReady());
        $this->assertFalse(AddressSchema::mappingsReady());
        foreach (['address_regions', 'address_cities', 'address_city_aliases', 'provider_city_mappings', 'provider_city_sync_runs'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }
        $this->assertTrue(Schema::hasTable('shipping_addresses'), 'the platform\'s own address book is not ours to drop');
        $this->assertFalse(Schema::hasColumn('shipping_addresses', 'city_id'));
        $this->assertSame(AddressSchema::MODE_OFF, AddressSchema::mode());
    }

    public function test_every_page_explains_what_is_missing_instead_of_failing(): void
    {
        foreach ($this->pages() as $url) {
            $html = $this->get($url)->assertStatus(503)->getContent();

            $this->assertStringContainsString(e(translate('address_not_migrated')), $html, $url);
            foreach (AddressMigrations::MIGRATIONS as $migration) {
                $this->assertStringContainsString("php artisan migrate --path=database/migrations/{$migration}.php", $html, "{$url} names {$migration}");
            }
        }
    }

    public function test_nothing_can_be_changed_through_the_pages_before_the_migrations(): void
    {
        $settings = DB::table('business_settings')->count();

        $this->post(route('admin.address-system.mode.update'), ['mode' => 'soft'])->assertStatus(503);
        $this->post(route('admin.address-system.regions.update', 1), ['name_ar' => 'س', 'name_en' => 'S'])->assertStatus(503);
        $this->post(route('admin.address-system.regions.status', 1), ['active' => 0])->assertStatus(503);
        $this->post(route('admin.address-system.cities.store'), ['region_id' => 1, 'name_ar' => 'س', 'name_en' => 'S'])->assertStatus(503);
        $this->post(route('admin.address-system.cities.update', 1), ['region_id' => 1, 'name_ar' => 'س', 'name_en' => 'S'])->assertStatus(503);
        $this->post(route('admin.address-system.cities.status', 1), ['active' => 0])->assertStatus(503);
        $this->post(route('admin.address-system.legacy.confirm'), ['text' => 'الرياض', 'city_id' => 1])->assertStatus(503);
        $this->post(route('admin.address-system.legacy.apply'))->assertStatus(503);
        $this->post(route('admin.address-system.import.preview'))->assertStatus(503);
        $this->post(route('admin.address-system.import.apply'), ['confirm' => '1'])->assertStatus(503);

        $this->assertSame($settings, DB::table('business_settings')->count(), 'the mode was not saved');
        $this->assertSame($this->addressesBefore, $this->savedAddresses());
        $this->assertFalse(Schema::hasTable('address_cities'), 'and nothing was created');
        Http::assertNothingSent();
    }

    public function test_a_page_lists_only_the_migrations_that_are_still_to_run(): void
    {
        $this->migrate(AddressMigrations::MIGRATIONS[0]);

        $html = $this->get(route('admin.address-system.index'))->assertStatus(503)->getContent();

        $this->assertStringNotContainsString('--path=database/migrations/' . AddressMigrations::MIGRATIONS[0] . '.php', $html, 'already done');
        $this->assertStringContainsString('--path=database/migrations/' . AddressMigrations::MIGRATIONS[1] . '.php', $html);
        $this->assertStringContainsString(e(translate('address_migration_done')), $html);
        $this->assertStringContainsString(e(translate('address_migration_pending')), $html);
    }

    public function test_the_pages_need_the_masters_and_the_address_columns_but_not_the_provider_mappings(): void
    {
        $this->migrate(AddressMigrations::MIGRATIONS[0]);
        $this->get(route('admin.address-system.index'))->assertStatus(503);

        $this->migrate(AddressMigrations::MIGRATIONS[1]);
        $html = $this->get(route('admin.address-system.index'))->assertOk()->getContent();
        $this->assertStringContainsString(e(translate('address_mappings_not_ready')), $html, 'the overview says the mapping tables are still to come');
        $this->get(route('admin.address-system.legacy.index'))->assertOk();

        $this->migrate(AddressMigrations::MIGRATIONS[2]);
        $this->migrate(AddressMigrations::MIGRATIONS[3]);
        $this->get(route('admin.address-system.index'))->assertOk();
    }

    public function test_the_migration_list_follows_what_is_on_the_database(): void
    {
        $pending = static fn (): array => array_keys(array_filter(AddressMigrations::status(), static fn (bool $done): bool => !$done));
        $this->assertSame(array_fill_keys(AddressMigrations::MIGRATIONS, false), AddressMigrations::status());

        foreach (AddressMigrations::MIGRATIONS as $position => $migration) {
            $this->migrate($migration);

            $this->assertSame(array_slice(AddressMigrations::MIGRATIONS, $position + 1), $pending(), "after {$migration}");
        }
        $this->assertSame([], $pending());
    }

    public function test_the_directory_columns_count_as_migrated_only_when_the_provider_cities_table_has_them(): void
    {
        $this->migrate(AddressMigrations::MIGRATIONS[3]);
        $this->assertTrue(AddressMigrations::status()[AddressMigrations::MIGRATIONS[3]]);

        Schema::table('provider_cities', static function ($table): void {
            $table->dropColumn('prefix');
        });
        $this->assertFalse(AddressMigrations::status()[AddressMigrations::MIGRATIONS[3]]);

        Schema::drop('provider_cities');
        $this->assertFalse(AddressMigrations::status()[AddressMigrations::MIGRATIONS[3]], 'no table at all: the fulfillment migrations come first');
    }

    public function test_the_commands_do_nothing_and_succeed(): void
    {
        $this->artisan('address:backfill-legacy')->expectsOutputToContain('The address migrations have not been run yet; nothing to do.')->assertExitCode(0);
        $this->artisan('address:backfill-legacy', ['--apply' => true])->expectsOutputToContain('The address migrations have not been run yet; nothing to do.')->assertExitCode(0);
        $this->artisan('address:status')->expectsOutputToContain('The address migrations have not been run yet; nothing to report.')->assertExitCode(0);

        $this->assertSame($this->addressesBefore, $this->savedAddresses());
        $this->assertSame([], DB::table('audit_logs')->pluck('action')->all());
        Http::assertNothingSent();
    }

    public function test_the_status_command_lists_every_migration_as_pending(): void
    {
        Artisan::call('address:status');
        $output = Artisan::output();

        foreach (AddressMigrations::MIGRATIONS as $migration) {
            $this->assertMatchesRegularExpression('/pending\s+' . preg_quote($migration, '/') . '/', $output);
        }
    }

    public function test_the_services_behind_the_pages_are_harmless_too(): void
    {
        $report = app(AddressStatus::class)->report();

        $this->assertFalse($report['ready']);
        $this->assertNull($report['catalog']);
        $this->assertNull($report['addresses']);
        $this->assertSame([], $report['providers']);
        $this->assertSame(['configured' => 'off', 'in_force' => 'off'], $report['mode']);
        $this->assertNull(app(DeliveryRestrictionNotice::class)->forPage());
        $this->assertSame(['address_mode_blocked_not_migrated'], app(AddressModeGate::class)->problems('soft'));
        $this->assertSame(0, app(LegacyBackfill::class)->run(apply: true)['updated']);
    }

    public function test_once_the_migrations_have_run_the_pages_work_again(): void
    {
        foreach (AddressMigrations::MIGRATIONS as $migration) {
            $this->migrate($migration);
        }

        foreach ($this->pages() as $url) {
            if (str_contains($url, '/cities/1/edit')) {
                continue;
            }
            $this->get($url)->assertOk();
        }
        $this->get(route('admin.address-system.cities.edit', 1))->assertNotFound('there is no city 1 yet');
        $this->assertSame(
            $this->addressesBefore,
            array_map(static fn (array $row): array => array_diff_key($row, array_flip(AddressSchema::ADDRESS_COLUMNS)), $this->savedAddresses()),
            'the old addresses came through the migrations as they were',
        );
    }
}
