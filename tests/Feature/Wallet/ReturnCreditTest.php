<?php

namespace Tests\Feature\Wallet;

use App\Events\OrderEditReturnPaymentEvent;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Address\PanelAddressTestCase;
use Tests\Support\Wallet\WalletTables;

/**
 * An administrator returning the amount an order edit took off it to the customer's wallet. The order's return amount is cleared in
 * the same transaction as the credit, so a credit the wallet writer refuses leaves the return amount owed and no ledger row.
 */
class ReturnCreditTest extends PanelAddressTestCase
{
    private const ORDER_ID = 100;

    private const RETURN_AMOUNT = 50.5;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([OrderEditReturnPaymentEvent::class]);
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('wallet_balance', 18, 12)->default(0);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('edit_return_amount', 16, 2)->default(0);
            $table->decimal('edit_due_amount', 16, 2)->default(0);
        });
        Schema::create('order_edit_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->decimal('order_due_amount', 16, 2)->default(0);
            $table->decimal('order_return_amount', 16, 2)->default(0);
            $table->string('order_due_payment_status')->nullable();
            $table->string('order_return_payment_status')->nullable();
            $table->string('order_return_payment_method')->nullable();
            $table->string('order_return_transaction_ref')->nullable();
            $table->string('order_return_payment_note')->nullable();
            $table->string('edit_by')->nullable();
            $table->unsignedBigInteger('edited_user_id')->nullable();
            $table->string('edited_user_name')->nullable();
            $table->timestamps();
        });
        Schema::create('admin_wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->decimal('pending_amount', 16, 2)->default(0);
            $table->timestamps();
        });
        WalletTables::createLedger();
        WalletTables::setSetting('wallet_status', '1');
        Cache::flush();
        DB::table('admin_wallets')->insert(['admin_id' => 1, 'pending_amount' => 200]);

        $this->actingAsAdmin();
    }

    private function orderOwingAReturnTo(int $customerId): void
    {
        DB::table('orders')->insert([
            'id' => self::ORDER_ID, 'customer_id' => $customerId, 'is_guest' => 0, 'order_amount' => 300, 'seller_id' => 1, 'seller_is' => 'admin',
            'edit_return_amount' => self::RETURN_AMOUNT, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_edit_histories')->insert([
            'order_id' => self::ORDER_ID, 'order_return_amount' => self::RETURN_AMOUNT, 'order_return_payment_status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The amount is sent as the order page shows it, the stored value unchanged: the controller compares the two strictly. */
    private function returnToWallet()
    {
        return $this->postJson(route('admin.orders.customer-return-amount'), [
            'order_id' => self::ORDER_ID, 'amount' => DB::table('orders')->where('id', self::ORDER_ID)->value('edit_return_amount'), 'order_return_payment_method' => 'wallet', 'order_return_payment_note' => 'Edited order',
        ]);
    }

    public function test_a_credit_the_wallet_writer_refuses_leaves_the_return_amount_owed_and_writes_nothing(): void
    {
        $this->orderOwingAReturnTo(customerId: 999);

        $this->returnToWallet()->assertRedirect();

        $order = DB::table('orders')->where('id', self::ORDER_ID)->first();
        $this->assertEquals([self::RETURN_AMOUNT, 300], [$order->edit_return_amount, $order->order_amount], 'the return is still owed');
        $this->assertSame(0, DB::table('wallet_transactions')->count(), 'no ledger row');
        $this->assertEquals(200, DB::table('admin_wallets')->where('admin_id', 1)->value('pending_amount'), 'the admin wallet is as it was');
        $this->assertSame('pending', DB::table('order_edit_histories')->value('order_return_payment_status'));
        $this->assertSame(['error'], $this->toastTypes());
        Event::assertNotDispatched(OrderEditReturnPaymentEvent::class);
    }

    public function test_a_credit_the_wallet_accepts_clears_the_return_amount_with_one_ledger_row(): void
    {
        $customerId = (int) DB::table('users')->insertGetId(['f_name' => 'Sara', 'wallet_balance' => 10, 'created_at' => now(), 'updated_at' => now()]);
        $this->orderOwingAReturnTo(customerId: $customerId);

        $this->returnToWallet()->assertRedirect();

        $order = DB::table('orders')->where('id', self::ORDER_ID)->first();
        $this->assertEquals([0, 300 - self::RETURN_AMOUNT], [$order->edit_return_amount, $order->order_amount]);
        $this->assertEquals(10 + self::RETURN_AMOUNT, DB::table('users')->where('id', $customerId)->value('wallet_balance'));
        $this->assertSame(1, DB::table('wallet_transactions')->where('transaction_type', 'return_order_amount_by_admin')->count());
        $this->assertSame(['success'], $this->toastTypes());
    }
}
