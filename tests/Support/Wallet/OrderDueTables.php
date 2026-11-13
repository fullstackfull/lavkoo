<?php

namespace Tests\Support\Wallet;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tables an edited order's due payment reads and writes, on top of the wallet tables: the order, its edit history, the admin
 * wallet, and the storages a customer's image is read from when the customer is serialised, with the columns the payment uses. WalletTables refuses to change a non-SQLite database that is not named as a test one, and
 * these tables are only changed after it agreed.
 */
final class OrderDueTables
{
    public const ADMIN_PENDING = 0.0;

    public static function create(): void
    {
        WalletTables::create();

        Schema::create('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('payment_status')->default('unpaid');
            $table->decimal('order_amount', 16, 2)->default(0);
            $table->decimal('edit_due_amount', 16, 2)->default(0);
            $table->decimal('edit_return_amount', 16, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('order_edit_histories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->decimal('order_amount', 16, 2)->default(0);
            $table->decimal('order_due_amount', 16, 2)->default(0);
            $table->decimal('order_return_amount', 16, 2)->default(0);
            $table->string('order_due_payment_status')->nullable();
            $table->string('order_due_payment_method')->nullable();
            $table->string('order_return_payment_status')->nullable();
            $table->timestamps();
        });
        Schema::create('admin_wallets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->decimal('pending_amount', 16, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('storages', function (Blueprint $table): void {
            $table->id();
            $table->string('data_type');
            $table->unsignedBigInteger('data_id');
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });
        DB::table('admin_wallets')->insert(['admin_id' => 1, 'pending_amount' => self::ADMIN_PENDING]);
        WalletTables::setSetting('wallet_status', '1');
    }

    public static function drop(): void
    {
        WalletTables::drop();
        Schema::dropIfExists('storages');
        Schema::dropIfExists('admin_wallets');
        Schema::dropIfExists('order_edit_histories');
        Schema::dropIfExists('orders');
    }

    /** An order of the customer that was edited after it was paid and now has a due left to pay. */
    public static function seedEditedOrder(int $orderId, int $customerId, float $orderAmount, float $dueAmount): void
    {
        DB::table('orders')->insert([
            'id' => $orderId, 'customer_id' => $customerId, 'is_guest' => false, 'payment_status' => 'unpaid',
            'order_amount' => $orderAmount, 'edit_due_amount' => $dueAmount, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_edit_histories')->insert([
            'order_id' => $orderId, 'order_amount' => $orderAmount + $dueAmount, 'order_due_amount' => $dueAmount,
            'order_due_payment_status' => 'unpaid', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{due: float, order_amount: float, payment_status: string, history_status: ?string, admin_pending: float, ledger_rows: int} */
    public static function state(int $orderId): array
    {
        $order = DB::table('orders')->where('id', $orderId)->first();

        return [
            'due' => (float) $order->edit_due_amount,
            'order_amount' => (float) $order->order_amount,
            'payment_status' => (string) $order->payment_status,
            'history_status' => DB::table('order_edit_histories')->where('order_id', $orderId)->value('order_due_payment_status'),
            'admin_pending' => (float) DB::table('admin_wallets')->where('admin_id', 1)->value('pending_amount'),
            'ledger_rows' => DB::table('wallet_transactions')->count(),
        ];
    }
}
