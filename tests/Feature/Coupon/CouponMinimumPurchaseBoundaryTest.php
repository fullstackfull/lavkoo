<?php

namespace Tests\Feature\Coupon;

use App\Models\User;
use App\Services\Analytics\Analytics;
use App\Services\Analytics\EventRecorder;
use App\Utils\OrderManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use Tests\Feature\Address\StorefrontTestCase;

/**
 * The minimum-order boundary of a coupon, in SAR, as the Saudi storefront applies it.
 *
 * One rule serves both channels: OrderManager::getTotalCouponAmount() (app/Utils/OrderManager.php:502), called by the web coupon
 * form (app/Http/Controllers/Web/CouponController.php:23) and by the customer API (app/Http/Controllers/RestAPI/v1/CouponController.php:104).
 * Its minimum clause is `min_purchase > eligible subtotal` (app/Utils/OrderManager.php:598): a subtotal below the minimum is refused,
 * a subtotal equal to it or above it passes, provided the other coupon conditions hold.
 *
 * Limits: the carts here carry no product discount, so the eligible subtotal is the cart price times the quantity. Product-level
 * discounts are not exercised. The coupons table is INFERRED: the installation dump is not in the repository. Its base columns come from
 * the Coupon model and the code that reads them; the columns the migrations added are reproduced as written. These tests prove this rule
 * on those tables, not on the live schema.
 *
 * Translations: the messages on the paths tested are defined in resources/lang/en/messages.php, so translate() writes nothing on them.
 * An empty cart is not tested. That branch (app/Utils/OrderManager.php:547-553) asks for Please_add_item_to_cart, which no language file
 * defines, so translate() would append it to the tracked language files. The digest guard in tearDown() runs before the base class
 * removes its placeholder keys, so a write cannot be hidden by that cleanup.
 */
class CouponMinimumPurchaseBoundaryTest extends StorefrontTestCase
{
    private const COUPON_CODE = 'SAVE10SAR';
    private const DISCOUNT_SAR = 10;
    private const MINIMUM_SAR = 100;
    private const NOT_REACHED = 'Minimum purchase amount not reached for this coupon.';
    private const APPLIED = 'Coupon applied successfully!';

    /** @var array<string, string> */
    private array $languageFileDigests = [];

    private MockInterface $analyticsRecorder;

    protected function setUp(): void
    {
        parent::setUp();

        App::setLocale('en');
        $this->createCheckoutPageTables();
        Schema::create(table: 'coupons', callback: function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('code')->nullable();
            $table->string('coupon_type')->nullable();
            $table->string('discount_type')->nullable();
            $table->decimal(column: 'discount', total: 18, places: 12)->default(0);
            $table->decimal(column: 'min_purchase', total: 18, places: 12)->default(0);
            $table->decimal(column: 'max_discount', total: 18, places: 12)->default(0);
            $table->date('start_date')->nullable();
            $table->date('expire_date')->nullable();
            $table->boolean('status')->default(true);
            $table->string('added_by')->default('admin');
            $table->string('coupon_bearer')->default('inhouse');
            $table->bigInteger('seller_id')->nullable();
            $table->bigInteger('customer_id')->nullable();
            $table->integer('limit')->nullable();
            $table->timestamps();
        });
        Schema::table(table: 'orders', callback: function (Blueprint $table) {
            $table->string('coupon_code')->nullable();
            $table->string('order_group_id')->nullable();
        });
        $this->useThrowawayPassportKeys();
        $this->analyticsRecorder = $this->mock(EventRecorder::class);
        $this->app->forgetInstance(Analytics::class);

        $this->languageFileDigests = $this->digestsOfLanguageFiles();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->languageFileDigests !== []) {
                $this->assertSame(
                    $this->languageFileDigests,
                    $this->digestsOfLanguageFiles(),
                    'a file under resources/lang changed during the test: translate() wrote it, or another process edited the same working tree',
                );
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_a_cart_below_the_minimum_is_refused_with_the_minimum_message(): void
    {
        $this->shopperWithCart(subtotal: 99);
        $this->saudiCouponOfTenRiyals();

        $result = OrderManager::getTotalCouponAmount(request: request(), couponCode: self::COUPON_CODE);

        $this->assertFalse($result['status']);
        $this->assertSame(self::NOT_REACHED, $result['messages']);
        $this->assertFalse(session()->has('coupon_code'));
    }

    public function test_a_cart_equal_to_the_minimum_is_accepted_with_the_discount(): void
    {
        $this->shopperWithCart(subtotal: 100);
        $this->saudiCouponOfTenRiyals();

        $result = OrderManager::getTotalCouponAmount(request: request(), couponCode: self::COUPON_CODE);

        $this->assertTrue($result['status']);
        $this->assertSame(self::APPLIED, $result['messages']);
        $this->assertEquals(self::DISCOUNT_SAR, $result['discount']);
        $this->assertSame(self::COUPON_CODE, session('coupon_code'));
    }

    public function test_a_cart_above_the_minimum_is_accepted_with_the_discount(): void
    {
        $this->shopperWithCart(subtotal: 120);
        $this->saudiCouponOfTenRiyals();

        $result = OrderManager::getTotalCouponAmount(request: request(), couponCode: self::COUPON_CODE);

        $this->assertTrue($result['status']);
        $this->assertSame(self::APPLIED, $result['messages']);
        $this->assertEquals(self::DISCOUNT_SAR, $result['discount']);
        $this->assertSame(self::COUPON_CODE, session('coupon_code'));
    }

    /** Current copy, characterized and not endorsed: a fixed discount above the cart is refused under the minimum message even when the minimum is met. */
    public function test_a_fixed_discount_above_the_cart_is_refused_under_the_minimum_message_while_the_minimum_is_met(): void
    {
        $this->shopperWithCart(subtotal: 40);
        $this->saudiCouponOfTenRiyals(overrides: ['min_purchase' => 30, 'discount' => 50]);

        $result = OrderManager::getTotalCouponAmount(request: request(), couponCode: self::COUPON_CODE);

        $this->assertFalse($result['status']);
        $this->assertSame(self::NOT_REACHED, $result['messages']);
    }

    public function test_the_web_coupon_form_refuses_a_cart_below_the_minimum_with_the_same_message(): void
    {
        $this->shopperWithCart(subtotal: 99);
        $this->saudiCouponOfTenRiyals();
        $this->analyticsRecorder->shouldReceive('record')->once();

        $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post(uri: route(name: 'coupon.apply'), data: ['code' => self::COUPON_CODE])
            ->assertOk()
            ->assertExactJson(['status' => 0, 'messages' => ['0' => self::NOT_REACHED]]);
    }

    public function test_the_web_coupon_form_accepts_a_cart_equal_to_the_minimum(): void
    {
        $this->shopperWithCart(subtotal: 100);
        $this->saudiCouponOfTenRiyals();
        $this->analyticsRecorder->shouldReceive('record')->once();

        $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post(uri: route(name: 'coupon.apply'), data: ['code' => self::COUPON_CODE])
            ->assertOk()
            ->assertExactJson(['status' => 1, 'messages' => self::APPLIED]);
    }

    public function test_the_web_coupon_form_accepts_a_cart_above_the_minimum(): void
    {
        $this->shopperWithCart(subtotal: 120);
        $this->saudiCouponOfTenRiyals();
        $this->analyticsRecorder->shouldReceive('record')->once();

        $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post(uri: route(name: 'coupon.apply'), data: ['code' => self::COUPON_CODE])
            ->assertOk()
            ->assertExactJson(['status' => 1, 'messages' => self::APPLIED]);
    }

    public function test_the_customer_api_refuses_a_cart_below_the_minimum_with_the_same_message(): void
    {
        $customer = $this->shopperWithCart(subtotal: 99);
        $this->saudiCouponOfTenRiyals();
        $this->analyticsRecorder->shouldReceive('record')->once();

        $response = $this->actingAs(user: $customer, guard: 'api')->getJson('/api/v1/coupon/apply?code=' . self::COUPON_CODE);

        $response->assertStatus(202);
        $this->assertSame(self::NOT_REACHED, $response->json());
    }

    public function test_the_customer_api_accepts_a_cart_equal_to_the_minimum(): void
    {
        $customer = $this->shopperWithCart(subtotal: 100);
        $this->saudiCouponOfTenRiyals();
        $this->analyticsRecorder->shouldReceive('record')->once();

        $response = $this->actingAs(user: $customer, guard: 'api')->getJson('/api/v1/coupon/apply?code=' . self::COUPON_CODE);

        $response->assertOk();
        $this->assertEquals(self::DISCOUNT_SAR, $response->json('coupon_discount'));
        $this->assertSame('discount_on_purchase', $response->json('coupon_type'));
    }

    public function test_the_customer_api_accepts_a_cart_above_the_minimum(): void
    {
        $customer = $this->shopperWithCart(subtotal: 120);
        $this->saudiCouponOfTenRiyals();
        $this->analyticsRecorder->shouldReceive('record')->once();

        $response = $this->actingAs(user: $customer, guard: 'api')->getJson('/api/v1/coupon/apply?code=' . self::COUPON_CODE);

        $response->assertOk();
        $this->assertEquals(self::DISCOUNT_SAR, $response->json('coupon_discount'));
        $this->assertSame('discount_on_purchase', $response->json('coupon_type'));
    }

    /** A signed-in customer with one checked, active, in-house product whose subtotal is the given SAR amount. */
    private function shopperWithCart(int|float $subtotal): User
    {
        $customer = $this->signIn($this->makeCustomer());

        DB::table('products')->insert([
            'id' => 1, 'name' => 'Paracetamol 500 mg', 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => $subtotal,
        ]);
        DB::table('carts')->insert([
            'customer_id' => $customer->id, 'is_guest' => false, 'cart_group_id' => 'group-sar', 'product_id' => 1, 'product_type' => 'physical',
            'quantity' => 1, 'price' => $subtotal, 'discount' => 0, 'seller_id' => null, 'seller_is' => 'admin', 'is_checked' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $customer;
    }

    /** A coupon that gives 10 SAR off a cart of at least 100 SAR, usable once, for every seller and every customer. */
    private function saudiCouponOfTenRiyals(array $overrides = []): void
    {
        DB::table('coupons')->insert($overrides + [
            'title' => 'Ten riyals off', 'code' => self::COUPON_CODE, 'coupon_type' => 'discount_on_purchase', 'discount_type' => 'amount',
            'discount' => self::DISCOUNT_SAR, 'min_purchase' => self::MINIMUM_SAR, 'max_discount' => 0,
            'start_date' => now()->subDay()->toDateString(), 'expire_date' => now()->addYear()->toDateString(), 'status' => 1,
            'added_by' => 'admin', 'coupon_bearer' => 'inhouse', 'seller_id' => 0, 'customer_id' => 0, 'limit' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * The api guard needs Passport keys. They are generated in memory for each test. BuildsApiAddressSchema::useThrowawayPassportKeys()
     * does the same but caches one pair per run; it is not composed here because that trait is not part of this storefront harness.
     */
    private function useThrowawayPassportKeys(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);

        config(['passport.private_key' => $private, 'passport.public_key' => openssl_pkey_get_details($key)['key']]);
    }

    /** @return array<string, string> */
    private function digestsOfLanguageFiles(): array
    {
        $digests = [];
        foreach (glob(base_path('resources/lang/*/*.php')) ?: [] as $file) {
            $digests[$file] = md5_file($file);
        }

        return $digests;
    }
}
