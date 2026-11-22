<?php

namespace Tests\Feature\Wallet;

use App\Models\User;
use App\Repositories\WalletTransactionRepository;
use App\Services\Customer\CustomerBalanceLock;
use App\Utils\CustomerManager;
use App\Utils\OrderManager;
use Error;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Wallet\WalletTables;
use Tests\Support\Wallet\WalletWritingHost;
use Tests\TestCase;

/**
 * Every wallet writer changes the balance and writes its ledger row in one transaction, under the customer's row lock.
 *
 * A failure between the two writes must leave neither behind, and a missing customer must change nothing. Retries are not
 * guarded: the writers have no idempotency key, so the characterization below pins what happens today without endorsing it.
 */
class WalletWriteAtomicityTest extends TestCase
{
    private const CUSTOMER_ID = 1;
    private const OPENING_BALANCE = 100.0;
    private const DEBIT_AMOUNT = 10.0;

    protected function setUp(): void
    {
        parent::setUp();
        WalletTables::drop();
        WalletTables::create();
        WalletTables::setSetting('wallet_status', '1');
        WalletTables::setSetting('loyalty_point_exchange_rate', '100');
        WalletTables::seedCustomer(self::CUSTOMER_ID, self::OPENING_BALANCE);
    }

    protected function tearDown(): void
    {
        WalletTables::drop();
        parent::tearDown();
    }

    /** @return array<string, array{0: string}> */
    public static function writers(): array
    {
        return [
            'repository' => ['repository'],
            'customer manager' => ['customer_manager'],
            'order manager' => ['order_manager'],
            'customer trait' => ['customer_trait'],
        ];
    }

    #[DataProvider('writers')]
    public function test_a_failed_ledger_write_leaves_the_wallet_unchanged(string $writer): void
    {
        Schema::drop('wallet_transactions');

        $result = $this->write($writer, customerId: (string) self::CUSTOMER_ID, transactionType: 'order_place', reference: 'order-atomic');

        $this->assertFalse($result);
        $this->assertSame(self::OPENING_BALANCE, WalletTables::walletBalance(self::CUSTOMER_ID));
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('writers')]
    public function test_a_missing_customer_is_refused_and_nothing_is_written(string $writer): void
    {
        $result = $this->write($writer, customerId: '999', transactionType: 'order_place', reference: 'order-missing');

        $this->assertFalse($result);
        $this->assertSame(0, DB::table('wallet_transactions')->count());
        $this->assertSame(self::OPENING_BALANCE, WalletTables::walletBalance(self::CUSTOMER_ID));
    }

    /**
     * Not endorsed. Nothing keys a write to its reference or to any other value, so a retry debits again. POS writes the constant
     * reference order_place_in_pos, so the reference cannot serve as the key either. Whether writes need an idempotency key is an
     * owner decision.
     */
    #[DataProvider('writers')]
    public function test_current_behaviour_an_identical_retry_debits_again(string $writer): void
    {
        $this->write($writer, customerId: (string) self::CUSTOMER_ID, transactionType: 'order_place', reference: 'order-retried');
        $this->write($writer, customerId: (string) self::CUSTOMER_ID, transactionType: 'order_place', reference: 'order-retried');

        $this->assertSame(self::OPENING_BALANCE - 2 * self::DEBIT_AMOUNT, WalletTables::walletBalance(self::CUSTOMER_ID));
        $this->assertSame(2, DB::table('wallet_transactions')->count());
    }

    public function test_an_error_inside_the_write_is_rethrown_and_rolled_back(): void
    {
        $thrown = null;

        try {
            CustomerBalanceLock::apply(self::CUSTOMER_ID, function (User $customer): void {
                $customer->wallet_balance = 0;
                $customer->save();
                throw new Error('failure after the balance was written');
            });
        } catch (Error $error) {
            $thrown = $error;
        }

        $this->assertInstanceOf(Error::class, $thrown);
        $this->assertSame(self::OPENING_BALANCE, WalletTables::walletBalance(self::CUSTOMER_ID));
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_an_exception_inside_the_write_returns_false_and_rolls_back(): void
    {
        $result = CustomerBalanceLock::apply(self::CUSTOMER_ID, function (User $customer): void {
            $customer->wallet_balance = 0;
            $customer->save();
            throw new RuntimeException('failure after the balance was written');
        });

        $this->assertFalse($result);
        $this->assertSame(self::OPENING_BALANCE, WalletTables::walletBalance(self::CUSTOMER_ID));
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_a_failed_write_inside_an_outer_transaction_undoes_only_its_own_changes(): void
    {
        DB::beginTransaction();
        DB::table('users')->where('id', self::CUSTOMER_ID)->update(['loyalty_point' => 7]);

        $result = CustomerBalanceLock::apply(self::CUSTOMER_ID, function (User $customer): void {
            $customer->wallet_balance = 0;
            $customer->save();
            throw new RuntimeException('failure after the balance was written');
        });
        DB::commit();

        $this->assertFalse($result);
        $this->assertSame(self::OPENING_BALANCE, WalletTables::walletBalance(self::CUSTOMER_ID));
        $this->assertSame(7.0, (float) DB::table('users')->where('id', self::CUSTOMER_ID)->value('loyalty_point'));
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_a_missing_customer_never_runs_the_write(): void
    {
        $writeRan = false;

        $result = CustomerBalanceLock::apply(999, function () use (&$writeRan): void {
            $writeRan = true;
        });

        $this->assertFalse($result);
        $this->assertFalse($writeRan);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_a_successful_write_commits_and_returns_its_result(): void
    {
        $result = CustomerBalanceLock::apply(self::CUSTOMER_ID, function (User $customer): string {
            $customer->wallet_balance = 42;
            $customer->save();

            return 'written';
        });

        $this->assertSame('written', $result);
        $this->assertSame(42.0, WalletTables::walletBalance(self::CUSTOMER_ID));
        $this->assertSame(0, DB::transactionLevel());
    }

    private function write(string $writer, string $customerId, string $transactionType, string $reference, float $amount = self::DEBIT_AMOUNT): mixed
    {
        return match ($writer) {
            'repository' => app(WalletTransactionRepository::class)->addWalletTransaction(
                user_id: $customerId,
                amount: $amount,
                transactionType: $transactionType,
                reference: $reference,
            ),
            'customer_manager' => CustomerManager::create_wallet_transaction(
                user_id: $customerId,
                amount: $amount,
                transaction_type: $transactionType,
                reference: $reference,
            ),
            'order_manager' => OrderManager::createWalletTransaction(
                user_id: $customerId,
                amount: $amount,
                transaction_type: $transactionType,
                reference: $reference,
            ),
            'customer_trait' => app(WalletWritingHost::class)->write(
                customerId: $customerId,
                amount: $amount,
                transactionType: $transactionType,
                reference: $reference,
            ),
        };
    }
}
