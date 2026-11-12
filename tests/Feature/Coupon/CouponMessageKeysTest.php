<?php

namespace Tests\Feature\Coupon;

use App\Services\CouponService;
use App\Utils\OrderManager;
use ArrayObject;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Address\StorefrontTestCase;

/**
 * Two messages that coupon refusals ask for were not defined in any language file, so each refusal wrote the key into a tracked
 * file at runtime: the customer's empty cart (app/Utils/OrderManager.php:551) and the admin's percentage refusal
 * (app/Services/CouponService.php:126). Both are now defined in every locale with the text the app already displays, which is the text
 * translate() derives from the key. No wording changes and no new translations are introduced.
 *
 * The digest guard in tearDown() runs before the base class removes its placeholder keys, so a write cannot be hidden by that cleanup.
 */
class CouponMessageKeysTest extends StorefrontTestCase
{
    private const EMPTY_CART_KEY = 'Please_add_item_to_cart';
    private const EMPTY_CART_TEXT = 'Please add item to cart';
    private const PERCENTAGE_KEY = 'when_discount_type_percentage,discount_amount_will_be_less_than_100';
    private const PERCENTAGE_TEXT = 'When discount type percentage,discount amount will be less than 100';
    private const COUPON_CODE = 'SAVE10SAR';

    /** @var array<string, string> */
    private array $languageFileDigests = [];

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

    public function test_every_locale_defines_the_two_messages_and_the_english_text_is_unchanged(): void
    {
        foreach (glob(base_path('resources/lang/*/new-messages.php')) ?: [] as $file) {
            $messages = include $file;

            $this->assertArrayHasKey(self::EMPTY_CART_KEY, $messages, $file);
            $this->assertArrayHasKey(self::PERCENTAGE_KEY, $messages, $file);
        }

        $english = include base_path('resources/lang/en/new-messages.php');
        $this->assertSame(self::EMPTY_CART_TEXT, $english[self::EMPTY_CART_KEY]);
        $this->assertSame(self::PERCENTAGE_TEXT, $english[self::PERCENTAGE_KEY]);
    }

    public function test_an_empty_checked_cart_is_refused_with_its_message_and_writes_no_language_file(): void
    {
        $this->signIn($this->makeCustomer());
        DB::table('coupons')->insert([
            'title' => 'Ten riyals off', 'code' => self::COUPON_CODE, 'coupon_type' => 'discount_on_purchase', 'discount_type' => 'amount',
            'discount' => 10, 'min_purchase' => 0, 'max_discount' => 0, 'start_date' => now()->subDay()->toDateString(),
            'expire_date' => now()->addYear()->toDateString(), 'status' => 1, 'added_by' => 'admin', 'coupon_bearer' => 'inhouse',
            'seller_id' => 0, 'customer_id' => 0, 'limit' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = OrderManager::getTotalCouponAmount(request: request(), couponCode: self::COUPON_CODE);

        $this->assertFalse($result['status']);
        $this->assertSame(self::EMPTY_CART_TEXT, $result['messages']);
    }

    public function test_an_admin_percentage_refusal_shows_its_message_and_writes_no_language_file(): void
    {
        Toastr::shouldReceive('error')->once()->with(self::PERCENTAGE_TEXT);

        $this->assertFalse((new CouponService())->checkConditions(new ArrayObject([
            'coupon_type' => 'discount_on_purchase', 'discount_type' => 'percentage', 'discount' => '100', 'min_purchase' => '0',
        ])));
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
