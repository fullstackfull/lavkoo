<?php

namespace Tests\Feature\Notifications;

use App\Events\OrderStatusEvent;
use App\Models\NotificationMessage;
use App\Models\Order;
use App\Models\User;
use App\Services\Storefront\OrderNotificationContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\FakesThePushService;

/**
 * K-11: a push about an order says which market the order belongs to, so opening it opens that market.
 *
 * Without it an app serving several markets has to guess, and the guess it makes is the market the person happens to be looking at -- so a
 * tap on a Kuwaiti order opens a Saudi screen, or asks the server for a Kuwaiti order with a Saudi header and is told it does not exist.
 *
 * The market is the one the notification is already SENT under (`OrderNotificationContext`), not a second reading of the record: the money,
 * the language, the selling company and now the market a tap opens all come from one answer, so they cannot disagree about one order.
 *
 * The first test is the one that protects what exists: with storefronts off the payload is exactly the payload this platform has always sent
 * -- no key at all, nothing for an older app to misread and nothing to strip.
 *
 * The push service is faked at its HTTP boundary, so what is read here is the body that would have left the server.
 */
class PushMarketTest extends ApiAddressTestCase
{
    use FakesThePushService;

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
        Schema::create('delivery_men', static function (Blueprint $table): void {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('fcm_token')->nullable();
            $table->string('app_language')->nullable();
            $table->timestamps();
        });
        Schema::create('deliveryman_notifications', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('notification_messages', static function (Blueprint $table): void {
            $table->id();
            $table->string('user_type');
            $table->string('key');
            $table->text('message')->nullable();
            $table->integer('status')->default(1);
            $table->timestamps();
        });

        // The same keys the language test uses: a customer's `pending` event looks up the message named `order_pending_message`.
        NotificationMessage::create(['user_type' => 'customer', 'key' => 'order_pending_message', 'message' => 'Order {orderId} is pending', 'status' => 1]);
        NotificationMessage::create(['user_type' => 'seller', 'key' => 'new_order_message', 'message' => 'A new order {orderId}', 'status' => 1]);
        NotificationMessage::create(['user_type' => 'delivery_man', 'key' => 'new_order_assigned_message', 'message' => 'Order {orderId} is yours', 'status' => 1]);

        $this->configureThePushService();
    }

    /** With storefronts off the payload is the one this platform has always sent. */
    public function test_with_storefronts_off_the_payload_carries_no_market_at_all(): void
    {
        $order = $this->placeOrderOf($this->customerWithADevice(), market: 'KW');

        event(new OrderStatusEvent(key: 'pending', type: 'customer', order: $order));

        $data = $this->onePushData();
        $this->assertArrayNotHasKey('storefront', $data, 'no key, so there is nothing for an older app to misread');
        $this->assertSame('order', $data['type'], 'and everything else is unchanged');
        $this->assertSame((string) self::ORDER_ID, (string) $data['order_id']);
    }

    public function test_a_push_about_an_order_carries_the_market_that_order_was_sold_in(): void
    {
        $this->storefrontsOn();
        $order = $this->placeOrderOf($this->customerWithADevice(), market: 'KW');

        event(new OrderStatusEvent(key: 'pending', type: 'customer', order: $order));

        $this->assertSame('KW', $this->onePushData()['storefront']);
    }

    /** The seller's push carries it too, which is the one K-11 asks for: the seller app serves several markets. */
    public function test_the_sellers_push_carries_the_market(): void
    {
        $this->storefrontsOn();
        $seller = $this->sellerWithADevice();
        $order = $this->placeOrderOf($this->customerWithADevice(), market: 'KW', sellerId: $seller);

        event(new OrderStatusEvent(key: 'new_order_message', type: 'seller', order: $order));

        $this->assertSame('KW', $this->onePushData()['storefront']);
    }

    /** And the delivery man's, because a parcel of one market is not another's to pick up. */
    public function test_the_delivery_mans_push_carries_the_market(): void
    {
        $this->storefrontsOn();
        $deliveryMan = $this->deliveryManWithADevice();
        $order = $this->placeOrderOf($this->customerWithADevice(), market: 'KW', deliveryManId: $deliveryMan);

        event(new OrderStatusEvent(key: 'new_order_assigned_message', type: 'delivery_man', order: $order));

        $this->assertSame('KW', $this->onePushData()['storefront']);
    }

    /**
     * An order that records no market is the DEFAULT market's, which is every order placed before storefronts existed.
     *
     * The default is named rather than left out, deliberately: an app that reads no key cannot tell "this order has no market" from "this
     * server is too old to say", and one of those answers means it must not switch and the other means it may.
     */
    public function test_an_order_that_records_no_market_is_the_default_markets(): void
    {
        $this->storefrontsOn();
        $order = $this->placeOrderOf($this->customerWithADevice(), market: null);

        event(new OrderStatusEvent(key: 'pending', type: 'customer', order: $order));

        $this->assertSame('SA', $this->onePushData()['storefront']);
    }

    /** The market comes from the order and not from whatever market the sending process happens to be serving. */
    public function test_the_market_is_the_orders_and_not_the_one_the_sender_was_serving(): void
    {
        $this->storefrontsOn();
        $order = $this->placeOrderOf($this->customerWithADevice(), market: 'BH');

        // A worker that handled a Kuwaiti order a moment ago, as a queue worker handling a batch does.
        app()->instance(\App\Services\Storefront\StorefrontContext::class, new \App\Services\Storefront\StorefrontContext(country: 'KW', currency: 'KWD', storefrontsEnabled: true));

        event(new OrderStatusEvent(key: 'pending', type: 'customer', order: $order));

        $this->assertSame('BH', $this->onePushData()['storefront'], 'the order decides, not the process');
    }

    /** And the rule itself, asked directly, so the one place that answers it is pinned as well as its callers. */
    public function test_the_rule_answers_an_array_so_nothing_is_sent_when_there_is_nothing_to_say(): void
    {
        $this->assertSame([], OrderNotificationContext::marketOf(order: ['storefront_country' => 'KW']), 'storefronts are off');

        $this->storefrontsOn();

        $this->assertSame(['storefront' => 'KW'], OrderNotificationContext::marketOf(order: ['storefront_country' => 'KW']));
        $this->assertSame(['storefront' => 'SA'], OrderNotificationContext::marketOf(order: ['storefront_country' => null]), 'the default market');
    }

    /** @return array<string, mixed> */
    private function onePushData(): array
    {
        $pushes = $this->pushesSent();

        $this->assertCount(1, $pushes, 'exactly one push left the server');

        return $pushes[0]['message']['data'];
    }

    private function storefrontsOn(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.default_country' => 'SA']);
    }

    private function customerWithADevice(): User
    {
        return $this->makeCustomer(['cm_firebase_token' => 'a-customer-device']);
    }

    private function sellerWithADevice(): int
    {
        return (int) DB::table('sellers')->insertGetId([
            'f_name' => 'A shop', 'status' => 'approved', 'cm_firebase_token' => 'a-seller-device', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function deliveryManWithADevice(): int
    {
        return (int) DB::table('delivery_men')->insertGetId([
            'f_name' => 'A rider', 'fcm_token' => 'a-rider-device', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function placeOrderOf(User $customer, ?string $market, ?int $sellerId = null, ?int $deliveryManId = null): Order
    {
        if (!Schema::hasColumn('orders', 'storefront_country')) {
            (require database_path('migrations/2026_11_26_100000_add_storefront_snapshot_to_orders_table.php'))->up();
        }

        DB::table('orders')->insert([
            'id' => self::ORDER_ID,
            'customer_id' => $customer->id,
            'seller_id' => $sellerId,
            'seller_is' => $sellerId === null ? 'admin' : 'seller',
            'delivery_man_id' => $deliveryManId,
            'storefront_country' => $market,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Cache::flush();

        return Order::findOrFail(self::ORDER_ID);
    }
}
