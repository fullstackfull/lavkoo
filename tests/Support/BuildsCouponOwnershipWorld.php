<?php

namespace Tests\Support;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two approved vendors and the eight kinds of coupon row they can meet, as the seller app and the vendor panel read them.
 *
 * Like the other schema builders the `coupons` and `sellers` tables are INFERRED from the models and the migrations that add columns to them:
 * the installation dump is not in the repository. They hold the columns the two coupon controllers read or write and prove the ownership
 * rules, not the live schema. The class using this trait must also use BuildsFulfillmentSchema, which empties a real test database first.
 *
 * Who owns what (the `seller_id` column reads: NULL the platform, 0 every vendor, a number that vendor):
 *   MINE        added by vendor 5, borne by the vendor, seller_id 5
 *   THEIRS      added by vendor 6, seller_id 6
 *   PLATFORM    added by the admin for the platform, seller_id NULL
 *   ALLVENDORS  added by the admin for every vendor, borne by the vendors, seller_id 0
 *   ALLHOUSE    added by the admin for every vendor, borne by the platform, seller_id 0
 *   ADMINFORME  added by the admin for vendor 5 alone, borne by the vendor, seller_id 5
 *   REPOINTED   added by a vendor, then sent by the admin (who may edit any coupon) to every vendor: seller_id 0
 *   ORPHANED    added by a vendor, then taken from it by the admin: seller_id NULL
 */
trait BuildsCouponOwnershipWorld
{
    private const VENDOR = 5;

    private const OTHER_VENDOR = 6;

    private const VENDOR_TOKEN = 'vendor-five-bearer-token-0123456789abcdef';

    private const OTHER_VENDOR_TOKEN = 'vendor-six-bearer-token-0123456789abcdef';

    private const EDITED_TITLE = 'Edited title';

    private const MISSING_COUPON = 987654;

    /** The codes of the coupons vendor 5 neither edits nor switches, on either surface. */
    private const NOT_THE_VENDORS_TO_CHANGE = ['THEIRS', 'PLATFORM', 'ALLVENDORS', 'ALLHOUSE', 'ADMINFORME', 'REPOINTED', 'ORPHANED'];

    /** The codes of the coupons vendor 5 does not delete either. REPOINTED is not among them: a vendor-added coupon addressed to every vendor has always been deletable by any vendor, and the fixes leave that as it was. */
    private const NOT_THE_VENDORS_TO_DELETE = ['THEIRS', 'PLATFORM', 'ALLVENDORS', 'ALLHOUSE', 'ADMINFORME', 'ORPHANED'];

    /** The codes, in alphabetical order, that the vendor's list shows vendor 5: its own coupons and the ones addressed to every vendor. */
    private const LISTED_TO_THE_VENDOR = ['ADMINFORME', 'ALLHOUSE', 'ALLVENDORS', 'MINE', 'REPOINTED'];

    protected function buildCouponOwnershipWorld(): void
    {
        $this->buildCoreSchema();

        Schema::table('orders', static function (Blueprint $table): void {
            $table->string('coupon_code')->nullable();
        });

        Schema::create('sellers', static function (Blueprint $table): void {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('status')->default('approved');
            $table->string('auth_token')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
        });

        Schema::create('coupons', static function (Blueprint $table): void {
            $table->id();
            $table->string('added_by')->default('admin');
            $table->string('coupon_type')->nullable();
            $table->string('coupon_bearer')->default('inhouse');
            $table->bigInteger('seller_id')->nullable();
            $table->bigInteger('customer_id')->nullable();
            $table->string('title')->nullable();
            $table->string('code')->nullable();
            $table->date('start_date')->nullable();
            $table->date('expire_date')->nullable();
            $table->decimal('min_purchase', 18, 12)->default(0);
            $table->decimal('max_discount', 18, 12)->default(0);
            $table->decimal('discount', 18, 12)->default(0);
            $table->string('discount_type')->default('percentage');
            $table->boolean('status')->default(true);
            $table->integer('limit')->nullable();
            $table->timestamps();
        });

        DB::table('currencies')->insert(['id' => 1, 'name' => 'Saudi Riyal', 'code' => 'SAR', 'symbol' => 'SAR', 'exchange_rate' => 1, 'status' => 1]);
        DB::table('business_settings')->insert([
            ['type' => 'currency_model', 'value' => json_encode('single_currency')],
            ['type' => 'system_default_currency', 'value' => json_encode(1)],
            ['type' => 'pagination_limit', 'value' => json_encode(10)],
        ]);

        DB::table('users')->insert(['id' => 1, 'f_name' => 'Test', 'l_name' => 'Customer', 'phone' => '0500000000']);
        DB::table('sellers')->insert([
            ['id' => self::VENDOR, 'f_name' => 'Vendor', 'l_name' => 'Five', 'status' => 'approved', 'auth_token' => self::VENDOR_TOKEN],
            ['id' => self::OTHER_VENDOR, 'f_name' => 'Vendor', 'l_name' => 'Six', 'status' => 'approved', 'auth_token' => self::OTHER_VENDOR_TOKEN],
        ]);

        foreach ([
            ['code' => 'MINE', 'title' => 'Own spring offer', 'added_by' => 'seller', 'coupon_bearer' => 'seller', 'seller_id' => self::VENDOR],
            ['code' => 'THEIRS', 'title' => 'Rival spring offer', 'added_by' => 'seller', 'coupon_bearer' => 'seller', 'seller_id' => self::OTHER_VENDOR],
            ['code' => 'PLATFORM', 'title' => 'Store spring offer', 'added_by' => 'admin', 'coupon_bearer' => 'inhouse', 'seller_id' => null],
            ['code' => 'ALLVENDORS', 'title' => 'Sellers wide offer', 'added_by' => 'admin', 'coupon_bearer' => 'seller', 'seller_id' => 0],
            ['code' => 'ALLHOUSE', 'title' => 'Shop wide offer', 'added_by' => 'admin', 'coupon_bearer' => 'inhouse', 'seller_id' => 0],
            ['code' => 'ADMINFORME', 'title' => 'Special offer', 'added_by' => 'admin', 'coupon_bearer' => 'seller', 'seller_id' => self::VENDOR],
            ['code' => 'REPOINTED', 'title' => 'Moved offer', 'added_by' => 'seller', 'coupon_bearer' => 'seller', 'seller_id' => 0],
            ['code' => 'ORPHANED', 'title' => 'Lost offer', 'added_by' => 'seller', 'coupon_bearer' => 'seller', 'seller_id' => null],
        ] as $coupon) {
            DB::table('coupons')->insert($coupon + [
                'coupon_type' => 'discount_on_purchase', 'customer_id' => 0, 'limit' => 100,
                'min_purchase' => 50, 'max_discount' => 10, 'discount' => 10, 'discount_type' => 'amount', 'status' => 1,
                'start_date' => now()->subDay()->toDateString(), 'expire_date' => now()->addDays(30)->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * The request middleware of the shell needs the installation database, which these tests do not build; the guards under test do not.
     *
     * @param list<class-string> $also
     */
    private function withoutTheShellMiddleware(array $also = []): void
    {
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class, ActivationCheckMiddleware::class, ...$also,
        ]);
    }

    /** @return array<string, mixed> a complete, valid edit of the coupon that changes its title, limit, discount, minimum purchase and dates */
    private function editOf(string $code): array
    {
        return [
            'coupon_type' => 'discount_on_purchase', 'customer_id' => 0, 'limit' => 7, 'discount_type' => 'amount', 'discount' => 25, 'max_discount' => 25,
            'min_purchase' => 90, 'code' => $code, 'title' => self::EDITED_TITLE, 'start_date' => now()->toDateString(), 'expire_date' => now()->addDays(60)->toDateString(),
        ];
    }

    /** @return array<string, array{string}> */
    public static function everyCoupon(): array
    {
        return self::datasetOf(['MINE', ...self::NOT_THE_VENDORS_TO_CHANGE]);
    }

    /** @return array<string, array{string}> */
    public static function couponsTheVendorMayNotChange(): array
    {
        return self::datasetOf(self::NOT_THE_VENDORS_TO_CHANGE);
    }

    /** @return array<string, array{string}> */
    public static function couponsTheVendorMayNotDelete(): array
    {
        return self::datasetOf(self::NOT_THE_VENDORS_TO_DELETE);
    }

    /**
     * @param list<string> $codes
     * @return array<string, array{string}> one case per code, named after it
     */
    private static function datasetOf(array $codes): array
    {
        return array_combine($codes, array_map(static fn (string $code): array => [$code], $codes));
    }

    /** @return list<string> the kinds of toast (success, error, warning) that the requests so far asked the next page to show */
    private function toastTypes(): array
    {
        return array_column(session('laravel-toaster-magic::messages', []), 'type');
    }

    private function couponId(string $code): int
    {
        return (int) DB::table('coupons')->where('code', $code)->value('id');
    }

    /** @return array<string, array<string, mixed>> every coupon row as stored, by code: what a refused request must leave exactly as it found it */
    private function storedCoupons(): array
    {
        return DB::table('coupons')->orderBy('id')->get()->mapWithKeys(static fn (object $row): array => [$row->code => (array) $row])->all();
    }

    /**
     * @param array<string, array<string, mixed>> $coupons
     * @return array<string, array<string, mixed>>
     */
    private function withoutCode(array $coupons, string $code): array
    {
        unset($coupons[$code]);

        return $coupons;
    }

    /** @return list<string> */
    private function codesOf(iterable $coupons): array
    {
        $codes = [];
        foreach ($coupons as $coupon) {
            $codes[] = is_array($coupon) ? $coupon['code'] : $coupon->code;
        }
        sort($codes);

        return $codes;
    }
}
