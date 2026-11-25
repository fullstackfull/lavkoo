<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A city alias belongs to one country's namespace, so two countries may both spell their own city that way (H1-10).
 *
 * `address_city_aliases.normalized_alias` was unique across the whole table, which is right for one country and wrong for six: a Gulf
 * platform has more than one city whose name normalises the same way, and the first country to record "al khobar" as a spelling of its
 * city would have stopped every other country recording it as a spelling of theirs. The alias would not merely be unavailable -- the
 * legacy matcher would have resolved a customer's typed city to ANOTHER COUNTRY's city, which is how a parcel crosses a border silently.
 *
 * **Empty string, not NULL.** `country_code` is NOT NULL with a default of `''`, and `''` is the DEFAULT storefront's namespace -- the one
 * namespace every alias on this platform is in, there having only ever been one storefront. NULL would have been the natural spelling of
 * that, but MySQL and MariaDB count two NULLs as DIFFERENT in a unique key, so `(NULL, 'al khobar')` could be stored twice and the key
 * that stops one alias pointing at two cities would have quietly stopped biting. The same reasoning as `provider_cities.country` and
 * `seller_market_scopes.staff_id`.
 *
 * No backfill, therefore, and no data rewrite: every existing row keeps `''` and every writer normalises the default storefront's country
 * BACK to `''` (`App\Services\Address\CityAliasNamespace`), so one market never has two namespaces.
 *
 * The wide key goes on before the narrow one comes off, so there is no window in which one alias could be made to point at two cities. On
 * MySQL and MariaDB the country is compared byte for byte (ascii_bin), as PHP compares it.
 */
return new class extends Migration {
    private const TABLE = 'address_city_aliases';

    private const COLUMN = 'country_code';

    /** The namespace every alias of a single-market platform is in. */
    private const DEFAULT_STOREFRONT = '';

    /** The key as `2026_11_07_100000` created it, which this widens. */
    private const UNIQUE_BEFORE = 'address_city_aliases_alias_unique';

    private const UNIQUE_AFTER = 'address_city_aliases_country_alias_unique';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        if (!Schema::hasColumn(self::TABLE, self::COLUMN)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $column = $table->char(self::COLUMN, 2)->default(self::DEFAULT_STOREFRONT);

                if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                    $column->charset('ascii')->collation('ascii_bin');
                }
            });
        }

        if (!$this->hasIndex(self::UNIQUE_AFTER)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique([self::COLUMN, 'normalized_alias'], self::UNIQUE_AFTER);
            });
        }

        if ($this->hasIndex(self::UNIQUE_BEFORE)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique(self::UNIQUE_BEFORE);
            });
        }
    }

    /**
     * Refused while an alias names a country of its own: the narrow key could not be put back without deciding which of two countries'
     * identical spellings to throw away, and nothing left on the row would say which was which.
     */
    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE) || !Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        if (DB::table(self::TABLE)->where(self::COLUMN, '<>', self::DEFAULT_STOREFRONT)->exists()) {
            throw new RuntimeException('City aliases name a country. Rolling this migration back would have to throw one country\'s spelling away.');
        }

        if (!$this->hasIndex(self::UNIQUE_BEFORE)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique('normalized_alias', self::UNIQUE_BEFORE);
            });
        }

        if ($this->hasIndex(self::UNIQUE_AFTER)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique(self::UNIQUE_AFTER);
            });
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn(self::COLUMN);
        });
    }

    private function hasIndex(string $name): bool
    {
        return collect(Schema::getIndexes(self::TABLE))->contains(fn (array $index): bool => $index['name'] === $name);
    }
};
