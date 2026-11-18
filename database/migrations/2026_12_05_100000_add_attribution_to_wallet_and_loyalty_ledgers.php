<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What stands behind a wallet or loyalty ledger row (E-05): the order, order group, refund or payment it came from, the staff member who made it, and the storefront, the
 * currency and the selling company of the market it was made in, copied from the order's own record when the row is written, so that a row read later still says what it was
 * for and for whom whatever the settings and the rates are by then. Additive: every column is nullable and a row that exists today keeps all of them empty (no backfill). The
 * `reference` of a row is not touched: reports and the REST filters read it as a label.
 *
 * Nothing writes the columns until this migration has run (App\Services\Storefront\LedgerAttribution asks the table first). A loyalty point has no currency, so only the wallet
 * ledger gets `currency_code`.
 *
 * The columns are appended, without a position, so MySQL 8 and MariaDB 11.4 add them without rebuilding the table, and carry no index: add one only after reading the plans of
 * a report on real data. On MySQL and MariaDB the country is compared byte for byte (ascii_bin), like `orders.storefront_country`.
 */
return new class extends Migration {
    private const WALLET = 'wallet_transactions';

    private const LOYALTY = 'loyalty_point_transactions';

    private const COUNTRY = 'storefront_country';

    /** @var array<string, list<string>> the columns of each ledger this migration adds */
    private const COLUMNS = [
        self::WALLET => ['source_type', 'source_id', 'actor_type', 'actor_id', self::COUNTRY, 'currency_code', 'legal_entity_id'],
        self::LOYALTY => ['source_type', 'source_id', 'actor_type', 'actor_id', self::COUNTRY, 'legal_entity_id'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $name => $columns) {
            if (!Schema::hasTable($name)) {
                continue;
            }

            foreach ($columns as $column) {
                if (!Schema::hasColumn($name, $column)) {
                    Schema::table($name, fn (Blueprint $table) => $this->define(table: $table, column: $column));
                }
            }
        }
    }

    /** Dropping the columns would forget what the movements were for and who made them, which cannot be worked out again, so it is refused while any row carries a record. */
    public function down(): void
    {
        foreach (self::COLUMNS as $name => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasTable($name) && Schema::hasColumn($name, $column) && DB::table($name)->whereNotNull($column)->exists()) {
                    throw new RuntimeException("Rows of {$name} carry what they were made for ({$column}). Rolling this migration back would forget it.");
                }
            }
        }

        foreach (self::COLUMNS as $name => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasTable($name) && Schema::hasColumn($name, $column)) {
                    Schema::table($name, fn (Blueprint $table) => $table->dropColumn($column));
                }
            }
        }
    }

    private function define(Blueprint $table, string $column): void
    {
        match ($column) {
            'source_type', 'actor_type' => $table->string($column, 32)->nullable(),
            'source_id' => $table->string($column, 191)->nullable(),
            'actor_id', 'legal_entity_id' => $table->unsignedBigInteger($column)->nullable(),
            self::COUNTRY => $this->defineCountry(table: $table),
            'currency_code' => $table->string($column, 8)->nullable(),
        };
    }

    private function defineCountry(Blueprint $table): void
    {
        $column = $table->char(self::COUNTRY, 2)->nullable();

        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $column->charset('ascii')->collation('ascii_bin');
        }
    }
};
