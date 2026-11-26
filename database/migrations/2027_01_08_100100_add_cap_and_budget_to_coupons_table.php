<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A coupon's total cap and its spending budget (I1-14).
 *
 * `coupons.limit` is the PER-CUSTOMER limit and always has been: `getTotalCouponAmount` compares it with the order groups THIS customer
 * has placed with the code. Nothing anywhere caps how many times a coupon may be redeemed in total, or how much discount it may give
 * away, so a code that leaks costs whatever the campaign's traffic decides.
 *
 * Both are NULLABLE and null means NO LIMIT, which is what every coupon that exists has: an administrator who has not typed a cap has not
 * capped anything, and a zero would mean the opposite -- a coupon nobody may use.
 *
 * `budget` is `decimal(18,12)` to match `coupons.discount`, `min_purchase` and `max_discount` since 2025_07_02, and it is in the same money
 * those are: the currency of the storefront the coupon belongs to ({@see \App\Services\Storefront\CouponCountry}). Nothing reads either
 * column until `gcc.coupons.limits.enabled` is on.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table): void {
            if (!Schema::hasColumn('coupons', 'total_uses_limit')) {
                $table->unsignedInteger('total_uses_limit')->nullable();
            }

            if (!Schema::hasColumn('coupons', 'budget')) {
                $table->decimal('budget', 18, 12)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table): void {
            foreach (['total_uses_limit', 'budget'] as $column) {
                if (Schema::hasColumn('coupons', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
