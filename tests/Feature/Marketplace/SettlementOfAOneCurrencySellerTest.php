<?php

namespace Tests\Feature\Marketplace;

use App\Models\VendorLedgerEntry;
use App\Models\VendorSettlement;
use App\Services\Marketplace\SettlementEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsWalletAndVendorLedgerSchema;
use Tests\Support\WritesSellerLedger;
use Tests\TestCase;

/**
 * E-17.1 of the master plan, the half that must not move: a seller whose ledger is in one currency, which is every Saudi seller today, is settled exactly as the engine settled them before a settlement was
 * drawn in one currency, in both positions of `gcc.settlements.one_currency`.
 *
 * The figures are not taken from the engine under test: each is worked out from the entries the test writes (the sums, the running balance before the first claimed entry and after the last one), and this class
 * was written and run green against the engine as it was before the change, where the switch does not exist and both positions are the same run.
 *
 * `vendor_settlements` and `vendor_ledger_entries` have no migration; the tables are built in setUp with the columns the code writes (tests/Support/BuildsWalletAndVendorLedgerSchema), so this proves the code,
 * not the schema of an installation.
 */
class SettlementOfAOneCurrencySellerTest extends TestCase
{
    use BuildsWalletAndVendorLedgerSchema;
    use WritesSellerLedger;

    private SettlementEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildVendorSettlementTables();
        $this->useDefaultCurrency(code: 'SAR');
        $this->engine = app(SettlementEngine::class);
    }

    /** @return array<string, array{bool}> */
    public static function switchPositions(): array
    {
        return ['the switch off, as shipped' => [false], 'the switch on' => [true]];
    }

    /** @return array<string, mixed> what the engine wrote on a settlement, and what approving, paying or cancelling added */
    private function figures(VendorSettlement $settlement): array
    {
        return [
            'seller_id' => $settlement->seller_id,
            'seller_is' => $settlement->seller_is,
            'period_start' => $settlement->period_start?->toDateTimeString(),
            'period_end' => $settlement->period_end?->toDateTimeString(),
            'opening_balance' => $settlement->opening_balance,
            'total_credits' => $settlement->total_credits,
            'total_debits' => $settlement->total_debits,
            'net_amount' => $settlement->net_amount,
            'closing_balance' => $settlement->closing_balance,
            'entry_count' => $settlement->entry_count,
            'currency' => $settlement->currency,
            'status' => $settlement->status,
            'calculated_at' => $settlement->calculated_at?->toDateTimeString(),
            'approved_by' => $settlement->approved_by,
            'approved_at' => $settlement->approved_at?->toDateTimeString(),
            'paid_at' => $settlement->paid_at?->toDateTimeString(),
            'payout_reference' => $settlement->payout_reference,
            'note' => $settlement->note,
        ];
    }

    /** @return list<string> the status of each entry of the ledger, in the order they were written */
    private function statuses(): array
    {
        return VendorLedgerEntry::query()->orderBy('id')->pluck('status')->all();
    }

    #[DataProvider('switchPositions')]
    public function test_the_settlement_of_a_seller_with_one_currency_is_the_one_of_today_to_the_last_figure(bool $oneCurrency): void
    {
        config(['gcc.settlements.one_currency' => $oneCurrency]);

        $this->travelTo(Carbon::parse('2026-10-25 09:00:00'));
        $settledBefore = [$this->earning(amount: 1000.0)->id, $this->charge(amount: 100.0)->id];
        $this->travelTo(Carbon::parse('2026-10-26 02:00:00'));
        $earlier = $this->engine->calculate(sellerId: self::SELLER);
        $this->engine->approve($earlier, approvedBy: 3);
        $this->engine->markPaid($earlier->fresh(), payoutReference: 'BANK-1', paidBy: 4);

        $this->travelTo(Carbon::parse('2026-11-01 10:00:00'));
        $earning = $this->earning(amount: 100.5);
        $commission = $this->charge(amount: 10.1235);
        $this->travelTo(Carbon::parse('2026-11-02 09:30:00'));
        $insideTheReturnWindow = $this->earning(amount: 250.0, changes: ['status' => VendorLedgerEntry::STATUS_PENDING]);
        $heldForAPayout = $this->ledgerEntry(changes: ['entryType' => VendorLedgerEntry::TYPE_PAYOUT, 'debit' => 50.0, 'status' => VendorLedgerEntry::STATUS_RESERVED, 'referenceType' => 'payout_reserve', 'currency' => 'SAR']);
        $this->travelTo(Carbon::parse('2026-11-03 12:00:00'));
        $payoutGivenBack = $this->ledgerEntry(changes: ['entryType' => VendorLedgerEntry::TYPE_MANUAL_ADJUSTMENT, 'credit' => 50.0, 'referenceType' => 'payout_release', 'currency' => 'SAR']);
        $secondEarning = $this->earning(amount: 40.0);
        $refund = $this->ledgerEntry(changes: ['entryType' => VendorLedgerEntry::TYPE_REFUND, 'debit' => 25.0, 'referenceType' => 'refund_order_details', 'currency' => 'SAR']);
        $bonusWithoutAReference = $this->ledgerEntry(changes: ['entryType' => VendorLedgerEntry::TYPE_BONUS, 'credit' => 5.25, 'referenceType' => null, 'referenceId' => null, 'currency' => 'SAR']);

        $this->travelTo(Carbon::parse('2026-11-04 02:00:00'));
        $settlement = $this->engine->calculate(sellerId: self::SELLER);

        $this->assertSame([
            'seller_id' => self::SELLER,
            'seller_is' => 'seller',
            'period_start' => '2026-11-01 10:00:00',
            'period_end' => '2026-11-03 12:00:00',
            'opening_balance' => 900.0,
            'total_credits' => 145.75,
            'total_debits' => 35.1235,
            'net_amount' => 110.6265,
            'closing_balance' => 1260.6265,
            'entry_count' => 5,
            'currency' => 'SAR',
            'status' => VendorSettlement::STATUS_CALCULATED,
            'calculated_at' => '2026-11-04 02:00:00',
            'approved_by' => null,
            'approved_at' => null,
            'paid_at' => null,
            'payout_reference' => null,
            'note' => null,
        ], $this->figures($settlement));
        $this->assertSame($this->figures($settlement), $this->figures(VendorSettlement::findOrFail($settlement->id)));
        $this->assertSame([$earning->id, $commission->id, $secondEarning->id, $refund->id, $bonusWithoutAReference->id], $this->entriesClaimedBy($settlement->id));
        $this->assertSame([$insideTheReturnWindow->id, $heldForAPayout->id, $payoutGivenBack->id], $this->unclaimedEntries());
        $this->assertSame($settledBefore, $this->entriesClaimedBy($earlier->id));
        $this->assertSame(
            ['paid', 'paid', 'available', 'available', 'pending', 'reserved', 'available', 'available', 'available', 'available'],
            $this->statuses(),
        );
        $this->assertSame(2, VendorSettlement::count());
    }

    #[DataProvider('switchPositions')]
    public function test_the_first_settlement_of_a_seller_opens_at_zero_and_closes_at_the_running_balance(bool $oneCurrency): void
    {
        config(['gcc.settlements.one_currency' => $oneCurrency]);
        $this->travelTo(Carbon::parse('2026-10-25 09:00:00'));
        $this->earning(amount: 1000.0);
        $this->charge(amount: 100.0);

        $this->travelTo(Carbon::parse('2026-10-26 02:00:00'));
        $settlement = $this->engine->calculate(sellerId: self::SELLER);

        $this->assertSame(
            ['opening_balance' => 0.0, 'total_credits' => 1000.0, 'total_debits' => 100.0, 'net_amount' => 900.0, 'closing_balance' => 900.0, 'entry_count' => 2, 'currency' => 'SAR'],
            array_intersect_key($this->figures($settlement), array_flip(['opening_balance', 'total_credits', 'total_debits', 'net_amount', 'closing_balance', 'entry_count', 'currency'])),
        );
        $this->assertSame(['2026-10-25 09:00:00', '2026-10-25 09:00:00'], [$this->figures($settlement)['period_start'], $this->figures($settlement)['period_end']]);
    }

    #[DataProvider('switchPositions')]
    public function test_a_reference_is_the_day_it_was_drawn_and_six_characters_of_its_own(bool $oneCurrency): void
    {
        config(['gcc.settlements.one_currency' => $oneCurrency]);
        $this->travelTo(Carbon::parse('2026-11-04 02:00:00'));
        $this->earning(amount: 10.0);
        $first = $this->engine->calculate(sellerId: self::SELLER);
        $this->earning(amount: 20.0);
        $second = $this->engine->calculate(sellerId: self::SELLER);

        $this->assertMatchesRegularExpression('/^STL-20261104-[0-9A-F]{6}$/', $first->reference);
        $this->assertMatchesRegularExpression('/^STL-20261104-[0-9A-F]{6}$/', $second->reference);
        $this->assertNotSame($first->reference, $second->reference);
    }

    #[DataProvider('switchPositions')]
    public function test_a_period_given_to_the_engine_limits_the_entries_and_is_recorded_as_the_period(bool $oneCurrency): void
    {
        config(['gcc.settlements.one_currency' => $oneCurrency]);
        $this->travelTo(Carbon::parse('2026-11-01 08:00:00'));
        $before = $this->earning(amount: 10.0);
        $this->travelTo(Carbon::parse('2026-11-02 08:00:00'));
        $inside = [$this->earning(amount: 20.0)->id, $this->charge(amount: 2.0)->id];
        $this->travelTo(Carbon::parse('2026-11-03 08:00:00'));
        $after = $this->earning(amount: 40.0);

        $this->travelTo(Carbon::parse('2026-11-05 02:00:00'));
        $ofTheDay = $this->engine->calculate(sellerId: self::SELLER, periodStart: Carbon::parse('2026-11-02 00:00:00'), periodEnd: Carbon::parse('2026-11-02 23:59:59'));
        $ofTheRest = $this->engine->calculate(sellerId: self::SELLER);

        $this->assertSame($inside, $this->entriesClaimedBy($ofTheDay->id));
        $this->assertSame(
            ['2026-11-02 00:00:00', '2026-11-02 23:59:59', 10.0, 20.0, 2.0, 18.0, 28.0, 2],
            array_values(array_intersect_key($this->figures($ofTheDay), array_flip(['period_start', 'period_end', 'opening_balance', 'total_credits', 'total_debits', 'net_amount', 'closing_balance', 'entry_count']))),
        );
        $this->assertSame([$before->id, $after->id], $this->entriesClaimedBy($ofTheRest->id));
        $this->assertSame(
            ['2026-11-01 08:00:00', '2026-11-03 08:00:00', 0.0, 50.0, 0.0, 50.0, 68.0, 2],
            array_values(array_intersect_key($this->figures($ofTheRest), array_flip(['period_start', 'period_end', 'opening_balance', 'total_credits', 'total_debits', 'net_amount', 'closing_balance', 'entry_count']))),
        );
    }

    #[DataProvider('switchPositions')]
    public function test_a_seller_with_nothing_waiting_gets_no_settlement(bool $oneCurrency): void
    {
        config(['gcc.settlements.one_currency' => $oneCurrency]);
        $this->earning(amount: 250.0, changes: ['status' => VendorLedgerEntry::STATUS_PENDING]);
        $this->ledgerEntry(changes: ['entryType' => VendorLedgerEntry::TYPE_MANUAL_ADJUSTMENT, 'credit' => 50.0, 'referenceType' => 'payout_release', 'currency' => 'SAR']);

        $this->assertNull($this->engine->calculate(sellerId: self::SELLER));
        $this->assertNull($this->engine->calculate(sellerId: 999));
        $this->assertSame([], $this->engine->calculateForAll());
        $this->assertSame(0, VendorSettlement::count());
    }

    #[DataProvider('switchPositions')]
    public function test_a_second_run_finds_nothing_left_to_claim(bool $oneCurrency): void
    {
        config(['gcc.settlements.one_currency' => $oneCurrency]);
        $entries = [$this->earning(amount: 100.0)->id, $this->charge(amount: 15.0)->id];

        $first = $this->engine->calculate(sellerId: self::SELLER);
        $second = $this->engine->calculate(sellerId: self::SELLER);

        $this->assertNull($second);
        $this->assertSame($entries, $this->entriesClaimedBy($first->id));
        $this->assertSame(1, VendorSettlement::count());
        $this->assertSame(85.0, $first->net_amount);
    }

    #[DataProvider('switchPositions')]
    public function test_calculateForAll_settles_every_seller_with_money_waiting_once(bool $oneCurrency): void
    {
        config(['gcc.settlements.one_currency' => $oneCurrency]);
        $this->earning(amount: 100.0);
        $this->charge(amount: 10.0);
        $this->earning(amount: 30.5, changes: ['sellerId' => 8]);
        $this->earning(amount: 7.25, changes: ['sellerIs' => 'admin']);
        $this->earning(amount: 250.0, changes: ['sellerId' => 9, 'status' => VendorLedgerEntry::STATUS_PENDING]);

        $settlements = $this->engine->calculateForAll();

        $nets = [];
        foreach ($settlements as $settlement) {
            $nets[$settlement->seller_id . '/' . $settlement->seller_is] = $settlement->net_amount;
        }
        ksort($nets);
        $this->assertSame(['7/admin' => 7.25, '7/seller' => 90.0, '8/seller' => 30.5], $nets);
        $this->assertCount(3, $settlements);
        $this->assertSame(3, VendorSettlement::count());
        $this->assertSame([], $this->engine->calculateForAll());
    }

    #[DataProvider('switchPositions')]
    public function test_approving_and_paying_a_settlement_marks_its_own_entries_paid_and_no_other(bool $oneCurrency): void
    {
        config(['gcc.settlements.one_currency' => $oneCurrency]);
        $this->travelTo(Carbon::parse('2026-11-04 02:00:00'));
        $this->earning(amount: 100.0);
        $settlement = $this->engine->calculate(sellerId: self::SELLER);
        $later = $this->earning(amount: 60.0);

        $this->assertFalse($this->engine->markPaid($settlement), 'a settlement nobody approved is not paid');
        $this->travelTo(Carbon::parse('2026-11-04 10:00:00'));
        $this->assertTrue($this->engine->approve($settlement, approvedBy: 3));
        $this->assertFalse($this->engine->approve($settlement->fresh(), approvedBy: 5), 'approving twice does not change who approved');
        $this->assertFalse($this->engine->cancel($settlement->fresh()), 'an approved settlement is not cancelled');
        $this->travelTo(Carbon::parse('2026-11-05 11:00:00'));
        $this->assertTrue($this->engine->markPaid($settlement->fresh(), payoutReference: 'BANK-9', paidBy: 4));

        $paid = $settlement->fresh();
        $this->assertSame(
            [VendorSettlement::STATUS_PAID, 3, '2026-11-04 10:00:00', '2026-11-05 11:00:00', 'BANK-9', 100.0],
            [$paid->status, (int) $paid->approved_by, $paid->approved_at->toDateTimeString(), $paid->paid_at->toDateTimeString(), $paid->payout_reference, $paid->net_amount],
        );
        $this->assertEquals(4, DB::table('vendor_settlements')->where('id', $paid->id)->value('paid_by'));
        $this->assertSame(['paid', 'available'], $this->statuses());
        $this->assertSame([$later->id], $this->unclaimedEntries());
    }

    #[DataProvider('switchPositions')]
    public function test_cancelling_a_settlement_releases_its_entries_for_the_next_run(bool $oneCurrency): void
    {
        config(['gcc.settlements.one_currency' => $oneCurrency]);
        $entries = [$this->earning(amount: 100.0)->id, $this->charge(amount: 15.0)->id];
        $settlement = $this->engine->calculate(sellerId: self::SELLER);

        $this->assertTrue($this->engine->cancel($settlement, note: 'drawn a day early'));

        $this->assertSame([VendorSettlement::STATUS_CANCELLED, 'drawn a day early', 85.0], [$settlement->fresh()->status, $settlement->fresh()->note, $settlement->fresh()->net_amount]);
        $this->assertSame($entries, $this->unclaimedEntries());
        $this->assertSame(['available', 'available'], $this->statuses());

        $again = $this->engine->calculate(sellerId: self::SELLER);

        $this->assertNotSame($settlement->id, $again->id);
        $this->assertSame($entries, $this->entriesClaimedBy($again->id));
        $this->assertSame([0.0, 100.0, 15.0, 85.0, 85.0, 2], [$again->opening_balance, $again->total_credits, $again->total_debits, $again->net_amount, $again->closing_balance, $again->entry_count]);
    }
}
