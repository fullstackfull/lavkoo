<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per coupon and order group: the ledger a coupon's limits, cap and budget are counted from (I1-14).
 *
 * Until now a redemption was counted by reading `orders`: the order rows carrying that code, grouped by `order_group_id`
 * ({@see \App\Utils\OrderManager::countCouponUses}). That read answers the per-customer limit and nothing else, and it cannot answer the
 * questions the requirement asks:
 *
 *  - it has no market, so a limit cannot be counted PER STORE (Q-04) without reading each order's storefront again;
 *  - it has no amount, so the DISCOUNT a coupon has actually given -- its spend against a budget -- is not recorded anywhere; and
 *  - it is a count over a table two concurrent checkouts both write to, so there is nothing to lock and nothing to make a total cap hold.
 *
 * The unique key on (coupon_id, order_group_id) is what makes a redemption a fact rather than a count: one order group redeems one coupon
 * once, a replay of the same checkout writes nothing further, and the key is the thing two concurrent checkouts contend on.
 *
 * `coupon_code` is kept BESIDE `coupon_id` deliberately. The orders table records the code and not the id, so a redemption has to be
 * findable from an order, and an administrator may edit a coupon's code afterwards -- the id is what the limits are counted by and the code
 * is what the order says.
 *
 * `storefront_country` is the market the order was sold in, copied from the order's own record, so a limit counted per store counts rows
 * rather than re-reading six markets' orders. NULL is the default storefront's, as it is on every other row of this platform.
 *
 * The amounts are `decimal(18,12)`, which is what `coupons.discount` and `coupons.min_purchase` have been since 2025_07_02, and
 * `currency_code` says which money the figure is in -- a budget read across markets without it would add dinars to riyals.
 *
 * It ships EMPTY and nothing reads or writes it until `gcc.coupons.limits.enabled` is on, so a platform that runs this migration behaves
 * exactly as it did before.
 */
return new class extends Migration {
    private const TABLE = 'coupon_redemptions';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('coupon_id');
            $table->string('coupon_code');
            $table->string('order_group_id');
            // A guest checkout has no customer, so the per-customer limit cannot be counted for one -- which is what the platform does
            // today as well: countCouponUses() reads orders by customer_id and a guest's id is the guest row's.
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->char('storefront_country', 2)->nullable();
            $table->decimal('discount', 18, 12)->default(0);
            $table->char('currency_code', 3)->nullable();
            $table->timestamps();

            // Named, and named short: Laravel's own name for this would be
            // `coupon_redemptions_coupon_id_order_group_id_unique` (49), which fits, but the per-coupon index below would be
            // `coupon_redemptions_coupon_id_storefront_country_index` (53) and the pair is easier to recognise when both are named.
            $table->unique(['coupon_id', 'order_group_id'], 'coupon_redemption_group_unique');
            $table->index(['coupon_id', 'storefront_country'], 'coupon_redemption_market_index');
            $table->index(['customer_id', 'coupon_code'], 'coupon_redemption_customer_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
