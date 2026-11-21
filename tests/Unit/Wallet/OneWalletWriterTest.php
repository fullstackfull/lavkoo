<?php

namespace Tests\Unit\Wallet;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * There is one writer of a customer's wallet, and a second one cannot arrive quietly.
 *
 * Four near-identical copies of this write drifted apart over time and every one of them lost a concurrent credit. Deleting
 * them is only half the fix: the half that lasts is that the next copy fails a test instead of reaching production. This is a
 * narrow pin on THIS change — the names that were removed, and the arithmetic that was removed with them. The broad scan of
 * every form a money write can take lives on gcc/p1-wallet-balance-writers-guard and is not repeated here.
 */
class OneWalletWriterTest extends TestCase
{
    private const THE_WRITER = 'app/Services/Customer/CustomerWalletWriter.php';

    /**
     * The generic repository create required by RepositoryInterface. It takes a row already built by its caller, never reads or
     * writes `wallet_balance`, and nothing in the application calls it, so it cannot lose an update. It is named here so that
     * its appearance in a scan is a known fact rather than a new one.
     */
    private const KNOWN_EXCEPTION = 'app/Repositories/WalletTransactionRepository.php';

    /** @return array<string, array{0: string}> the names the four copies were called by */
    public static function removedWriters(): array
    {
        return [
            'CustomerTrait' => ['createWalletTransaction'],
            'CustomerManager' => ['create_wallet_transaction'],
            'WalletTransactionRepository' => ['addWalletTransaction'],
            'the repository bonus helper' => ['addFundToWalletBonus'],
        ];
    }

    #[DataProvider('removedWriters')]
    public function test_a_removed_writer_has_not_come_back(string $name): void
    {
        $found = array_values(array_filter(
            $this->filesMatching($name . '\s*\('),
            fn (string $file): bool => $file !== self::THE_WRITER,
        ));

        $this->assertSame([], $found, "{$name}() is back. Every wallet write goes through " . self::THE_WRITER . '.');
    }

    /** Nothing else builds a ledger row, because a row built elsewhere is a balance nobody locked. */
    public function test_only_the_writer_builds_a_wallet_transaction(): void
    {
        $found = array_values(array_filter(
            $this->filesMatching('new WalletTransaction\(|WalletTransaction::create\(|walletTransaction->create\('),
            fn (string $file): bool => !in_array($file, [self::THE_WRITER, self::KNOWN_EXCEPTION], true),
        ));

        $this->assertSame([], $found, 'a ledger row is being built outside ' . self::THE_WRITER);
    }

    /** Nothing else works out a new balance, because that arithmetic is what has to happen under the lock. */
    public function test_only_the_writer_works_out_a_new_wallet_balance(): void
    {
        $found = array_values(array_filter(
            $this->filesMatching('wallet_balance[^\n]*(\+|-)[^\n]*(credit|debit|bonus|amount)|(credit|debit|bonus)[^\n]*wallet_balance\s*='),
            fn (string $file): bool => $file !== self::THE_WRITER,
        ));

        $this->assertSame([], $found, 'a wallet balance is being worked out outside ' . self::THE_WRITER);
    }

    /** The writer reads the balance under a row lock, through the lock service rather than a second idiom of its own. */
    public function test_the_writer_reads_the_balance_under_the_lock(): void
    {
        $writer = file_get_contents(base_path(self::THE_WRITER));

        $this->assertStringContainsString('CustomerBalanceLock::apply(', $writer);
        $this->assertStringNotContainsString('DB::beginTransaction(', $writer);
    }

    /** @return list<string> the repository-relative paths of app and module files matching the pattern */
    private function filesMatching(string $pattern): array
    {
        $root = base_path();
        $matched = [];

        foreach (['app', 'Modules'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                if (preg_match('/' . $pattern . '/', (string) file_get_contents($file->getPathname())) === 1) {
                    $matched[] = str_replace($root . '/', '', $file->getPathname());
                }
            }
        }

        sort($matched);

        return $matched;
    }
}
