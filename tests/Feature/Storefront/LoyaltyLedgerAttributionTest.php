<?php

namespace Tests\Feature\Storefront;

use App\Enums\LedgerActor;
use App\Models\Admin;
use App\Models\Seller;
use App\Services\Storefront\LedgerOrigin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsLedgerWorld;
use Tests\TestCase;

/**
 * E-05 on the loyalty ledger, through each of the two places that write it (CustomerManager and LoyaltyPointTransactionRepository): a row names the order or the refund
 * behind it, the staff member who made a manual reversal, and the storefront and selling company of its market. A point has no currency, so the ledger has no currency
 * column. The balance, the amounts and the `reference` (the order id, the wallet row of a conversion) are exactly what they were.
 */
class LoyaltyLedgerAttributionTest extends TestCase
{
    use BuildsLedgerWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildLedgerWorld();
        $this->ledgerCustomer(id: 1, points: 1000.0);
    }

    protected function tearDown(): void
    {
        $this->dropLedgerWorld();

        parent::tearDown();
    }

    /** @param array<string, mixed> $expected */
    private function assertRow(array $expected): void
    {
        $actual = $this->newestLedgerRow(table: 'loyalty_point_transactions');
        ksort($expected);
        ksort($actual);

        $this->assertSame($expected, $actual);
    }

    /** @return array<string, mixed> the points a hundred spent earn at ten percent, as the platform writes them today, with no attribution */
    private function theSaudiEarning(): array
    {
        return [
            'user_id' => 1, 'transaction_id' => '<uuid>', 'credit' => 10.0, 'debit' => 0.0, 'balance' => 1010.0,
            'reference' => '5', 'transaction_type' => 'order_place',
            'created_at' => '2026-12-05 10:00:00', 'updated_at' => '2026-12-05 10:00:00',
        ];
    }

    private function pointsOf(int $customerId): float
    {
        return (float) DB::table('users')->where('id', $customerId)->value('loyalty_point');
    }

    #[DataProvider('loyaltyWriterCases')]
    public function test_the_points_of_an_order_name_the_order_and_keep_the_order_id_as_their_reference(string $writer): void
    {
        $this->ledgerOrder(id: 5, country: 'KW', currency: 'KWD', entityId: 3);

        $this->writeLoyaltyRow(writer: $writer, customerId: 1, reference: '5', amount: 100.0, type: 'order_place', origin: LedgerOrigin::order(id: 5));

        $this->assertRow(['source_type' => 'order', 'source_id' => '5', 'storefront_country' => 'KW', 'legal_entity_id' => 3, 'actor_type' => null, 'actor_id' => null] + $this->theSaudiEarning());
        $this->assertSame(1010.0, $this->pointsOf(customerId: 1));
    }

    #[DataProvider('loyaltyWriterCases')]
    public function test_the_points_taken_back_with_a_refund_name_the_refund_and_the_staff_member_who_refunded(string $writer): void
    {
        $this->ledgerOrder(id: 5, country: 'QA', currency: 'QAR', entityId: 4);
        $this->ledgerRefund(id: 3, orderId: 5);
        $this->actingAs((new Seller())->forceFill(['id' => 9]), 'seller');

        $this->writeLoyaltyRow(writer: $writer, customerId: 1, reference: '5', amount: 10.0, type: 'refund_order', origin: LedgerOrigin::refund(id: 3, by: LedgerActor::Seller));

        $this->assertRow([
            'source_type' => 'refund', 'source_id' => '3', 'actor_type' => 'seller', 'actor_id' => 9, 'storefront_country' => 'QA', 'legal_entity_id' => 4,
            'credit' => 0.0, 'debit' => 10.0, 'balance' => 990.0, 'transaction_type' => 'refund_order',
        ] + $this->theSaudiEarning());
    }

    #[DataProvider('loyaltyWriterCases')]
    public function test_the_administrator_who_refunded_is_the_one_signed_in_under_the_admin_guard(string $writer): void
    {
        $this->ledgerRefund(id: 3, orderId: 5);
        $this->actingAs((new Admin())->forceFill(['id' => 4]), 'admin');
        $this->actingAs((new Seller())->forceFill(['id' => 9]), 'seller');

        $this->writeLoyaltyRow(writer: $writer, customerId: 1, reference: '5', amount: 10.0, type: 'refund_order', origin: LedgerOrigin::refund(id: 3, by: LedgerActor::Admin));

        $row = $this->newestLedgerRow(table: 'loyalty_point_transactions');
        $this->assertSame(['admin', 4], [$row['actor_type'], $row['actor_id']]);
    }

    #[DataProvider('loyaltyWriterCases')]
    public function test_points_turned_into_wallet_money_take_the_storefront_and_company_of_the_request(string $writer): void
    {
        $entity = $this->registerEntity(country: 'KW');
        $this->switchMarketsOn(currency: true, entities: true);

        $this->servedFrom(country: 'KW', callback: fn () => $this->writeLoyaltyRow(writer: $writer, customerId: 1, reference: '8c1f0a52-0000-4000-8000-000000000001', amount: 100.0, type: 'point_to_wallet'));

        $row = $this->newestLedgerRow(table: 'loyalty_point_transactions');
        $this->assertSame(['KW', $entity->id], [$row['storefront_country'], $row['legal_entity_id']]);
        $this->assertSame([null, null, null, null], [$row['source_type'], $row['source_id'], $row['actor_type'], $row['actor_id']]);
        $this->assertSame('8c1f0a52-0000-4000-8000-000000000001', $row['reference'], 'the reference of a conversion is still the wallet row it made');
        $this->assertArrayNotHasKey('currency_code', $row, 'a point has no currency');
    }

    #[DataProvider('loyaltyWriterCases')]
    public function test_a_saudi_write_with_every_switch_off_leaves_the_row_exactly_as_it_was_and_the_new_columns_empty(string $writer): void
    {
        $this->writeLoyaltyRow(writer: $writer, customerId: 1, reference: '5', amount: 100.0, type: 'order_place');

        $this->assertRow($this->emptyAttribution(table: 'loyalty_point_transactions') + $this->theSaudiEarning());
        $this->assertSame(1010.0, $this->pointsOf(customerId: 1));
        $this->assertSame(1, DB::table('loyalty_point_transactions')->count());
    }

    #[DataProvider('loyaltyWriterCases')]
    public function test_before_the_migration_the_writer_saves_the_same_row_whatever_it_is_told_about_the_origin(string $writer): void
    {
        $this->dropLedgerWorld();
        $this->buildLedgerWorld(attributed: false);
        $this->ledgerCustomer(id: 1, points: 1000.0);
        $this->ledgerOrder(id: 5, country: 'KW', currency: 'KWD', entityId: 3);
        $this->switchMarketsOn(currency: true, entities: true);

        $this->servedFrom(country: 'KW', callback: fn () => $this->writeLoyaltyRow(writer: $writer, customerId: 1, reference: '5', amount: 100.0, type: 'order_place', origin: LedgerOrigin::order(id: 5, by: LedgerActor::Admin)));

        $this->assertRow($this->theSaudiEarning());
        $this->assertSame([], array_intersect(self::LEDGER_COLUMNS, Schema::getColumnListing('loyalty_point_transactions')), 'the migration has not run');
        $this->assertSame(1010.0, $this->pointsOf(customerId: 1));
    }

    #[DataProvider('loyaltyWriterCases')]
    public function test_loyalty_points_that_are_switched_off_write_nothing(string $writer): void
    {
        DB::table('business_settings')->where('type', 'loyalty_point_status')->update(['value' => '0']);
        $this->ledgerOrder(id: 5, country: 'KW', currency: 'KWD', entityId: 3);

        $this->writeLoyaltyRow(writer: $writer, customerId: 1, reference: '5', amount: 100.0, type: 'order_place', origin: LedgerOrigin::order(id: 5));

        $this->assertSame(0, DB::table('loyalty_point_transactions')->count());
        $this->assertSame(1000.0, $this->pointsOf(customerId: 1));
    }
}
