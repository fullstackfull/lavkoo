<?php

namespace Tests\Feature\Storefront;

use App\Models\VendorLedgerEntry;
use App\Services\Marketplace\VendorLedger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\BuildsWalletAndVendorLedgerSchema;
use Tests\Support\LedgerEditScanner;
use Tests\TestCase;

/**
 * E-17.0 of the master plan: a seller ledger entry records its currency, is never edited, and an event that is recorded again is recorded once.
 *
 * `vendor_ledger_entries` has no migration; the table is built in setUp with the columns VendorLedger writes (tests/Support/BuildsWalletAndVendorLedgerSchema). What makes two events the same is the key the class asks the
 * ledger for before it appends: the seller, whose side of the shop the seller is on (`seller_is`), the type of entry, and the reference (type and id) the entry is about.
 *
 * The class has ONE update path, `releaseMatured()`: it moves a pending entry whose window has passed to `available`. It changes `status` and `updated_at` and nothing else, and the entry is the same entry. Other classes
 * move the status too (PayoutService marks a reserve `paid`, SettlementEngine stamps `settlement_id` and marks entries `paid`); this test is about what VendorLedger itself does.
 */
class VendorLedgerEntryTest extends TestCase
{
    use BuildsWalletAndVendorLedgerSchema;

    private const SELLER = 7;

    private VendorLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildVendorLedgerTables();
        $this->ledger = new VendorLedger();
    }

    /** The store's default currency is the row of `currencies` that the `system_default_currency` setting points at. */
    private function useDefaultCurrency(string $code): void
    {
        $id = DB::table('currencies')->insertGetId(['name' => $code . ' currency', 'code' => $code, 'symbol' => $code, 'exchange_rate' => 1, 'status' => 1]);
        DB::table('business_settings')->insert(['type' => 'system_default_currency', 'value' => json_encode($id)]);
    }

    /** An order earning of 100 SAR for order line 5501, with the changes a test makes to it: the names are the parameters of VendorLedger::record(). */
    private function recordEvent(array $changes = []): ?VendorLedgerEntry
    {
        return $this->ledger->record(...$changes + [
            'sellerId' => self::SELLER,
            'entryType' => VendorLedgerEntry::TYPE_ORDER_EARNING,
            'credit' => 100.0,
            'referenceType' => 'order_details',
            'referenceId' => 5501,
            'currency' => 'SAR',
        ]);
    }

    /** @return list<array<string, mixed>> every row of the table, in the order it was written */
    private function rows(): array
    {
        return DB::table('vendor_ledger_entries')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
    }

    public function test_an_entry_records_the_currency_it_is_given(): void
    {
        $this->useDefaultCurrency(code: 'SAR');

        $first = $this->recordEvent(changes: ['currency' => 'AED', 'referenceId' => 1]);
        $second = $this->recordEvent(changes: ['currency' => 'USD', 'referenceId' => 2]);

        $this->assertSame('AED', $first->currency);
        $this->assertSame('USD', $second->currency);
        $this->assertSame(['AED', 'USD'], array_column($this->rows(), 'currency'));
    }

    public function test_an_entry_given_no_currency_records_the_default_currency_of_the_store(): void
    {
        $this->useDefaultCurrency(code: 'AED');

        $entry = $this->recordEvent(changes: ['currency' => null]);

        $this->assertSame('AED', $entry->currency);
        $this->assertSame(['AED'], array_column($this->rows(), 'currency'));
        $this->assertSame('AED', $this->ledger->baseCurrency());
    }

    public function test_the_amounts_are_stored_as_given_to_four_decimals_and_each_entry_carries_the_running_balance(): void
    {
        $this->recordEvent(changes: ['credit' => 100.5, 'referenceId' => 1]);
        $this->ledger->record(sellerId: self::SELLER, entryType: VendorLedgerEntry::TYPE_COMMISSION_CHARGE, debit: 10.123456, referenceType: 'order_details', referenceId: 1, currency: 'SAR');

        $rows = $this->rows();
        $this->assertEquals(100.5, $rows[0]['credit']);
        $this->assertEquals(0, $rows[0]['debit']);
        $this->assertEquals(100.5, $rows[0]['balance_after']);
        $this->assertEquals(0, $rows[1]['credit']);
        $this->assertEquals(10.1235, $rows[1]['debit']);
        $this->assertEquals(90.3765, $rows[1]['balance_after']);
    }

    public function test_the_balance_of_one_seller_does_not_run_into_the_ledger_of_another(): void
    {
        $this->recordEvent(changes: ['credit' => 100.0, 'referenceId' => 1]);
        $other = $this->recordEvent(changes: ['sellerId' => 8, 'credit' => 30.0, 'referenceId' => 1]);

        $this->assertEquals(30.0, $other->balance_after);
        $this->assertEquals(100.0, $this->ledger->balances(self::SELLER)['balance']);
        $this->assertEquals(30.0, $this->ledger->balances(8)['balance']);
    }

    public function test_an_event_recorded_twice_is_one_row_and_the_second_call_returns_the_first_entry(): void
    {
        $first = $this->recordEvent();
        $second = $this->recordEvent();

        $this->assertSame(1, VendorLedgerEntry::count());
        $this->assertSame($first->id, $second->id);
        $this->assertEquals(100.0, $this->ledger->balances(self::SELLER)['balance']);
    }

    public function test_a_second_call_for_the_same_event_returns_the_first_entry_unchanged_whatever_it_is_given(): void
    {
        $this->recordEvent();
        $before = $this->rows();

        $again = $this->recordEvent(changes: ['credit' => 999.0, 'currency' => 'USD', 'description' => 'a different story', 'status' => VendorLedgerEntry::STATUS_AVAILABLE]);

        $this->assertSame($before, $this->rows());
        $this->assertEquals(100.0, $again->credit);
        $this->assertSame('SAR', $again->currency);
        $this->assertSame(VendorLedgerEntry::STATUS_PENDING, $again->status);
    }

    /** @return array<string, array{array<string, mixed>}> one change to the key of the first event, which makes it another event */
    public static function eventsThatDifferInOnePartOfTheKey(): array
    {
        return [
            'the type of entry (the earning and its commission are two events of one order line)' => [['entryType' => VendorLedgerEntry::TYPE_COMMISSION_CHARGE, 'credit' => 0.0, 'debit' => 15.0]],
            'the id of the reference' => [['referenceId' => 5502]],
            'the type of the reference' => [['referenceType' => 'refund_order_details']],
            'the seller' => [['sellerId' => 8]],
            'the side of the shop the seller is on' => [['sellerIs' => 'admin']],
        ];
    }

    #[DataProvider('eventsThatDifferInOnePartOfTheKey')]
    public function test_two_different_events_are_two_rows(array $change): void
    {
        $first = $this->recordEvent();
        $second = $this->recordEvent(changes: $change);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, VendorLedgerEntry::count());
    }

    /** Not endorsed. An entry with no reference has no identity beyond its seller and its type, so a second one of the same type is taken for the first and is not written. No caller records one today. */
    public function test_current_behaviour_two_entries_of_one_type_without_a_reference_are_taken_for_the_same_event(): void
    {
        $first = $this->ledger->record(sellerId: self::SELLER, entryType: VendorLedgerEntry::TYPE_MANUAL_ADJUSTMENT, credit: 10.0, currency: 'SAR');
        $second = $this->ledger->record(sellerId: self::SELLER, entryType: VendorLedgerEntry::TYPE_MANUAL_ADJUSTMENT, credit: 25.0, currency: 'SAR');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, VendorLedgerEntry::count());
        $this->assertEquals(10.0, $second->credit);
    }

    public function test_recording_a_later_entry_leaves_the_earlier_ones_as_they_were(): void
    {
        $this->recordEvent(changes: ['referenceId' => 1]);
        $this->recordEvent(changes: ['referenceId' => 2, 'credit' => 40.0]);
        $before = $this->rows();

        $this->ledger->record(sellerId: self::SELLER, entryType: VendorLedgerEntry::TYPE_REFUND, debit: 25.0, status: VendorLedgerEntry::STATUS_AVAILABLE, referenceType: 'refund_order_details', referenceId: 1, currency: 'SAR');

        $this->assertSame($before, array_slice($this->rows(), 0, 2));
    }

    public function test_releaseMatured_changes_only_the_status_of_an_entry_whose_window_has_passed(): void
    {
        $matured = $this->recordEvent(changes: ['referenceId' => 1, 'availableAt' => now()->subDay()]);
        $waiting = $this->recordEvent(changes: ['referenceId' => 2, 'availableAt' => now()->addDay()]);
        $withoutWindow = $this->recordEvent(changes: ['referenceId' => 3]);
        $before = $this->rows();

        $released = $this->ledger->releaseMatured();

        $after = $this->rows();
        $this->assertSame(1, $released);
        $this->assertSame([VendorLedgerEntry::STATUS_AVAILABLE, VendorLedgerEntry::STATUS_PENDING, VendorLedgerEntry::STATUS_PENDING], array_column($after, 'status'));
        $this->assertSame([$matured->id, $waiting->id, $withoutWindow->id], array_column($after, 'id'));
        foreach ($before as $position => $row) {
            $this->assertSame(Arr::except($row, ['status', 'updated_at']), Arr::except($after[$position], ['status', 'updated_at']));
        }
    }

    public function test_the_class_has_one_statement_that_edits_an_entry_and_it_is_the_one_in_releaseMatured(): void
    {
        $file = (string) (new ReflectionClass(VendorLedger::class))->getFileName();
        $scanner = new LedgerEditScanner(tables: ['vendor_ledger_entries'], models: ['VendorLedgerEntry']);

        $edits = $scanner->editsIn(code: (string) file_get_contents($file));

        $release = new ReflectionMethod(VendorLedger::class, 'releaseMatured');
        $this->assertCount(1, $edits);
        $this->assertGreaterThanOrEqual($release->getStartLine(), $edits[0]['line']);
        $this->assertLessThanOrEqual($release->getEndLine(), $edits[0]['line']);
        $this->assertStringContainsString("'status' => VendorLedgerEntry::STATUS_AVAILABLE, 'updated_at' => now()", $edits[0]['statement']);
    }
}
