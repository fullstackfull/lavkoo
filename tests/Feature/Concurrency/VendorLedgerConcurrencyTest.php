<?php

namespace Tests\Feature\Concurrency;

use App\Models\VendorLedgerEntry;
use App\Services\Marketplace\LedgerChain;
use App\Services\Marketplace\VendorLedger;
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
     * @param list<array<string, mixed>> $perProcess
     * @return list<array{ok: bool, result?: mixed, error?: string, message?: string, started_at: float, finished_at: float, pid: int}>
     */
    private function appendAtOnce(array $perProcess): array
    {
        $reports = ConcurrentRunner::run('vendor_ledger.append', $perProcess);

        foreach ($reports as $index => $report) {
            $this->assertTrue($report['ok'], "process {$index} failed: " . ($report['error'] ?? '') . ' ' . ($report['message'] ?? ''));
        }
        $this->assertTrue(ConcurrentRunner::overlapped($reports), 'the two processes did not actually run at the same time, so nothing was raced');

        return $reports;
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
