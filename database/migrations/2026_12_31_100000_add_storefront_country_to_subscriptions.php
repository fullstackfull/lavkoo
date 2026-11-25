<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The market a newsletter signup was made in (I1-04).
 *
 * `subscriptions` held an e-mail address and nothing else, so six markets shared one list: a Kuwaiti visitor's address joined the same list
 * a Saudi visitor's did, and the only thing an operator could do with the list was send to all of it. The offer in such a message is in one
 * market's currency, for one market's catalogue, and half the people it reaches cannot buy from it.
 *
 * **NULL means a signup made before the markets existed**, and is read as the default storefront's. That is the convention the configuration
 * tables use (`StorefrontMarketRows`) and the opposite of the one the broadcast column uses, and the difference is the difference between a
 * SETTING and a MESSAGE: an unmarked broadcast was SENT to everybody and still is, while an unmarked subscriber signed up on the only
 * storefront there has ever been, which is the default one. So every row an installation already has belongs to Saudi Arabia rather than to
 * all six markets, and a send to Saudi Arabia reaches exactly the people it reaches today.
 *
 * Nullable rather than defaulted, so the difference between "signed up before the markets" and "signed up in Saudi Arabia" is not thrown
 * away at the moment the column is added: an operator looking at the list a year from now can still tell them apart.
 *
 * On MySQL and MariaDB the column is compared byte for byte (ascii_bin), as PHP compares it, so 'kw' is not 'KW' in either place -- the
 * repair `2026_11_30_100000` had to make after a `char(2)` built by hand took the server's case-insensitive collation.
 */
return new class extends Migration {
    private const TABLE = 'subscriptions';

    private const COLUMN = 'storefront_country';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE) || Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, static function (Blueprint $table): void {
            $column = $table->char(self::COLUMN, 2)->nullable();

            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $column->charset('ascii')->collation('ascii_bin');
            }
        });

        // The two reads this column exists for are both "the subscribers of one market": the admin list and its export. Both are
        // ordered by `created_at`, so the index carries it, and the table is small enough that the write cost is nothing.
        Schema::table(self::TABLE, static function (Blueprint $table): void {
            $table->index([self::COLUMN, 'created_at'], 'subscriptions_market_created_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE) || !Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, static function (Blueprint $table): void {
            $table->dropIndex('subscriptions_market_created_idx');
            $table->dropColumn(self::COLUMN);
        });
    }
};
