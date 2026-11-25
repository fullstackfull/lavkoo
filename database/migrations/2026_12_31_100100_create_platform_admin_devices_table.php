<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The devices a platform admin receives alerts on (L-16, GCC-20).
 *
 * A table of its own, and NOT a column on the shop: a seller's push token lives in `sellers.cm_firebase_token`, one per shop, and putting an
 * admin's beside it would mean one store of tokens feeding both the seller topics and the admin ones, where a mistake in a query is a seller
 * receiving a platform alert or an admin being sent a maintenance notice meant for shops. Nothing of this table is read by the seller world and
 * nothing of the seller world is read here.
 *
 * A device belongs to a SESSION (`token_id`), not to an admin: a phone that signed in is a phone that may be sent to, and when that session ends
 * -- signed out, revoked, evicted -- its row goes with it. A session that merely went idle has no moment to hook, so the send path asks for
 * devices whose session is still open rather than trusting the table alone.
 *
 * The push token is kept ENCRYPTED, with the application key, as the second factor's secret is. It is a credential for sending to a particular
 * phone, and a dump of this table should not be a list of them. The hash beside it is what a second registration of the same phone is recognised
 * by, since the encrypted bytes differ every time.
 *
 * No foreign key to `platform_admin_tokens`: the admin tables of this API carry none either, because the installation's own `admins` table cannot
 * be relied on to be referenceable (see the grants migration). The rows are removed by the code that ends a session, and the send path joins.
 */
return new class extends Migration {
    private const TABLE = 'platform_admin_devices';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('admin_id');
            $table->unsignedBigInteger('token_id');
            $table->string('platform', 16);
            $table->text('push_token');
            $table->char('push_token_hash', 64);
            $table->json('topics');
            $table->dateTime('last_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['token_id', 'push_token_hash'], 'pa_devices_session_token_unique');
            $table->index('admin_id');
        });
    }

    /** Dropping it loses only where alerts were being sent, which a device says again the next time it registers. */
    public function down(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            DB::table(self::TABLE)->delete();
            Schema::drop(self::TABLE);
        }
    }
};
