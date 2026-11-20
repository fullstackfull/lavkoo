<?php

namespace Tests\Feature\Customer;

use App\Http\Controllers\Admin\Settings\DatabaseSettingController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The admin screen that empties tables cannot empty the two money ledgers (E-08.0).
 *
 * It offered every table of the database but a short list, and the list did not hold the ledgers, so a tick in it destroyed the whole
 * wallet or loyalty history; the names were also read straight off the request, so a name the screen never offered went through anyway.
 * The controller is driven here rather than the route, because what is being proved is which names it acts on.
 */
class LedgerTablesAreNeverEmptiedTest extends CustomerLedgerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('deletable_rows', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
        DB::table('deletable_rows')->insert(['created_at' => now(), 'updated_at' => now()]);
    }

    public function test_ticking_both_ledgers_empties_neither(): void
    {
        $customer = $this->makeCustomerWithMoneyHistory();

        $this->empty(['wallet_transactions', 'loyalty_point_transactions']);

        $this->assertSame(['users' => 1, 'wallet' => 2, 'loyalty' => 2], $this->moneyRowsOf($customer->id));
    }

    public function test_a_ledger_sent_beside_a_table_that_may_be_emptied_still_survives(): void
    {
        $customer = $this->makeCustomerWithMoneyHistory();

        $this->empty(['deletable_rows', 'wallet_transactions']);

        $this->assertSame(2, DB::table('wallet_transactions')->where('user_id', $customer->id)->count());
        $this->assertSame(0, DB::table('deletable_rows')->count(), 'The table that may be emptied still is: the guard refuses the ledger, not the request.');
    }

    public function test_the_customer_row_that_carries_the_balance_is_protected_too(): void
    {
        $customer = $this->makeCustomerWithMoneyHistory();

        $this->empty(['users']);

        $this->assertSame(1, DB::table('users')->where('id', $customer->id)->count());
    }

    public function test_a_table_that_is_not_a_ledger_is_emptied_as_before(): void
    {
        $this->empty(['deletable_rows']);

        $this->assertSame(0, DB::table('deletable_rows')->count());
    }

    /** @param list<string> $tables */
    private function empty(array $tables): void
    {
        app(DatabaseSettingController::class)->delete(Request::create('/admin/database-settings/delete', 'POST', ['tables' => $tables]));
    }
}
