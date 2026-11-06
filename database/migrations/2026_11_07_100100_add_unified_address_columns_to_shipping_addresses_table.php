<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured Saudi address fields on the platform's existing address book.
 *
 * `shipping_addresses` belongs to the installation dump, so every change is guarded and additive: four nullable columns, no
 * default, no backfill, no foreign key. `city`, `zip`, `state` and `address` stay exactly as they are — old rows, reports, the
 * ZIP-based delivery restriction and every API that reads them keep working. A row without `city_id` is a legacy address.
 *
 * Rolling back drops the four columns; no existing value is touched either way.
 */
return new class extends Migration
{
    private const TABLE = 'shipping_addresses';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        if (!Schema::hasColumn(self::TABLE, 'region_id')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unsignedBigInteger('region_id')->nullable()->index('shipping_addresses_region_idx');
            });
        }
        if (!Schema::hasColumn(self::TABLE, 'city_id')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unsignedBigInteger('city_id')->nullable()->index('shipping_addresses_city_idx');
            });
        }
        if (!Schema::hasColumn(self::TABLE, 'district')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->string('district', 191)->nullable();
            });
        }
        if (!Schema::hasColumn(self::TABLE, 'national_address')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->string('national_address', 16)->nullable()->index('shipping_addresses_national_idx');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (['region_id' => 'shipping_addresses_region_idx', 'city_id' => 'shipping_addresses_city_idx', 'national_address' => 'shipping_addresses_national_idx'] as $column => $index) {
            if (Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, function (Blueprint $table) use ($index, $column) {
                    $table->dropIndex($index);
                    $table->dropColumn($column);
                });
            }
        }
        if (Schema::hasColumn(self::TABLE, 'district')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropColumn('district');
            });
        }
    }
};
