<?php

namespace Tests\Feature\Notifications;

use App\Events\OrderStatusEvent;
use App\Models\NotificationMessage;
use App\Models\Order;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\FakesThePushService;

/**
 * The body of a push is written in the language of the person it goes to: PushNotificationTrait::pushNotificationMessage asks the
 * message of the event for the translation whose locale is the recipient's app_language, and falls back to the global message when
 * that language has no translation row. A recipient with no app_language is written to in the default language of the store.
 *
 * The push service is faked at the HTTP call (the Google token and the FCM send), so what is asserted is the request body that would
 * have left the server. Only the body is pinned: the title comes from translate() in the language of the request, not of the recipient.
 */
class PushBodyLanguageTest extends ApiAddressTestCase
{
    use FakesThePushService;

    private const GLOBAL_TEXT = 'Order {orderId} is pending';

    private const ARABIC_TEXT = 'طلبك رقم {orderId} قيد الانتظار';

    private const DEFAULT_LANGUAGE_TEXT = 'طلب {orderId} بانتظار التأكيد';

    private const SELLER_GLOBAL_TEXT = 'A new order {orderId} is waiting';

    private const SELLER_ARABIC_TEXT = 'لديك طلب جديد {orderId}';

    private const ORDER_ID = 100001;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPipelineTables();
        Schema::table('users', static function (Blueprint $table): void {
            $table->string('cm_firebase_token')->nullable();
            $table->string('app_language')->nullable();
        });
        Schema::table('sellers', static function (Blueprint $table): void {
            $table->string('cm_firebase_token')->nullable();
            $table->string('app_language')->nullable();
        });
        Schema::create('notification_messages', static function (Blueprint $table): void {
            $table->id();
            $table->string('user_type');
            $table->string('key');
            $table->text('message')->nullable();
            $table->integer('status')->default(1);
            $table->timestamps();
        });

        $this->messageFor(userType: 'customer', key: 'order_pending_message', text: self::GLOBAL_TEXT, translations: ['ar' => self::ARABIC_TEXT, 'sa' => self::DEFAULT_LANGUAGE_TEXT]);
        $this->messageFor(userType: 'seller', key: 'new_order_message', text: self::SELLER_GLOBAL_TEXT, translations: ['ar' => self::SELLER_ARABIC_TEXT]);
        $this->configureThePushService();
        $this->setStoreLanguages(default: 'en');
    }

    /** @param array<string, string> $translations text by locale */
    private function messageFor(string $userType, string $key, string $text, array $translations): void
    {
        $message = NotificationMessage::create(['user_type' => $userType, 'key' => $key, 'message' => $text, 'status' => 1]);
        foreach ($translations as $locale => $translated) {
            Translation::create(['translationable_type' => NotificationMessage::class, 'translationable_id' => $message->id, 'locale' => $locale, 'key' => 'message', 'value' => $translated]);
        }
    }

    private function setStoreLanguages(string $default): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => 'language'], ['value' => json_encode([
            ['id' => 1, 'name' => 'English', 'direction' => 'ltr', 'code' => 'en', 'status' => 1, 'default' => $default === 'en'],
            ['id' => 2, 'name' => 'Arabic', 'direction' => 'rtl', 'code' => 'sa', 'status' => 1, 'default' => $default === 'sa'],
        ])]);
        Cache::flush();
    }

    private function placeOrderOf(User $customer, ?int $sellerId = null): Order
    {
        DB::table('orders')->insert(['id' => self::ORDER_ID, 'customer_id' => $customer->id, 'seller_id' => $sellerId, 'seller_is' => $sellerId === null ? 'admin' : 'seller', 'created_at' => now(), 'updated_at' => now()]);

        return Order::findOrFail(self::ORDER_ID);
    }

    private function assertOnePushWithTheBody(string $expectedBody, string $expectedDevice): void
    {
        $pushes = $this->pushesSent();

        $this->assertCount(1, $pushes, 'exactly one push left the server');
        $this->assertSame(self::FCM_SEND_URL, $pushes[0]->url());
        $this->assertSame($expectedDevice, $pushes[0]['message']['token']);
        $this->assertSame($expectedBody, $pushes[0]['message']['notification']['body']);
        $this->assertSame($expectedBody, $pushes[0]['message']['data']['body'], 'the data copy of the body is the same text');
    }

    private function pendingNotificationToTheCustomerOf(Order $order): void
    {
        event(new OrderStatusEvent(key: 'pending', type: 'customer', order: $order));
    }

    private function newOrderNotificationToTheSellerOf(Order $order): void
    {
        event(new OrderStatusEvent(key: 'new_order_message', type: 'seller', order: $order));
    }

    public function test_a_customer_whose_app_language_has_a_translation_is_written_to_in_that_language(): void
    {
        $customer = $this->makeCustomer(['app_language' => 'ar', 'cm_firebase_token' => 'device-of-the-arabic-customer']);

        $this->pendingNotificationToTheCustomerOf($this->placeOrderOf($customer));

        $this->assertOnePushWithTheBody(expectedBody: 'طلبك رقم 100001 قيد الانتظار', expectedDevice: 'device-of-the-arabic-customer');
    }

    public function test_a_customer_whose_language_has_no_translation_row_gets_the_global_message(): void
    {
        $customer = $this->makeCustomer(['app_language' => 'bd', 'cm_firebase_token' => 'device-of-the-bengali-customer']);

        $this->pendingNotificationToTheCustomerOf($this->placeOrderOf($customer));

        $this->assertOnePushWithTheBody(expectedBody: 'Order 100001 is pending', expectedDevice: 'device-of-the-bengali-customer');
    }

    public function test_a_customer_with_no_app_language_gets_the_global_message_when_the_default_language_has_no_translation(): void
    {
        $customer = $this->makeCustomer(['app_language' => null, 'cm_firebase_token' => 'device-without-a-language']);

        $this->pendingNotificationToTheCustomerOf($this->placeOrderOf($customer));

        $this->assertOnePushWithTheBody(expectedBody: 'Order 100001 is pending', expectedDevice: 'device-without-a-language');
    }

    public function test_a_customer_with_no_app_language_is_written_to_in_the_default_language_of_the_store_when_it_has_a_translation(): void
    {
        $this->setStoreLanguages(default: 'sa');
        $customer = $this->makeCustomer(['app_language' => null, 'cm_firebase_token' => 'device-without-a-language']);

        $this->pendingNotificationToTheCustomerOf($this->placeOrderOf($customer));

        $this->assertOnePushWithTheBody(expectedBody: 'طلب 100001 بانتظار التأكيد', expectedDevice: 'device-without-a-language');
    }

    public function test_the_language_is_the_recipients_own_and_not_the_default_language_of_the_store(): void
    {
        $this->setStoreLanguages(default: 'sa');
        $customer = $this->makeCustomer(['app_language' => 'bd', 'cm_firebase_token' => 'device-of-the-bengali-customer']);

        $this->pendingNotificationToTheCustomerOf($this->placeOrderOf($customer));

        $this->assertOnePushWithTheBody(expectedBody: 'Order 100001 is pending', expectedDevice: 'device-of-the-bengali-customer');
    }

    /** @return array<string, array{0: ?string, 1: string}> the seller's app language and the body the seller is sent */
    public static function sellerLanguages(): array
    {
        return [
            'a seller whose language has a translation' => ['ar', 'لديك طلب جديد 100001'],
            'a seller whose language has none' => ['bd', 'A new order 100001 is waiting'],
            'a seller with no language' => [null, 'A new order 100001 is waiting'],
        ];
    }

    #[DataProvider('sellerLanguages')]
    public function test_a_seller_is_written_to_in_the_sellers_own_language_whatever_the_language_of_the_customer(?string $sellerLanguage, string $expectedBody): void
    {
        $sellerId = DB::table('sellers')->insertGetId(['f_name' => 'Seller one', 'status' => 'approved', 'cm_firebase_token' => 'device-of-the-seller', 'app_language' => $sellerLanguage, 'created_at' => now(), 'updated_at' => now()]);
        $customer = $this->makeCustomer(['app_language' => 'ar', 'cm_firebase_token' => 'device-of-the-arabic-customer']);

        $this->newOrderNotificationToTheSellerOf($this->placeOrderOf($customer, $sellerId));

        $this->assertOnePushWithTheBody(expectedBody: $expectedBody, expectedDevice: 'device-of-the-seller');
    }
}
