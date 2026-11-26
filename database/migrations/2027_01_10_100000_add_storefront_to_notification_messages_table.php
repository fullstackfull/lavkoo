<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A market's own wording for one notification (I1-03).
 *
 * `notification_messages` holds one row per `(user_type, key)` for the whole installation, with its translations by locale beside it, so
 * every market was sent the same words -- and the wording a Kuwaiti customer is told an order is on its way in is not a translation
 * question, it is a market question: the brand, the delivery promise and the legal phrasing differ per country.
 *
 * The column is the override's key. A row whose `storefront_country` is NULL is the global text, which is every row that exists today and
 * what the default market keeps reading; a row naming a country is that market's own wording for the same `(user_type, key)`.
 *
 * **There is deliberately NO unique key on (user_type, key, storefront_country).** This table comes from the installed SQL dump and has
 * never had one, so an installation may already hold two rows for one `(user_type, key)` -- and a migration that refuses to run on a
 * production dump is worse than one that leaves the resolution to the reader. {@see App\Services\Notification\MarketNotificationText}
 * therefore reads the LOWEST id for a market, which is deterministic on every engine, rather than whichever row came back first.
 *
 * Additive and nullable: nothing writes the column until `gcc.notifications.market_templates` is on, and with it off the readers do not
 * even look at it.
 */
return new class extends Migration
{
    private const TABLE = 'notification_messages';

    private const COLUMN = 'storefront_country';

    private const INDEX = 'notif_messages_market_type_key_idx';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE) || Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, static function (Blueprint $table): void {
            $table->string(self::COLUMN, 2)->nullable()->after('user_type');
            $table->index([self::COLUMN, 'user_type', 'key'], self::INDEX);
        });
    }

    /**
     * Dropping the column would forget which market each override belonged to and leave its wording looking like a second global text, so
     * it is refused while any row names a market.
     */
    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE) || !Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        if (DB::table(self::TABLE)->whereNotNull(self::COLUMN)->exists()) {
            throw new RuntimeException(
                'notification_messages holds a market override: remove the overrides before taking this column away, or two texts for one'
                . ' key become two global texts.',
            );
        }

        Schema::table(self::TABLE, static function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
            $table->dropColumn(self::COLUMN);
        });
    }
};
