<?php

namespace Tests\Feature\Wallet;

use App\Utils\CustomerManager;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Wallet\WalletTables;
use Tests\Support\Wallet\WalletWriters;
use Tests\TestCase;

/** A wallet debit may not take the balance below zero, on any write path, and a refused debit leaves no trace. */
class WalletOverdraftTest extends TestCase
{
    private const CUSTOMER_ID = 1;

    protected function setUp(): void
    {
        parent::setUp();
        WalletTables::create();
        WalletTables::setSetting('wallet_status', '1');
        WalletTables::setSetting('loyalty_point_exchange_rate', '100');
    }

    /** @return array<string, array{0: string}> */
    public static function paths(): array
    {
        return [
            'repository' => ['repository'],
            'customer manager' => ['customer_manager'],
            'order manager' => ['order_manager'],
            'customer trait' => ['customer_trait'],
        ];
    }

    #[DataProvider('paths')]
    public function test_a_debit_above_the_balance_is_refused_and_leaves_no_trace(string $path): void
    {
        WalletTables::seedCustomer(self::CUSTOMER_ID, 40.0);

        $result = WalletWriters::write($path, (string) self::CUSTOMER_ID, 'order_place', 'order-over', 60.0);

        $this->assertFalse($result);
        $this->assertSame(40.0, WalletTables::walletBalance(self::CUSTOMER_ID));
        $this->assertSame(0, DB::table('wallet_transactions')->count());
    }

    #[DataProvider('paths')]
    public function test_a_debit_equal_to_the_balance_is_allowed_and_leaves_zero(string $path): void
    {
        WalletTables::seedCustomer(self::CUSTOMER_ID, 40.0);

        $result = WalletWriters::write($path, (string) self::CUSTOMER_ID, 'order_place', 'order-exact', 40.0);

        $this->assertNotFalse($result);
        $this->assertSame(0.0, WalletTables::walletBalance(self::CUSTOMER_ID));
        $this->assertSame(1, DB::table('wallet_transactions')->count());
    }

    #[DataProvider('paths')]
    public function test_repeated_debits_stop_before_the_balance_goes_negative(string $path): void
    {
        WalletTables::seedCustomer(self::CUSTOMER_ID, 100.0);
        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = WalletWriters::write($path, (string) self::CUSTOMER_ID, 'order_place', "order-{$i}", 30.0);
        }

        $this->assertSame([true, true, true, false, false], array_map(fn (mixed $result): bool => $result !== false, $results));
        $this->assertSame(10.0, WalletTables::walletBalance(self::CUSTOMER_ID));
        $this->assertSame(3, DB::table('wallet_transactions')->count());
    }

    #[DataProvider('paths')]
    public function test_a_top_up_lets_the_same_refused_debit_succeed_on_retry(string $path): void
    {
        WalletTables::seedCustomer(self::CUSTOMER_ID, 40.0);
        $this->assertFalse(WalletWriters::write($path, (string) self::CUSTOMER_ID, 'order_place', 'order-retry', 60.0));

        WalletWriters::write($path, (string) self::CUSTOMER_ID, 'add_fund_by_admin', 'top-up', 30.0);
        $this->assertNotFalse(WalletWriters::write($path, (string) self::CUSTOMER_ID, 'order_place', 'order-retry', 60.0));

        $this->assertSame(10.0, WalletTables::walletBalance(self::CUSTOMER_ID));
    }

    public function test_a_credit_is_never_refused_even_from_an_empty_wallet(): void
    {
        WalletTables::seedCustomer(self::CUSTOMER_ID, 0.0);

        $result = WalletWriters::write('repository', (string) self::CUSTOMER_ID, 'add_fund_by_admin', 'top-up', 25.0);

        $this->assertNotFalse($result);
        $this->assertSame(25.0, WalletTables::walletBalance(self::CUSTOMER_ID));
    }

    public function test_a_due_payment_above_the_balance_is_refused_by_customer_manager(): void
    {
        WalletTables::seedCustomer(self::CUSTOMER_ID, 40.0);

        $result = CustomerManager::create_wallet_transaction(
            (string) self::CUSTOMER_ID,
            60.0,
            'due_payment_for_order',
            'reduce_wallet_amount',
            ['payment_method' => 'wallet'],
        );

        $this->assertFalse($result);
        $this->assertSame(40.0, WalletTables::walletBalance(self::CUSTOMER_ID));
        $this->assertSame(0, DB::table('wallet_transactions')->count());
    }
}
