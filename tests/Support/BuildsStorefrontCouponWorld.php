<?php

namespace Tests\Support;

use App\Services\Storefront\CouponCountry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The coupons of the storefront money tests: the table with the columns the eligibility reads, and the migration of GCC-05 slice 5 that gives it the
 * country. On top of BuildsStorefrontMoneyWorld.
 */
trait BuildsStorefrontCouponWorld
{
    protected function buildStorefrontCouponWorld(bool $withCountryColumn = true): void
    {
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('orders');
        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->string('added_by')->default('admin');
            $table->string('coupon_type')->default('discount_on_purchase');
            $table->string('coupon_bearer')->default('inhouse');
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('customer_id')->default(0);
            $table->string('title')->nullable();
            $table->string('code');
            $table->date('start_date');
            $table->date('expire_date');
            $table->decimal('min_purchase', 40, 20)->default(0);
            $table->decimal('max_discount', 40, 20)->default(0);
            $table->decimal('discount', 40, 20)->default(0);
            $table->string('discount_type')->default('amount');
            $table->integer('limit')->nullable();
            $table->integer('status')->default(1);
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('coupon_code')->nullable();
            $table->string('order_group_id')->nullable();
        });

        if ($withCountryColumn) {
            $this->runCouponCountryMigration();
        }

        CouponCountry::flush();
    }

    protected function dropStorefrontCouponWorld(): void
    {
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('orders');
        CouponCountry::flush();
    }

    protected function runCouponCountryMigration(): void
    {
        (require base_path('database/migrations/2026_12_01_100000_add_storefront_country_to_coupons_table.php'))->up();
    }

    /** @param array<string, mixed> $attributes */
    protected function giveCoupon(string $code, ?string $country, array $attributes = []): int
    {
        $row = array_merge([
            'code' => $code, 'title' => $code, 'start_date' => now()->subDay()->toDateString(), 'expire_date' => now()->addDay()->toDateString(),
            'coupon_type' => 'discount_on_purchase', 'discount' => 5, 'discount_type' => 'amount', 'min_purchase' => 20, 'limit' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ], $attributes);

        if (Schema::hasColumn('coupons', 'storefront_country')) {
            $row['storefront_country'] = $country;
        }

        return (int) DB::table('coupons')->insertGetId($row);
    }

    /** @return list<string> the codes of the coupons the current request's storefront may offer, in code order */
    protected function offeredCodes(): array
    {
        return \App\Models\Coupon::query()->forStorefront()->orderBy('code')->pluck('code')->all();
    }
}
