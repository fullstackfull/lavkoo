<?php

namespace Tests\Feature\Storefront;

use App\Events\OrderEditDuePaymentEvent;
use App\Events\OrderStatusEvent;
use App\Models\Order;
use App\Services\Storefront\StorefrontContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * What the platform sends about an order today, recorded before anything binds a storefront around a notification (I1-06).
 *
 * The two switches of PR 87 — gcc.storefronts.enabled and gcc.storefronts.currency — are off here, as they ship, and an order that
 * carries a storefront record is notified exactly as an order without one: the title in the store's own language, and the delivery charge
 * converted into the platform's currency. The last two pins hold with the switches on, for an order that records no storefront and for the
 * one listener that sends nothing at all.
 */
class OrderNotificationTodayTest extends OrderNotificationTestCase
{
    public function test_with_the_switches_off_a_push_about_an_order_of_another_market_is_the_one_the_platform_sends_today(): void
    {
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
        ]);

        $this->countTheQueriesOfTheNotification();
        event(new OrderStatusEvent(key: 'pending', type: 'customer', order: $order));

        $push = $this->theOnePushSent();
        $this->assertSame(self::FCM_SEND_URL, $push->url());
        $this->assertSame('customer-device', $push['message']['token']);
        $this->assertSame(self::TITLE_IN_ENGLISH, $push['message']['notification']['title']);
        $this->assertSame('Order ' . self::KW_ORDER . ' is pending', $push['message']['notification']['body']);
        $this->assertFalse(app()->bound(StorefrontContext::class), 'nothing was bound');
        $this->assertSame('KW', DB::table('orders')->where('id', self::KW_ORDER)->value('storefront_country'), 'the order does carry a record');
        $this->assertSame(self::QUERIES_OF_A_CUSTOMER_PUSH, $this->queriesOfTheNotification, 'the switched-off platform asks the database nothing extra');
    }

    public function test_with_the_switches_off_the_delivery_charge_in_a_push_is_the_platform_conversion_it_has_always_been(): void
    {
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
            'delivery_man_id' => $this->deliveryMan(id: 1, token: 'kw-rider', language: null)->id,
            'deliveryman_charge' => self::CHARGE_STORED,
        ]);

        event(new OrderStatusEvent(key: 'new_order_assigned_message', type: 'delivery_man', order: $order));

        $push = $this->theOnePushSent();
        $this->assertSame('kw-rider', $push['message']['token']);
        $this->assertSame(self::CHARGE_CONVERTED_TO_THE_PLATFORM_CURRENCY, $push['message']['data']['deliveryman_charge']);
        $this->assertSame(self::TITLE_IN_ENGLISH, $push['message']['notification']['title']);
    }

    public function test_with_the_switches_off_a_queued_order_email_keeps_the_title_of_the_store_language(): void
    {
        Mail::fake();
        $this->seedOrderPlacedEmailTemplate(titleInEnglish: 'Your order is placed', titleInArabic: 'تم إنشاء طلبك');
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
        ]);

        $this->placeOrderMailFor($order);

        $this->assertSame('Your order is placed', $this->titleOfTheEmailSent());
    }

    /** The amounts in the body of the same e-mail today: the platform's conversion of every line, with the platform's symbol. */
    public function test_with_the_switches_off_the_email_body_of_an_order_of_another_market_is_the_platform_conversion(): void
    {
        $this->captureTheEmailsTheMailerRenders();
        $this->seedOrderPlacedEmailTemplate(titleInEnglish: 'Your order is placed', titleInArabic: 'تم إنشاء طلبك', design: 'order-place');
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
        ]);

        $this->placeOrderMailFor($order);

        $body = $this->bodyOfTheEmailTheMailerSent();
        $this->assertStringContainsString(self::LINE_CONVERTED_TO_THE_PLATFORM_CURRENCY, $body);
        $this->assertStringNotContainsString(self::LINE_AS_THE_ORDER_HOLDS_IT, $body);
    }

    public function test_an_order_with_no_storefront_record_is_notified_as_the_default_storefront(): void
    {
        $this->switchStorefrontsOn();
        $this->insertOrder(self::SA_ORDER, [
            'customer_id' => $this->customerWhoReadsArabic()->id,
            'delivery_man_id' => $this->deliveryMan(id: 1, token: 'legacy-rider', language: null)->id,
            'deliveryman_charge' => self::CHARGE_STORED,
        ]);

        event(new OrderStatusEvent(key: 'new_order_assigned_message', type: 'delivery_man', order: Order::findOrFail(self::SA_ORDER)));

        $push = $this->theOnePushSent();
        $this->assertNull(DB::table('orders')->where('id', self::SA_ORDER)->value('storefront_country'), 'the order carries no record');
        $this->assertSame(self::CHARGE_CONVERTED_TO_THE_PLATFORM_CURRENCY, $push['message']['data']['deliveryman_charge']);
        $this->assertSame(self::TITLE_IN_ENGLISH, $push['message']['notification']['title']);
    }

    /**
     * The due-payment listener sends nothing at all today, in any storefront: PushNotificationTrait::pushNotificationMessage has no entry
     * for 'order_edit_due_payment_message', the only key OrderManager::sendPushNotificationAfterDuePayment raises, so the lookup asks for a
     * message with no key and finds none. Recorded, not repaired: the missing entry is not this branch's work.
     */
    public function test_the_due_payment_listener_sends_no_push_in_any_storefront(): void
    {
        $this->switchStorefrontsOn();
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
            'seller_id' => $this->sellerWhoReadsArabic()->id,
            'seller_is' => 'seller',
        ]);

        event(new OrderEditDuePaymentEvent(notification: (object) ['key' => 'order_edit_due_payment_message', 'type' => 'seller', 'order' => $order]));

        $this->assertSame([], $this->pushesSent());
    }
}
