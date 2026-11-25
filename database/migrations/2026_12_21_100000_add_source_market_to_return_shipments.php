<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which market's shelf a returned unit goes back to, and the money that return owes back (H1-13): the market the order was sold in, the currency the
 * customer's payment was taken in, and the merchant account that took it, copied onto the return when it is authorised so that a return read later names
 * the shelf and the money of the sale whatever the settings and the rates are by then.
 *
 * Additive: every column is nullable and a return that exists today keeps all three empty, which is read as a return of the only market there is, in the
 * platform's own currency settings. Nothing writes them while `gcc.returns.cross_border.enabled` is off, which is the default.
 *
 * `return_shipments` comes from the installation dump rather than from a migration, so a database that has not imported the dump has no such table and
 * this does nothing at all. The columns are appended, without a position, so MySQL 8 and MariaDB 11.4 add them without rebuilding the table, and carry no
 * index: add one only after reading the plans of a report on real data. On MySQL and MariaDB the country is compared byte for byte (ascii_bin), as PHP
 * compares it, like `orders.storefront_country` and `products.storefront_country`.
 */
return new class extends Migration {
    private const TABLE = 'return_shipments';

    private const SOURCE = 'source_country';

    private const CURRENCY = 'refund_currency';

    private const ACCOUNT = 'refund_account_id';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        if (!Schema::hasColumn(self::TABLE, self::SOURCE)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $column = $table->char(self::SOURCE, 2)->nullable();

                if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                    $column->charset('ascii')->collation('ascii_bin');
                }
            });
        }

        if (!Schema::hasColumn(self::TABLE, self::CURRENCY)) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->string(self::CURRENCY, 8)->nullable());
        }

        if (!Schema::hasColumn(self::TABLE, self::ACCOUNT)) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->unsignedBigInteger(self::ACCOUNT)->nullable());
        }
    }

    /** Dropping the columns would forget which market's goods came back and in what money, which cannot be worked out again, so it is refused while any return carries a record. */
    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        $recorded = Schema::hasColumn(self::TABLE, self::SOURCE) && DB::table(self::TABLE)->whereNotNull(self::SOURCE)->exists();

        if ($recorded) {
            throw new RuntimeException('Returns carry the market and the money of their order. Rolling this migration back would forget it.');
        }

        foreach ([self::SOURCE, self::CURRENCY, self::ACCOUNT] as $column) {
            if (Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
