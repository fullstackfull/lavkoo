<?php

namespace Tests\Feature\Concurrency;

use App\Models\VendorLedgerEntry;
use App\Services\Marketplace\LedgerChain;
use App\Services\Marketplace\VendorLedger;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsWalletAndVendorLedgerSchema;
use Tests\Support\Concurrency\ConcurrentRunner;
use Tests\Support\WritesSellerLedger;
use Tests\TestCase;

/**
 * The seller ledger under two PHP processes at the same instant, against a real MySQL or MariaDB.
 *
 * `balance_after` is a read-then-write, so two events landing together would both read the balance before them and both write their own, and the ledger would be short by the
 * smaller of the two for ever. `VendorLedger::record()` locks the last entry of the chain for the rest of its transaction to stop that, and nothing in the repository tested
 * it: the lock could be deleted outright with every single-process test still green, which is what a mutation of the line showed.
 *
 * What is NOT asserted here is that two chains do not wait for each other. They do: `ORDER BY id DESC LIMIT 1 FOR UPDATE` walks the primary key unless an index covers the
 * narrowing predicates, and `vendor_ledger_entries` has no migration in this repository to add one in. LedgerChain's docblock says so, and the index is the owner's to
 * approve. What IS asserted is that each chain's running balance comes out right when both are written at once, which is the behaviour the narrowing exists for.
 *
 * What IS now asserted, and is a defect rather than a behaviour, is what a second delivery of the SAME order line does when it reads the ledger before the first delivery
 * commits: it credits the line again. That one is older than the chain key, it is the same with the switch on and off, and its cure is a unique key or a lock over the
 * idempotency tuple, which this table has no migration here to add. The case that measures it says so at length, and arranges the interleaving rather than hoping for it.
 *
 * Which is the dividing line in this file. A case whose assertion must hold for EVERY order the scheduler could pick runs off the common start barrier and asserts the
 * invariant. A case about ONE order holds the two processes at a meeting point (Tests\Support\Concurrency\Rendezvous) and shows in its own report that they were held; the
 * alternative, asserting one order and hoping for it, is a test that goes red by luck.
 *
 * Needs a real engine: SQLite in memory is private to one process, so the whole file skips there.
 */
class VendorLedgerConcurrencyTest extends TestCase
{
    use BuildsWalletAndVendorLedgerSchema;
    use WritesSellerLedger;

    private const COMPANY_SA = 11;

    private const COMPANY_AE = 12;

    private VendorLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process.');
        }

        $this->buildVendorLedgerTables();
        $this->useDefaultCurrency('SAR');
        $this->ledger = app(VendorLedger::class);
    }

    /**
     * Two processes appending at one instant, each reported.
     *
     * What is NOT asserted here is that the two writes interleaved in any particular way. They are released from one common barrier and left to run free, so the order they
     * land in is the scheduler's; `ConcurrentRunner::ranAtTheSameTime()` would answer true for any of those orders and says nothing about which one happened. That is the
     * right instrument for the cases below it, whose assertions have to hold whatever the order is. A case about ONE order has to arrange it: see the one that does.
     *
     * @param list<array<string, mixed>> $perProcess
     * @return list<array{ok: bool, result?: mixed, error?: string, message?: string, started_at: float, finished_at: float, pid: int}>
     */
    private function appendAtOnce(array $perProcess): array
    {
        $reports = ConcurrentRunner::run('vendor_ledger.append', $perProcess);

        foreach ($reports as $index => $report) {
            $this->assertTrue($report['ok'], "process {$index} failed: " . ($report['error'] ?? '') . ' ' . ($report['message'] ?? ''));
        }

        return $reports;
    }

    /** @return array<string, array{bool}> */
    public static function switchPositions(): array
    {
        return ['the switch off, as shipped' => [false], 'chains kept apart' => [true]];
    }

    /** @return list<float> the running balance of every entry of the seller, in the order they were written */
    private function runningBalances(): array
    {
        return VendorLedgerEntry::query()
            ->where('seller_id', self::SELLER)
            ->orderBy('id')
            ->pluck('balance_after')
            ->map(static fn ($balance): float => (float) $balance)
            ->all();
    }

    public function test_two_entries_of_one_chain_written_at_once_do_not_both_read_the_same_balance(): void
    {
        $this->earning(10, 'SAR');

        $this->appendAtOnce([
            ['seller_id' => self::SELLER, 'credit' => 100, 'reference_id' => 'race-a', 'currency' => 'SAR'],
            ['seller_id' => self::SELLER, 'credit' => 100, 'reference_id' => 'race-b', 'currency' => 'SAR'],
        ]);

        $this->assertSame([10.0, 110.0, 210.0], $this->runningBalances(), 'both processes read the balance before them, so one credit is missing from the running balance for ever');
    }

    public function test_two_entries_of_one_chain_written_at_once_keep_the_chains_balance_whole_while_chains_are_kept_apart(): void
    {
        config(['gcc.ledgers.keyed_by_entity_and_currency' => true]);
        $this->earning(10, 'SAR', ['legalEntityId' => self::COMPANY_SA]);

        $this->appendAtOnce([
            ['seller_id' => self::SELLER, 'credit' => 100, 'reference_id' => 'race-c', 'currency' => 'SAR', 'legal_entity_id' => self::COMPANY_SA, 'chains_apart' => true],
            ['seller_id' => self::SELLER, 'credit' => 100, 'reference_id' => 'race-d', 'currency' => 'SAR', 'legal_entity_id' => self::COMPANY_SA, 'chains_apart' => true],
        ]);

        $this->assertSame(
            210.0,
            $this->ledger->balances(sellerId: self::SELLER, chain: $this->ledger->chain(sellerId: self::SELLER, legalEntityId: self::COMPANY_SA, currency: 'SAR'))['balance'],
            'the company chain is short of one of the two credits',
        );
        $this->assertSame([10.0, 110.0, 210.0], $this->runningBalances(), 'the second entry of the chain did not read the balance the first one wrote');
    }

    /**
     * CURRENT BEHAVIOUR, AND A DEFECT OF ITS OWN: a second delivery of one order line that reads the ledger before the first
     * delivery commits credits the line again.
     *
     * The writer's idempotency is a read-then-write like the running balance, and nothing holds it. The second transaction
     * looks the event up before the first has committed, misses, and the lock it then takes is on the entry BEFORE it, which
     * orders the two running balances without stopping the second insert. Measured on MariaDB 10.11.14: a 100.00 line
     * delivered twice this way leaves 3 entries and 210.0000 credited where 110.0000 is owed, and the figures are the same in
     * both positions of the switch.
     *
     * ## Why the interleaving is arranged and not raced
     *
     * Two processes released from the common barrier and left to run free meet wherever the scheduler puts them. Under
     * REPEATABLE READ the second transaction's read view is fixed by its FIRST read — the idempotency lookup — so whether it
     * sees the first transaction's committed row is a coin flip. A case asserting the double credit off the barrier alone was
     * red on 6 runs in 10 on an idle machine and on 1 in 10 on a loaded one, while reading as a settled figure. So the two
     * processes hold each other at a meeting point instead (Rendezvous): the second takes its snapshot, the first then
     * appends and commits, and only then is the second released to append. The defect stops being a thing that sometimes
     * happens and becomes a property of the writer: THIS order of events always costs the line twice.
     *
     * ## Not the double credit this branch closed
     *
     * That one needed no concurrency at all — one process, the store's base currency setting moved in between, and the second
     * delivery of one line was credited again (tests/Feature/Marketplace/OneEventIsCreditedOnceTest.php) — and it was
     * introduced by keying the idempotency lookup on the chain. This one is older than the chain key and survives it: the
     * whole file raced only DISTINCT references before, so nothing in the repository showed it. The cure is a unique key over
     * (seller_id, seller_is, entry_type, reference_type, reference_id) or a lock covering that tuple, and
     * `vendor_ledger_entries` has no migration in this repository to add one in, so it is the owner's to approve, exactly as
     * the index this class's docblock names.
     *
     * So this asserts what the ledger does TODAY, to put the figure on the record and keep it from moving unnoticed. It is
     * not an endorsement of it: when the cure lands, this case becomes the invariant (one entry, 110.0000) and the assertion
     * message says so.
     */
    #[DataProvider('switchPositions')]
    public function test_a_second_delivery_that_read_before_the_first_committed_credits_the_order_line_again(bool $chainsApart): void
    {
        config(['gcc.ledgers.keyed_by_entity_and_currency' => $chainsApart]);
        $this->earning(10, 'SAR');

        $line = ['seller_id' => self::SELLER, 'credit' => 100, 'reference_id' => 'one-order-line', 'currency' => 'SAR', 'chains_apart' => $chainsApart];
        $reports = $this->appendAtOnce([
            ['op' => 'vendor_ledger.append_once_the_other_has_read'] + $line,
            ['op' => 'vendor_ledger.append_with_a_snapshot_taken_first'] + $line,
        ]);

        // The arrangement itself, asserted before its consequence: the second delivery was held, and what it read when it
        // was let go was the first delivery's committed entry. A first delivery that was NOT held would have committed
        // before the second one read, and the second would have found it — which the figures below would then show.
        $this->assertGreaterThan(0, $reports[1]['result']['held_for'], 'the second delivery was not held at the meeting point, so the order of the two writes was the scheduler\'s and not this test\'s');
        $this->assertSame(210.0, (float) $reports[1]['result']['balance_after'], 'the second delivery did not count the first one, so the two did not happen in the order this case arranges');

        $this->assertSame(
            [10.0, 110.0, 210.0],
            $this->runningBalances(),
            'the ledger no longer credits one order line twice under two processes, so the cure has landed and this case is now the invariant it was waiting for',
        );
        $this->assertSame(2, VendorLedgerEntry::query()->where('reference_id', 'one-order-line')->count(), 'one order line, two entries');
    }

    public function test_two_chains_of_one_seller_written_at_once_each_keep_their_own_running_balance(): void
    {
        config(['gcc.ledgers.keyed_by_entity_and_currency' => true]);
        $this->earning(10, 'SAR', ['legalEntityId' => self::COMPANY_SA]);
        $this->earning(20, 'AED', ['legalEntityId' => self::COMPANY_AE]);

        $this->appendAtOnce([
            ['seller_id' => self::SELLER, 'credit' => 100, 'reference_id' => 'race-e', 'currency' => 'SAR', 'legal_entity_id' => self::COMPANY_SA, 'chains_apart' => true],
            ['seller_id' => self::SELLER, 'credit' => 50, 'reference_id' => 'race-f', 'currency' => 'AED', 'legal_entity_id' => self::COMPANY_AE, 'chains_apart' => true],
        ]);

        $this->assertTrue(LedgerChain::areKeptApart());
        $this->assertSame(110.0, $this->ledger->balances(sellerId: self::SELLER, chain: $this->ledger->chain(sellerId: self::SELLER, legalEntityId: self::COMPANY_SA, currency: 'SAR'))['balance']);
        $this->assertSame(70.0, $this->ledger->balances(sellerId: self::SELLER, chain: $this->ledger->chain(sellerId: self::SELLER, legalEntityId: self::COMPANY_AE, currency: 'AED'))['balance'], 'the dirham write read the riyal chain');
    }
}
