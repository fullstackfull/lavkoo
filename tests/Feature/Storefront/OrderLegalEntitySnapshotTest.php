<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\OrderStorefrontSnapshot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsLegalEntityWorld;
use Tests\TestCase;

/**
 * GCC-13, D1-03: an order records who sold it, from the lines it was saved with: the id of the company of its storefront and a frozen copy of the
 * company's identity, written once. It is asked from the order snapshot of GCC-05, and it works whether or not storefront currencies are on.
 */
class OrderLegalEntitySnapshotTest extends TestCase
{
    use BuildsLegalEntityWorld;

    private const ORDER = 9001;

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

    private function record(int $orderId = self::ORDER): void
    {
        app(OrderStorefrontSnapshot::class)->record(orderId: $orderId);
    }

    /** @return array{legal_entity_id: ?int, entity_snapshot: ?array<string, mixed>} */
    private function stamp(int $orderId = self::ORDER): array
    {
        $row = DB::table('orders')->where('id', $orderId)->first(['legal_entity_id', 'entity_snapshot']);

        return [
            'legal_entity_id' => $row->legal_entity_id === null ? null : (int) $row->legal_entity_id,
            'entity_snapshot' => $row->entity_snapshot === null ? null : json_decode($row->entity_snapshot, true),
        ];
    }

    /** @return array<string, array{string}> */
    public static function countries(): array
    {
        return array_combine(['SA', 'AE', 'KW', 'QA', 'BH', 'OM'], array_map(static fn (string $code): array => [$code], ['SA', 'AE', 'KW', 'QA', 'BH', 'OM']));
    }

    #[DataProvider('countries')]
    public function test_an_order_of_each_storefront_is_stamped_with_that_countrys_company(string $country): void
    {
        $entities = [];
        foreach (['SA', 'AE', 'KW', 'QA', 'BH', 'OM'] as $code) {
            $entities[$code] = $this->registerEntity($code);
        }
        $this->orderOf(self::ORDER, $country);

        $this->record();

        $stamp = $this->stamp();
        $this->assertSame($entities[$country]->id, $stamp['legal_entity_id']);
        $this->assertSame($country, $stamp['entity_snapshot']['country']);
        $this->assertSame("Lavkoo {$country} Trading Co.", $stamp['entity_snapshot']['legal_name']);
        $this->assertSame("TAX-{$country}-001", $stamp['entity_snapshot']['tax_registration_number']);
        $this->assertSame("INV-{$country}", $stamp['entity_snapshot']['invoice_series_key']);
        $this->assertSame($entities[$country]->id, $stamp['entity_snapshot']['legal_entity_id']);
    }

    public function test_the_snapshot_keeps_non_ascii_text_as_it_was_written(): void
    {
        $this->registerEntity('KW', ['legal_name' => 'شركة لافكو للتجارة', 'trade_name' => null]);
        $this->orderOf(self::ORDER, 'KW');

        $this->record();

        $this->assertSame('شركة لافكو للتجارة', $this->stamp()['entity_snapshot']['legal_name']);
        $this->assertStringContainsString('شركة لافكو للتجارة', (string) DB::table('orders')->where('id', self::ORDER)->value('entity_snapshot'), 'stored as written, not as \\u escapes');
    }

    public function test_a_record_is_written_once_and_a_later_change_of_the_company_never_rewrites_it(): void
    {
        $entity = $this->registerEntity('KW');
        $this->orderOf(self::ORDER, 'KW');
        $this->record();
        $first = $this->stamp();

        $entity->update(['legal_name' => 'Renamed Co.', 'tax_registration_number' => 'TAX-NEW']);
        app(\App\Services\Storefront\LegalEntityRegistry::class)->flush();
        $this->record();

        $this->assertSame($first, $this->stamp());
        $this->assertSame('Lavkoo KW Trading Co.', $this->stamp()['entity_snapshot']['legal_name']);
    }

    public function test_an_order_placed_after_a_change_carries_the_new_identity_and_the_old_one_keeps_the_old(): void
    {
        $entity = $this->registerEntity('QA');
        $this->orderOf(1, 'QA');
        $this->record(1);

        $entity->update(['legal_name' => 'Lavkoo Qatar W.L.L.']);
        app(\App\Services\Storefront\LegalEntityRegistry::class)->flush();
        $this->orderOf(2, 'QA');
        $this->record(2);

        $this->assertSame('Lavkoo QA Trading Co.', $this->stamp(1)['entity_snapshot']['legal_name']);
        $this->assertSame('Lavkoo Qatar W.L.L.', $this->stamp(2)['entity_snapshot']['legal_name']);
    }

    public function test_nothing_is_stamped_while_the_switch_is_off_or_storefronts_are_off(): void
    {
        $this->registerEntity('KW');
        $this->orderOf(self::ORDER, 'KW');

        config(['gcc.storefronts.legal_entities.enabled' => false]);
        $this->record();
        $this->assertSame(['legal_entity_id' => null, 'entity_snapshot' => null], $this->stamp(), 'the switch is off');

        config(['gcc.storefronts.legal_entities.enabled' => true, 'gcc.storefronts.enabled' => false]);
        $this->record();
        $this->assertSame(['legal_entity_id' => null, 'entity_snapshot' => null], $this->stamp(), 'storefronts are off');
    }

    public function test_a_country_without_an_active_company_gets_no_stamp(): void
    {
        $this->registerEntity('QA', ['status' => false]);
        $this->orderOf(1, 'QA');
        $this->orderOf(2, 'BH');

        $this->record(1);
        $this->record(2);

        $this->assertNull($this->stamp(1)['legal_entity_id'], 'an inactive company');
        $this->assertNull($this->stamp(2)['legal_entity_id'], 'no company at all');
    }

    public function test_an_order_of_more_than_one_country_gets_no_stamp(): void
    {
        $this->registerEntity('KW');
        $this->registerEntity('QA');
        $this->orderOf(self::ORDER, 'KW', 'QA');

        $this->record();

        $this->assertNull($this->stamp()['legal_entity_id']);
    }

    public function test_nothing_is_stamped_and_nothing_throws_before_the_orders_migration_has_run(): void
    {
        $this->registerEntity('KW');
        $this->orderOf(self::ORDER, 'KW');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['legal_entity_id', 'entity_snapshot']));

        $this->record();

        $this->assertFalse(Schema::hasColumn('orders', 'legal_entity_id'));
        $this->assertSame(self::ORDER, (int) DB::table('orders')->value('id'), 'the order is untouched');
    }

    public function test_nothing_is_stamped_and_nothing_throws_before_the_registry_table_exists(): void
    {
        $this->orderOf(self::ORDER, 'KW');
        Schema::drop('legal_entities');
        app(\App\Services\Storefront\LegalEntityRegistry::class)->flush();

        $this->record();

        $this->assertNull($this->stamp()['legal_entity_id']);
    }

    public function test_the_company_is_stamped_without_the_currency_switch(): void
    {
        $this->registerEntity('KW');
        $this->orderOf(1, 'KW');

        $this->record(1);

        $row = DB::table('orders')->where('id', 1)->first();
        $this->assertSame(1, (int) $row->legal_entity_id);
        $this->assertNull($row->storefront_country, 'the currency switch is off: no storefront record');
        $this->assertNull($row->currency_code);
    }

    public function test_the_company_and_the_currency_are_recorded_together_when_both_switches_are_on(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('symbol')->nullable();
            $table->string('code')->nullable();
            $table->decimal('exchange_rate', 20, 8)->default(1);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        DB::table('currencies')->insert(['name' => 'KWD', 'symbol' => 'KD', 'code' => 'KWD', 'exchange_rate' => 0.307, 'status' => 1]);
        config(['gcc.storefronts.currency.enabled' => true]);
        $this->registerEntity('KW');
        $this->orderOf(1, 'KW');

        try {
            $this->record(1);

            $row = DB::table('orders')->where('id', 1)->first();
            $this->assertSame(1, (int) $row->legal_entity_id);
            $this->assertSame('KW', $row->storefront_country);
            $this->assertSame('KWD', $row->currency_code);
        } finally {
            Schema::dropIfExists('currencies');
        }
    }
}
