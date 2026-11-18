<?php

namespace Tests\Unit\Customer;

use App\Models\User;
use App\Services\Customer\CustomerBalanceLock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use RuntimeException;
use Tests\Support\Wallet\WalletTables;
use Tests\TestCase;

/**
 * A refused or failed balance write returns false, but a deadlock or lock-wait timeout must reach the caller: it is not a
 * refusal, and reading it as one hides that the write was lost to a concurrent writer.
 */
class CustomerBalanceLockTest extends TestCase
{
    private const CUSTOMER_ID = 1;
    private const MISSING_CUSTOMER_ID = 2;
    private const OPENING_BALANCE = 100.0;
    private const CHANGED_BALANCE = 90.0;
    private const DEADLOCK_MESSAGE = 'Deadlock found when trying to get lock; try restarting transaction';
    private const LOCK_WAIT_TIMEOUT_MESSAGE = 'Lock wait timeout exceeded; try restarting transaction';

    protected function setUp(): void
    {
        parent::setUp();
        WalletTables::create();
        WalletTables::seedCustomer(customerId: self::CUSTOMER_ID, walletBalance: self::OPENING_BALANCE);
    }

    public function test_successful_write_commits_and_returns_its_result(): void
    {
        $result = CustomerBalanceLock::apply(self::CUSTOMER_ID, function (User $customer): string {
            $this->changeBalance(customer: $customer);

            return 'written';
        });

        $this->assertSame('written', $result);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(self::CHANGED_BALANCE, WalletTables::walletBalance(customerId: self::CUSTOMER_ID));
    }

    public function test_missing_customer_returns_false_and_writes_nothing(): void
    {
        $writeRan = false;

        $result = CustomerBalanceLock::apply(self::MISSING_CUSTOMER_ID, function () use (&$writeRan): string {
            $writeRan = true;

            return 'written';
        });

        $this->assertFalse($result);
        $this->assertFalse($writeRan);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(self::OPENING_BALANCE, WalletTables::walletBalance(customerId: self::CUSTOMER_ID));
    }

    public function test_ordinary_exception_returns_false_and_rolls_back(): void
    {
        $result = CustomerBalanceLock::apply(self::CUSTOMER_ID, function (User $customer): never {
            $this->changeBalance(customer: $customer);

            throw new RuntimeException('The ledger row could not be written.');
        });

        $this->assertFalse($result);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(self::OPENING_BALANCE, WalletTables::walletBalance(customerId: self::CUSTOMER_ID));
    }

    public function test_deadlock_is_rethrown_after_rolling_back_the_write(): void
    {
        $deadlock = $this->concurrencyError(message: self::DEADLOCK_MESSAGE);

        $this->assertThrows(
            test: fn () => CustomerBalanceLock::apply(self::CUSTOMER_ID, function (User $customer) use ($deadlock): never {
                $this->changeBalance(customer: $customer);

                throw $deadlock;
            }),
            expectedClass: fn (QueryException $thrown): bool => $thrown === $deadlock,
        );
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(self::OPENING_BALANCE, WalletTables::walletBalance(customerId: self::CUSTOMER_ID));
    }

    public function test_lock_wait_timeout_is_rethrown(): void
    {
        $timeout = $this->concurrencyError(message: self::LOCK_WAIT_TIMEOUT_MESSAGE);

        $this->expectExceptionObject($timeout);

        CustomerBalanceLock::apply(self::CUSTOMER_ID, function () use ($timeout): never {
            throw $timeout;
        });
    }

    public function test_deadlock_inside_an_outer_transaction_reaches_the_outer_caller(): void
    {
        $deadlock = $this->concurrencyError(message: self::DEADLOCK_MESSAGE);

        $this->assertThrows(
            test: fn () => DB::transaction(function () use ($deadlock): void {
                CustomerBalanceLock::apply(self::CUSTOMER_ID, function (User $customer) use ($deadlock): never {
                    $this->changeBalance(customer: $customer);

                    throw $deadlock;
                });
            }),
            expectedClass: fn (QueryException $thrown): bool => $thrown === $deadlock,
        );
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(self::OPENING_BALANCE, WalletTables::walletBalance(customerId: self::CUSTOMER_ID));
    }

    private function changeBalance(User $customer): void
    {
        DB::table('users')->where('id', $customer->id)->update(['wallet_balance' => self::CHANGED_BALANCE]);
    }

    private function concurrencyError(string $message): QueryException
    {
        return new QueryException('sqlite', 'select 1', [], new PDOException($message));
    }
}
