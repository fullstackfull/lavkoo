<?php

namespace Tests\Feature\Wallet;

use App\Repositories\WalletTransactionRepository;
use App\Services\Customer\CustomerWalletWriter;
use App\Traits\CustomerTrait;
use App\Utils\CustomerManager;
use App\Utils\OrderManager;
use Illuminate\Support\Facades\DB;
use Tests\Support\Wallet\WalletTables;
use Tests\TestCase;

/**
 * E-16: the ledger row's `balance` is the balance the account holds after that row -- the add-fund bonus included -- whichever writer
 * wrote it.
 *
 * There are five writers of `wallet_transactions` on this platform and four of them set the account to
 * `previous + bonus + credit - debit` while writing `previous + credit - debit` on the row. An `add_fund` that earned a bonus therefore
 * left a row short of the account by exactly the bonus it was printing in the next column -- on the admin wallet report, which prints
 * both on one line, and in the add-fund email, which prints the row's balance as the customer's new balance. A statement whose running
 * balance is not the balance is not a statement.
 *
 * `App\Services\Customer\CustomerWalletWriter` was already right and is in this test as the control. The other four now compute the
 * figure once and use it twice, so they cannot disagree with themselves again.
 *
 * Rows written before this are NOT rewritten and keep the understated figure. They stay reconcilable, because `admin_bonus` is on the
 * row in both eras: `credit + admin_bonus - debit` summed over a customer's rows reaches their balance across the boundary. What cannot
 * be told from the row alone is an old `add_fund` row from a new one.
 */
class EveryWalletWriterRowSaysWhatTheAccountHoldsTest extends TestCase
{
    private const CUSTOMER_ID = 1;

    private const OPENING_BALANCE = 350.0;

    /** 10 per cent of a 100 top-up, capped well above it. */
    private const BONUS = 10.0;

    protected function setUp(): void
    {
        parent::setUp();

        WalletTables::reset();
        WalletTables::setSetting('wallet_status', '1');
        WalletTables::setSetting('currency_model', 'single_currency');
        WalletTables::setSetting('decimal_point_settings', '2');
        WalletTables::seedCustomer(self::CUSTOMER_ID, self::OPENING_BALANCE);
        WalletTables::addFundBonus(bonusType: 'percentage', amount: 10.0, minimumAmount: 50.0, maximumBonus: 50.0);
        session(['currency_exchange_rate' => 1]);
    }

    protected function tearDown(): void
    {
        WalletTables::drop();

        parent::tearDown();
    }

    /** @return array<string, callable(): mixed> every writer of the ledger, by the name it is known as */
    public static function theWriters(): array
    {
        return [
            'CustomerManager::create_wallet_transaction (the gateway top-up)' => [
                static fn () => CustomerManager::create_wallet_transaction(self::CUSTOMER_ID, 100.0, 'add_fund', 'add_funds_to_wallet'),
            ],
            'OrderManager::createWalletTransaction' => [
                static fn () => OrderManager::createWalletTransaction(self::CUSTOMER_ID, 100.0, 'add_fund', 'add_funds_to_wallet'),
            ],
            'WalletTransactionRepository::addWalletTransaction (the admin panel)' => [
                static fn () => app(WalletTransactionRepository::class)->addWalletTransaction(
                    user_id: (string) self::CUSTOMER_ID, amount: 100.0, transactionType: 'add_fund', reference: 'add_funds_to_wallet',
                ),
            ],
            'CustomerWalletWriter::record (the control: already right)' => [
                static fn () => CustomerWalletWriter::record(
                    customerId: self::CUSTOMER_ID, amount: 100.0, transactionType: 'add_fund', reference: 'add_funds_to_wallet',
                ),
            ],
        ];
    }

    /** @param callable(): mixed $write */
    #[\PHPUnit\Framework\Attributes\DataProvider('theWriters')]
    public function test_a_top_up_that_earned_a_bonus_leaves_a_row_that_says_what_the_account_holds(callable $write): void
    {
        $write();

        $row = WalletTables::ledgerRow();
        $this->assertSame(1, DB::table('wallet_transactions')->count(), 'one row');
        $this->assertSame(100.0, (float) $row->credit, 'the credit is the amount paid in');
        $this->assertSame(self::BONUS, (float) $row->admin_bonus, 'the bonus is on the row');
        $this->assertSame(460.0, WalletTables::walletBalance(self::CUSTOMER_ID), 'the account holds the opening balance, the credit and the bonus');
        $this->assertSame(460.0, (float) $row->balance, 'and the row says so');
        $this->assertSame(
            450.0,
            (float) $row->balance - (float) $row->admin_bonus,
            'the figure every writer but one used to put on the row, kept here so the difference is visible rather than described',
        );
    }

    /**
     * The fifth writer is a method of App\Traits\CustomerTrait, whose own constructor takes four repositories, so it is reached
     * through a class that uses the trait and declares a constructor of its own -- the only way to call that writer without standing
     * up four repositories that have nothing to do with the figure being asserted.
     */
    public function test_the_writer_on_the_customer_trait_leaves_the_same_row(): void
    {
        $caller = new class {
            use CustomerTrait {
                createWalletTransaction as public;
            }

            public function __construct()
            {
            }
        };

        $caller->createWalletTransaction(user_id: self::CUSTOMER_ID, amount: 100.0, transaction_type: 'add_fund', reference: 'add_funds_to_wallet');

        $row = WalletTables::ledgerRow();
        $this->assertSame(self::BONUS, (float) $row->admin_bonus);
        $this->assertSame(460.0, WalletTables::walletBalance(self::CUSTOMER_ID));
        $this->assertSame(460.0, (float) $row->balance);
    }

    /** A credit that earns no bonus is untouched by this: the row and the account were already the same figure. */
    public function test_a_credit_with_no_bonus_is_unchanged(): void
    {
        CustomerManager::create_wallet_transaction(self::CUSTOMER_ID, 10.0, 'add_fund', 'add_funds_to_wallet');

        $row = WalletTables::ledgerRow();
        $this->assertSame(0.0, (float) $row->admin_bonus, 'below the band, so no bonus');
        $this->assertSame(360.0, WalletTables::walletBalance(self::CUSTOMER_ID));
        $this->assertSame(360.0, (float) $row->balance);
    }

    /** A debit has no bonus either, and the row still says what the account holds. */
    public function test_a_debit_still_says_what_the_account_holds(): void
    {
        CustomerManager::create_wallet_transaction(self::CUSTOMER_ID, 50.0, 'order_place', 'order payment');

        $row = WalletTables::ledgerRow();
        $this->assertSame(50.0, (float) $row->debit);
        $this->assertSame(300.0, WalletTables::walletBalance(self::CUSTOMER_ID));
        $this->assertSame(300.0, (float) $row->balance);
    }
}
