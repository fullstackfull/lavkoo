<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The company a seller's money was earned, charged or paid under (GCC-13, D1-03, E-17.2). Read by App\Services\Marketplace\VendorLedger, which
 * writes it from the order's own frozen company for an entry that has an order, and from the entries a correction draws on for one that has not.
 *
 * ## The live shape of this table is not known from this tree
 *
 * `vendor_ledger_entries` has NO migration anywhere in this repository: the live table came from the installation dump
 * (installation/backup/database.sql, which is not in the tree) and the tests build their own from the columns the code writes. So this migration
 * makes no claim about the table it is adding to. It does nothing at all when the table is absent, which is every installation that has not
 * imported that dump; it adds the column only when it is missing; it alters no existing column; and it adds no index.
 *
 * ## Why no index
 *
 * An index on a large live money table changes the plans of every query that reads it, and the measurements that would justify one can only be
 * taken on real data. It is the owner's to approve, so this migration does not assume it (App\Services\Marketplace\LedgerChain, on the ledger
 * branch, says what an index on this column would buy and what it would not).
 *
 * The column ships null on every row, including every row written before it existed: an entry that names no company is read as an entry of the
 * one ledger the platform has always kept, which is the live Saudi position and stays exactly as it is.
 */
return new class extends Migration {
    private const TABLE = 'vendor_ledger_entries';

    private const COLUMN = 'legal_entity_id';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE) || Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, fn (Blueprint $table) => $table->unsignedBigInteger(self::COLUMN)->nullable());
    }

    /** Dropping the column would forget which company a seller's money belongs to, which cannot be worked out again, so it is refused while an entry names one. */
    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE) || !Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        if (DB::table(self::TABLE)->whereNotNull(self::COLUMN)->exists()) {
            throw new RuntimeException('Seller ledger entries name a selling company. Rolling this migration back would forget whose money they are.');
        }

        Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropColumn(self::COLUMN));
    }
};
