<?php

namespace Tests\Feature\Storefront;

use App\Models\Admin;
use App\Services\RefundStatusService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Fluent;
use Tests\Support\BuildsLedgerWorld;
use Tests\TestCase;

/**
 * E-05 criteria 1, 2 and 5 for a refund paid back into the customer's wallet, through the service the admin's refund screen uses: the ledger row names the refund and the
 * administrator who paid it, and carries the storefront, the currency and the selling company of the order it gives back. The credit and its `order_refund` reference are what
 * they were, and a refund that is not paid into the wallet writes no row.
 */
class RefundCreditLedgerTest extends TestCase
{
    use BuildsLedgerWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildLedgerWorld();
        $this->ledgerCustomer(id: 1, wallet: 150.0);
        DB::table('business_settings')->insert(['type' => 'wallet_add_refund', 'value' => '1']);
        $this->ledgerOrder(id: 5, country: 'KW', currency: 'KWD', entityId: 3);
        $this->ledgerRefund(id: 3, orderId: 5);
        $this->actingAs((new Admin())->forceFill(['id' => 4]), 'admin');
    }

    protected function tearDown(): void
    {
        $this->dropLedgerWorld();

        parent::tearDown();
    }

    /** @param array<string, mixed> $request */
    private function payRefundBack(array $request = []): void
    {
        app(RefundStatusService::class)->getRefundStatusProcessData(
            request: new Fluent($request + ['refund_status' => 'refunded', 'payment_method' => 'customer_wallet', 'payment_info' => 'paid to the wallet']),
            orderDetails: new Fluent(['refund_request' => 0]),
            refund: new Fluent(['id' => 3, 'customer_id' => 1, 'amount' => 30, 'order_id' => 5]),
            loyaltyPoint: 0,
        );
    }

    public function test_the_credit_names_the_refund_the_administrator_and_the_market_of_the_order_it_gives_back(): void
    {
        $this->payRefundBack();

        $row = $this->newestLedgerRow();
        $this->assertSame(['refund', '3', 'admin', 4, 'KW', 'KWD', 3], [$row['source_type'], $row['source_id'], $row['actor_type'], $row['actor_id'], $row['storefront_country'], $row['currency_code'], $row['legal_entity_id']]);
        $this->assertSame(['order_refund', 'order_refund', 30.0, 0.0, 180.0], [$row['transaction_type'], $row['reference'], $row['credit'], $row['debit'], $row['balance']]);
        $this->assertSame(180.0, (float) DB::table('users')->where('id', 1)->value('wallet_balance'));
    }

    public function test_a_refund_of_an_order_that_recorded_nothing_names_the_refund_and_leaves_the_market_empty(): void
    {
        DB::table('orders')->where('id', 5)->update(['storefront_country' => null, 'currency_code' => null, 'currency_rate' => null, 'legal_entity_id' => null]);

        $this->payRefundBack();

        $row = $this->newestLedgerRow();
        $this->assertSame(['refund', '3', 'admin', 4, null, null, null], [$row['source_type'], $row['source_id'], $row['actor_type'], $row['actor_id'], $row['storefront_country'], $row['currency_code'], $row['legal_entity_id']]);
    }

    public function test_a_refund_that_is_not_paid_into_the_wallet_writes_no_row(): void
    {
        $this->payRefundBack(request: ['payment_method' => 'cash']);
        $this->assertSame(0, DB::table('wallet_transactions')->count());

        DB::table('business_settings')->where('type', 'wallet_add_refund')->update(['value' => '0']);
        Cache::flush();
        $this->payRefundBack();
        $this->assertSame(0, DB::table('wallet_transactions')->count());
    }

    public function test_before_the_migration_the_credit_is_written_exactly_as_it_always_was(): void
    {
        $this->dropLedgerWorld();
        $this->buildLedgerWorld(attributed: false);
        $this->ledgerCustomer(id: 1, wallet: 150.0);
        DB::table('business_settings')->insert(['type' => 'wallet_add_refund', 'value' => '1']);
        $this->ledgerOrder(id: 5, country: 'KW', currency: 'KWD', entityId: 3);
        $this->ledgerRefund(id: 3, orderId: 5);

        $this->payRefundBack();

        $this->assertSame([], array_intersect(self::LEDGER_COLUMNS, Schema::getColumnListing('wallet_transactions')), 'the migration has not run');
        $this->assertSame(
            ['user_id' => 1, 'transaction_id' => '<uuid>', 'credit' => 30.0, 'debit' => 0.0, 'admin_bonus' => 0.0, 'balance' => 180.0, 'transaction_type' => 'order_refund', 'payment_method' => null, 'reference' => 'order_refund', 'created_at' => '2026-12-05 10:00:00', 'updated_at' => '2026-12-05 10:00:00'],
            $this->newestLedgerRow(),
        );
    }
}
