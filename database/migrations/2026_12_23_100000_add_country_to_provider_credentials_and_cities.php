<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One carrier code, two countries: its credentials and its city list (H1-11's last criterion).
 *
 * A carrier that serves Saudi Arabia and Kuwait is ONE adapter with TWO accounts. Until now `integration_settings` held one row per provider
 * code and `provider_cities` was unique on (provider, external_id), so a second country could neither keep its own credentials nor its own
 * city list -- the second import would collide with the first on any city whose id the two lists share, which for a carrier numbering its
 * cities from 1 is most of them.
 *
 * **Empty string, not NULL.** `country` is NOT NULL with a default of `''`, and `''` means "the platform-wide row" -- the row every
 * installation has today. NULL would have been the natural spelling of that, but MySQL and MariaDB count two NULLs as DIFFERENT in a unique
 * key, so `(provider, NULL, external_id)` could be stored twice and the key that stops a city being imported twice would have quietly
 * stopped biting. The same reasoning as `seller_market_scopes.staff_id` defaulting to 0 rather than NULL.
 *
 * Every existing row takes `''` from the default, so a platform with one account and one city list answers exactly as it did: the readers
 * fall back to the platform-wide row whenever the country asked for has none of its own.
 *
 * Rolling back is refused once a row names a country, for the reason every other market column refuses it: the country cannot be worked out
 * again from what is left, and a rollback would silently merge two carriers' accounts into one.
 */
return new class extends Migration {
    private const CREDENTIALS = 'integration_settings';

    private const CITIES = 'provider_cities';

    private const COLUMN = 'country';

    /** The platform-wide row: the one every installation has before a second country exists. */
    private const PLATFORM_WIDE = '';

    private const CREDENTIALS_UNIQUE = 'integration_settings_provider_country_unique';

    /** The key as `2026_11_05_100000` created it, which this widens. */
    private const CITIES_UNIQUE_BEFORE = 'provider_cities_unique';

    private const CITIES_UNIQUE_AFTER = 'provider_cities_country_unique';

    public function up(): void
    {
        $this->addCountryTo(self::CREDENTIALS);
        $this->addCountryTo(self::CITIES);

        if (Schema::hasTable(self::CREDENTIALS) && !$this->hasIndex(self::CREDENTIALS, self::CREDENTIALS_UNIQUE)) {
            Schema::table(self::CREDENTIALS, function (Blueprint $table): void {
                $table->unique(['provider', self::COLUMN], self::CREDENTIALS_UNIQUE);
            });
        }

        if (!Schema::hasTable(self::CITIES) || $this->hasIndex(self::CITIES, self::CITIES_UNIQUE_AFTER)) {
            return;
        }

        // The narrow key goes only once the wide one is there, so there is no window in which a city could be imported twice.
        Schema::table(self::CITIES, function (Blueprint $table): void {
            $table->unique(['provider', self::COLUMN, 'external_id'], self::CITIES_UNIQUE_AFTER);
        });

        if ($this->hasIndex(self::CITIES, self::CITIES_UNIQUE_BEFORE)) {
            Schema::table(self::CITIES, function (Blueprint $table): void {
                $table->dropUnique(self::CITIES_UNIQUE_BEFORE);
            });
        }
    }

    public function down(): void
    {
        $this->refuseToForgetACountry(self::CREDENTIALS);
        $this->refuseToForgetACountry(self::CITIES);

        if (Schema::hasTable(self::CITIES) && Schema::hasColumn(self::CITIES, self::COLUMN)) {
            if (!$this->hasIndex(self::CITIES, self::CITIES_UNIQUE_BEFORE)) {
                Schema::table(self::CITIES, function (Blueprint $table): void {
                    $table->unique(['provider', 'external_id'], self::CITIES_UNIQUE_BEFORE);
                });
            }

            if ($this->hasIndex(self::CITIES, self::CITIES_UNIQUE_AFTER)) {
                Schema::table(self::CITIES, function (Blueprint $table): void {
                    $table->dropUnique(self::CITIES_UNIQUE_AFTER);
                });
            }

            Schema::table(self::CITIES, function (Blueprint $table): void {
                $table->dropColumn(self::COLUMN);
            });
        }

        if (!Schema::hasTable(self::CREDENTIALS) || !Schema::hasColumn(self::CREDENTIALS, self::COLUMN)) {
            return;
        }

        if ($this->hasIndex(self::CREDENTIALS, self::CREDENTIALS_UNIQUE)) {
            Schema::table(self::CREDENTIALS, function (Blueprint $table): void {
                $table->dropUnique(self::CREDENTIALS_UNIQUE);
            });
        }

        Schema::table(self::CREDENTIALS, function (Blueprint $table): void {
            $table->dropColumn(self::COLUMN);
        });
    }

    private function addCountryTo(string $table): void
    {
        if (!Schema::hasTable($table) || Schema::hasColumn($table, self::COLUMN)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $column = $blueprint->char(self::COLUMN, 2)->default(self::PLATFORM_WIDE);

            // Compared byte for byte, as every other market column of this platform is.
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $column->charset('ascii')->collation('ascii_bin');
            }
        });
    }

    private function refuseToForgetACountry(string $table): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, self::COLUMN)) {
            return;
        }

        if (DB::table($table)->where(self::COLUMN, '<>', self::PLATFORM_WIDE)->exists()) {
            throw new RuntimeException("Rows of {$table} name a country. Rolling this migration back would forget which, and merge two carriers' accounts into one.");
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $index): bool => $index['name'] === $name);
    }
};
