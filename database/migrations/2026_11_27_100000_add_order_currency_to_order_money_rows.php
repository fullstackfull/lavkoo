<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the money rows of an order are counted in (D1-06): the order lines, the order's payment transactions and the two refund tables each
 * record the currency of their own amount, so a sold figure is never read back through today's settings or today's rate.
 *
 * ONE STATED UNIT. `currency_code` is the currency the row's own stored amount IS in, and `currency_rate` is the rate that turns that stored
 * amount into it — one, for every row written so far. It is not a label for what a buyer was shown next to an amount held in some other unit:
 * a row whose amount is in the platform's base currency records nothing at all and is read exactly as it was before. The pair means here what
 * `orders.currency_code` and `orders.currency_rate` mean, is copied from the order's own record rather than worked out again, and is read by
 * the same reader, which honours a rate of one and leaves any other rate alone.
 *
 * Additive: every column is nullable, carries no default, and nothing is backfilled, so a row that exists today keeps both empty and no stored
 * amount is touched. The columns are appended, so on MySQL and MariaDB the statement can name ALGORITHM=INPLACE, LOCK=NONE and the table is
 * neither rebuilt nor locked while orders are being placed. They carry no index: add one only after reading the plans of a report on real data.
 */
return new class extends Migration {
    private const TABLES = ['order_details', 'order_transactions', 'refund_requests', 'refund_transactions'];

    private const CURRENCY = 'currency_code';

    private const RATE = 'currency_rate';

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $missing = array_values(array_filter(
                [self::CURRENCY, self::RATE],
                static fn (string $column): bool => !Schema::hasColumn($table, $column),
            ));

            if ($missing !== []) {
                $this->appendColumns(table: $table, columns: $missing);
            }
        }
    }

    /**
     * Dropping the columns would forget what a sold line or a paid refund was counted in, which cannot be worked out again from the amount, so
     * it is refused while any row carries a currency.
     */
    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, self::CURRENCY) && DB::table($table)->whereNotNull(self::CURRENCY)->exists()) {
                throw new RuntimeException('Rows of ' . $table . ' carry a currency. Rolling this migration back would forget what they were counted in.');
            }
        }

        foreach (self::TABLES as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $present = array_values(array_filter(
                [self::CURRENCY, self::RATE],
                static fn (string $column): bool => Schema::hasColumn($table, $column),
            ));

            if ($present !== []) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($present));
            }
        }
    }

    /**
     * Appends the columns in one statement.
     *
     * On MySQL and MariaDB the statement names ALGORITHM=INPLACE, LOCK=NONE, which MariaDB 10.11 accepts for exactly this change: appending
     * nullable columns to the end of an InnoDB table rebuilds nothing and blocks no reader, and these are the tables a checkout writes to. An
     * engine that will not take those clauses fails the migration instead of quietly taking a table lock on the order history, so an operator
     * finds out before a deployment and not during one.
     *
     * @param list<string> $columns
     */
    private function appendColumns(string $table, array $columns): void
    {
        if (!in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                foreach ($columns as $column) {
                    $column === self::CURRENCY
                        ? $blueprint->string(self::CURRENCY, 8)->nullable()
                        : $blueprint->decimal(self::RATE, 20, 8)->nullable();
                }
            });

            return;
        }

        $additions = array_map(
            static fn (string $column): string => 'ADD COLUMN `' . $column . '` ' . ($column === self::CURRENCY ? 'VARCHAR(8) NULL' : 'DECIMAL(20,8) NULL'),
            $columns,
        );

        DB::statement('ALTER TABLE `' . $table . '` ' . implode(', ', $additions) . ', ALGORITHM=INPLACE, LOCK=NONE');
    }
};
