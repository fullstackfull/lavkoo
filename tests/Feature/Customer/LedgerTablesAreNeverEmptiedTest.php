<?php

namespace Tests\Feature\Customer;

use App\Http\Controllers\Admin\Settings\DatabaseSettingController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/**
 * The admin screen that empties tables cannot empty the two money ledgers, however the table is spelled (E-08.0).
 *
 * It offered every table of the database but a short list, and the list did not hold the ledgers, so a tick in it destroyed the whole
 * wallet or loyalty history. Putting them on the list was not enough: the screen subtracted the protected names from whatever the request
 * asked for, which compares strings, and a table has more than one spelling -- `<schema>.wallet_transactions` emptied the wallet ledger on
 * MariaDB and `wallet_transactions as w` emptied it on SQLite, both of them matching no protected name. The screen now keeps only the names
 * it offers, so a tampered form has nothing to reach.
 *
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

    public function test_a_ledger_spelled_so_that_no_protected_name_matches_it_is_still_refused_beside_a_table_that_may_be_emptied(): void
    {
        $customer = $this->makeCustomerWithMoneyHistory();

        $this->empty(['deletable_rows', $this->qualified('wallet_transactions')]);

        $this->assertSame(2, DB::table('wallet_transactions')->where('user_id', $customer->id)->count());
        $this->assertSame(0, DB::table('deletable_rows')->count());
    }

    public function test_a_table_that_is_not_a_ledger_is_emptied_as_before(): void
    {
        $this->empty(['deletable_rows']);

        $this->assertSame(0, DB::table('deletable_rows')->count());
    }

    /** @return array<string, array{0: string}> the ways a protected table can be named without matching the protected name */
    public static function spellings(): array
    {
        return [
            'as the screen itself spells it' => ['%s'],
            'qualified by the schema it is in' => ['<schema>.%s'],
            'with an alias after it' => ['%s as t'],
            'in capitals' => ['%S'],
            'wrapped in the quotes the engine uses' => ['`%s`'],
            'with a space in front of it' => [' %s'],
        ];
    }

    #[DataProvider('spellings')]
    public function test_neither_ledger_can_be_emptied_however_it_is_spelled(string $spelling): void
    {
        $customer = $this->makeCustomerWithMoneyHistory();

        $this->empty([$this->spell($spelling, 'wallet_transactions'), $this->spell($spelling, 'loyalty_point_transactions')]);

        $this->assertSame(['users' => 1, 'wallet' => 2, 'loyalty' => 2], $this->moneyRowsOf($customer->id));
    }

    /** The customer row carries `wallet_balance` and `loyalty_point`: emptying it destroys every balance the store holds. */
    #[DataProvider('spellings')]
    public function test_the_customer_row_that_carries_the_balance_cannot_be_emptied_however_it_is_spelled(string $spelling): void
    {
        $customer = $this->makeCustomerWithMoneyHistory();

        $this->empty([$this->spell($spelling, 'users')]);

        $this->assertSame(1, DB::table('users')->where('id', $customer->id)->count());
        $this->assertSame(150.25, (float) DB::table('users')->where('id', $customer->id)->value('wallet_balance'));
    }

    public function test_the_screen_acts_on_nothing_it_does_not_offer(): void
    {
        $offered = app(DatabaseSettingController::class)->index(Request::create('/admin/database-settings'))->getData()['tables'];

        $this->assertNotContains('wallet_transactions', $offered);
        $this->assertNotContains('loyalty_point_transactions', $offered);
        $this->assertNotContains('users', $offered);
        $this->assertContains('deletable_rows', $offered, 'A table that may be emptied is still offered.');
        $this->assertSame([], array_values(array_intersect($offered, (new ReflectionClass(DatabaseSettingController::class))->getConstant('TABLES_NEVER_EMPTIED'))), 'What the screen offers and what it accepts are read from one list, so they cannot drift apart.');
    }

    /** @param list<string> $tables */
    private function empty(array $tables): void
    {
        app(DatabaseSettingController::class)->delete(Request::create('/admin/database-settings/delete', 'POST', ['tables' => $tables]));
    }

    private function spell(string $spelling, string $table): string
    {
        return str_contains($spelling, '%S')
            ? str_replace('%S', strtoupper($table), $spelling)
            : str_replace(['<schema>', '%s'], [Schema::getCurrentSchemaName(), $table], $spelling);
    }

    private function qualified(string $table): string
    {
        return Schema::getCurrentSchemaName() . '.' . $table;
    }
}
