<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tables a delivery man's withdrawal is decided against, on top of the seller withdraw schema: `withdraw_requests` already carries
 * `delivery_man_id`, so what this adds is the delivery man and the wallet the decision moves.
 *
 * Shared by the SQLite feature test of the decision and the MySQL test of two decisions at once, so both are measured against one shape.
 */
trait BuildsDeliveryManWithdrawSchema
{
    use BuildsSellerWithdrawSchema;

    private const DELIVERY_MAN_WITHDRAW_TABLES = ['delivery_men', 'deliveryman_wallets'];

    protected function buildDeliveryManWithdrawSchema(): void
    {
        $this->dropDeliveryManWithdrawTables();
        $this->buildSellerWithdrawSchema();

        Schema::create('delivery_men', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id')->default(0);
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('fcm_token')->nullable();
            $table->string('app_language')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('deliveryman_wallets', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('delivery_man_id');
            $table->decimal('current_balance', 16, 2)->default(0);
            $table->decimal('cash_in_hand', 16, 2)->default(0);
            $table->decimal('pending_withdraw', 16, 2)->default(0);
            $table->decimal('total_withdraw', 16, 2)->default(0);
            $table->timestamps();
        });
    }

    protected function dropDeliveryManWithdrawTables(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        // The seller trait's own guard refuses a database whose name does not say it is a test database; it is asked first, so these two
        // tables are never dropped anywhere it would refuse.
        $this->dropSellerWithdrawTables();

        foreach (self::DELIVERY_MAN_WITHDRAW_TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
}
