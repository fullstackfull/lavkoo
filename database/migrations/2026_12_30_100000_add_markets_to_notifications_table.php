<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The markets a broadcast is for (I1-04).
 *
 * An admin broadcast reached every customer of the platform, because there was only ever one. With six markets a promotion of Kuwait
 * appearing in a Saudi customer's app inbox is wrong in both directions -- the offer does not apply to them, and the Kuwaiti customer who
 * should have seen it is not told it was meant for them.
 *
 * **An unmarked row means EVERY market, not the default market's**, which is the opposite of the convention `StorefrontMarketRows` uses for
 * the configuration tables, and the reason is the difference between a message and a setting. An unmarked shipping rule was WRITTEN for the
 * only storefront there has ever been, so the default market is its owner. An unmarked broadcast was SENT to everybody, and still is: every
 * row an installation already has keeps reaching every customer, and a platform that chooses no market goes on behaving exactly as it does
 * today. Narrowing is something an operator does on purpose.
 *
 * A list rather than one code, because the requirement is one, several or all markets: a promotion of the two Gulf markets that share a
 * price is one broadcast, not two, and two would show twice to a customer who reads both.
 */
return new class extends Migration
{
    private const TABLE = 'notifications';

    private const COLUMN = 'storefront_countries';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE) || Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, static function (Blueprint $table): void {
            // Text rather than `json`: this installation's MySQL dump is MyISAM in places and the column holds a handful of two-letter
            // codes, never a document anything queries into. Nullable with no default, so every existing row reads as "every market".
            $table->text(self::COLUMN)->nullable()->after('sent_to');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE) || !Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, static function (Blueprint $table): void {
            $table->dropColumn(self::COLUMN);
        });
    }
};
