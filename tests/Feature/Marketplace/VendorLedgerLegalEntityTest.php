<?php

namespace Tests\Feature\Marketplace;

use App\Http\Controllers\Admin\Vendor\VendorController;
use App\Models\OrderItemCommission;
use App\Models\VendorLedgerEntry;
use App\Models\VendorPayoutRequest;
use App\Services\Marketplace\PayoutService;
use App\Services\Marketplace\RefundReversalService;
use App\Services\Storefront\LegalEntityRegistry;
use App\Utils\OrderManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\BuildsSellerLedgerWorld;
use Tests\TestCase;

/**
 * Whose money a seller-ledger row is (GCC-13, D1-03; the stated blocker on E-17.2). Every one of the eight writers of the ledger passed no company, so
 * `legal_entity_id` was null on every row the running system wrote. Each writer now carries one:
 *
 *   - the order earning and the commission charged on it read the ORDER's own frozen stamp, so a registry that changes later never reaches money
 *     already recorded, and the two can never land in two different companies;
 *   - a refund and the commission it gives back land in the company of the EARNING they reverse, read off that entry, never resolved afresh;
 *   - a payout reserve, a released reservation, money back after a failed transfer and the legacy withdraw mirror carry the company of the ledger rows
 *     they draw on, because they have no order to read.
 *
 * The first group is the way back and is pinned first: with the switch off every figure and every row is what it is today, and so is the number of
 * companies on the ledger, which is none.
 */
class VendorLedgerLegalEntityTest extends TestCase
{
    use BuildsSellerLedgerWorld;

    private const SELLER = 7;

    private const COMPANY_SA = 11;

    private const COMPANY_AE = 12;

    private const MIGRATION = 'migrations/2026_11_27_100300_add_legal_entity_to_vendor_ledger_entries_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSellerLedgerWorld();
    }

    protected function tearDown(): void
    {
        $this->tearDownSellerLedgerWorld();

        parent::tearDown();
    }

    // ---- the migration ---------------------------------------------------------------------------------------------------------

    public function test_the_migration_does_nothing_while_the_ledger_table_is_absent(): void
    {
        Schema::dropIfExists('vendor_ledger_entries');

        (require database_path(self::MIGRATION))->up();

        $this->assertFalse(Schema::hasTable('vendor_ledger_entries'), 'the migration invents no table it knows nothing about');
    }

    public function test_the_migration_adds_the_column_once_and_running_it_again_changes_nothing(): void
    {
        $this->assertFalse(Schema::hasColumn('vendor_ledger_entries', 'legal_entity_id'));

        $this->runCompanyMigrations();
        $this->assertTrue(Schema::hasColumn('vendor_ledger_entries', 'legal_entity_id'));

        (require database_path(self::MIGRATION))->up();
        $this->assertTrue(Schema::hasColumn('vendor_ledger_entries', 'legal_entity_id'));
    }

    public function test_the_migration_refuses_to_roll_back_while_an_entry_names_a_company(): void
    {
        $this->runCompanyMigrations();
        $this->turnSellingCompaniesOn();
        $this->ledger()->record(sellerId: self::SELLER, entryType: VendorLedgerEntry::TYPE_BONUS, credit: 10, legalEntityId: self::COMPANY_SA);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('name a selling company');

        (require database_path(self::MIGRATION))->down();
    }

    public function test_every_writer_of_the_ledger_can_be_told_the_company(): void
    {
        $parameters = array_map(
            fn ($parameter) => $parameter->getName(),
            (new ReflectionMethod(\App\Services\Marketplace\VendorLedger::class, 'record'))->getParameters(),
        );

        $this->assertContains('legalEntityId', $parameters);
    }

    // ---- the way back ----------------------------------------------------------------------------------------------------------

    public function test_with_the_switch_off_the_column_is_not_written_even_when_a_caller_names_a_company(): void
    {
        $this->runCompanyMigrations();

        $entry = $this->ledger()->record(sellerId: self::SELLER, entryType: VendorLedgerEntry::TYPE_BONUS, credit: 10, legalEntityId: self::COMPANY_SA);

        $this->assertSame(10.0, $entry->balance_after);
        $this->assertSame([$entry->id => null], $this->companiesOnTheLedger(), 'the ledger is the one ledger it has always been');
        $this->assertFalse($this->ledger()->recordsCompanies());
    }

    public function test_with_the_switch_on_but_no_column_nothing_is_written_and_no_write_fails(): void
    {
        $this->turnSellingCompaniesOn();

        $entry = $this->ledger()->record(sellerId: self::SELLER, entryType: VendorLedgerEntry::TYPE_BONUS, credit: 25, legalEntityId: self::COMPANY_SA);

        $this->assertSame(25.0, $entry->balance_after, 'an installation whose dump has no company column keeps writing money');
        $this->assertFalse($this->ledger()->recordsCompanies());
        $this->assertNull($this->ledger()->companyOfSeller(sellerId: self::SELLER));
        $this->assertNull($this->ledger()->companyOfEntry(entryId: $entry->id));
    }

    public function test_an_order_of_the_live_saudi_platform_writes_the_two_entries_it_writes_today(): void
    {
        $this->runCompanyMigrations();
        $this->orderStampedWith(orderId: 500, company: null);

        $this->earnOnOrderLine(orderId: 500, orderDetailsId: 900, amount: 100, commission: 10);

        $rows = DB::table('vendor_ledger_entries')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([null, null], array_values($this->companiesOnTheLedger()));
        $this->assertSame(
            [VendorLedgerEntry::TYPE_ORDER_EARNING, VendorLedgerEntry::TYPE_COMMISSION_CHARGE],
            $rows->pluck('entry_type')->all(),
        );
    }

    // ---- an order's earning and its commission read the order's own stamp ------------------------------------------------------

    public function test_the_earning_and_its_commission_carry_the_company_the_order_was_stamped_with(): void
    {
        $this->runCompanyMigrations();
        $this->turnSellingCompaniesOn();
        $this->orderStampedWith(orderId: 501, company: self::COMPANY_AE);

        $this->earnOnOrderLine(orderId: 501, orderDetailsId: 901, amount: 100, commission: 10);

        $this->assertSame(
            [self::COMPANY_AE, self::COMPANY_AE],
            array_values($this->companiesOnTheLedger()),
            'the earning and the commission charged on it are one company',
        );
    }

    public function test_an_order_with_no_stamp_earns_without_a_company_even_while_the_switch_is_on(): void
    {
        $this->runCompanyMigrations();
        $this->turnSellingCompaniesOn();
        $this->orderStampedWith(orderId: 502, company: null);

        $this->earnOnOrderLine(orderId: 502, orderDetailsId: 902, amount: 100, commission: 10);

        $this->assertSame([null, null], array_values($this->companiesOnTheLedger()), 'an order placed before the switch names no company');
    }

    public function test_restamping_the_order_afterwards_never_rewrites_money_already_recorded(): void
    {
        $this->runCompanyMigrations();
        $this->turnSellingCompaniesOn();
        $this->orderStampedWith(orderId: 503, company: self::COMPANY_SA);
        $this->earnOnOrderLine(orderId: 503, orderDetailsId: 903, amount: 100, commission: 10);

        DB::table('orders')->where('id', 503)->update(['legal_entity_id' => self::COMPANY_AE]);
        $this->earnOnOrderLine(orderId: 503, orderDetailsId: 903, amount: 100, commission: 10);

        $this->assertSame(
            [self::COMPANY_SA, self::COMPANY_SA],
            array_values($this->companiesOnTheLedger()),
            'the second write of the same event is the idempotent one and changes nothing',
        );
    }

    // ---- a refund lands in the company of the earning it reverses --------------------------------------------------------------

    public function test_a_refund_and_its_commission_reversal_land_in_the_company_of_the_earning(): void
    {
        $this->runCompanyMigrations();
        $this->turnSellingCompaniesOn();
        $this->orderStampedWith(orderId: 504, company: self::COMPANY_AE);
        $this->earnOnOrderLine(orderId: 504, orderDetailsId: 904, amount: 100, commission: 10);

        $reversal = app(RefundReversalService::class)->reverseForOrderLine(orderDetailsId: 904, refundedAmount: 100);

        $this->assertSame(['refund_debit' => 100.0, 'commission_credit' => 10.0], $reversal);
        $this->assertSame(
            [self::COMPANY_AE, self::COMPANY_AE, self::COMPANY_AE, self::COMPANY_AE],
            array_values($this->companiesOnTheLedger()),
            'a reversal is in the company of the earning, so no company is left over-credited and none taken negative',
        );
    }

    public function test_a_refund_of_an_earning_that_names_no_company_names_none_either(): void
    {
        $this->runCompanyMigrations();
        $this->turnSellingCompaniesOn();
        $this->orderStampedWith(orderId: 505, company: null);
        $this->earnOnOrderLine(orderId: 505, orderDetailsId: 905, amount: 100, commission: 10);

        // The company the registry would answer for this order today, recorded after the earning: a backfill, or an operator's correction.
        DB::table('orders')->where('id', 505)->update(['legal_entity_id' => self::COMPANY_SA]);

        app(RefundReversalService::class)->reverseForOrderLine(orderDetailsId: 905, refundedAmount: 100);

        $this->assertSame(
            [null, null, null, null],
            array_values($this->companiesOnTheLedger()),
            'the reversal follows the earning, not a company the earning never had',
        );
    }

    public function test_a_refund_of_a_line_with_no_earning_on_the_ledger_falls_back_to_the_orders_stamp(): void
    {
        $this->runCompanyMigrations();
        $this->turnSellingCompaniesOn();
        $this->orderStampedWith(orderId: 506, company: self::COMPANY_SA);
        $this->commissionSnapshot(orderId: 506, orderDetailsId: 906, amount: 100, commission: 10);

        app(RefundReversalService::class)->reverseForOrderLine(orderDetailsId: 906, refundedAmount: 100);

        $this->assertSame([self::COMPANY_SA, self::COMPANY_SA], array_values($this->companiesOnTheLedger()));
    }

    public function test_a_refund_with_the_switch_off_is_the_refund_it_is_today(): void
    {
        $this->runCompanyMigrations();
        $this->turnSellingCompaniesOn();
        $this->orderStampedWith(orderId: 507, company: self::COMPANY_AE);
        $this->earnOnOrderLine(orderId: 507, orderDetailsId: 907, amount: 100, commission: 10);
        config(['gcc.storefronts.legal_entities.enabled' => false]);
        app(LegalEntityRegistry::class)->flush();

        $reversal = app(RefundReversalService::class)->reverseForOrderLine(orderDetailsId: 907, refundedAmount: 100);

        $this->assertSame(['refund_debit' => 100.0, 'commission_credit' => 10.0], $reversal);
        $this->assertSame(
            [self::COMPANY_AE, self::COMPANY_AE, null, null],
            array_values($this->companiesOnTheLedger()),
            'the reversal records no company while the switch is off, and the figures are unchanged',
        );
    }

    // ---- the writers that have no order ----------------------------------------------------------------------------------------

    public function test_a_payout_reserve_carries_the_one_company_the_sellers_ledger_names(): void
    {
        $this->runCompanyMigrations();
        $this->turnSellingCompaniesOn();
        $this->availableEarning(amount: 400, company: self::COMPANY_AE);

        $result = app(PayoutService::class)->requestPayout(sellerId: self::SELLER, amount: 300);

        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertSame(self::COMPANY_AE, $this->ledger()->companyOfEntry(entryId: $result['request']->reserve_entry_id));
    }

    public function test_a_payout_reserve_of_a_ledger_of_two_companies_names_neither(): void
    {
        $this->runCompanyMigrations();
        $this->turnSellingCompaniesOn();
        $this->availableEarning(amount: 400, company: self::COMPANY_AE);
        $this->availableEarning(amount: 400, company: self::COMPANY_SA, reference: 'line-2');

        $result = app(PayoutService::class)->requestPayout(sellerId: self::SELLER, amount: 300);

        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertNull(
            $this->ledger()->companyOfEntry(entryId: $result['request']->reserve_entry_id),
            'a reserve made of two companies money names neither, and the write is never blocked',
        );
    }

    public function test_a_released_reservation_goes_back_to_the_company_it_was_held_out_of(): void
    {
        $this->runCompanyMigrations();
        $this->turnSellingCompaniesOn();
        $this->availableEarning(amount: 400, company: self::COMPANY_AE);
        $request = app(PayoutService::class)->requestPayout(sellerId: self::SELLER, amount: 300)['request'];

        app(PayoutService::class)->releaseReservation($request);

        $release = VendorLedgerEntry::where('reference_type', 'payout_release')->firstOrFail();
        $this->assertSame(self::COMPANY_AE, $release->legal_entity_id);
        $this->assertSame(300.0, $release->credit);
    }

    public function test_money_back_after_a_failed_transfer_goes_back_to_the_company_it_was_held_out_of(): void
    {
        $this->runCompanyMigrations();
        $this->turnSellingCompaniesOn();
        $this->availableEarning(amount: 400, company: self::COMPANY_AE);
        $request = app(PayoutService::class)->requestPayout(sellerId: self::SELLER, amount: 300)['request'];
        $request->forceFill(['status' => VendorPayoutRequest::STATUS_APPROVED])->save();

        app(PayoutService::class)->markFailed($request, reason: 'bank bounced');

        $back = VendorLedgerEntry::where('reference_type', 'payout_failed')->firstOrFail();
        $this->assertSame(self::COMPANY_AE, $back->legal_entity_id);
        $this->assertSame(300.0, $back->credit);
    }

    public function test_the_writers_with_no_order_name_no_company_while_the_switch_is_off(): void
    {
        $this->runCompanyMigrations();
        $this->turnSellingCompaniesOn();
        $this->availableEarning(amount: 400, company: self::COMPANY_AE);
        config(['gcc.storefronts.legal_entities.enabled' => false]);
        app(LegalEntityRegistry::class)->flush();

        $request = app(PayoutService::class)->requestPayout(sellerId: self::SELLER, amount: 300)['request'];
        app(PayoutService::class)->releaseReservation($request);

        $this->assertNull(DB::table('vendor_ledger_entries')->where('reference_type', 'payout_reserve')->value(LegalEntityRegistry::COLUMN));
        $this->assertNull(DB::table('vendor_ledger_entries')->where('reference_type', 'payout_release')->value(LegalEntityRegistry::COLUMN));
    }

    public function test_the_legacy_withdraw_mirror_carries_the_company_of_the_rows_it_is_mirrored_against(): void
    {
        $this->runCompanyMigrations();
        $this->turnSellingCompaniesOn();
        $this->availableEarning(amount: 400, company: self::COMPANY_AE);

        $this->assertSame(
            self::COMPANY_AE,
            $this->ledger()->companyOfSeller(sellerId: self::SELLER),
            'the legacy mirror of VendorController::withdrawStatus passes exactly this',
        );
        $this->assertStringContainsString(
            'legalEntityId: $ledger->companyOfSeller(sellerId: $withdraw[\'seller_id\'])',
            (string) file_get_contents((new \ReflectionClass(VendorController::class))->getFileName()),
            'the legacy withdraw mirror is wired to it',
        );
    }

    // ---- the ledger readers ----------------------------------------------------------------------------------------------------

    public function test_the_company_of_a_seller_is_none_when_the_ledger_names_none(): void
    {
        $this->runCompanyMigrations();
        $this->turnSellingCompaniesOn();
        $this->ledger()->record(sellerId: self::SELLER, entryType: VendorLedgerEntry::TYPE_BONUS, credit: 10);

        $this->assertNull($this->ledger()->companyOfSeller(sellerId: self::SELLER));
    }

    public function test_the_company_of_a_seller_ignores_another_sellers_rows(): void
    {
        $this->runCompanyMigrations();
        $this->turnSellingCompaniesOn();
        $this->ledger()->record(sellerId: self::SELLER, entryType: VendorLedgerEntry::TYPE_BONUS, credit: 10, legalEntityId: self::COMPANY_AE);
        $this->ledger()->record(sellerId: 99, entryType: VendorLedgerEntry::TYPE_BONUS, credit: 10, legalEntityId: self::COMPANY_SA);

        $this->assertSame(self::COMPANY_AE, $this->ledger()->companyOfSeller(sellerId: self::SELLER));
        $this->assertSame(self::COMPANY_SA, $this->ledger()->companyOfSeller(sellerId: 99));
    }

    public function test_the_company_of_an_entry_that_does_not_exist_is_none(): void
    {
        $this->runCompanyMigrations();
        $this->turnSellingCompaniesOn();

        $this->assertNull($this->ledger()->companyOfEntry(entryId: 4242));
        $this->assertNull($this->ledger()->companyOfEntry(entryId: null));
    }

    // ---- the world -------------------------------------------------------------------------------------------------------------

    /** The seller's side of one order line, written by the real path: OrderManager::recordOrderItemCommission. */
    private function earnOnOrderLine(int $orderId, int $orderDetailsId, float $amount, float $commission = 0): void
    {
        // No commission_rules table, so CommissionEngine falls back to the store's own legacy percentage.
        DB::table('business_settings')->updateOrInsert(
            ['type' => 'sales_commission'],
            ['value' => (string) ($amount > 0 ? round($commission / $amount * 100, 4) : 0)],
        );

        OrderManager::recordOrderItemCommission(
            orderId: $orderId,
            orderDetailsId: $orderDetailsId,
            cartItem: ['product_id' => 1, 'seller_id' => self::SELLER, 'seller_is' => 'seller'],
            product: ['id' => 1, 'category_id' => null],
            netLineAmount: $amount,
        );
    }

    /** A commission snapshot with no ledger entries behind it: a line whose earning was never written. */
    private function commissionSnapshot(int $orderId, int $orderDetailsId, float $amount, float $commission): OrderItemCommission
    {
        return OrderItemCommission::create([
            'order_id' => $orderId,
            'order_details_id' => $orderDetailsId,
            'product_id' => 1,
            'seller_id' => self::SELLER,
            'seller_is' => 'seller',
            'commissionable_amount' => $amount,
            'commission_amount' => $commission,
            'seller_net_amount' => $amount - $commission,
            'currency' => 'SAR',
        ]);
    }

    /** Money the seller may ask to be paid: an available earning of one company. */
    private function availableEarning(float $amount, ?int $company, string $reference = 'line-1'): VendorLedgerEntry
    {
        return $this->ledger()->record(
            sellerId: self::SELLER,
            entryType: VendorLedgerEntry::TYPE_ORDER_EARNING,
            credit: $amount,
            status: VendorLedgerEntry::STATUS_AVAILABLE,
            referenceType: 'order_details',
            referenceId: $reference,
            legalEntityId: $company,
        );
    }
}
