<?php

namespace Tests\Feature\Storefront;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsStorefrontCouponWorld;
use Tests\Support\BuildsStorefrontMoneyWorld;
use Tests\TestCase;

/**
 * GCC-05, slice 5: what the readiness command says about the rows a storefront's money reads before the storefront opens. It reports, and only what would make the
 * money wrong fails the run: a seller's product in a storefront that has no seller markets, a coupon that names a storefront nobody serves, and a missing shipping row.
 */
class StorefrontMoneyReadinessTest extends TestCase
{
    use BuildsStorefrontCouponWorld;
    use BuildsStorefrontMoneyWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontMoneyWorld();
        $this->buildStorefrontCouponWorld();
        Schema::dropIfExists('products');
        Schema::dropIfExists('shipping_types');
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('added_by')->default('admin');
            $table->string('product_type')->default('physical');
            $table->integer('status')->default(1);
            $table->decimal('shipping_cost', 20, 6)->nullable();
            $table->char('storefront_country', 2)->default('SA');
        });
        Schema::create('shipping_types', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id')->default(0);
            $table->string('shipping_type')->nullable();
        });
        DB::table('shipping_types')->insert(['seller_id' => 0, 'shipping_type' => 'order_wise']);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('shipping_types');
        $this->dropStorefrontCouponWorld();
        $this->dropStorefrontMoneyWorld();

        parent::tearDown();
    }

    /** @param array<string, mixed> $attributes */
    private function product(string $country, array $attributes = []): void
    {
        DB::table('products')->insert($attributes + ['storefront_country' => $country, 'shipping_cost' => 2.5]);
    }

    public function test_it_counts_the_active_physical_products_that_ship_free_for_each_storefront(): void
    {
        $this->product('KW', ['shipping_cost' => 0]);
        $this->product('KW', ['shipping_cost' => null]);
        $this->product('KW', ['shipping_cost' => 4.5]);
        $this->product('KW', ['shipping_cost' => 0, 'status' => 0]);
        $this->product('KW', ['shipping_cost' => 0, 'product_type' => 'digital']);
        $this->product('AE', ['shipping_cost' => 0]);
        $this->product('SA', ['shipping_cost' => 0]);

        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain('KW   launch: shipping is product-only (2 active physical products have no shipping cost and ship free), no seller-owned products, 0 live coupons of 0 typed for it.')
            ->expectsOutputToContain('AE   launch: shipping is product-only (1 active physical products have no shipping cost and ship free)')
            ->expectsOutputToContain('QA   launch: shipping is product-only (0 active physical products')
            ->doesntExpectOutputToContain('SA   launch')
            ->assertExitCode(0);
    }

    public function test_a_seller_owned_product_in_a_storefront_that_is_switched_on_is_unsafe(): void
    {
        $this->product('KW', ['added_by' => 'seller']);
        $this->product('KW', ['added_by' => 'seller']);
        $this->product('SA', ['added_by' => 'seller']);

        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain('KW   launch: shipping is product-only (0 active physical products have no shipping cost and ship free), UNSAFE: 2 seller-owned products')
            ->expectsOutputToContain('AE   launch: shipping is product-only (0 active physical products have no shipping cost and ship free), no seller-owned products')
            ->expectsOutputToContain('Not ready: what is marked UNSAFE above has to be fixed first, then run this again.')
            ->assertExitCode(1);
    }

    public function test_a_seller_product_of_a_storefront_that_is_not_switched_on_is_not_reported(): void
    {
        config(['gcc.storefronts.countries.KW.active' => false]);
        $this->product('KW', ['added_by' => 'seller']);

        $this->artisan('gcc:storefront:currency-readiness')->doesntExpectOutputToContain('KW   launch')->assertExitCode(0);
    }

    public function test_it_counts_the_live_coupons_and_all_the_coupons_typed_for_each_storefront(): void
    {
        $this->giveCoupon(code: 'LIVE1', country: 'KW');
        $this->giveCoupon(code: 'LIVE2', country: 'KW');
        $this->giveCoupon(code: 'OLD', country: 'KW', attributes: ['expire_date' => now()->subDay()->toDateString()]);
        $this->giveCoupon(code: 'OFF', country: 'KW', attributes: ['status' => 0]);
        $this->giveCoupon(code: 'LATER', country: 'KW', attributes: ['start_date' => now()->addDay()->toDateString(), 'expire_date' => now()->addDays(5)->toDateString()]);
        $this->giveCoupon(code: 'DIRHAM', country: 'AE');
        $this->giveCoupon(code: 'RIYAL', country: null);

        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain('2 live coupons of 5 typed for it.')
            ->expectsOutputToContain('1 live coupons of 1 typed for it.')
            ->assertExitCode(0);
    }

    public function test_a_coupon_that_names_a_storefront_that_is_not_configured_is_unsafe(): void
    {
        $this->giveCoupon(code: 'GHOST', country: 'XX');
        $this->giveCoupon(code: 'RIYAL10', country: null);
        $this->giveCoupon(code: 'DINAR', country: 'KW');

        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain('UNSAFE: 1 coupons name a storefront that is not configured, so no storefront ever offers them.')
            ->assertExitCode(1);
    }

    public function test_a_coupon_that_carries_a_storefront_while_the_switch_is_off_is_a_warning_and_not_a_failure(): void
    {
        $this->giveCoupon(code: 'DINAR', country: 'KW');
        config(['gcc.storefronts.currency.enabled' => false]);

        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain('Warning: 1 coupons carry a storefront while storefront currencies are off, so the default storefront offers them with amounts typed in another currency.')
            ->assertExitCode(0);
    }

    public function test_a_pending_coupons_migration_is_a_warning(): void
    {
        $this->dropStorefrontCouponWorld();
        $this->buildStorefrontCouponWorld(withCountryColumn: false);

        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain('Warning: the coupons migration has not run, so a storefront with its own currency meets no coupon until it does.')
            ->expectsOutputToContain('KW   launch: shipping is product-only (0 active physical products have no shipping cost and ship free), no seller-owned products.')
            ->assertExitCode(0);
    }

    public function test_a_missing_shipping_row_is_unsafe_and_a_missing_table_is_not_reported(): void
    {
        DB::table('shipping_types')->delete();

        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain('UNSAFE: the shop\'s shipping type row (shipping_types, seller 0) is missing.')
            ->assertExitCode(1);

        Schema::dropIfExists('shipping_types');

        $this->artisan('gcc:storefront:currency-readiness')->doesntExpectOutputToContain('shipping type row')->assertExitCode(0);
    }

    public function test_a_world_without_the_tables_reports_what_it_can_and_names_what_it_does_not_cover(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('shipping_types');
        $this->dropStorefrontCouponWorld();

        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain('KW   launch: shipping is product-only.')
            ->expectsOutputToContain('Ready: every storefront that is switched on has the currency it trades in.')
            ->expectsOutputToContain('Not covered by this report: the wallet payment, loyalty, referral rewards, whether the tax rates are right, documents, reports and POS.')
            ->assertExitCode(0);
    }

    public function test_it_changes_nothing(): void
    {
        $this->product('KW', ['added_by' => 'seller']);
        $this->giveCoupon(code: 'GHOST', country: 'XX');
        $before = [DB::table('products')->get()->all(), DB::table('coupons')->get()->all(), DB::table('business_settings')->get()->all(), DB::table('shipping_types')->get()->all()];

        $this->artisan('gcc:storefront:currency-readiness')->assertExitCode(1);

        $this->assertEquals($before, [DB::table('products')->get()->all(), DB::table('coupons')->get()->all(), DB::table('business_settings')->get()->all(), DB::table('shipping_types')->get()->all()]);
    }
}
