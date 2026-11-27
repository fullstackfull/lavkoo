<?php

namespace Tests\Unit\Wallet;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The writers of a customer's wallet are a known, closed set, and every one of them holds the same two guards.
 *
 * Near-identical copies of this write drifted apart over time and every one of them lost a concurrent credit. The fix has two
 * halves. The first is that each writer now reads the balance under the customer's row lock and refuses a debit that would take
 * the wallet below nothing. The second, the half that lasts, is that the NEXT copy fails a test instead of reaching production.
 *
 * This test was written for an end state in which CustomerWalletWriter is the only writer. That consolidation has NOT been made:
 * CustomerWalletWriter exists and three callers use it, while CustomerTrait, CustomerManager and WalletTransactionRepository are
 * still the writers for every other path. Asserting the end state would have made this test a standing failure that says nothing
 * about drift, so it asserts what is true -- the set is closed, and each member is guarded -- and the consolidation is named
 * below as the work that remains. Moving a caller onto CustomerWalletWriter is safe for this test; adding a fifth writer is not.
 *
 * The broad scan of every form a money write can take lives in tests/Support/PlatformWriteScanner and is not repeated here.
 */
class OneWalletWriterTest extends TestCase
{
    /**
     * Every file that may read and write a customer's wallet balance, with the writer it is called by.
     *
     * CustomerWalletWriter is the one the others are to be folded into (see the class docblock). Until that is done and proven,
     * all four are writers and all four are held to the same two guards by
     * test_every_writer_locks_the_balance_and_refuses_an_overdraft.
     */
    private const WRITERS = [
        'app/Services/Customer/CustomerWalletWriter.php' => 'write',
        'app/Traits/CustomerTrait.php' => 'createWalletTransaction',
        'app/Utils/CustomerManager.php' => 'create_wallet_transaction',
        'app/Utils/OrderManager.php' => 'createWalletTransaction',
        'app/Repositories/WalletTransactionRepository.php' => 'addWalletTransaction',
    ];

    /**
     * The generic repository create required by RepositoryInterface. It takes a row already built by its caller, never reads or
     * writes `wallet_balance`, and nothing in the application calls it, so it cannot lose an update. It is named here so that
     * its appearance in a scan is a known fact rather than a new one.
     */
    private const KNOWN_EXCEPTION = 'app/Repositories/WalletTransactionRepository.php';

    /**
     * Where each writer's CREDIT arithmetic lives, for the writers that do not keep it in their own file.
     *
     * Four of the five work the credit out inline. CustomerWalletWriter keeps its shaping in WalletPosting on purpose -- the lost update
     * and the row that disagreed with the balance were both in the writing, and holding the arithmetic apart is what lets one locked
     * writer serve every caller -- so that is where its credit is rounded.
     */
    private const CREDIT_ARITHMETIC = [
        'app/Services/Customer/CustomerWalletWriter.php' => 'app/Services/Customer/WalletPosting.php',
    ];

    /** Where a wallet balance is read and written: a file not listed above has no business doing either. */
    private const BALANCE_ARITHMETIC = 'wallet_balance[^\n]*(\+|-)[^\n]*(credit|debit|bonus|amount)|(credit|debit|bonus)[^\n]*wallet_balance\s*=';

    /** @return array<string, array{0: string}> */
    public static function theWriters(): array
    {
        $cases = [];
        foreach (array_keys(self::WRITERS) as $file) {
            $cases[$file] = [$file];
        }

        return $cases;
    }

    /**
     * The two guards, in every writer. The lock is what makes a second writer wait and then read what the first committed; the
     * overdraft refusal is what keeps a debit from taking a wallet below nothing. A writer with the lock and without the refusal
     * is the hole CustomerWalletWriter shipped with, so neither half is optional.
     */
    #[DataProvider('theWriters')]
    public function test_every_writer_locks_the_balance_and_refuses_an_overdraft(string $file): void
    {
        $source = (string) file_get_contents(base_path($file));

        $this->assertStringContainsString('CustomerBalanceLock::apply(', $source, "{$file} writes a wallet balance without taking the customer's row lock.");
        $this->assertStringContainsString('WalletOverdraft::refuses(', $source, "{$file} writes a wallet balance without refusing an overdraft.");
        $this->assertStringNotContainsString('DB::beginTransaction(', $source, "{$file} opens its own transaction instead of going through the lock service.");
    }

    /**
     * One unit for the credit, in every writer (E-16).
     *
     * Each of the five computed its credit where it stood and none rounded it, so one reward reached the ledger as whatever float the
     * path's own conversion produced. `WalletMinorUnit::credited()` is the one helper they round through, and it hands the amount straight
     * back while `gcc.wallet.credits_in_the_markets_minor_unit` is off, which is how it ships -- so this asserts the SEAM is there, and
     * `Tests\Feature\Wallet\EveryWriterCreditsTheSameAmountTest` asserts what comes through it in both positions of the switch.
     *
     * A sixth writer, or a fifth that works its credit out again downstream of the helper, fails here rather than in a quarterly
     * reconciliation.
     */
    #[DataProvider('theWriters')]
    public function test_every_writer_rounds_its_credit_through_the_one_helper(string $file): void
    {
        $arithmetic = self::CREDIT_ARITHMETIC[$file] ?? $file;
        $source = self::codeWithoutComments((string) file_get_contents(base_path($arithmetic)));

        $this->assertStringContainsString(
            'WalletMinorUnit::credited(',
            $source,
            "{$file} works out a wallet credit without the one helper that says what unit a market counts money in.",
        );
    }

    /** A ledger row built outside these files is a balance nobody locked. */
    public function test_a_wallet_transaction_is_built_only_by_a_known_writer(): void
    {
        $allowed = array_merge(array_keys(self::WRITERS), [self::KNOWN_EXCEPTION]);

        $found = array_values(array_filter(
            $this->filesMatching('new WalletTransaction\(|WalletTransaction::create\(|walletTransaction->create\('),
            static fn (string $file): bool => !in_array($file, $allowed, true),
        ));

        $this->assertSame([], $found, 'a ledger row is being built outside the known wallet writers: ' . implode(', ', $found));
    }

    /** The same for the arithmetic itself, which is what has to happen under the lock. */
    public function test_a_new_wallet_balance_is_worked_out_only_by_a_known_writer(): void
    {
        $found = array_values(array_filter(
            $this->filesMatching(self::BALANCE_ARITHMETIC),
            static fn (string $file): bool => !array_key_exists($file, self::WRITERS),
        ));

        $this->assertSame([], $found, 'a wallet balance is being worked out outside the known wallet writers: ' . implode(', ', $found));
    }

    /**
     * The count itself, so that folding one writer into another is a passing change and adding a fifth is not. A consolidation
     * lowers this number; nothing should raise it.
     */
    public function test_no_further_writer_has_arrived(): void
    {
        $writers = array_values(array_unique(array_merge(
            $this->filesMatching(self::BALANCE_ARITHMETIC),
            $this->filesMatching('new WalletTransaction\(|WalletTransaction::create\(|walletTransaction->create\('),
        )));
        $unexpected = array_values(array_diff($writers, array_keys(self::WRITERS), [self::KNOWN_EXCEPTION]));

        $this->assertSame([], $unexpected, 'a wallet writer this test does not know about: ' . implode(', ', $unexpected));
        $this->assertLessThanOrEqual(
            count(self::WRITERS),
            count(array_intersect($writers, array_keys(self::WRITERS))),
            'the set of wallet writers has grown',
        );
    }

    /**
     * The file's code with its comments and string literals taken out, so that a rule DESCRIBED in a docblock --
     * App\Services\Wallet\Reconciliation\LedgerSpec states this very arithmetic in prose -- is not counted as a write of it.
     */
    private static function codeWithoutComments(string $source): string
    {
        $clean = '';
        foreach (@token_get_all($source) ?: [] as $token) {
            if (!is_array($token)) {
                $clean .= $token;
                continue;
            }
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $clean .= "\n";
                continue;
            }
            if (in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
                $clean .= "''";
                continue;
            }
            $clean .= $token[1];
        }

        return $clean;
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
                if (preg_match('/' . $pattern . '/', self::codeWithoutComments((string) file_get_contents($file->getPathname()))) === 1) {
                    $matched[] = str_replace($root . '/', '', $file->getPathname());
                }
            }
        }

        sort($matched);

        return $matched;
    }
}
