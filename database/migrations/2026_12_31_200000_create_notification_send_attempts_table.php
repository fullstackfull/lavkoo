<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every attempt to send one notification, stored (I1-09).
 *
 * Nothing was: the push sender catches `Exception` and returns false, and the mail sender's catch block is EMPTY, so a market whose mail
 * password was wrong or whose Firebase service account had been revoked sent nothing and told nobody. This is the table the attempts go
 * into, and `App\Services\Notification\NotificationAttemptLog` is the only thing that writes it.
 *
 * It holds no message and no address. `recipient_digest` is a SHA-256 of the address, so the attempts of one recipient can be counted and
 * read together without the table becoming a second copy of the customer list, and `provider_error` has addresses and long digit runs taken
 * out of it, because an SMTP or FCM error quotes the address it failed on.
 *
 * `storefront_country` is NULL while storefronts are off and for a message that belongs to no market -- the platform of today, where every
 * message is the one market's. The index is named by hand: Laravel's own name for a three-column index on this table is 69 characters and
 * MariaDB's limit is 64 (`MigrationIdentifierLengthTest`).
 */
return new class extends Migration
{
    private const TABLE = 'notification_send_attempts';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, static function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 20);
            $table->string('storefront_country', 2)->nullable();
            $table->string('template_key', 191);
            $table->string('recipient_type', 40)->nullable();
            $table->char('recipient_digest', 64)->nullable();
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempt')->default(1);
            // Whether this was the last attempt the budget allowed. A first failure a retry fixed is noise; a final one is an
            // undelivered message, and it is the only thing the market's admin is shown.
            $table->boolean('is_final')->default(true);
            $table->text('provider_error')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['storefront_country', 'status', 'is_final'], 'nsa_market_status_final');
            $table->index(['created_at'], 'nsa_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
