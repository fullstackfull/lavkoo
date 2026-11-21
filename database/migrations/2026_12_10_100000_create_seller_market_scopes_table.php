<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The markets a seller account, or one of its staff, may work in, and the role that applies in each (K-09).
 *
 * One row per principal and market. The row IS the grant: no route creates or widens one, because a market a request names is a
 * request and not a grant. `gcc:seller-market:assign` and `gcc:seller-market:revoke` on the server are the only writers.
 *
 * `staff_id` is 0 for the shop's own account and the staff member's id otherwise, rather than NULL, so the unique index holds on
 * every engine: MySQL and MariaDB count two NULLs as different, which would let the same market be granted to an owner twice.
 *
 * `seller_role_id` is the role that applies to that staff member in that market, and NULL means the role on `seller_staff` — which
 * is what every staff member has today, so a grant that names no role per market leaves the permission set byte-identical. This
 * column is the whole of "different permissions in different markets": the permission machinery is unchanged, a second role is
 * simply selected.
 *
 * The country is two characters compared byte for byte, as `products.storefront_country` is, so 'sa' is not 'SA' in either place.
 *
 * Additive and dormant: nothing reads the table until `gcc.seller.market_scopes` is on, and a principal with no row is
 * unrestricted, so a Saudi-only seller sees and does exactly what they did before. No foreign key is declared: `sellers` and
 * `seller_staff` are created by the installation dump that is not in this repository, so the key cannot be relied on to exist at
 * this point in the chain, and a leftover row can only narrow a principal who is given a recycled id, never widen one. Revoke a
 * principal's markets before deleting it to leave nothing behind.
 */
return new class extends Migration {
    private const TABLE = 'seller_market_scopes';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('staff_id')->default(0);
            $country = $table->char('country', 2);
            $table->unsignedBigInteger('seller_role_id')->nullable();
            $table->timestamps();

            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $country->charset('ascii')->collation('ascii_bin');
            }

            $table->unique(['seller_id', 'staff_id', 'country'], 'seller_market_scopes_principal_country_unique');
            $table->index(['staff_id'], 'seller_market_scopes_staff_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
