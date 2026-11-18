<?php

namespace Tests\Feature\Storefront;

use App\Enums\LedgerActor;
use App\Models\Admin;
use App\Models\Seller;
use App\Services\Storefront\LedgerAttribution;
use App\Services\Storefront\LedgerOrigin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionProperty;
use Tests\Support\BuildsLedgerWorld;
use Tests\TestCase;

/**
 * E-05: what LedgerAttribution adds to a ledger row. The market is copied from the order's own record (through the refund for a refund, from the payment for a gateway
 * top-up), else it is the storefront of the request while storefronts are on, else it stays empty. The actor is whoever is signed in under the staff guard. And while the
 * migration has not run it adds nothing and reads nothing.
 */
class LedgerAttributionTest extends TestCase
{
    use BuildsLedgerWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildLedgerWorld();
    }

    protected function tearDown(): void
    {
        $this->dropLedgerWorld();

        parent::tearDown();
    }

    /** @return array<string, int|string> */
    private function attribute(?LedgerOrigin $origin = null, string $table = 'wallet_transactions'): array
    {
        return app(LedgerAttribution::class)->of(table: $table, origin: $origin);
    }

    private function signInAdmin(int $id): void
    {
        $this->actingAs((new Admin())->forceFill(['id' => $id]), 'admin');
    }

    private function signInSeller(int $id): void
    {
        $this->actingAs((new Seller())->forceFill(['id' => $id]), 'seller');
    }

    // ---- before the migration ---------------------------------------------------------------------------------------------------------------

    public function test_before_the_migration_nothing_is_added_and_no_other_table_is_read(): void
    {
        $this->dropLedgerWorld();
        $this->buildLedgerWorld(attributed: false);
        $this->ledgerOrder(id: 5, country: 'KW', currency: 'KWD', entityId: 3);
        $this->switchMarketsOn(currency: true, entities: true);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $columns = $this->servedFrom(country: 'KW', callback: fn () => $this->attribute(origin: LedgerOrigin::order(id: 5, by: LedgerActor::Admin)));
        $queries = implode(' | ', array_column(DB::getQueryLog(), 'query'));

        $this->assertSame([], $columns);
        $this->assertStringNotContainsString('orders', $queries);
        $this->assertStringNotContainsString('currencies', $queries);
        $this->assertStringNotContainsString('legal_entities', $queries);
    }

    public function test_a_no_is_asked_again_after_half_a_minute_so_a_worker_started_before_the_migration_notices_it(): void
    {
        $this->dropLedgerWorld();
        $this->buildLedgerWorld(attributed: false);
        $this->assertSame([], $this->attribute(origin: LedgerOrigin::order(id: 5)));

        $this->ledgerMigration()->up();
        $this->assertSame([], $this->attribute(origin: LedgerOrigin::order(id: 5)), 'a "no" is remembered for half a minute');

        $listings = new ReflectionProperty(LedgerAttribution::class, 'listings');
        $remembered = $listings->getValue(app(LedgerAttribution::class));
        $remembered['wallet_transactions']['at'] -= 31;
        $listings->setValue(app(LedgerAttribution::class), $remembered);

        $this->assertSame(['source_type' => 'order', 'source_id' => '5'], $this->attribute(origin: LedgerOrigin::order(id: 5)));
    }

    public function test_flush_makes_the_recorder_look_at_the_tables_again(): void
    {
        $this->dropLedgerWorld();
        $this->buildLedgerWorld(attributed: false);
        $this->assertSame([], $this->attribute(origin: LedgerOrigin::order(id: 5)));

        $this->ledgerMigration()->up();
        app(LedgerAttribution::class)->flush();

        $this->assertSame(['source_type' => 'order', 'source_id' => '5'], $this->attribute(origin: LedgerOrigin::order(id: 5)));
    }

    public function test_only_the_columns_a_ledger_has_are_filled(): void
    {
        Schema::table('wallet_transactions', fn (Blueprint $table) => $table->dropColumn(['actor_type', 'actor_id', 'storefront_country', 'currency_code', 'legal_entity_id']));
        $this->ledgerOrder(id: 5, country: 'KW', currency: 'KWD', entityId: 3);
        $this->signInAdmin(id: 4);

        $this->assertSame(['source_type' => 'order', 'source_id' => '5'], $this->attribute(origin: LedgerOrigin::order(id: 5, by: LedgerActor::Admin)));
    }

    public function test_a_movement_with_nothing_known_about_it_fills_nothing_and_no_column_is_named_with_an_empty_value(): void
    {
        $this->assertSame([], $this->attribute());
        $this->assertSame([], $this->attribute(origin: LedgerOrigin::by(actor: LedgerActor::Admin)), 'nobody is signed in');
    }

    public function test_building_an_origin_reads_nothing(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        LedgerOrigin::order(id: 5, by: LedgerActor::Admin);
        LedgerOrigin::checkout(orderIds: [5, 6]);
        LedgerOrigin::refund(id: 3, by: LedgerActor::Seller);
        LedgerOrigin::payment(id: '5b1c1c9a-0000-4000-8000-000000000001');
        LedgerOrigin::by(actor: LedgerActor::Admin);

        $this->assertSame([], DB::getQueryLog());
    }

    // ---- what stands behind the row -----------------------------------------------------------------------------------------------------------

    public function test_an_order_is_named(): void
    {
        $this->assertSame(['source_type' => 'order', 'source_id' => '5'], $this->attribute(origin: LedgerOrigin::order(id: 5)));
    }

    public function test_a_checkout_names_the_order_group_its_orders_share_found_from_the_first_of_them(): void
    {
        $this->ledgerOrder(id: 10, group: '4821-AbCde-1764930000');
        $this->ledgerOrder(id: 11, group: '4821-AbCde-1764930000');

        $this->assertSame(['source_type' => 'order_group', 'source_id' => '4821-AbCde-1764930000'], $this->attribute(origin: LedgerOrigin::checkout(orderIds: [10, 11])));
    }

    public function test_a_checkout_whose_order_cannot_be_found_or_that_made_no_order_names_nothing(): void
    {
        $this->assertSame([], $this->attribute(origin: LedgerOrigin::checkout(orderIds: [999])));
        $this->assertSame([], $this->attribute(origin: LedgerOrigin::checkout(orderIds: [])));
    }

    public function test_a_refund_and_a_payment_are_named(): void
    {
        $this->ledgerOrder(id: 5);
        $this->ledgerRefund(id: 3, orderId: 5);

        $this->assertSame(['source_type' => 'refund', 'source_id' => '3'], $this->attribute(origin: LedgerOrigin::refund(id: 3)));
        $this->assertSame(['source_type' => 'payment', 'source_id' => '5b1c1c9a-0000-4000-8000-000000000001'], $this->attribute(origin: LedgerOrigin::payment(id: '5b1c1c9a-0000-4000-8000-000000000001')));
    }

    public function test_the_loyalty_ledger_is_attributed_the_same_way_without_a_currency(): void
    {
        $this->ledgerOrder(id: 5, country: 'KW', currency: 'KWD', entityId: 3);

        $this->assertSame(
            ['source_type' => 'order', 'source_id' => '5', 'storefront_country' => 'KW', 'legal_entity_id' => 3],
            $this->attribute(origin: LedgerOrigin::order(id: 5), table: 'loyalty_point_transactions'),
        );
    }

    // ---- who made it --------------------------------------------------------------------------------------------------------------------------

    public function test_the_actor_is_the_person_signed_in_under_the_staff_guard_the_caller_names(): void
    {
        $this->signInAdmin(id: 4);
        $this->signInSeller(id: 9);

        $this->assertSame(['actor_type' => 'admin', 'actor_id' => 4], $this->attribute(origin: LedgerOrigin::by(actor: LedgerActor::Admin)));
        $this->assertSame(['actor_type' => 'seller', 'actor_id' => 9], $this->attribute(origin: LedgerOrigin::by(actor: LedgerActor::Seller)));
    }

    public function test_a_guard_nobody_is_signed_in_under_names_no_actor_even_when_another_guard_has_somebody(): void
    {
        $this->signInAdmin(id: 4);

        $this->assertSame([], $this->attribute(origin: LedgerOrigin::by(actor: LedgerActor::Seller)));
        $this->assertSame(['source_type' => 'order', 'source_id' => '5'], $this->attribute(origin: LedgerOrigin::order(id: 5, by: LedgerActor::Seller)));
    }

    public function test_a_movement_that_names_no_actor_records_none_whoever_is_signed_in(): void
    {
        $this->signInAdmin(id: 4);

        $this->assertSame(['source_type' => 'order', 'source_id' => '5'], $this->attribute(origin: LedgerOrigin::order(id: 5)));
        $this->assertSame([], $this->attribute());
    }

    // ---- the market of the movement -------------------------------------------------------------------------------------------------------------

    public function test_the_market_is_copied_from_the_orders_own_record(): void
    {
        $this->ledgerOrder(id: 5, country: 'KW', currency: 'KWD', entityId: 3);

        $this->assertSame(
            ['source_type' => 'order', 'source_id' => '5', 'storefront_country' => 'KW', 'currency_code' => 'KWD', 'legal_entity_id' => 3],
            $this->attribute(origin: LedgerOrigin::order(id: 5)),
        );
    }

    public function test_a_refund_takes_the_market_of_the_order_it_gives_back(): void
    {
        $this->ledgerOrder(id: 5, country: 'QA', currency: 'QAR', entityId: 4);
        $this->ledgerRefund(id: 3, orderId: 5);

        $this->assertSame(
            ['source_type' => 'refund', 'source_id' => '3', 'storefront_country' => 'QA', 'currency_code' => 'QAR', 'legal_entity_id' => 4],
            $this->attribute(origin: LedgerOrigin::refund(id: 3)),
        );
    }

    public function test_the_record_of_the_order_is_taken_as_it_is_and_never_mixed_with_the_request(): void
    {
        $this->ledgerOrder(id: 5, country: 'AE');
        $this->switchMarketsOn(currency: true, entities: true);
        $this->registerEntity(country: 'KW');

        $columns = $this->servedFrom(country: 'KW', callback: fn () => $this->attribute(origin: LedgerOrigin::order(id: 5)));

        $this->assertSame(['source_type' => 'order', 'source_id' => '5', 'storefront_country' => 'AE'], $columns);
    }

    public function test_an_order_with_no_record_takes_the_storefront_of_the_request_while_storefronts_are_on(): void
    {
        $this->ledgerOrder(id: 5);
        $this->switchMarketsOn();

        $columns = $this->servedFrom(country: 'KW', callback: fn () => $this->attribute(origin: LedgerOrigin::order(id: 5)));

        $this->assertSame(['source_type' => 'order', 'source_id' => '5', 'storefront_country' => 'KW'], $columns, 'no currency while the storefront currency is off, no company while the registry is off');
    }

    public function test_the_request_gives_its_currency_and_its_company_when_those_switches_are_on(): void
    {
        $entity = $this->registerEntity(country: 'KW');
        $this->switchMarketsOn(currency: true, entities: true);

        $columns = $this->servedFrom(country: 'KW', callback: fn () => $this->attribute());

        $this->assertSame(['storefront_country' => 'KW', 'currency_code' => 'KWD', 'legal_entity_id' => $entity->id], $columns);
    }

    public function test_the_default_storefront_is_recorded_too_while_storefronts_are_on(): void
    {
        $this->switchMarketsOn(currency: true);

        $this->assertSame(['storefront_country' => 'SA', 'currency_code' => 'SAR'], $this->servedFrom(country: 'SA', callback: fn () => $this->attribute()));
    }

    public function test_a_panel_a_queue_or_a_console_command_has_no_storefront_so_nothing_is_recorded(): void
    {
        $this->switchMarketsOn(currency: true, entities: true);
        $this->registerEntity(country: 'KW');

        $this->assertSame([], $this->attribute(), 'no storefront is bound to this process');
    }

    public function test_with_storefronts_off_the_market_stays_empty_even_when_a_context_is_bound(): void
    {
        config(['gcc.storefronts.enabled' => false]);

        $this->assertSame([], $this->servedFrom(country: 'KW', callback: fn () => $this->attribute()));
        $this->assertSame(['source_type' => 'order', 'source_id' => '5'], $this->servedFrom(country: 'KW', callback: fn () => $this->attribute(origin: LedgerOrigin::order(id: 5))));
    }

    public function test_an_order_table_without_the_record_columns_gives_the_request_and_fails_nothing(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['storefront_country', 'currency_code', 'currency_rate', 'legal_entity_id', 'entity_snapshot']));
        $this->ledgerOrderWithoutRecord(5);
        $this->switchMarketsOn();

        $this->assertSame(['source_type' => 'order', 'source_id' => '5', 'storefront_country' => 'KW'], $this->servedFrom(country: 'KW', callback: fn () => $this->attribute(origin: LedgerOrigin::order(id: 5))));
    }

    private function ledgerOrderWithoutRecord(int $id): void
    {
        DB::table('orders')->insert(['id' => $id, 'order_amount' => 10]);
    }

    // ---- a gateway top-up ---------------------------------------------------------------------------------------------------------------------

    public function test_a_top_up_takes_the_storefront_the_payment_was_started_in_and_the_currency_it_was_taken_in(): void
    {
        $entity = $this->registerEntity(country: 'KW');
        $this->switchMarketsOn(entities: true);
        $payment = $this->ledgerPayment(id: '5b1c1c9a-0000-4000-8000-000000000001', additionalData: ['storefront' => 'KW'], currency: 'KWD');

        $this->assertSame(
            ['source_type' => 'payment', 'source_id' => $payment, 'storefront_country' => 'KW', 'currency_code' => 'KWD', 'legal_entity_id' => $entity->id],
            $this->attribute(origin: LedgerOrigin::payment(id: $payment)),
        );
    }

    public function test_a_top_up_never_takes_the_storefront_of_the_request_the_gateway_called_back_with(): void
    {
        $this->switchMarketsOn(currency: true, entities: true);
        $this->registerEntity(country: 'SA');
        $started = $this->ledgerPayment(id: '5b1c1c9a-0000-4000-8000-000000000001', additionalData: ['storefront' => 'KW'], currency: 'KWD');
        $startedWithStorefrontsOff = $this->ledgerPayment(id: '5b1c1c9a-0000-4000-8000-000000000002', additionalData: ['payment_mode' => 'web'], currency: 'SAR');

        $fromTheCallback = $this->servedFrom(country: 'SA', callback: fn () => [$this->attribute(origin: LedgerOrigin::payment(id: $started)), $this->attribute(origin: LedgerOrigin::payment(id: $startedWithStorefrontsOff))]);

        $this->assertSame('KW', $fromTheCallback[0]['storefront_country'], 'the callback names no storefront, so the default one is bound: the payment says KW');
        $this->assertSame(['source_type' => 'payment', 'source_id' => $startedWithStorefrontsOff], $fromTheCallback[1], 'nothing was recorded with the payment, so nothing is recorded with the credit');
    }

    public function test_a_top_up_whose_payment_row_is_gone_or_holds_no_usable_storefront_names_the_payment_only(): void
    {
        $this->switchMarketsOn();
        $garbled = $this->ledgerPayment(id: '5b1c1c9a-0000-4000-8000-000000000003', additionalData: ['storefront' => ['KW']]);
        $invalid = $this->ledgerPayment(id: '5b1c1c9a-0000-4000-8000-000000000004', additionalData: ['storefront' => 'KUWAIT']);

        $this->assertSame(['source_type' => 'payment', 'source_id' => 'missing'], $this->attribute(origin: LedgerOrigin::payment(id: 'missing')));
        $this->assertSame(['source_type' => 'payment', 'source_id' => $garbled], $this->attribute(origin: LedgerOrigin::payment(id: $garbled)));
        $this->assertSame(['source_type' => 'payment', 'source_id' => $invalid], $this->attribute(origin: LedgerOrigin::payment(id: $invalid)));
    }
}
