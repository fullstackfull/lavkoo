<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The room the plan asks the structure to leave for shared balances, and nothing more (GCC-15, E-02): one nullable column on each market
 * account, which no code anywhere reads or writes.
 *
 * WHAT IT IS FOR. The plan's question — whether a customer's points, and later their money, may be shared across some markets rather than
 * held apart in each — is open, and the answer belongs to the owner and to a finance and legal reading per country, not to this migration.
 * What the plan does ask for now is that the structure not have to be rebuilt once that answer comes. This column is that room: accounts
 * that would one day share would carry the same value in it, and until a reader is written every account carries null and every market's
 * balance is its own.
 *
 * WHY NOTHING READS IT, AND WHY THAT IS THE POINT. Switching sharing on is deliberately NOT a setting. A column alone cannot share
 * anything: `CustomerMarketBalances` reads and writes one account, found by (customer, market), and would have to be changed to read a
 * group. So turning sharing on takes a code change that a person writes and another reviews, which is what E-02's fourth criterion asks
 * for, and `MarketAccountSharingKeyTest` fails the moment any file outside this migration names the column — including a file that only
 * sets it, because a value written is a value something later believes.
 *
 * WHY A STRING AND NOT A FOREIGN KEY. A foreign key would need a table of groups, and the shape of a group is exactly what is undecided:
 * it may turn out to be the legal entity, a currency union, or a list the owner writes by hand. A nullable free string decides none of
 * that and is cheap to replace once the shape is known, which an empty column always is.
 *
 * EXPAND ONLY, AND NO INDEX. The column is nullable with no default, so adding it reads and writes no existing row and changes no answer
 * any customer is served; both tables are empty while the feature is dormant. It carries no index, because an index pays for a read and
 * there is no reader: the index goes on in the same change as the first query that groups by it, when the plans of that query on real data
 * can be read. The rollback drops the column while nothing carries one, and refuses once a row does, so a key a later slice has begun to
 * fill is never silently forgotten.
 */
return new class extends Migration {
    /** The accounts of E-01 and E-02, created by 2026_12_05_120000_create_customer_market_balance_accounts. */
    private const ACCOUNTS = ['customer_market_wallet_accounts', 'customer_market_loyalty_accounts'];

    private const SHARING_KEY = 'sharing_group';

    public function up(): void
    {
        foreach (self::ACCOUNTS as $accounts) {
            if (!Schema::hasTable($accounts) || Schema::hasColumn($accounts, self::SHARING_KEY)) {
                continue;
            }

            Schema::table($accounts, function (Blueprint $table): void {
                $table->string(self::SHARING_KEY, 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        $this->refuseWhileAKeyIsRecorded();

        foreach (self::ACCOUNTS as $accounts) {
            if (Schema::hasTable($accounts) && Schema::hasColumn($accounts, self::SHARING_KEY)) {
                Schema::table($accounts, fn (Blueprint $table) => $table->dropColumn(self::SHARING_KEY));
            }
        }
    }

    private function refuseWhileAKeyIsRecorded(): void
    {
        foreach (self::ACCOUNTS as $accounts) {
            if (!Schema::hasTable($accounts) || !Schema::hasColumn($accounts, self::SHARING_KEY)) {
                continue;
            }

            if (DB::table($accounts)->whereNotNull(self::SHARING_KEY)->exists()) {
                throw new RuntimeException("Accounts in {$accounts} are grouped by a sharing key. Rolling this migration back would forget which accounts belong together.");
            }
        }
    }
};
