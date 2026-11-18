<?php

namespace Tests\Feature;

use App\Utils\OrderManager;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** DB_STRICT_TESTS=1 really switches the test connection to strict mode, so the strict runs of the suites mean something. */
class StrictModeTest extends TestCase
{
    private function strictRun(): bool
    {
        return getenv('DB_STRICT_TESTS') === '1' && in_array(config('database.default'), ['mysql', 'mariadb'], true);
    }

    public function test_the_strict_run_uses_a_strict_sql_mode(): void
    {
        if (!$this->strictRun()) {
            $this->markTestSkipped('Only in the strict-mode run (DB_STRICT_TESTS=1 on MySQL/MariaDB).');
        }

        $mode = (string) DB::selectOne('select @@session.sql_mode as mode')->mode;

        $this->assertStringContainsString('STRICT_TRANS_TABLES', $mode);
        $this->assertStringContainsString('ONLY_FULL_GROUP_BY', $mode);
    }

    public function test_the_strict_run_rejects_a_value_longer_than_its_column(): void
    {
        if (!$this->strictRun()) {
            $this->markTestSkipped('Only in the strict-mode run (DB_STRICT_TESTS=1 on MySQL/MariaDB).');
        }
        Schema::dropIfExists('strict_probe');
        Schema::create('strict_probe', function (Blueprint $table): void {
            $table->id();
            $table->string('short', 5);
        });

        try {
            $this->expectException(QueryException::class);
            DB::table('strict_probe')->insert(['short' => 'far too long for the column']);
        } finally {
            Schema::dropIfExists('strict_probe');
        }
    }

    /**
     * The coupon use count of a customer groups the orders by checkout. A bare `select *` with a GROUP BY is refused by a server that enforces
     * ONLY_FULL_GROUP_BY (the default of MySQL 8), so the count has to read only the column it groups by. It runs in the strict run and in the plain one.
     */
    public function test_the_coupon_use_count_is_a_valid_statement_in_strict_mode_and_counts_one_per_checkout(): void
    {
        Schema::dropIfExists('orders');
        Schema::create('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('coupon_code')->nullable();
            $table->string('order_group_id')->nullable();
        });
        DB::table('orders')->insert([
            ['id' => 1, 'customer_id' => 7, 'coupon_code' => 'SAVE', 'order_group_id' => 'G1'],
            ['id' => 2, 'customer_id' => 7, 'coupon_code' => 'SAVE', 'order_group_id' => 'G1'],
            ['id' => 3, 'customer_id' => 7, 'coupon_code' => 'SAVE', 'order_group_id' => 'G2'],
            ['id' => 4, 'customer_id' => 7, 'coupon_code' => 'SAVE', 'order_group_id' => null],
            ['id' => 5, 'customer_id' => 7, 'coupon_code' => 'OTHER', 'order_group_id' => 'G3'],
            ['id' => 6, 'customer_id' => 8, 'coupon_code' => 'SAVE', 'order_group_id' => 'G4'],
        ]);

        try {
            $this->assertSame(3, OrderManager::countCouponUses(customerId: 7, couponCode: 'SAVE'));
            $this->assertSame(1, OrderManager::countCouponUses(customerId: 7, couponCode: 'OTHER'));
            $this->assertSame(0, OrderManager::countCouponUses(customerId: 9, couponCode: 'SAVE'));
        } finally {
            Schema::dropIfExists('orders');
        }
    }
}
