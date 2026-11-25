<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which STOREFRONT a payment routing rule belongs to, beside the destination it already names (D2-06).
 *
 * `payment_routing_rules.country` is the DESTINATION of the order -- where the parcel goes. On six
 * markets that is not the same question as which shop the rule governs: "do not offer this gateway in
 * the Kuwaiti shop" and "do not offer this gateway for orders delivered to Kuwait" are different rules,
 * and until now only the second could be written. An operator who meant the first wrote `country = KW`
 * and got a rule that also acted on a Saudi shop's order being shipped to Kuwait, and did NOT act on a
 * Kuwaiti shopper's order delivered inside Saudi Arabia.
 *
 * ARRIVES NULL ON EVERY EXISTING ROW, and null means every storefront -- which is exactly how every rule
 * written before this column behaved. So nothing an operator has already written changes, and the
 * column only ever NARROWS a rule that names it.
 *
 * It holds an ISO country code, which is the storefront key this platform uses (A1-09), and
 * `PaymentRoutingRule::storefrontMatches()` resolves both sides through `AddressCountry` before
 * comparing -- so the engine's collation cannot decide this and neither can the spelling an admin form
 * accepted. `ascii` all the same, for the reason the three compared columns of the create migration give:
 * a value this application compares byte for byte has no business carrying a collation that disagrees.
 *
 * Guarded both ways: nothing happens if the table is absent (the create migration is itself guarded,
 * because whether the installed SQL dump already carries the table cannot be read from this repository)
 * and nothing happens if the column is already there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payment_routing_rules') || Schema::hasColumn('payment_routing_rules', 'storefront')) {
            return;
        }

        Schema::table('payment_routing_rules', function (Blueprint $table): void {
            $storefront = $table->string('storefront', 8)->nullable()->after('country');

            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $storefront->charset('ascii')->collation('ascii_bin');
            }
        });
    }

    public function down(): void
    {
        // Only while no rule names a storefront: dropping the column would silently WIDEN every rule that
        // did, from one market to all six, and a rollback is not the place to decide that.
        if (Schema::hasTable('payment_routing_rules')
            && Schema::hasColumn('payment_routing_rules', 'storefront')
            && DB::table('payment_routing_rules')->whereNotNull('storefront')->count() === 0) {
            Schema::table('payment_routing_rules', static fn (Blueprint $table) => $table->dropColumn('storefront'));
        }
    }
};
