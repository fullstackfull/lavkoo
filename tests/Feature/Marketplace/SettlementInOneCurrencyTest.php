<?php

namespace Tests\Feature\Marketplace;

use App\Models\VendorLedgerEntry;
use App\Models\VendorSettlement;
use App\Services\Marketplace\SettlementEngine;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsWalletAndVendorLedgerSchema;
use Tests\Support\WritesSellerLedger;
use Tests\TestCase;

/**
 * E-17.1 of the master plan: a settlement never nets entries of different currencies. Everything here runs with `gcc.settlements.one_currency` on; SettlementWithTheSwitchOffTest pins the shipped position,
 * where the criterion does not hold, and SettlementOfAOneCurrencySellerTest pins that a seller with one currency is settled the same in both.
 *
 * Three decisions of SettlementEngine are tested as it states them: an entry that names no currency is read as the store's base currency; the opening and the closing balance are recorded only while the
 * seller's one chain of running balances is in the settlement's currency up to its last entry, and are left empty (NULL) once that chain has mixed currencies; and the claim is what it was (one locked read,
 * then the stamp), so a later run finds nothing of a currency already drawn. Several processes drawing at once are in tests/Feature/Concurrency/SettlementConcurrencyTest, which needs a real engine.
 *
 * NOT COVERED: a chain of running balances for each currency (E-17.2), which is why a mixed ledger has no balances here; a legal entity on a settlement; what the seller's own screens and the ledger screen of
 * the admin print. `vendor_settlements` and `vendor_ledger_entries` have no migration: the tables are built in setUp from the columns the code writes, with balance columns that accept NULL.
 */
class SettlementInOneCurrencyTest extends TestCase
{
    use BuildsWalletAndVendorLedgerSchema;
    use WritesSellerLedger;

    private SettlementEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildVendorSettlementTables();
        config(['gcc.settlements.one_currency' => true]);
        $this->engine = app(SettlementEngine::class);
    }

    /** @return list<mixed> the currency and the totals of a settlement, which are sums of its own entries */
    private function totals(VendorSettlement $settlement): array
    {
        return [$settlement->currency, $settlement->total_credits, $settlement->total_debits, $settlement->net_amount, $settlement->entry_count];
    }

    /** @return list<float|null> */
    private function balances(VendorSettlement $settlement): array
    {
        return [$settlement->opening_balance, $settlement->closing_balance];
    }

    public function test_entries_of_two_currencies_are_drawn_into_two_settlements_each_with_its_own_entries_and_its_own_totals(): void
    {
        $this->useDefaultCurrency(code: 'SAR');
        $riyals = [$this->earning(amount: 100.5, currency: 'SAR')->id];
        $dinars = [$this->earning(amount: 12.345, currency: 'KWD')->id];
        $riyals[] = $this->charge(amount: 10.1235, currency: 'SAR')->id;
        $dinars[] = $this->earning(amount: 7.655, currency: 'KWD')->id;
        $dinars[] = $this->charge(amount: 1.5, currency: 'KWD')->id;

        $settlements = $this->engine->calculateEachCurrency(sellerId: self::SELLER);

        $this->assertCount(2, $settlements);
        [$inRiyals, $inDinars] = $settlements;
        $this->assertSame(['SAR', 100.5, 10.1235, 90.3765, 2], $this->totals($inRiyals));
        $this->assertSame(['KWD', 20.0, 1.5, 18.5, 3], $this->totals($inDinars));
        $this->assertSame($riyals, $this->entriesClaimedBy($inRiyals->id));
        $this->assertSame($dinars, $this->entriesClaimedBy($inDinars->id));
        $this->assertSame([], $this->unclaimedEntries());
        $this->assertSame(2, VendorSettlement::count());
        $this->assertNotSame($inRiyals->reference, $inDinars->reference);
        $this->assertSame([VendorSettlement::STATUS_CALCULATED, VendorSettlement::STATUS_CALCULATED], [$inRiyals->status, $inDinars->status]);
    }

    public function test_one_call_of_calculate_draws_one_currency_and_leaves_the_other_unclaimed_and_available_until_it_is_drawn(): void
    {
        $this->useDefaultCurrency(code: 'SAR');
        $riyals = [$this->earning(amount: 100.5, currency: 'SAR')->id];
        $dinars = [$this->earning(amount: 12.345, currency: 'KWD')->id, $this->earning(amount: 7.655, currency: 'KWD')->id];
        $riyals[] = $this->charge(amount: 10.1235, currency: 'SAR')->id;

        $first = $this->engine->calculate(sellerId: self::SELLER);

        $this->assertSame(['SAR', 100.5, 10.1235, 90.3765, 2], $this->totals($first));
        $this->assertSame($riyals, $this->entriesClaimedBy($first->id));
        $this->assertSame($dinars, $this->unclaimedEntries());
        $this->assertSame(['available', 'available'], VendorLedgerEntry::query()->whereIn('id', $dinars)->orderBy('id')->pluck('status')->all());

        $second = $this->engine->calculate(sellerId: self::SELLER);

        $this->assertSame(['KWD', 20.0, 0.0, 20.0, 2], $this->totals($second));
        $this->assertSame($dinars, $this->entriesClaimedBy($second->id));
        $this->assertSame($riyals, $this->entriesClaimedBy($first->id));
        $this->assertSame([], $this->unclaimedEntries());

        $this->assertNull($this->engine->calculate(sellerId: self::SELLER));
        $this->assertSame([], $this->engine->calculateEachCurrency(sellerId: self::SELLER));
        $this->assertSame(2, VendorSettlement::count());
    }

    public function test_the_currency_of_the_earliest_entry_waiting_is_the_one_a_single_call_draws(): void
    {
        $this->useDefaultCurrency(code: 'SAR');
        $this->earning(amount: 12.345, currency: 'KWD');
        $this->earning(amount: 100.5, currency: 'SAR');

        $this->assertSame(['KWD', 12.345, 0.0, 12.345, 1], $this->totals($this->engine->calculate(sellerId: self::SELLER)));
    }

    public function test_a_later_run_draws_only_what_arrived_since_in_each_currency(): void
    {
        $this->useDefaultCurrency(code: 'SAR');
        $this->earning(amount: 100.5, currency: 'SAR');
        $this->earning(amount: 12.345, currency: 'KWD');
        $this->engine->calculateEachCurrency(sellerId: self::SELLER);
        $later = $this->earning(amount: 3.005, currency: 'KWD');

        $settlements = $this->engine->calculateEachCurrency(sellerId: self::SELLER);

        $this->assertCount(1, $settlements);
        $this->assertSame(['KWD', 3.005, 0.0, 3.005, 1], $this->totals($settlements[0]));
        $this->assertSame([$later->id], $this->entriesClaimedBy($settlements[0]->id));
        $this->assertSame(3, VendorSettlement::count());
    }

    public function test_entries_that_must_not_be_drawn_are_left_alone_in_every_currency(): void
    {
        $this->useDefaultCurrency(code: 'SAR');
        $drawn = $this->earning(amount: 12.345, currency: 'KWD');
        $left = [
            $this->earning(amount: 9.0, currency: 'KWD', changes: ['status' => VendorLedgerEntry::STATUS_PENDING])->id,
            $this->ledgerEntry(changes: ['entryType' => VendorLedgerEntry::TYPE_PAYOUT, 'debit' => 2.0, 'status' => VendorLedgerEntry::STATUS_RESERVED, 'referenceType' => 'payout_reserve', 'currency' => 'KWD'])->id,
            $this->ledgerEntry(changes: ['entryType' => VendorLedgerEntry::TYPE_MANUAL_ADJUSTMENT, 'credit' => 2.0, 'referenceType' => 'payout_release', 'currency' => 'KWD'])->id,
            $this->earning(amount: 4.0, currency: 'KWD', changes: ['sellerId' => 8])->id,
        ];

        $settlements = $this->engine->calculateEachCurrency(sellerId: self::SELLER);

        $this->assertCount(1, $settlements);
        $this->assertSame(['KWD', 12.345, 0.0, 12.345, 1], $this->totals($settlements[0]));
        $this->assertSame([$drawn->id], $this->entriesClaimedBy($settlements[0]->id));
        $this->assertSame($left, $this->unclaimedEntries());
    }

    public function test_calculateForAll_draws_every_currency_of_every_seller(): void
    {
        $this->useDefaultCurrency(code: 'SAR');
        $this->earning(amount: 100.5, currency: 'SAR');
        $this->earning(amount: 12.345, currency: 'KWD');
        $this->earning(amount: 30.0, currency: 'SAR', changes: ['sellerId' => 8]);
        $this->earning(amount: 4.75, currency: 'AED', changes: ['sellerId' => 8]);
        $this->earning(amount: 1.125, currency: 'BHD', changes: ['sellerId' => 9]);

        $settlements = $this->engine->calculateForAll();

        $nets = [];
        foreach ($settlements as $settlement) {
            $nets[$settlement->seller_id . ' ' . $settlement->currency] = $settlement->net_amount;
        }
        ksort($nets);
        $this->assertSame(['7 KWD' => 12.345, '7 SAR' => 100.5, '8 AED' => 4.75, '8 SAR' => 30.0, '9 BHD' => 1.125], $nets);
        $this->assertCount(5, $settlements);
        $this->assertSame([], $this->unclaimedEntries());
        $this->assertSame([], $this->engine->calculateForAll());
    }

    public function test_a_period_is_applied_to_every_currency_and_each_settlement_of_an_open_period_spans_its_own_entries(): void
    {
        $this->useDefaultCurrency(code: 'SAR');
        $this->travelTo(Carbon::parse('2026-11-01 08:00:00'));
        $this->earning(amount: 100.5, currency: 'SAR');
        $this->travelTo(Carbon::parse('2026-11-02 08:00:00'));
        $this->earning(amount: 12.345, currency: 'KWD');
        $this->travelTo(Carbon::parse('2026-11-03 08:00:00'));
        $this->earning(amount: 7.655, currency: 'KWD');
        $this->earning(amount: 40.0, currency: 'SAR');
        $this->travelTo(Carbon::parse('2026-11-05 02:00:00'));

        $ofTheSecond = $this->engine->calculateEachCurrency(sellerId: self::SELLER, periodStart: Carbon::parse('2026-11-02 00:00:00'), periodEnd: Carbon::parse('2026-11-02 23:59:59'));
        $ofTheRest = $this->engine->calculateEachCurrency(sellerId: self::SELLER);

        $this->assertCount(1, $ofTheSecond);
        $this->assertSame(['KWD', 12.345, 0.0, 12.345, 1], $this->totals($ofTheSecond[0]));
        $this->assertSame(['2026-11-02 00:00:00', '2026-11-02 23:59:59'], [$ofTheSecond[0]->period_start->toDateTimeString(), $ofTheSecond[0]->period_end->toDateTimeString()]);
        $this->assertCount(2, $ofTheRest);
        $this->assertSame(['SAR', 140.5, 0.0, 140.5, 2], $this->totals($ofTheRest[0]));
        $this->assertSame(['2026-11-01 08:00:00', '2026-11-03 08:00:00'], [$ofTheRest[0]->period_start->toDateTimeString(), $ofTheRest[0]->period_end->toDateTimeString()]);
        $this->assertSame(['KWD', 7.655, 0.0, 7.655, 1], $this->totals($ofTheRest[1]));
        $this->assertSame(['2026-11-03 08:00:00', '2026-11-03 08:00:00'], [$ofTheRest[1]->period_start->toDateTimeString(), $ofTheRest[1]->period_end->toDateTimeString()]);
    }

    public function test_an_entry_that_names_no_currency_is_settled_with_the_base_currency_of_the_store(): void
    {
        $this->useDefaultCurrency(code: 'SAR');
        $riyals = [
            $this->withoutACurrency($this->earning(amount: 5.0))->id,
            $this->earning(amount: 10.0, currency: 'SAR')->id,
        ];
        $dinar = $this->earning(amount: 12.345, currency: 'KWD');
        $riyals[] = $this->withoutACurrency($this->charge(amount: 2.5), label: '')->id;
        $riyals[] = $this->withoutACurrency($this->earning(amount: 1.25), label: '  ')->id;

        $settlements = $this->engine->calculateEachCurrency(sellerId: self::SELLER);

        $this->assertCount(2, $settlements);
        $this->assertSame(['SAR', 16.25, 2.5, 13.75, 4], $this->totals($settlements[0]));
        $this->assertSame($riyals, $this->entriesClaimedBy($settlements[0]->id));
        $this->assertSame(['KWD', 12.345, 0.0, 12.345, 1], $this->totals($settlements[1]));
        $this->assertSame([$dinar->id], $this->entriesClaimedBy($settlements[1]->id));
        $this->assertSame([], $this->unclaimedEntries());
    }

    public function test_the_base_currency_an_unnamed_entry_is_read_as_is_the_store_s_whatever_it_is(): void
    {
        $this->useDefaultCurrency(code: 'KWD');
        $this->withoutACurrency($this->earning(amount: 5.005));
        $this->earning(amount: 12.345, currency: 'KWD');
        $this->earning(amount: 100.0, currency: 'SAR');

        $settlements = $this->engine->calculateEachCurrency(sellerId: self::SELLER);

        $this->assertCount(2, $settlements);
        $this->assertSame(['KWD', 17.35, 0.0, 17.35, 2], $this->totals($settlements[0]));
        $this->assertSame(['SAR', 100.0, 0.0, 100.0, 1], $this->totals($settlements[1]));
    }

    public function test_a_ledger_of_entries_that_name_no_currency_is_one_settlement_in_the_base_currency_with_its_balances(): void
    {
        $this->useDefaultCurrency(code: 'SAR');
        $this->withoutACurrency($this->earning(amount: 100.0));
        $this->withoutACurrency($this->charge(amount: 15.0));

        $settlements = $this->engine->calculateEachCurrency(sellerId: self::SELLER);

        $this->assertCount(1, $settlements);
        $this->assertSame(['SAR', 100.0, 15.0, 85.0, 2], $this->totals($settlements[0]));
        $this->assertSame([0.0, 85.0], $this->balances($settlements[0]));
    }

    public function test_when_the_base_currency_cannot_be_read_unnamed_entries_are_settled_on_their_own_and_never_with_a_named_currency(): void
    {
        $unnamed = [$this->withoutACurrency($this->earning(amount: 5.0, currency: 'SAR'))->id];
        $riyal = $this->earning(amount: 10.0, currency: 'SAR');
        $unnamed[] = $this->withoutACurrency($this->earning(amount: 2.5, currency: 'SAR'), label: '')->id;

        $settlements = $this->engine->calculateEachCurrency(sellerId: self::SELLER);

        $this->assertCount(2, $settlements);
        $this->assertSame([null, 7.5, 0.0, 7.5, 2], $this->totals($settlements[0]));
        $this->assertSame($unnamed, $this->entriesClaimedBy($settlements[0]->id));
        $this->assertSame(['SAR', 10.0, 0.0, 10.0, 1], $this->totals($settlements[1]));
        $this->assertSame([$riyal->id], $this->entriesClaimedBy($settlements[1]->id));
        $this->assertSame([], $this->unclaimedEntries());
    }

    public function test_a_currency_is_the_same_currency_however_its_code_is_written_and_the_settlement_keeps_the_first_spelling(): void
    {
        $this->useDefaultCurrency(code: 'SAR');
        $this->earning(amount: 1.0, currency: 'sar');
        $this->earning(amount: 2.0, currency: 'SAR');
        $this->withoutACurrency($this->earning(amount: 4.0), label: ' Sar ');

        $settlements = $this->engine->calculateEachCurrency(sellerId: self::SELLER);

        $this->assertCount(1, $settlements);
        $this->assertSame(['sar', 7.0, 0.0, 7.0, 3], $this->totals($settlements[0]));
        $this->assertSame([0.0, 7.0], $this->balances($settlements[0]));
    }

    public function test_a_settlement_of_a_ledger_that_mixes_currencies_records_no_opening_and_no_closing_balance(): void
    {
        $this->useDefaultCurrency(code: 'SAR');
        $this->earning(amount: 100.5, currency: 'SAR');
        $this->earning(amount: 12.345, currency: 'KWD');
        $this->charge(amount: 10.1235, currency: 'SAR');

        [$inRiyals, $inDinars] = $this->engine->calculateEachCurrency(sellerId: self::SELLER);

        $this->assertSame([null, null], $this->balances($inRiyals));
        $this->assertSame([null, null], $this->balances($inDinars));
        $this->assertSame(['SAR', 100.5, 10.1235, 90.3765, 2], $this->totals($inRiyals));
        $this->assertSame(['KWD', 12.345, 0.0, 12.345, 1], $this->totals($inDinars));
        $this->assertFalse($inRiyals->hasBalances());
        $this->assertFalse($inDinars->hasBalances());
    }

    public function test_a_settlement_keeps_its_balances_while_the_ledger_up_to_its_last_entry_is_in_its_currency(): void
    {
        $this->useDefaultCurrency(code: 'SAR');
        $this->earning(amount: 100.0, currency: 'SAR');
        $this->charge(amount: 10.0, currency: 'SAR');
        $this->earning(amount: 12.345, currency: 'KWD');

        [$inRiyals, $inDinars] = $this->engine->calculateEachCurrency(sellerId: self::SELLER);

        $this->assertSame([0.0, 90.0], $this->balances($inRiyals));
        $this->assertTrue($inRiyals->hasBalances());
        $this->assertSame([null, null], $this->balances($inDinars));

        $this->earning(amount: 5.0, currency: 'SAR');
        $afterTheLedgerMixed = $this->engine->calculate(sellerId: self::SELLER);

        $this->assertSame(['SAR', 5.0, 0.0, 5.0, 1], $this->totals($afterTheLedgerMixed));
        $this->assertSame([null, null], $this->balances($afterTheLedgerMixed));
    }

    public function test_an_entry_of_another_currency_that_was_settled_and_paid_long_ago_still_leaves_the_balances_unknown(): void
    {
        $this->useDefaultCurrency(code: 'SAR');
        $this->earning(amount: 12.345, currency: 'KWD');
        $paid = $this->engine->calculate(sellerId: self::SELLER);
        $this->engine->approve($paid, approvedBy: 3);
        $this->engine->markPaid($paid->fresh(), payoutReference: 'BANK-1', paidBy: 4);
        $this->earning(amount: 100.0, currency: 'SAR');

        $settlement = $this->engine->calculate(sellerId: self::SELLER);

        $this->assertSame([0.0, 12.345], $this->balances($paid->fresh()));
        $this->assertSame(['SAR', 100.0, 0.0, 100.0, 1], $this->totals($settlement));
        $this->assertSame([null, null], $this->balances($settlement));
    }

    public function test_the_ledger_of_another_seller_or_of_the_other_side_of_the_shop_does_not_mix_this_one(): void
    {
        $this->useDefaultCurrency(code: 'SAR');
        $this->earning(amount: 12.345, currency: 'KWD', changes: ['sellerId' => 8]);
        $this->earning(amount: 3.0, currency: 'AED', changes: ['sellerIs' => 'admin']);
        $this->earning(amount: 100.0, currency: 'SAR');

        $settlement = $this->engine->calculate(sellerId: self::SELLER);

        $this->assertSame(['SAR', 100.0, 0.0, 100.0, 1], $this->totals($settlement));
        $this->assertSame([0.0, 100.0], $this->balances($settlement));
    }

    public function test_cancelling_a_settlement_releases_the_entries_of_its_own_currency_and_no_other(): void
    {
        $this->useDefaultCurrency(code: 'SAR');
        $riyals = [$this->earning(amount: 100.5, currency: 'SAR')->id, $this->charge(amount: 10.1235, currency: 'SAR')->id];
        $dinars = [$this->earning(amount: 12.345, currency: 'KWD')->id, $this->earning(amount: 7.655, currency: 'KWD')->id];
        [$inRiyals, $inDinars] = $this->engine->calculateEachCurrency(sellerId: self::SELLER);

        $this->assertTrue($this->engine->cancel($inDinars, note: 'the dinar account is not open yet'));

        $this->assertSame($dinars, $this->unclaimedEntries());
        $this->assertSame($riyals, $this->entriesClaimedBy($inRiyals->id));
        $this->assertSame([VendorSettlement::STATUS_CALCULATED, VendorSettlement::STATUS_CANCELLED], [$inRiyals->fresh()->status, $inDinars->fresh()->status]);
        $this->assertSame(['SAR', 100.5, 10.1235, 90.3765, 2], $this->totals($inRiyals->fresh()));

        $again = $this->engine->calculateEachCurrency(sellerId: self::SELLER);

        $this->assertCount(1, $again);
        $this->assertSame(['KWD', 20.0, 0.0, 20.0, 2], $this->totals($again[0]));
        $this->assertSame($dinars, $this->entriesClaimedBy($again[0]->id));
        $this->assertSame($riyals, $this->entriesClaimedBy($inRiyals->id));
    }

    public function test_approving_and_paying_the_settlement_of_one_currency_leaves_the_settlement_of_the_other_as_it_was(): void
    {
        $this->useDefaultCurrency(code: 'SAR');
        $this->earning(amount: 100.5, currency: 'SAR');
        $this->earning(amount: 12.345, currency: 'KWD');
        [$inRiyals, $inDinars] = $this->engine->calculateEachCurrency(sellerId: self::SELLER);

        $this->assertTrue($this->engine->approve($inRiyals, approvedBy: 3));
        $this->assertTrue($this->engine->markPaid($inRiyals->fresh(), payoutReference: 'BANK-1', paidBy: 4));

        $this->assertSame([VendorSettlement::STATUS_PAID, VendorSettlement::STATUS_CALCULATED], [$inRiyals->fresh()->status, $inDinars->fresh()->status]);
        $this->assertSame(['paid', 'available'], VendorLedgerEntry::query()->orderBy('id')->pluck('status')->all());
        $this->assertNull($inDinars->fresh()->approved_at);
        $this->assertFalse($this->engine->markPaid($inDinars->fresh()), 'the settlement of the other currency was approved by nobody');
    }

    public function test_what_is_waiting_is_reported_for_each_currency_without_claiming_anything(): void
    {
        $this->useDefaultCurrency(code: 'SAR');
        $this->earning(amount: 100.5, currency: 'SAR');
        $this->earning(amount: 12.345, currency: 'KWD');
        $this->charge(amount: 10.1235, currency: 'SAR');
        $this->withoutACurrency($this->earning(amount: 4.0));
        $this->earning(amount: 30.0, currency: 'SAR', changes: ['sellerId' => 8]);
        $this->ledgerEntry(changes: ['entryType' => VendorLedgerEntry::TYPE_MANUAL_ADJUSTMENT, 'credit' => 2.0, 'referenceType' => 'payout_release', 'currency' => 'KWD']);

        $lines = [];
        foreach ($this->engine->waiting() as $line) {
            $lines[$line['seller_id'] . ' ' . $line['currency']] = [$line['seller_is'], $line['entries'], $line['credits'], $line['debits'], $line['net']];
        }
        ksort($lines);

        $this->assertSame([
            '7 KWD' => ['seller', 1, 12.345, 0.0, 12.345],
            '7 SAR' => ['seller', 3, 104.5, 10.1235, 94.3765],
            '8 SAR' => ['seller', 1, 30.0, 0.0, 30.0],
        ], $lines);
        $this->assertCount(2, $this->engine->waiting(sellerId: self::SELLER));
        $this->assertSame([], $this->engine->waiting(sellerId: 999));
        $this->assertCount(6, $this->unclaimedEntries());
        $this->assertSame(0, VendorSettlement::count());
    }
}
