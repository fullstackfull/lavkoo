<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The identity of one notification, so a replay of the same business event sends nothing twice (I1-09).
 *
 * `notification_send_attempts` records what was attempted; nothing said WHICH message an attempt was of, so two
 * deliveries of one order status change were two rows that no reader could tell from two real status changes. The
 * column holds a SHA-256 the sender derives from the business event, the recipient and the rendered message
 * (`App\Services\Notification\MessageIdentity`), and it is NULL for every message that names no subject, which is
 * every row written before this migration ran.
 *
 * **The unique key is the whole mechanism and it is (key, attempt), not (key).** A claim is an insert, so the engine
 * is what decides which of two workers replaying one event may send -- not a read followed by a write, which two
 * workers pass through together. `attempt` is in the key because the budget's own retries of the SAME message are
 * further attempts of it and must still be storable: attempt 1 is the claim, 2 and 3 are its retries.
 *
 * NULL keys do not collide on MySQL, MariaDB or SQLite, so the existing rows and every message with no subject are
 * unaffected by it. The index is named by hand: Laravel's own name for it on this table is 65 characters and
 * MariaDB's limit is 64 (`MigrationIdentifierLengthTest`).
 */
return new class extends Migration
{
    private const TABLE = 'notification_send_attempts';

    private const COLUMN = 'idempotency_key';

    private const INDEX = 'nsa_idempotency_attempt_unique';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        if (!Schema::hasColumn(self::TABLE, self::COLUMN)) {
            Schema::table(self::TABLE, static function (Blueprint $table): void {
                $table->char(self::COLUMN, 64)->nullable()->after('template_key');
            });
        }

        if (!Schema::hasIndex(self::TABLE, self::INDEX)) {
            Schema::table(self::TABLE, static function (Blueprint $table): void {
                $table->unique([self::COLUMN, 'attempt'], self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, static function (Blueprint $table): void {
            if (Schema::hasIndex(self::TABLE, self::INDEX)) {
                $table->dropUnique(self::INDEX);
            }

            if (Schema::hasColumn(self::TABLE, self::COLUMN)) {
                $table->dropColumn(self::COLUMN);
            }
        });
    }
};
