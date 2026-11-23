<?php

namespace Tests\Feature\Storefront;

use App\Enums\LedgerActor;
use App\Models\Admin;
use App\Services\Storefront\LedgerOrigin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsLedgerWorld;
use Tests\TestCase;

/**
 * E-05 on the wallet ledger, through each of the four places that write it (CustomerManager, OrderManager, CustomerTrait and WalletTransactionRepository): a row names the
 * order, order group, refund or payment behind it (criterion 1), the staff member who made a manual movement (criterion 2), and the storefront, currency and selling company
 * of its market (criterion 5), while its amounts, its balance and its `reference` are exactly what they were.
 */
class WalletLedgerAttributionTest extends TestCase
{
    use BuildsLedgerWorld;

    private const GROUP = '4821-AbCde-1764930000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildLedgerWorld();
        $this->ledgerCustomer(id: 1, wallet: 150.0);
    }

    protected function tearDown(): void
    {
        $this->dropLedgerWorld();

        parent::tearDown();
    }

    /** @param array<string, mixed> $expected */
    private function assertRow(array $expected, string $message = ''): void
    {
        $actual = $this->newestLedgerRow();
        ksort($expected);
        ksort($actual);

        $this->assertSame($expected, $actual, $message);
    }

    /** @return array<string, mixed> a debit of forty from a wallet of a hundred and fifty, as the Saudi platform writes it today, with no attribution */
    private function theSaudiDebit(): array
    {
        return [
            'user_id' => 1, 'transaction_id' => '<uuid>', 'credit' => 0.0, 'debit' => 40.0, 'admin_bonus' => 0.0, 'balance' => 110.0,
            'transaction_type' => 'order_place', 'payment_method' => null, 'reference' => 'order payment',
            'created_at' => '2026-12-05 10:00:00', 'updated_at' => '2026-12-05 10:00:00',
        ];
    }

    private function signInAdmin(int $id): void
    {
        $this->actingAs((new Admin())->forceFill(['id' => $id]), 'admin');
    }

    // ---- criterion 1: the order, order group, refund or payment behind the row -------------------------------------------------------------------

    #[DataProvider('walletWriterCases')]
    public function test_a_debit_names_the_order_it_pays_and_keeps_its_reference(string $writer): void
    {
        $this->ledgerOrder(id: 5);

        $this->writeWalletRow(writer: $writer, customerId: 1, amount: 40.0, type: 'order_place', reference: 'order payment', origin: LedgerOrigin::order(id: 5));

        $this->assertRow(['source_type' => 'order', 'source_id' => '5'] + $this->emptyAttribution() + $this->theSaudiDebit());
    }

    #[DataProvider('walletWriterCases')]
    public function test_the_debit_of_a_checkout_names_the_order_group_its_orders_share(string $writer): void
    {
        $this->ledgerOrder(id: 10, group: self::GROUP);
        $this->ledgerOrder(id: 11, group: self::GROUP);

        $this->writeWalletRow(writer: $writer, customerId: 1, amount: 40.0, type: 'order_place', reference: 'order payment', origin: LedgerOrigin::checkout(orderIds: [10, 11]));

        $this->assertRow(['source_type' => 'order_group', 'source_id' => self::GROUP] + $this->emptyAttribution() + $this->theSaudiDebit());
    }

    #[DataProvider('walletWriterCases')]
    public function test_a_refund_credit_names_the_refund(string $writer): void
    {
        $this->ledgerOrder(id: 5);
        $this->ledgerRefund(id: 3, orderId: 5);

        $this->writeWalletRow(writer: $writer, customerId: 1, amount: 30.0, type: 'order_refund', reference: 'order_refund', origin: LedgerOrigin::refund(id: 3));

        $this->assertRow(['source_type' => 'refund', 'source_id' => '3'] + $this->emptyAttribution() + [
            'credit' => 30.0, 'debit' => 0.0, 'balance' => 180.0, 'transaction_type' => 'order_refund', 'reference' => 'order_refund',
        ] + $this->theSaudiDebit());
    }

    #[DataProvider('walletWriterCases')]
    public function test_a_gateway_top_up_names_its_payment(string $writer): void
    {
        $payment = $this->ledgerPayment(id: '5b1c1c9a-0000-4000-8000-000000000001');

        $this->writeWalletRow(writer: $writer, customerId: 1, amount: 25.0, type: 'add_fund_by_admin', reference: 'add_funds_to_wallet', origin: LedgerOrigin::payment(id: $payment));

        $this->assertRow(['source_type' => 'payment', 'source_id' => $payment] + $this->emptyAttribution() + [
            'credit' => 25.0, 'debit' => 0.0, 'balance' => 175.0, 'transaction_type' => 'add_fund_by_admin', 'reference' => 'add_funds_to_wallet',
        ] + $this->theSaudiDebit());
    }

    // ---- criterion 2: the actor of a manual movement -----------------------------------------------------------------------------------------

    #[DataProvider('walletWriterCases')]
    public function test_a_manual_top_up_records_the_administrator_who_made_it(string $writer): void
    {
        $this->signInAdmin(id: 4);

        $this->writeWalletRow(writer: $writer, customerId: 1, amount: 25.0, type: 'add_fund_by_admin', reference: 'add_fund_by_admin', origin: LedgerOrigin::by(actor: LedgerActor::Admin));

        $this->assertRow(['actor_type' => 'admin', 'actor_id' => 4] + $this->emptyAttribution() + [
            'credit' => 25.0, 'debit' => 0.0, 'balance' => 175.0, 'transaction_type' => 'add_fund_by_admin', 'reference' => 'add_fund_by_admin',
        ] + $this->theSaudiDebit());
    }

    #[DataProvider('walletWriterCases')]
    public function test_a_top_up_made_while_nobody_is_signed_in_records_no_actor(string $writer): void
    {
        $this->writeWalletRow(writer: $writer, customerId: 1, amount: 25.0, type: 'add_fund_by_admin', reference: 'add_fund_by_admin', origin: LedgerOrigin::by(actor: LedgerActor::Admin));

        $this->assertRow($this->emptyAttribution() + [
            'credit' => 25.0, 'debit' => 0.0, 'balance' => 175.0, 'transaction_type' => 'add_fund_by_admin', 'reference' => 'add_fund_by_admin',
        ] + $this->theSaudiDebit());
    }

    // ---- criterion 5: storefront, legal entity and currency ----------------------------------------------------------------------------------

    /** @return array<string, array{string, string, int}> */
    public static function theSixMarkets(): array
    {
        return [
            'Saudi Arabia' => ['SA', 'SAR', 1],
            'United Arab Emirates' => ['AE', 'AED', 2],
            'Kuwait' => ['KW', 'KWD', 3],
            'Qatar' => ['QA', 'QAR', 4],
            'Bahrain' => ['BH', 'BHD', 5],
            'Oman' => ['OM', 'OMR', 6],
        ];
    }

    #[DataProvider('theSixMarkets')]
    public function test_each_market_is_copied_from_the_order_its_debit_pays(string $country, string $currency, int $entity): void
    {
        $this->ledgerOrder(id: 5, country: $country, currency: $currency, entityId: $entity);

        $this->writeWalletRow(writer: 'CustomerManager', customerId: 1, amount: 40.0, type: 'order_place', reference: 'order payment', origin: LedgerOrigin::order(id: 5));

        $this->assertRow(['source_type' => 'order', 'source_id' => '5', 'storefront_country' => $country, 'currency_code' => $currency, 'legal_entity_id' => $entity, 'actor_type' => null, 'actor_id' => null] + $this->theSaudiDebit());
    }

    #[DataProvider('walletWriterCases')]
    public function test_the_market_of_a_refund_credit_is_the_market_of_its_order(string $writer): void
    {
        $this->ledgerOrder(id: 5, country: 'KW', currency: 'KWD', entityId: 3);
        $this->ledgerRefund(id: 3, orderId: 5);

        $this->writeWalletRow(writer: $writer, customerId: 1, amount: 30.0, type: 'order_refund', reference: 'order_refund', origin: LedgerOrigin::refund(id: 3));

        $row = $this->newestLedgerRow();
        $this->assertSame(['KW', 'KWD', 3], [$row['storefront_country'], $row['currency_code'], $row['legal_entity_id']]);
    }

    #[DataProvider('walletWriterCases')]
    public function test_a_movement_with_no_order_takes_the_storefront_currency_and_company_of_the_request(string $writer): void
    {
        $entity = $this->registerEntity(country: 'KW');
        $this->switchMarketsOn(currency: true, entities: true);

        $this->servedFrom(country: 'KW', callback: fn () => $this->writeWalletRow(writer: $writer, customerId: 1, amount: 5.0, type: 'add_fund_by_admin', reference: 'earned_by_referral'));

        $row = $this->newestLedgerRow();
        $this->assertSame(['KW', 'KWD', $entity->id], [$row['storefront_country'], $row['currency_code'], $row['legal_entity_id']]);
        $this->assertSame([null, null, null, null], [$row['source_type'], $row['source_id'], $row['actor_type'], $row['actor_id']]);
    }

    #[DataProvider('walletWriterCases')]
    public function test_an_admin_top_up_is_made_in_no_storefront(string $writer): void
    {
        $this->switchMarketsOn(currency: true, entities: true);
        $this->registerEntity(country: 'SA');
        $this->signInAdmin(id: 4);

        $this->writeWalletRow(writer: $writer, customerId: 1, amount: 25.0, type: 'add_fund_by_admin', reference: 'add_fund_by_admin', origin: LedgerOrigin::by(actor: LedgerActor::Admin));

        $row = $this->newestLedgerRow();
        $this->assertSame([null, null, null], [$row['storefront_country'], $row['currency_code'], $row['legal_entity_id']], 'a panel has no storefront');
        $this->assertSame(['admin', 4], [$row['actor_type'], $row['actor_id']]);
    }

    // ---- nothing else moves --------------------------------------------------------------------------------------------------------------------

    #[DataProvider('walletWriterCases')]
    public function test_a_saudi_write_with_every_switch_off_leaves_the_row_exactly_as_it_was_and_the_new_columns_empty(string $writer): void
    {
        $this->writeWalletRow(writer: $writer, customerId: 1, amount: 40.0, type: 'order_place', reference: 'order payment');

        $this->assertRow($this->emptyAttribution() + $this->theSaudiDebit());
        $this->assertSame(110.0, (float) DB::table('users')->where('id', 1)->value('wallet_balance'));
        $this->assertSame(1, DB::table('wallet_transactions')->count());
    }

    #[DataProvider('walletWriterCases')]
    public function test_a_saudi_order_that_recorded_nothing_leaves_the_market_empty_whatever_is_named_about_its_source(string $writer): void
    {
        $this->ledgerOrder(id: 5);

        $this->writeWalletRow(writer: $writer, customerId: 1, amount: 40.0, type: 'order_place', reference: 'order payment', origin: LedgerOrigin::order(id: 5));

        $row = $this->newestLedgerRow();
        $this->assertSame([null, null, null], [$row['storefront_country'], $row['currency_code'], $row['legal_entity_id']]);
        $this->assertSame(['order', '5'], [$row['source_type'], $row['source_id']]);
        $this->assertSame($this->theSaudiDebit(), array_intersect_key($row, $this->theSaudiDebit()));
    }

    #[DataProvider('walletWriterCases')]
    public function test_before_the_migration_the_writer_saves_the_same_row_whatever_it_is_told_about_the_origin(string $writer): void
    {
        $this->dropLedgerWorld();
        $this->buildLedgerWorld(attributed: false);
        $this->ledgerCustomer(id: 1, wallet: 150.0);
        $this->ledgerOrder(id: 5, country: 'KW', currency: 'KWD', entityId: 3);
        $this->switchMarketsOn(currency: true, entities: true);
        $this->signInAdmin(id: 4);

        $this->servedFrom(country: 'KW', callback: fn () => $this->writeWalletRow(writer: $writer, customerId: 1, amount: 40.0, type: 'order_place', reference: 'order payment', origin: LedgerOrigin::order(id: 5, by: LedgerActor::Admin)));

        $this->assertRow($this->theSaudiDebit());
        $this->assertSame([], array_intersect(self::LEDGER_COLUMNS, Schema::getColumnListing('wallet_transactions')), 'the migration has not run');
        $this->assertSame(110.0, (float) DB::table('users')->where('id', 1)->value('wallet_balance'));
    }

    #[DataProvider('walletWriterCases')]
    public function test_the_balance_the_amounts_and_the_reference_are_the_same_with_and_without_the_attribution(string $writer): void
    {
        $this->ledgerOrder(id: 5, country: 'KW', currency: 'KWD', entityId: 3);
        $this->writeWalletRow(writer: $writer, customerId: 1, amount: 40.0, type: 'order_place', reference: 'order payment', origin: LedgerOrigin::order(id: 5));
        $attributed = array_intersect_key($this->newestLedgerRow(), $this->theSaudiDebit());
        $attributedBalance = (float) DB::table('users')->where('id', 1)->value('wallet_balance');

        $this->dropLedgerWorld();
        $this->buildLedgerWorld(attributed: false);
        $this->ledgerCustomer(id: 1, wallet: 150.0);
        $this->writeWalletRow(writer: $writer, customerId: 1, amount: 40.0, type: 'order_place', reference: 'order payment');

        $this->assertSame($attributed, $this->newestLedgerRow());
        $this->assertSame($attributedBalance, (float) DB::table('users')->where('id', 1)->value('wallet_balance'));
    }

    #[DataProvider('walletWriterCases')]
    public function test_a_wallet_that_is_switched_off_writes_nothing_and_asks_for_nothing(string $writer): void
    {
        DB::table('business_settings')->where('type', 'wallet_status')->update(['value' => '0']);
        $this->ledgerOrder(id: 5, country: 'KW', currency: 'KWD', entityId: 3);

        $result = $this->writeWalletRow(writer: $writer, customerId: 1, amount: 40.0, type: 'order_place', reference: 'order payment', origin: LedgerOrigin::order(id: 5));

        $this->assertFalse($result);
        $this->assertSame(0, DB::table('wallet_transactions')->count());
    }
}
