<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The company an order was sold by (GCC-13, D1-03): its id and a frozen copy of its identity, so that a later change of the company's name or tax number
 * never rewrites what an order says. Additive: both columns are nullable and an order that exists today keeps them empty, which is read as an order sold
 * by the Saudi company in the platform's own settings, as every order was before. Nothing writes the columns until legal entities are switched on.
 *
 * The columns are appended, without a position, so MySQL 8 and MariaDB 11.4 add them without rebuilding the table. They carry no index: add one only
 * after reading the plans of a report on real data.
 */
return new class extends Migration {
    private const ENTITY = 'legal_entity_id';

    private const SNAPSHOT = 'entity_snapshot';

    public function up(): void
    {
        if (!Schema::hasColumn('orders', self::ENTITY)) {
            Schema::table('orders', fn (Blueprint $table) => $table->unsignedBigInteger(self::ENTITY)->nullable());
        }

        if (!Schema::hasColumn('orders', self::SNAPSHOT)) {
            Schema::table('orders', fn (Blueprint $table) => $table->json(self::SNAPSHOT)->nullable());
        }
    }

    /** Dropping the columns would forget who sold an order, which cannot be worked out again, so it is refused while any order carries a record. */
    public function down(): void
    {
        $recorded = Schema::hasColumn('orders', self::ENTITY) && DB::table('orders')->whereNotNull(self::ENTITY)->exists();

        if ($recorded) {
            throw new RuntimeException('Orders carry a selling company. Rolling this migration back would forget who sold them.');
        }

        foreach ([self::ENTITY, self::SNAPSHOT] as $column) {
            if (Schema::hasColumn('orders', $column)) {
                Schema::table('orders', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
