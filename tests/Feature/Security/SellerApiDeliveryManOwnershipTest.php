<?php

namespace Tests\Feature\Security;

use App\Events\ChattingEvent;
use App\Events\OrderStatusEvent;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSellerApiOrders;
use Tests\TestCase;

/**
 * A seller hands an order to a delivery man and writes to one, naming the delivery man by an id the app sends. A delivery man works for one
 * shop, so each handler must also check that the delivery man is the caller's own: another shop's driver and the in-house shop's driver (whose
 * seller_id is 0) are not found, and no order is handed to them and no message and no push notification reaches them. The order is checked
 * the same way as everywhere else: another seller's order and the in-house order whose seller_id equals the caller's id are not found, instead
 * of crashing or being handed a driver.
 */
class SellerApiDeliveryManOwnershipTest extends TestCase
{
    use BuildsSellerApiOrders;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([OrderStatusEvent::class, ChattingEvent::class]);
        $this->buildSellerApiWorld();
        $this->createSellerApiOrderTables();
        $this->createTablesTheHandlersReadFrom();
        $this->seedSellerApiOrders();
        DB::table('shops')->insert([['seller_id' => self::SELLER, 'name' => 'Shop'], ['seller_id' => self::OTHER_SELLER, 'name' => 'Other shop']]);
    }

    protected function tearDown(): void
    {
        $this->tearDownSellerApiWorld();

        parent::tearDown();
    }

    // ---- handing an order to a delivery man: API v3 ----

    public function test_v3_another_shops_delivery_man_cannot_be_assigned_to_the_sellers_order(): void
    {
        $response = $this->sellerApi('PUT', 'orders/assign-delivery-man', ['order_id' => self::OWN_ORDER, 'delivery_man_id' => self::OTHER_SELLERS_DELIVERY_MAN]);

        $this->assertSame([null, null], $this->deliveryOf(self::OWN_ORDER));
        Event::assertNotDispatched(OrderStatusEvent::class);
        $this->assertNotFound($response);
    }

    public function test_v3_the_in_house_shops_delivery_man_cannot_be_assigned_to_the_sellers_order(): void
    {
        $response = $this->sellerApi('PUT', 'orders/assign-delivery-man', ['order_id' => self::OWN_ORDER, 'delivery_man_id' => self::IN_HOUSE_DELIVERY_MAN]);

        $this->assertSame([null, null], $this->deliveryOf(self::OWN_ORDER));
        $this->assertNotFound($response);
    }

    public function test_v3_the_in_house_order_sharing_the_sellers_id_cannot_be_handed_to_the_sellers_delivery_man(): void
    {
        $response = $this->sellerApi('PUT', 'orders/assign-delivery-man', ['order_id' => self::IN_HOUSE_ORDER, 'delivery_man_id' => self::OWN_DELIVERY_MAN]);

        $this->assertSame([self::IN_HOUSE_DELIVERY_MAN, null], $this->deliveryOf(self::IN_HOUSE_ORDER));
        Event::assertNotDispatched(OrderStatusEvent::class);
        $this->assertNotFound($response);
    }

    public function test_v3_another_sellers_order_is_not_found_instead_of_crashing_and_keeps_its_delivery_man(): void
    {
        $response = $this->sellerApi('PUT', 'orders/assign-delivery-man', ['order_id' => self::OTHER_SELLERS_ORDER, 'delivery_man_id' => self::OWN_DELIVERY_MAN]);

        $this->assertSame([self::OTHER_SELLERS_DELIVERY_MAN, null], $this->deliveryOf(self::OTHER_SELLERS_ORDER));
        $this->assertNotFound($response);
    }

    public function test_v3_an_order_that_does_not_exist_is_not_found_instead_of_crashing(): void
    {
        $response = $this->sellerApi('PUT', 'orders/assign-delivery-man', ['order_id' => 999, 'delivery_man_id' => self::OWN_DELIVERY_MAN]);

        $this->assertNotFound($response);
    }

    public function test_v3_the_seller_can_still_assign_their_own_delivery_man_to_their_own_order(): void
    {
        $response = $this->sellerApi('PUT', 'orders/assign-delivery-man', ['order_id' => self::OWN_ORDER, 'delivery_man_id' => self::OWN_DELIVERY_MAN]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $response->json('success'));
        $this->assertSame([self::OWN_DELIVERY_MAN, 'self_delivery'], $this->deliveryOf(self::OWN_ORDER));
        $this->assertNotNull(DB::table('orders')->where('id', self::OWN_ORDER)->value('deliveryman_assigned_at'));
        Event::assertDispatched(OrderStatusEvent::class, fn (OrderStatusEvent $event) => $event->order['id'] === self::OWN_ORDER && $event->type === 'delivery_man');
    }

    public function test_v3_the_order_details_update_cannot_hand_the_order_to_another_shops_delivery_man(): void
    {
        $response = $this->sellerApi('POST', 'orders/order-detail-info-update', ['order_id' => self::OWN_ORDER, 'payment_status' => 'unpaid', 'delivery_man_id' => self::OTHER_SELLERS_DELIVERY_MAN]);

        $this->assertSame([null, null], $this->deliveryOf(self::OWN_ORDER));
        Event::assertNotDispatched(OrderStatusEvent::class);
        $this->assertNotFound($response);
    }

    public function test_v3_the_seller_can_still_hand_their_own_order_to_their_own_delivery_man_through_the_order_details_update(): void
    {
        $response = $this->sellerApi('POST', 'orders/order-detail-info-update', ['order_id' => self::OWN_ORDER, 'payment_status' => 'unpaid', 'delivery_man_id' => self::OWN_DELIVERY_MAN]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([self::OWN_DELIVERY_MAN, 'self_delivery'], $this->deliveryOf(self::OWN_ORDER));
        Event::assertDispatched(OrderStatusEvent::class, fn (OrderStatusEvent $event) => $event->order['id'] === self::OWN_ORDER && $event->type === 'delivery_man');
    }

    // ---- handing an order to a delivery man: API v2 ----

    public function test_v2_another_shops_delivery_man_cannot_be_assigned_to_the_sellers_order(): void
    {
        $response = $this->sellerApiV2('PUT', 'orders/assign-delivery-man', ['order_id' => self::OWN_ORDER, 'delivery_man_id' => self::OTHER_SELLERS_DELIVERY_MAN]);

        $this->assertSame([null, null], $this->deliveryOf(self::OWN_ORDER));
        Event::assertNotDispatched(OrderStatusEvent::class);
        $this->assertNotFound($response);
    }

    public function test_v2_the_in_house_order_sharing_the_sellers_id_cannot_be_handed_to_the_sellers_delivery_man(): void
    {
        $response = $this->sellerApiV2('PUT', 'orders/assign-delivery-man', ['order_id' => self::IN_HOUSE_ORDER, 'delivery_man_id' => self::OWN_DELIVERY_MAN]);

        $this->assertSame([self::IN_HOUSE_DELIVERY_MAN, null], $this->deliveryOf(self::IN_HOUSE_ORDER));
        Event::assertNotDispatched(OrderStatusEvent::class);
        $this->assertNotFound($response);
    }

    public function test_v2_another_sellers_order_is_not_found_instead_of_crashing_and_keeps_its_delivery_man(): void
    {
        $response = $this->sellerApiV2('PUT', 'orders/assign-delivery-man', ['order_id' => self::OTHER_SELLERS_ORDER, 'delivery_man_id' => self::OWN_DELIVERY_MAN]);

        $this->assertSame([self::OTHER_SELLERS_DELIVERY_MAN, null], $this->deliveryOf(self::OTHER_SELLERS_ORDER));
        $this->assertNotFound($response);
    }

    public function test_v2_the_seller_can_still_assign_their_own_delivery_man_to_their_own_order(): void
    {
        $response = $this->sellerApiV2('PUT', 'orders/assign-delivery-man', ['order_id' => self::OWN_ORDER, 'delivery_man_id' => self::OWN_DELIVERY_MAN]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $response->json('success'));
        $this->assertSame([self::OWN_DELIVERY_MAN, 'self_delivery'], $this->deliveryOf(self::OWN_ORDER));
        Event::assertDispatched(OrderStatusEvent::class, fn (OrderStatusEvent $event) => $event->order['id'] === self::OWN_ORDER && $event->type === 'delivery_man');
    }

    // ---- writing to a delivery man ----

    /** @return array<string, array{0: string}> */
    public static function apiVersions(): array
    {
        return ['v3' => ['v3'], 'v2' => ['v2']];
    }

    #[DataProvider('apiVersions')]
    public function test_a_message_to_another_shops_delivery_man_is_not_found_stored_or_pushed(string $version): void
    {
        $response = $this->sendMessage($version, self::OTHER_SELLERS_DELIVERY_MAN);

        $this->assertSame(0, DB::table('chattings')->count());
        Event::assertNotDispatched(ChattingEvent::class);
        $this->assertNotFound($response);
    }

    #[DataProvider('apiVersions')]
    public function test_a_message_to_the_in_house_shops_delivery_man_is_not_found_stored_or_pushed(string $version): void
    {
        $response = $this->sendMessage($version, self::IN_HOUSE_DELIVERY_MAN);

        $this->assertSame(0, DB::table('chattings')->count());
        Event::assertNotDispatched(ChattingEvent::class);
        $this->assertNotFound($response);
    }

    #[DataProvider('apiVersions')]
    public function test_a_message_to_a_delivery_man_that_does_not_exist_is_not_found_instead_of_crashing(string $version): void
    {
        $response = $this->sendMessage($version, 999);

        $this->assertSame(0, DB::table('chattings')->count());
        $this->assertNotFound($response);
    }

    #[DataProvider('apiVersions')]
    public function test_the_seller_can_still_write_to_their_own_delivery_man(string $version): void
    {
        $response = $this->sendMessage($version, self::OWN_DELIVERY_MAN);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Come to the shop', $response->json('message'));
        $this->assertSame(
            [['seller_id' => self::SELLER, 'delivery_man_id' => self::OWN_DELIVERY_MAN, 'message' => 'Come to the shop', 'notification_receiver' => 'deliveryman']],
            DB::table('chattings')->get(['seller_id', 'delivery_man_id', 'message', 'notification_receiver'])->map(fn ($row) => (array) $row)->all(),
        );
        Event::assertDispatched(ChattingEvent::class, fn (ChattingEvent $event) => $event->type === 'delivery_man' && $event->userData['id'] === self::OWN_DELIVERY_MAN);
    }

    // ---- helpers ----

    private function assertNotFound(TestResponse $response): void
    {
        $this->assertSame(404, $response->getStatusCode());
    }

    private function sendMessage(string $version, int $deliveryManId): TestResponse
    {
        $data = ['id' => $deliveryManId, 'message' => 'Come to the shop'];

        return $version === 'v3'
            ? $this->sellerApi('POST', 'messages/send/delivery-man', $data)
            : $this->sellerApiV2('POST', 'messages/send/delivery-man', $data);
    }

    private function createTablesTheHandlersReadFrom(): void
    {
        $this->createSellerApiTable('shops', function (Blueprint $table) {
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('chattings', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->text('message')->nullable();
            $table->text('attachment')->nullable();
            $table->boolean('sent_by_seller')->default(false);
            $table->boolean('seen_by_seller')->default(true);
            $table->boolean('seen_by_customer')->default(true);
            $table->boolean('seen_by_delivery_man')->default(true);
            $table->string('notification_receiver')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('users', fn (Blueprint $table) => $table->string('f_name')->nullable());
        $this->createSellerApiTable('order_edit_histories', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id');
            $table->decimal('order_due_amount', 16, 2)->default(0);
            $table->decimal('order_return_amount', 16, 2)->default(0);
            $table->string('order_due_payment_method')->nullable();
            $table->string('order_due_payment_status')->nullable();
            $table->string('order_return_payment_status')->nullable();
            $table->timestamps();
        });
    }
}
