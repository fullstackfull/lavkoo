<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a refund gives back was sold in and by whom (D1-10): the storefront of the order, its currency, the rate that turns a stored amount into that currency and the
 * selling company, copied from the order's own record when the refund row is made, so that a refund read later names the money and the company of the sale whatever the
 * settings and the rates are by then. Additive: every column is nullable and a refund that exists today keeps all four empty. Nothing writes them until the order itself
 * carries the record (the storefront currency and the selling company are switched on). The amount of a refund is not touched.
 *
 * The columns are appended, without a position, so MySQL 8 and MariaDB 11.4 add them without rebuilding the table, and carry no index. The country is compared byte for
 * byte on MySQL and MariaDB (ascii_bin), like `orders.storefront_country`.
 */
return new class extends Migration {
    private const TABLES = ['refund_requests', 'refund_transactions'];

    private const COUNTRY = 'storefront_country';

    private const CURRENCY = 'currency_code';

    private const RATE = 'currency_rate';

    private const ENTITY = 'legal_entity_id';

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            if (!Schema::hasTable($name)) {
                continue;
            }

            if (!Schema::hasColumn($name, self::COUNTRY)) {
                Schema::table($name, function (Blueprint $table) {
                    $column = $table->char(self::COUNTRY, 2)->nullable();

                    if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                        $column->charset('ascii')->collation('ascii_bin');
                    }
                });
            }

            if (!Schema::hasColumn($name, self::CURRENCY)) {
                Schema::table($name, fn (Blueprint $table) => $table->string(self::CURRENCY, 8)->nullable());
            }

            if (!Schema::hasColumn($name, self::RATE)) {
                Schema::table($name, fn (Blueprint $table) => $table->decimal(self::RATE, 20, 8)->nullable());
            }

            if (!Schema::hasColumn($name, self::ENTITY)) {
                Schema::table($name, fn (Blueprint $table) => $table->unsignedBigInteger(self::ENTITY)->nullable());
            }
        }
    }

    /** Dropping the columns would forget what a refund gave back and who sold it, which cannot be worked out again, so it is refused while any refund carries a record. */
    public function down(): void
    {
        foreach (self::TABLES as $name) {
            $recorded = Schema::hasTable($name) && Schema::hasColumn($name, self::COUNTRY) && DB::table($name)->whereNotNull(self::COUNTRY)->exists();

            if ($recorded) {
                throw new RuntimeException("Refunds carry the money of their order ({$name}). Rolling this migration back would forget it.");
            }
        }

        foreach (self::TABLES as $name) {
            foreach ([self::COUNTRY, self::CURRENCY, self::RATE, self::ENTITY] as $column) {
                if (Schema::hasTable($name) && Schema::hasColumn($name, $column)) {
                    Schema::table($name, fn (Blueprint $table) => $table->dropColumn($column));
                }
            }
        }
    }
};
