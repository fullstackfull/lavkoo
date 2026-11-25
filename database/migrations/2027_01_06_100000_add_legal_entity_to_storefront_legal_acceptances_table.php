<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which company a legal acceptance was given to (GCC-11, GCC-13): the id of the storefront's selling company and a frozen copy of its identity.
 *
 * The table already carried a `legal_entity` text column, and it held ONE PLATFORM-WIDE value for all six storefronts (`GCC_LEGAL_ENTITY`), so every
 * receipt named the same company whichever country's text the customer had accepted. These two columns are the per-storefront answer, in the same two
 * names and the same two shapes `orders` records a sale's selling company under (`2026_11_27_100100`), so one company reads one way everywhere.
 *
 * Additive: both columns are nullable, every row that exists today keeps them empty, and nothing writes them until legal entities are switched on. The
 * index is the one a regulator's question needs -- every acceptance one company holds -- and it is the company alone, because the storefront already has
 * an index of its own on this table.
 */
return new class extends Migration {
    private const TABLE = 'storefront_legal_acceptances';

    private const ENTITY = 'legal_entity_id';

    private const SNAPSHOT = 'entity_snapshot';

    private const INDEX = 'storefront_legal_acc_entity_idx';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        if (!Schema::hasColumn(self::TABLE, self::ENTITY)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unsignedBigInteger(self::ENTITY)->nullable();
                $table->index(self::ENTITY, self::INDEX);
            });
        }

        if (!Schema::hasColumn(self::TABLE, self::SNAPSHOT)) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->json(self::SNAPSHOT)->nullable());
        }
    }

    /**
     * Dropping the columns would forget which company a customer accepted a text from, which cannot be worked out again -- the company's name and tax
     * number may have changed since -- so it is refused while any receipt carries one.
     */
    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        if (Schema::hasColumn(self::TABLE, self::ENTITY) && DB::table(self::TABLE)->whereNotNull(self::ENTITY)->exists()) {
            throw new RuntimeException('Legal acceptances name a selling company. Rolling this migration back would forget who each customer accepted a text from.');
        }

        if (Schema::hasColumn(self::TABLE, self::ENTITY)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
                $table->dropColumn(self::ENTITY);
            });
        }

        if (Schema::hasColumn(self::TABLE, self::SNAPSHOT)) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropColumn(self::SNAPSHOT));
        }
    }
};
