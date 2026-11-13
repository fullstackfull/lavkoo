<?php

namespace Tests\Feature\Wallet;

use App\Models\WalletTransaction;
use App\Repositories\WalletTransactionRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The customer wallet ledger as WalletTransactionRepository::addWalletTransaction() runs it today.
 *
 * It is one of three places that write a wallet balance. App\Utils\CustomerManager::create_wallet_transaction() and
 * App\Utils\OrderManager::createWalletTransaction() keep their own copies of the same rules. These tests do not cover them.
 *
 * The tables are built from the migrations that define them. No migration in the repository defines business_settings (the
 * installation dump does), so its columns come from BusinessSetting's fillable list and from how the code reads it.
 *
 * Tests whose names start with test_current_behaviour pin what the code does now. The owner has not endorsed that behaviour.
 */
class WalletLedgerCharacterizationTest extends TestCase
{
    private const CUSTOMER_ID = 1;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->decimal('wallet_balance', 18, 12)->default(0);
            $table->decimal('loyalty_point', 18, 4)->nullable()->default(0);
            $table->timestamps();
        });
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->uuid('transaction_id');
            $table->decimal('credit', 24, 3)->default(0);
            $table->decimal('debit', 24, 3)->default(0);
            $table->decimal('admin_bonus', 24, 3)->default(0);
            $table->decimal('balance', 24, 3)->default(0);
            $table->string('transaction_type', 191)->nullable();
            $table->string('reference', 191)->nullable();
            $table->string('payment_method')->nullable();
            $table->timestamps();
        });
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('add_fund_bonus_categories', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('bonus_type', 50);
            $table->float('bonus_amount', 14, 2)->default(0);
            $table->float('min_add_money_amount', 14, 2)->default(0);
            $table->float('max_bonus_amount', 14, 2)->default(0);
            $table->dateTime('start_date_time')->nullable();
            $table->dateTime('end_date_time')->nullable();
            $table->boolean('is_active')->default(0);
            $table->timestamps();
        });

        $this->setSetting('wallet_status', '1');
        $this->setSetting('loyalty_point_exchange_rate', '100');
    }

    public function test_adding_money_by_admin_credits_the_wallet_and_writes_the_credit(): void
    {
        $this->customerWithBalance(50.0);

        $entry = $this->repository()->addWalletTransaction(
            user_id: (string) self::CUSTOMER_ID,
            amount: 100.0,
            transactionType: 'add_fund_by_admin',
            reference: 'admin-top-up',
        );

        $this->assertInstanceOf(WalletTransaction::class, $entry);
        $this->assertSame(150.0, $this->walletOf(self::CUSTOMER_ID));
        $this->assertSame(100.0, (float) $entry->credit);
        $this->assertSame(0.0, (float) $entry->debit);
        $this->assertSame(150.0, (float) $entry->balance);
    }

    public function test_an_order_payment_debits_the_wallet_and_writes_the_debit(): void
    {
        $this->customerWithBalance(150.0);

        $entry = $this->repository()->addWalletTransaction(
            user_id: (string) self::CUSTOMER_ID,
            amount: 40.0,
            transactionType: 'order_place',
            reference: 'order-1001',
        );

        $this->assertInstanceOf(WalletTransaction::class, $entry);
        $this->assertSame(110.0, $this->walletOf(self::CUSTOMER_ID));
        $this->assertSame(0.0, (float) $entry->credit);
        $this->assertSame(40.0, (float) $entry->debit);
        $this->assertSame(110.0, (float) $entry->balance);
    }

    public function test_redeemed_loyalty_points_are_credited_at_the_exchange_rate(): void
    {
        $this->customerWithBalance(0.0);

        $entry = $this->repository()->addWalletTransaction(
            user_id: (string) self::CUSTOMER_ID,
            amount: 500.0,
            transactionType: 'loyalty_point',
            reference: 'points-redeemed',
        );

        // 500 points at 100 points per currency unit is 5 units.
        $this->assertInstanceOf(WalletTransaction::class, $entry);
        $this->assertSame(5.0, $this->walletOf(self::CUSTOMER_ID));
        $this->assertSame(5.0, (float) $entry->credit);
    }

    public function test_nothing_is_written_while_the_wallet_is_switched_off(): void
    {
        $this->customerWithBalance(30.0);
        $this->setSetting('wallet_status', '0');

        $result = $this->repository()->addWalletTransaction(
            user_id: (string) self::CUSTOMER_ID,
            amount: 10.0,
            transactionType: 'add_fund_by_admin',
            reference: 'admin-top-up',
        );

        $this->assertFalse($result);
        $this->assertSame(30.0, $this->walletOf(self::CUSTOMER_ID));
        $this->assertSame(0, DB::table('wallet_transactions')->count());
    }

    public function test_nothing_is_written_for_a_customer_who_does_not_exist(): void
    {
        $result = $this->repository()->addWalletTransaction(
            user_id: '999',
            amount: 10.0,
            transactionType: 'add_fund_by_admin',
            reference: 'admin-top-up',
        );

        $this->assertFalse($result);
        $this->assertSame(0, DB::table('wallet_transactions')->count());
    }

    /**
     * Not endorsed. The REST order path refuses a wallet payment above the balance before it reaches this method. The repository
     * itself does not check, so a caller that skips the check takes the wallet below zero.
     */
    public function test_current_behaviour_a_payment_above_the_balance_is_accepted_and_the_wallet_goes_negative(): void
    {
        $this->customerWithBalance(30.0);

        $entry = $this->repository()->addWalletTransaction(
            user_id: (string) self::CUSTOMER_ID,
            amount: 40.0,
            transactionType: 'order_place',
            reference: 'order-1002',
        );

        $this->assertInstanceOf(WalletTransaction::class, $entry);
        $this->assertSame(-10.0, $this->walletOf(self::CUSTOMER_ID));
        $this->assertSame(-10.0, (float) $entry->balance);
    }

    /**
     * Not endorsed. The customer's wallet includes the add-fund bonus, but the ledger's running balance column does not.
     */
    public function test_current_behaviour_the_ledger_balance_leaves_out_an_add_fund_bonus_the_wallet_includes(): void
    {
        $this->customerWithBalance(0.0);
        DB::table('add_fund_bonus_categories')->insert([
            'title' => 'Ten percent on top-ups',
            'bonus_type' => 'percentage',
            'bonus_amount' => 10,
            'min_add_money_amount' => 0,
            'max_bonus_amount' => 1000,
            'start_date_time' => now()->subDay(),
            'end_date_time' => now()->addDay(),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->repository()->addWalletTransaction(
            user_id: (string) self::CUSTOMER_ID,
            amount: 200.0,
            transactionType: 'add_fund',
            reference: 'online-top-up',
        );

        $ledger = WalletTransaction::query()->latest('id')->first();
        $this->assertTrue($result);
        $this->assertSame(220.0, $this->walletOf(self::CUSTOMER_ID));
        $this->assertSame(20.0, (float) $ledger->admin_bonus);
        $this->assertSame(200.0, (float) $ledger->balance);
    }

    private function repository(): WalletTransactionRepository
    {
        return app(WalletTransactionRepository::class);
    }

    private function customerWithBalance(float $balance): void
    {
        DB::table('users')->insert([
            'id' => self::CUSTOMER_ID,
            'wallet_balance' => $balance,
            'loyalty_point' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function setSetting(string $type, string $value): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['type' => $type],
            ['value' => $value, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    private function walletOf(int $customerId): float
    {
        return (float) DB::table('users')->where('id', $customerId)->value('wallet_balance');
    }
}
