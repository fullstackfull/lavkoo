<?php

namespace Tests\Feature\Security;

use App\Events\ChattingEvent;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Three reads of the vendor panel that took a coupon, a delivery man or a shop from the request or from the seller's id without asking whose it was.
 * The coupon quick view returned any coupon by its id, so a seller read another seller's private coupon and the platform's (title, code, minimum
 * purchase, dates, bearer). The chat accepted any delivery man id, so a seller opened a conversation with, and sent a push message to, another shop's
 * driver or the in-house one, and read his profile card. The point of sale page took the shop whose id is the seller's id, the same mistake the shop
 * page had, so the page and the receipt it prints belonged to the in-house shop (id 1) for seller 1, who owns shop 12.
 */
class VendorCouponChatAndPosShopOwnershipTest extends VendorPanelTestCase
{
    private const OWN_COUPON = 1;
    private const OTHER_SELLERS_COUPON = 2;
    private const PLATFORM_COUPON = 3;

    private const OWN_DRIVER = 5;
    private const OTHER_SELLERS_DRIVER = 6;
    private const IN_HOUSE_DRIVER = 7;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('coupons', static function (Blueprint $table): void {
            $table->id();
            $table->string('added_by')->default('admin');
            $table->string('coupon_type')->default('discount_on_purchase');
            $table->string('coupon_bearer')->default('inhouse');
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('title')->nullable();
            $table->string('code')->nullable();
            $table->dateTime('start_date')->nullable();
            $table->dateTime('expire_date')->nullable();
            $table->decimal('min_purchase', 16, 2)->default(0);
            $table->decimal('max_discount', 16, 2)->default(0);
            $table->decimal('discount', 16, 2)->default(0);
            $table->string('discount_type')->default('percentage');
            $table->boolean('status')->default(1);
            $table->integer('limit')->nullable();
            $table->timestamps();
        });
        Schema::create('delivery_men', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id')->default(0);
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('country_code')->nullable();
            $table->string('phone')->nullable();
            $table->string('image')->nullable();
            $table->string('fcm_token')->nullable();
            $table->boolean('is_active')->default(1);
            $table->timestamps();
        });
        Schema::create('chattings', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->text('message')->nullable();
            $table->text('attachment')->nullable();
            $table->boolean('sent_by_seller')->default(0);
            $table->boolean('seen_by_seller')->default(0);
            $table->boolean('seen_by_delivery_man')->default(0);
            $table->string('notification_receiver')->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->timestamps();
        });
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
        });

        DB::table('currencies')->insert(['id' => 1, 'name' => 'US Dollar', 'symbol' => '$', 'code' => 'USD', 'exchange_rate' => 1]);
        DB::table('business_settings')->insert([
            ['type' => 'system_default_currency', 'value' => '1'], ['type' => 'currency_model', 'value' => 'single_currency'],
            ['type' => 'decimal_point_settings', 'value' => '2'], ['type' => 'currency_symbol_position', 'value' => 'left'],
        ]);
        DB::table('coupons')->insert([
            ['id' => self::OWN_COUPON, 'added_by' => 'seller', 'coupon_bearer' => 'seller', 'seller_id' => self::SELLER, 'title' => 'Own coupon', 'code' => 'OWN10', 'start_date' => '2026-01-01', 'expire_date' => '2030-01-01', 'discount' => 10, 'customer_id' => null],
            ['id' => self::OTHER_SELLERS_COUPON, 'added_by' => 'seller', 'coupon_bearer' => 'seller', 'seller_id' => self::OTHER_SELLER, 'title' => 'Rival private coupon', 'code' => 'RIVAL-VIP-70', 'start_date' => '2026-01-01', 'expire_date' => '2030-01-01', 'discount' => 70, 'customer_id' => 7],
            ['id' => self::PLATFORM_COUPON, 'added_by' => 'admin', 'coupon_bearer' => 'inhouse', 'seller_id' => null, 'title' => 'Marketplace VIP coupon', 'code' => 'PLATFORM-VIP-50', 'start_date' => '2026-01-01', 'expire_date' => '2030-01-01', 'discount' => 50, 'customer_id' => 7],
        ]);
        DB::table('delivery_men')->insert([
            ['id' => self::OWN_DRIVER, 'seller_id' => self::SELLER, 'f_name' => 'Own', 'l_name' => 'Driver', 'country_code' => '+966', 'phone' => '0500000005', 'fcm_token' => 'own-token'],
            ['id' => self::OTHER_SELLERS_DRIVER, 'seller_id' => self::OTHER_SELLER, 'f_name' => 'Rival', 'l_name' => 'Driver', 'country_code' => '+966', 'phone' => '0500000006', 'fcm_token' => 'rival-token'],
            ['id' => self::IN_HOUSE_DRIVER, 'seller_id' => 0, 'f_name' => 'Inhouse', 'l_name' => 'Driver', 'country_code' => '+966', 'phone' => '0500000007', 'fcm_token' => 'inhouse-token'],
        ]);
    }

    // ---- the coupon quick view ----

    /** @return array<string, array{int, string}> the coupons that are not the seller's, with a word only that coupon shows */
    public static function couponsThatAreNotTheSellers(): array
    {
        return [
            'another seller private coupon' => [self::OTHER_SELLERS_COUPON, 'RIVAL-VIP-70'],
            'a platform coupon' => [self::PLATFORM_COUPON, 'PLATFORM-VIP-50'],
            'a coupon that does not exist' => [999, 'coupon'],
        ];
    }

    #[DataProvider('couponsThatAreNotTheSellers')]
    public function test_a_coupon_that_is_not_the_sellers_is_not_shown_by_the_quick_view(int $couponId, string $secret): void
    {
        $response = $this->signInAs(self::SELLER)->getJson('/vendor/coupon/quick-view?id=' . $couponId);

        $response->assertNotFound();
        $this->assertStringNotContainsString($secret, (string)$response->getContent());
    }

    public function test_the_sellers_own_coupon_is_still_shown_by_the_quick_view(): void
    {
        $view = $this->signInAs(self::SELLER)->getJson('/vendor/coupon/quick-view?id=' . self::OWN_COUPON)->assertOk()->json('view');

        $this->assertStringContainsString('Own coupon', $view);
        $this->assertStringContainsString('OWN10', $view);
    }

    // ---- the chat with a delivery man ----

    /** @return array<string, array{int}> the drivers a seller may not talk to: another shop's, the in-house one, and one that does not exist */
    public static function deliveryMenThatAreNotTheSellers(): array
    {
        return [
            'another seller driver' => [self::OTHER_SELLERS_DRIVER],
            'an in-house driver' => [self::IN_HOUSE_DRIVER],
            'a driver that does not exist' => [999],
        ];
    }

    #[DataProvider('deliveryMenThatAreNotTheSellers')]
    public function test_a_delivery_man_that_is_not_the_sellers_gets_no_message_and_no_push_and_shows_no_card(int $driverId): void
    {
        Event::fake([ChattingEvent::class]);

        $this->signInAs(self::SELLER)->postJson('/vendor/messages/message', ['delivery_man_id' => $driverId, 'message' => 'Hello from seller 1'])->assertNotFound();
        $card = $this->getJson('/vendor/messages/message?delivery_man_id=' . $driverId);

        $card->assertNotFound();
        $this->assertStringNotContainsString('Rival', (string)$card->getContent());
        $this->assertStringNotContainsString('Inhouse', (string)$card->getContent());
        $this->assertSame(0, DB::table('chattings')->count());
        Event::assertNotDispatched(ChattingEvent::class);
    }

    public function test_the_sellers_own_delivery_man_is_still_messaged_and_shows_his_card(): void
    {
        Event::fake([ChattingEvent::class]);

        $sent = $this->signInAs(self::SELLER)->postJson('/vendor/messages/message', ['delivery_man_id' => self::OWN_DRIVER, 'message' => 'Hello driver'])->assertOk();
        $card = $this->getJson('/vendor/messages/message?delivery_man_id=' . self::OWN_DRIVER)->assertOk();

        $this->assertSame('Own Driver', $sent->json('userData.name'));
        $this->assertSame('Own Driver', $card->json('userData.name'));
        $chat = DB::table('chattings')->first();
        $this->assertSame([self::SELLER, self::OWN_DRIVER, 'Hello driver'], [(int)$chat->seller_id, (int)$chat->delivery_man_id, $chat->message]);
        Event::assertDispatched(ChattingEvent::class, static fn (ChattingEvent $event): bool => $event->key === 'message_from_seller' && $event->type === 'delivery_man');
    }

    // ---- the point of sale page's shop ----

    public function test_the_point_of_sale_page_belongs_to_the_sellers_own_shop(): void
    {
        Schema::table('sellers', static function (Blueprint $table): void {
            $table->string('country_code')->nullable();
        });
        DB::table('business_settings')->insert(['type' => 'seller_pos', 'value' => '1']);
        Schema::create('colors', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->timestamps();
        });
        Schema::create('product_stocks', static function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
        foreach (['with_vat', 'no_vat'] as $variant) {
            Cache::put("tax_system_type_vendor_{$variant}", [
                'SystemTaxVat' => ['is_active' => 0, 'tax_ids' => [], 'additionalData' => []], 'SystemTaxVatType' => 'order_wise', 'is_included' => 1,
                'productWiseTax' => false, 'categoryWiseTax' => false, 'taxVats' => collect([]),
            ], 3600);
        }

        $page = $this->signInAs(self::SELLER)->get('/vendor/pos')->assertOk()->getContent();

        $this->assertStringContainsString('pos-page-shop:' . self::SELLERS_SHOP . ':Alpha Pharmacy:Alpha Street 1, Jeddah', (string)$page);
    }
}
