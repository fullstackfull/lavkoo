<?php

namespace Tests\Support;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the vendor panel's order edit (the real writers) and its order page read and write beyond what the panel's core tables hold, on the INFERRED minimal
 * schema of the panel tests: the installation dump that owns the store tables is not in the repository. A table or a column the test has already built is left
 * alone, so a test with a world of its own (delivery men, users) can use it too.
 */
trait BuildsPanelOrderEditTables
{
    protected function createTheTablesTheOrderEditUses(): void
    {
        $this->addColumnsThatAreMissing(table: 'orders', columns: [
            'discount_type' => 'string', 'coupon_code' => 'string', 'coupon_discount_bearer' => 'string', 'tax_type' => 'string', 'tax_model' => 'string',
            'extra_discount_type' => 'string', 'free_delivery_bearer' => 'string', 'order_group_id' => 'string',
            'admin_commission' => 'amount', 'edit_due_amount' => 'amount', 'edit_return_amount' => 'amount', 'total_tax_amount' => 'amount', 'extra_discount' => 'amount',
            'discount_amount' => 'amount', 'init_order_amount' => 'amount', 'refer_and_earn_discount' => 'amount', 'deliveryman_charge' => 'amount',
            'shipping_method_id' => 'id', 'delivery_man_id' => 'id', 'deliveryman_assigned_at' => 'dateTime', 'is_shipping_free' => 'flag', 'edited_status' => 'flag',
        ]);
        $this->addColumnsThatAreMissing(table: 'order_details', columns: [
            'discount_type' => 'string', 'digital_file_after_sell' => 'string', 'shipping_method_id' => 'id', 'refund_started_at' => 'dateTime', 'is_stock_decreased' => 'flag',
            'refund_request' => 'amount',
        ]);

        $this->createTableUnlessItExists('users', static function (Blueprint $table): void {
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('cm_firebase_token')->nullable();
            $table->integer('loyalty_point')->default(0);
        });
        $this->createTableUnlessItExists('colors', static function (Blueprint $table): void {
            $table->string('name')->nullable();
            $table->string('code')->nullable();
        });
        foreach (['order_delivery_verifications' => 'image', 'offline_payments' => null, 'order_status_histories' => null] as $tableName => $extraColumn) {
            $this->createTableUnlessItExists($tableName, static function (Blueprint $table) use ($extraColumn): void {
                $table->unsignedBigInteger('order_id');
                if ($extraColumn !== null) {
                    $table->string($extraColumn)->nullable();
                }
            });
        }
        $this->createTableUnlessItExists('order_edit_histories', static function (Blueprint $table): void {
            $table->unsignedBigInteger('order_id');
            $table->string('edit_by')->nullable();
            $table->unsignedBigInteger('edited_user_id')->nullable();
            $table->string('edited_user_name')->nullable();
            $table->decimal('order_amount', 16, 2)->default(0);
            $table->decimal('order_due_amount', 16, 2)->default(0);
            $table->string('order_due_payment_method')->nullable();
            $table->string('order_due_payment_status')->nullable();
            $table->decimal('order_return_amount', 16, 2)->default(0);
            $table->string('order_return_payment_status')->nullable();
            $table->unsignedBigInteger('u_id')->nullable();
        });
        $this->createTableUnlessItExists('order_transactions', static function (Blueprint $table): void {
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is')->nullable();
            foreach (['order_amount', 'seller_amount', 'admin_commission', 'delivery_charge', 'tax'] as $column) {
                $table->decimal($column, 16, 2)->default(0);
            }
        });
        $this->createTableUnlessItExists('order_details_rewards', static function (Blueprint $table): void {
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('order_details_id')->nullable();
            $table->boolean('reward_delivered')->default(false);
            $table->string('reward_type')->nullable();
            $table->decimal('reward_amount', 16, 2)->default(0);
        });
        $this->createTableUnlessItExists('order_taxes', static function (Blueprint $table): void {
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('tax_for')->nullable();
        });
        $this->createTableUnlessItExists('shipping_types', static function (Blueprint $table): void {
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('shipping_type')->nullable();
        });
        $this->createTableUnlessItExists('shipping_methods', static function (Blueprint $table): void {
            $table->string('title')->nullable();
        });
        $this->createTableUnlessItExists('delivery_country_codes', static function (Blueprint $table): void {
            $table->string('country_code')->nullable();
        });
        $this->createTableUnlessItExists('coupons', static function (Blueprint $table): void {
            $table->string('code')->nullable();
            $table->string('coupon_type')->nullable();
            $table->string('coupon_bearer')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->date('start_date')->nullable();
            $table->date('expire_date')->nullable();
            $table->integer('status')->default(1);
        });
    }

    /** @param array<string, string> $columns the type of each column to add: string, amount, id, dateTime or flag */
    private function addColumnsThatAreMissing(string $table, array $columns): void
    {
        foreach ($columns as $column => $type) {
            if (Schema::hasColumn($table, $column)) {
                continue;
            }

            Schema::table($table, static function (Blueprint $blueprint) use ($column, $type): void {
                match ($type) {
                    'string' => $blueprint->string($column)->nullable(),
                    'amount' => $blueprint->decimal($column, 16, 2)->default(0),
                    'id' => $blueprint->unsignedBigInteger($column)->nullable(),
                    'dateTime' => $blueprint->dateTime($column)->nullable(),
                    'flag' => $blueprint->boolean($column)->default(false),
                };
            });
        }
    }

    private function createTableUnlessItExists(string $name, Closure $columns): void
    {
        if (Schema::hasTable($name)) {
            return;
        }

        Schema::create($name, static function (Blueprint $table) use ($columns): void {
            $table->id();
            $columns($table);
            $table->timestamps();
        });
    }
}
