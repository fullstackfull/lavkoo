<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A theme belongs to one storefront (GCC F-01), so activating the theme of one country never deactivates the theme of another. Additive: the column is nullable and
 * appended without a position, no theme that exists today is touched, and nothing reads it until the storefront themes are switched on.
 *
 * NULL is the default storefront's theme, which is every theme that exists today, so there is nothing to back-fill. A country code means the theme was made for that
 * storefront. The country is set once, by the admin form that creates the theme, only for a storefront that is not the default one, and never changes afterwards.
 *
 * No migration of this repository creates the themes table, so on a database that does not have it this does nothing, and the code that reads the column stays
 * dormant until the column exists. The column carries no index (the table holds a handful of rows) and no unique rule: "one active theme per storefront" is kept by
 * ThemeManager::activate, in one transaction. On MySQL and MariaDB it is compared byte for byte (ascii_bin), as PHP compares it, like `products.storefront_country`.
 */
return new class extends Migration {
    private const TABLE = 'themes';

    private const COLUMN = 'storefront_country';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE) && !Schema::hasColumn(self::TABLE, self::COLUMN)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $column = $table->char(self::COLUMN, 2)->nullable();

                if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                    $column->charset('ascii')->collation('ascii_bin');
                }
            });
        }
    }

    /**
     * Dropping the column would turn the themes of another storefront into themes of the default one, and every theme that was active would be active at once
     * on that storefront, so it is refused while a theme names a country. Delete those themes first.
     */
    public function down(): void
    {
        if (!Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        if (DB::table(self::TABLE)->whereNotNull(self::COLUMN)->exists()) {
            throw new RuntimeException('Themes of another storefront exist. Rolling this migration back would make them themes of the default storefront, and several could be active at once.');
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropColumn(self::COLUMN);
        });
    }
};
