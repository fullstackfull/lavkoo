<?php

namespace Tests\Feature\Marketplace;

use App\Models\VendorLedgerEntry;
use App\Services\Marketplace\AnEventIsAlreadyOnAnotherChain;
use App\Services\Marketplace\SettlementEngine;
use App\Services\Marketplace\VendorLedger;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsWalletAndVendorLedgerSchema;
use Tests\Support\WritesSellerLedger;
use Tests\TestCase;

/**
 * One order line is credited once, whatever the chain key resolved to while it was being delivered (E-17.2).
 *
 * The chain an entry belongs to is keyed by the seller, the selling company and the currency, and the currency of a call that names none is the store's base currency — a
 * SETTING, which moves. So the key of one event is not stable, and an idempotency lookup narrowed to the chain misses the entry that is already there as soon as the setting
 * changes between two deliveries of one order line: the line is credited a second time, both entries are stamped with the same running balance, and the settlement drawn out
 * of them pays the seller twice for one sale.
 *
 * The event itself is stable: every `reference_id` a writer passes is the key of one row of one table of this database, so (seller, entry type, reference) names one event
 * and the entry already on the ledger for it is the same money. `VendorLedger::record()` therefore looks the event up by that tuple, hands back the entry it finds when the
 * chain being written holds it, and REFUSES when it does not — because appending a second entry credits one line twice, and handing back the entry of another chain is a
 * write that never arrives, and nothing the writer can read says which of the two keys is the right one.
 *
 * Both switch positions are here. With `gcc.ledgers.keyed_by_entity_and_currency` off a seller has the one chain the platform has always had, so every entry of theirs is in
 * it and nothing is ever refused; that is the live Saudi position and it does not move.
 */
class OneEventIsCreditedOnceTest extends TestCase
{
    use BuildsWalletAndVendorLedgerSchema;
    use WritesSellerLedger;

    private const WHAT_THE_LINE_EARNED = 50.0;

    private const THE_ORDER_LINE = 900;

    private VendorLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildVendorSettlementTables();
        $this->useDefaultCurrency(code: 'SAR');
        $this->ledger = app(VendorLedger::class);
    }

    /** One delivery of one order line, as the order writer delivers it (app/Utils/OrderManager.php): no currency named, so the chain key is resolved from the setting. */
    private function deliverTheOrderLine(): ?VendorLedgerEntry
    {
        return $this->ledger->record(
            sellerId: self::SELLER,
            entryType: VendorLedgerEntry::TYPE_ORDER_EARNING,
            credit: self::WHAT_THE_LINE_EARNED,
            referenceType: 'order_details',
            referenceId: self::THE_ORDER_LINE,
            description: 'Order #5',
            availableAt: now()->subDay(),
        );
    }

    /**
     * What a store can do to its base currency between two deliveries of one order line, and whether it moves the chain key of an entry already stamped `SAR`.
     *
     * @return array<string, array{Closure, bool}>
     */
    private static function theChangesAStoreCanMake(): array
    {
        return [
            'nothing at all' => [static fn (): null => null, false],
            'the base currency setting now points at another currency' => [static function (): void {
                $another = DB::table('currencies')->insertGetId(['name' => 'AED currency', 'code' => 'AED', 'symbol' => 'AED-symbol', 'exchange_rate' => 1, 'status' => 1]);
                DB::table('business_settings')->where('type', 'system_default_currency')->update(['value' => (string) json_encode($another)]);
                Cache::flush();
            }, true],
            'the base currency row is deleted' => [static function (): void {
                DB::table('currencies')->delete();
                Cache::flush();
            }, true],
            'the base currency setting is deleted' => [static function (): void {
                DB::table('business_settings')->where('type', 'system_default_currency')->delete();
                Cache::flush();
            }, true],
            'the base currency is spelled in another case' => [static function (): void {
                DB::table('currencies')->update(['code' => 'sar ']);
                Cache::flush();
            }, false],
        ];
    }

    /**
     * Every change a store can make, in both positions of the switch. A chain key that moved is refused; one that did not is the idempotency the ledger has always had.
     *
     * @return array<string, array{Closure, bool, bool}>
     */
    public static function whatHappenedBetweenTheTwoDeliveries(): array
    {
        $cases = [];
        foreach (self::theChangesAStoreCanMake() as $change => [$between, $theKeyMoves]) {
            foreach (['chains kept apart' => true, 'one chain for the seller' => false] as $position => $chainsApart) {
                $cases[$change . ', ' . $position] = [$between, $chainsApart, $chainsApart && $theKeyMoves];
            }
        }

        return $cases;
    }

    #[DataProvider('whatHappenedBetweenTheTwoDeliveries')]
    public function test_one_order_line_delivered_twice_is_credited_once(Closure $between, bool $chainsApart, bool $isRefused): void
    {
        config(['gcc.ledgers.keyed_by_entity_and_currency' => $chainsApart]);

        $first = $this->deliverTheOrderLine();
        $between();

        $second = null;
        $refusal = null;
        try {
            $second = $this->deliverTheOrderLine();
        } catch (AnEventIsAlreadyOnAnotherChain $thrown) {
            $refusal = $thrown;
        }

        $this->assertTheOrderLineIsOnTheLedgerOnce(first: $first);
        $this->assertSame($isRefused, $refusal !== null, 'the second delivery was refused when it should not have been, or taken when it should have been refused');
        if (!$isRefused) {
            $this->assertSame($first->id, $second->id, 'the second delivery of one order line has to hand back the entry of the first');
        }
    }

    /**
     * The whole way through to the money: the setting goes unreadable for one delivery and comes back, and the seller is settled for the one earning they made.
     *
     * Measured on the head this replaces, with the same fixture: two entries, 100.0000 credited for a 50.00 earning, both stamped `balance_after` 50.0000, and a settlement
     * recording opening 0 + net 100 = closing 50.
     */
    public function test_a_base_currency_that_goes_unreadable_and_comes_back_leaves_one_earning_to_settle(): void
    {
        config(['gcc.ledgers.keyed_by_entity_and_currency' => true]);
        $earning = $this->deliverTheOrderLine();
        $setting = DB::table('business_settings')->where('type', 'system_default_currency')->first();

        DB::table('business_settings')->where('type', 'system_default_currency')->delete();
        Cache::flush();

        try {
            $this->deliverTheOrderLine();
            $this->fail('the order line was credited a second time while its base currency could not be read');
        } catch (AnEventIsAlreadyOnAnotherChain $refusal) {
            $this->assertSame($earning->id, $refusal->entry->id);
        }

        DB::table('business_settings')->insert(['type' => $setting->type, 'value' => $setting->value]);
        Cache::flush();
        DB::table('vendor_ledger_entries')->update(['status' => VendorLedgerEntry::STATUS_AVAILABLE]);

        $settlements = app(SettlementEngine::class)->calculateEachCurrency(sellerId: self::SELLER);

        $this->assertCount(1, $settlements, 'the one order line was settled in two settlements');
        $this->assertSame(
            ['SAR', 0.0, self::WHAT_THE_LINE_EARNED, self::WHAT_THE_LINE_EARNED, 1],
            [$settlements[0]->currency, $settlements[0]->opening_balance, $settlements[0]->net_amount, $settlements[0]->closing_balance, $settlements[0]->entry_count],
        );
    }

    /** A refusal names the event, the chain the money is already in and the chain the writer resolved, because only an operator can say which of the two is right. */
    public function test_a_refusal_names_both_chains_and_leaves_the_ledger_as_it_was(): void
    {
        config(['gcc.ledgers.keyed_by_entity_and_currency' => true]);
        $earning = $this->deliverTheOrderLine();

        try {
            $this->ledger->record(
                sellerId: self::SELLER,
                entryType: VendorLedgerEntry::TYPE_ORDER_EARNING,
                credit: self::WHAT_THE_LINE_EARNED,
                referenceType: 'order_details',
                referenceId: self::THE_ORDER_LINE,
                currency: 'AED',
            );
            $this->fail('the same order line was credited in a second currency');
        } catch (AnEventIsAlreadyOnAnotherChain $refusal) {
            $this->assertSame($earning->id, $refusal->entry->id);
            $this->assertStringContainsString('order_details #900', $refusal->getMessage());
            $this->assertStringContainsString('already on the seller ledger as entry #' . $earning->id, $refusal->getMessage());
            $this->assertStringContainsString('which carries currency SAR and selling company none', $refusal->getMessage());
            $this->assertStringContainsString('This write resolved currency AED and selling company none', $refusal->getMessage());
        }

        $this->assertTheOrderLineIsOnTheLedgerOnce(first: $earning);
    }

    private function assertTheOrderLineIsOnTheLedgerOnce(VendorLedgerEntry $first): void
    {
        $rows = DB::table('vendor_ledger_entries')->orderBy('id')->get();

        $this->assertSame([$first->id], $rows->pluck('id')->map(static fn ($id): int => (int) $id)->all(), 'the same order line was appended a second time');
        $this->assertSame(self::WHAT_THE_LINE_EARNED, (float) $rows->sum('credit'), 'one order line was credited twice');
        $this->assertSame(self::WHAT_THE_LINE_EARNED, (float) $rows->first()->balance_after, 'the running balance of the chain counts the line twice');
    }
}
