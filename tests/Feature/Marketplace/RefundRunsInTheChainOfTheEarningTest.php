<?php

namespace Tests\Feature\Marketplace;

use App\Models\OrderItemCommission;
use App\Models\VendorLedgerEntry;
use App\Services\Marketplace\RefundReversalService;
use App\Services\Marketplace\SettlementEngine;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsWalletAndVendorLedgerSchema;
use Tests\Support\WritesSellerLedger;
use Tests\TestCase;

/**
 * A refund runs in the chain of the earning it reverses (E-17.2).
 *
 * A reversal that lands in another chain is money of another kind: the line is settled as payable in the chain of its earning while the refund waits in a chain that can
 * never be collected, so a line refunded IN FULL leaves the marketplace owing its seller for a sale that was given back. It needs no second market and no two currencies on
 * the ledger — only the switch — because the two writers resolve their currency from two different places. The earning names none, so the writer stamps the store's base
 * currency (OrderManager). The reversal named the currency of the order's commission snapshot, which is stamped when the order is placed and falls back to the currency the
 * CUSTOMER was browsing in (OrderManager::recordOrderItemCommission), so it can be another one.
 *
 * `RefundReversalService` now reads the label off the entry it is reversing, which is the only place the chain of that money is recorded.
 */
class RefundRunsInTheChainOfTheEarningTest extends TestCase
{
    use BuildsWalletAndVendorLedgerSchema;
    use WritesSellerLedger;

    private const THE_ORDER_LINE = 900;

    private const WHAT_THE_LINE_SOLD_FOR = 100.0;

    private const COMMISSION_ON_IT = 10.0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildRefundReversalTables();
        $this->useDefaultCurrency(code: 'SAR');
    }

    /** The line as the order writer leaves it: an earning, the commission charged on it, and the frozen snapshot a refund is reversed against. */
    private function theLineWasSold(?string $earningCarries, ?string $theSnapshotSays): void
    {
        $earning = $this->ledgerEntry(changes: ['credit' => self::WHAT_THE_LINE_SOLD_FOR, 'currency' => $earningCarries, 'referenceId' => self::THE_ORDER_LINE]);
        if ($earningCarries === null) {
            $this->withoutACurrency($earning);
        }

        $this->charge(amount: self::COMMISSION_ON_IT, currency: $earningCarries, changes: ['referenceId' => self::THE_ORDER_LINE]);

        OrderItemCommission::create([
            'order_id' => 5,
            'order_details_id' => self::THE_ORDER_LINE,
            'seller_id' => self::SELLER,
            'seller_is' => 'seller',
            'commissionable_amount' => self::WHAT_THE_LINE_SOLD_FOR,
            'commission_amount' => self::COMMISSION_ON_IT,
            'seller_net_amount' => self::WHAT_THE_LINE_SOLD_FOR - self::COMMISSION_ON_IT,
            'reversed_amount' => 0,
            'currency' => $theSnapshotSays,
        ]);
    }

    /** The whole line is given back to the customer. */
    private function theWholeLineWasRefunded(): ?array
    {
        return app(RefundReversalService::class)->reverseForOrderLine(
            orderDetailsId: self::THE_ORDER_LINE,
            refundedAmount: self::WHAT_THE_LINE_SOLD_FOR,
            refundReference: self::THE_ORDER_LINE,
        );
    }

    /** @return array<string, mixed> the currency each entry of the reversal carries, by its type */
    private function whatTheReversalCarries(): array
    {
        return VendorLedgerEntry::query()
            ->where('reference_type', 'refund_order_details')
            ->orderBy('id')
            ->pluck('currency', 'entry_type')
            ->all();
    }

    /**
     * An earning labelled with the base currency, and one from the years before the ledger recorded a currency at all.
     *
     * The unlabelled one belongs to the base-currency chain (LedgerChain), and a reversal given no currency is stamped with the base currency by the writer, so it lands in
     * that chain with it. What must not happen either way is the snapshot's currency.
     *
     * @return array<string, array{?string, string}>
     */
    public static function whatTheEarningCarries(): array
    {
        return [
            'the earning is labelled SAR' => ['SAR', 'SAR'],
            'the earning names no currency at all' => [null, 'SAR'],
        ];
    }

    #[DataProvider('whatTheEarningCarries')]
    public function test_a_line_refunded_in_full_leaves_nothing_payable_although_the_snapshot_named_another_currency(?string $earningCarries, string $theReversalCarries): void
    {
        config(['gcc.ledgers.keyed_by_entity_and_currency' => true]);
        $this->theLineWasSold(earningCarries: $earningCarries, theSnapshotSays: 'AED');

        $this->assertSame(['refund_debit' => 100.0, 'commission_credit' => 10.0], $this->theWholeLineWasRefunded());

        $settlements = app(SettlementEngine::class)->calculateEachCurrency(sellerId: self::SELLER);

        $this->assertSame(
            0.0,
            (float) DB::table('vendor_settlements')->where('net_amount', '>', 0)->sum('net_amount'),
            'a fully refunded order line left a payable settlement: the refund was drawn into a chain the earning was never in',
        );
        $this->assertCount(1, $settlements, 'the refund was drawn into a settlement of its own, in a currency nobody can be paid in');
        $this->assertSame(0.0, (float) $settlements[0]->net_amount, 'a line refunded in full left money to settle');
        $this->assertSame(
            [VendorLedgerEntry::TYPE_REFUND => $theReversalCarries, VendorLedgerEntry::TYPE_RETURN_ADJUSTMENT => $theReversalCarries],
            $this->whatTheReversalCarries(),
            'the reversal was labelled with the currency of the order snapshot, not with the currency of the entry it reverses',
        );
    }

    /** The live platform: one chain for the seller, so no figure of theirs moves. The label is the one correction, and it is the entry's own money it now names. */
    public function test_with_the_switch_off_a_line_refunded_in_full_settles_at_nothing_as_it_always_did(): void
    {
        $this->theLineWasSold(earningCarries: 'SAR', theSnapshotSays: 'AED');

        $this->theWholeLineWasRefunded();
        $settlements = app(SettlementEngine::class)->calculateEachCurrency(sellerId: self::SELLER);

        $this->assertCount(1, $settlements);
        $this->assertSame(0.0, (float) $settlements[0]->net_amount);
        $this->assertSame(['SAR', 'SAR'], array_values($this->whatTheReversalCarries()));
    }

    /** A line whose earning is not on the ledger at all: the snapshot is the only answer there is, which is where it was the answer all along. */
    public function test_a_reversal_with_no_earning_to_read_takes_the_currency_of_the_snapshot(): void
    {
        config(['gcc.ledgers.keyed_by_entity_and_currency' => true]);
        OrderItemCommission::create([
            'order_id' => 5,
            'order_details_id' => self::THE_ORDER_LINE,
            'seller_id' => self::SELLER,
            'seller_is' => 'seller',
            'commissionable_amount' => self::WHAT_THE_LINE_SOLD_FOR,
            'commission_amount' => self::COMMISSION_ON_IT,
            'reversed_amount' => 0,
            'currency' => 'AED',
        ]);

        $this->theWholeLineWasRefunded();

        $this->assertSame(['AED', 'AED'], array_values($this->whatTheReversalCarries()));
    }
}
