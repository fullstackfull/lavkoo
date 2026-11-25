<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The register of registrations made while paying: one row for each e-mail and each phone a guest has been registered under,
 * naming the account it produced.
 *
 * The unique key on the identity is the whole point. A gateway may hand one confirmed payment to the settlement more than
 * once, and the rows are written in the same transaction as the account: whoever writes them first registers the guest, and
 * everybody after is told the account is there (App\Services\Payments\OneAccountPerRegistration). It is a register of its own
 * rather than a unique key on `users` because a key there would reach every sign-up on the platform -- the two apps, the web
 * form, the order API, the point of sale and two admin screens -- and would refuse to be applied to a live table that already
 * carries a duplicate.
 *
 * Until this migration has run the settlement registers a guest exactly as it always did.
 */
return new class extends Migration {
    private const TABLE = 'customer_registration_claims';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            // 'email:<address>' or 'phone:<number>', each capped so the pair of them fits a utf8mb4 unique key: 191 is the
            // longest a single-column one can be on a MySQL 5.7 installation of this platform, and the prefix takes six.
            $table->string('identity', 191)->unique();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->timestamp('created_at')->nullable();
        });
    }

    /** Dropping the register forgets which guests were registered: two deliveries of one callback could then register one guest twice. */
    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
