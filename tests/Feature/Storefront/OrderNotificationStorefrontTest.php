<?php

namespace Tests\Feature\Storefront;

use App\Events\OrderEditEvent;
use App\Events\OrderEditReturnPaymentEvent;
use App\Events\OrderStatusEvent;
use App\Events\RefundEvent;
use App\Models\Order;
use App\Services\Storefront\OrderNotificationContext;
use App\Services\Storefront\StorefrontContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * I1-06: a notification about an order is built from the storefront the order was sold in, and a queued listener does not leave that
 * storefront bound for the next order's notification, nor take it from the request that queued the job.
 *
 * Everything asserted here is read out of the FCM request that would have left the server, or out of the mailable the platform handed to
 * the mailer. The orders are sold through the platform's own snapshot writer, and the notifications are driven through the real events, so
 * the queued listeners go through Laravel's queue and run one after another in this one process, as a worker runs them.
 *
 * Every test here has both switches of PR 87 on — gcc.storefronts.enabled and gcc.storefronts.currency, which ship off. What the same
 * notifications carry while they are off is pinned next door, in OrderNotificationTodayTest.
 */
class OrderNotificationStorefrontTest extends OrderNotificationTestCase
{

    public function test_an_order_with_no_storefront_record_is_notified_as_the_default_storefront_even_from_a_request_of_another_market(): void
    {
        $this->switchStorefrontsOn();
        $this->serveRequestFrom('KW');
        $this->insertOrder(self::SA_ORDER, [
            'customer_id' => $this->customerWhoReadsArabic()->id,
            'delivery_man_id' => $this->deliveryMan(id: 1, token: 'legacy-rider', language: null)->id,
            'deliveryman_charge' => self::CHARGE_STORED,
        ]);

        event(new OrderStatusEvent(key: 'new_order_assigned_message', type: 'delivery_man', order: Order::findOrFail(self::SA_ORDER)));

        $this->assertSame(self::CHARGE_CONVERTED_TO_THE_PLATFORM_CURRENCY, $this->theOnePushSent()['message']['data']['deliveryman_charge']);
        $this->assertSame('KW', StorefrontContext::current()->country, 'the request keeps its own storefront');
    }

    public function test_two_orders_of_different_storefronts_notified_by_one_worker_do_not_inherit_each_other(): void
    {
        $this->switchStorefrontsOn();
        $this->serveRequestFrom('AE');
        $kuwaitOrder = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
            'delivery_man_id' => $this->deliveryMan(id: 1, token: 'kw-rider', language: 'sa')->id,
            'deliveryman_charge' => self::CHARGE_STORED,
        ]);
        $saudiOrder = $this->sellOrderIn(country: 'SA', orderId: self::SA_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
            'delivery_man_id' => $this->deliveryMan(id: 2, token: 'sa-rider', language: null)->id,
            'deliveryman_charge' => self::CHARGE_STORED,
        ]);

        $this->queueTheOrderPlacedNotification($kuwaitOrder);
        $this->queueTheOrderPlacedNotification($saudiOrder);

        $pushes = $this->pushesSent();
        $this->assertCount(2, $pushes);
        $this->assertSame('kw-rider', $pushes[0]['message']['token']);
        $this->assertSame(self::CHARGE_AS_THE_ORDER_HOLDS_IT, $pushes[0]['message']['data']['deliveryman_charge'], 'the first job is in its own market money');
        $this->assertSame(self::TITLE_IN_ARABIC, $pushes[0]['message']['notification']['title'], 'the first job is in its own rider language');
        $this->assertSame('sa-rider', $pushes[1]['message']['token']);
        $this->assertSame(self::CHARGE_CONVERTED_TO_THE_PLATFORM_CURRENCY, $pushes[1]['message']['data']['deliveryman_charge'], 'the second job did not inherit the first market, nor take the request market');
        $this->assertSame(self::TITLE_IN_ENGLISH, $pushes[1]['message']['notification']['title'], 'the second job did not inherit the first language');
        $this->assertSame('AE', StorefrontContext::current()->country, 'the request that queued them has its own storefront back');
    }

    public function test_a_queued_notification_does_not_take_the_storefront_of_the_request_that_queued_it(): void
    {
        $this->switchStorefrontsOn();
        $this->serveRequestFrom('SA');
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
            'delivery_man_id' => $this->deliveryMan(id: 1, token: 'kw-rider', language: null)->id,
            'deliveryman_charge' => self::CHARGE_STORED,
        ]);

        $this->queueTheOrderPlacedNotification($order);

        $this->assertSame(self::CHARGE_AS_THE_ORDER_HOLDS_IT, $this->theOnePushSent()['message']['data']['deliveryman_charge']);
        $this->assertSame('SA', StorefrontContext::current()->country);
    }

    public function test_the_delivery_charge_in_a_push_about_an_order_of_a_market_with_its_own_currency_is_the_money_the_order_holds(): void
    {
        $this->switchStorefrontsOn();
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
            'delivery_man_id' => $this->deliveryMan(id: 1, token: 'kw-rider', language: null)->id,
            'deliveryman_charge' => self::CHARGE_STORED,
        ]);

        event(new OrderStatusEvent(key: 'new_order_assigned_message', type: 'delivery_man', order: $order));

        $this->assertSame(self::CHARGE_AS_THE_ORDER_HOLDS_IT, $this->theOnePushSent()['message']['data']['deliveryman_charge']);
        $this->assertSame('KWD', DB::table('orders')->where('id', self::KW_ORDER)->value('currency_code'), 'which is the currency the order records');
    }

    public function test_the_refund_the_edit_and_the_return_payment_listeners_each_build_their_push_in_the_storefront_of_the_order(): void
    {
        $this->switchStorefrontsOn();
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
            'seller_is' => 'admin',
        ]);

        event(new RefundEvent(status: 'refunded', order: $order, refund: ['id' => 5], orderDetails: null));
        event(new OrderEditEvent(notification: (object) ['key' => 'order_edit_message', 'type' => 'customer', 'order' => $order]));
        event(new OrderEditReturnPaymentEvent(notification: (object) ['key' => 'order_edit_return_amount_message', 'type' => 'customer', 'order' => $order]));

        $pushes = $this->pushesSent();
        $this->assertCount(3, $pushes);
        $this->assertSame([
            'Order ' . self::KW_ORDER . ' was refunded',
            'Order ' . self::KW_ORDER . ' was edited',
            'Order ' . self::KW_ORDER . ' has an amount to return',
        ], array_map(static fn ($push): string => $push['message']['notification']['body'], $pushes));

        foreach ($pushes as $index => $push) {
            $this->assertSame(self::TITLE_IN_ARABIC, $push['message']['notification']['title'], "push {$index} is in the language of the order's recipient");
            $this->assertSame('', $push['message']['data']['deliveryman_charge'], "push {$index} carries no amount of money at all");
        }
    }

    public function test_a_refund_notifies_the_seller_of_the_order_in_the_sellers_own_language(): void
    {
        $this->switchStorefrontsOn();
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
            'seller_id' => $this->sellerWhoReadsArabic()->id,
            'seller_is' => 'seller',
        ]);

        event(new RefundEvent(status: 'refunded', order: $order, refund: ['id' => 5], orderDetails: null));

        $pushes = $this->pushesSent();
        $this->assertCount(2, $pushes);
        $this->assertSame('seller-device', $pushes[0]['message']['token']);
        $this->assertSame(self::TITLE_IN_ARABIC, $pushes[0]['message']['notification']['title'], 'the seller is written to in the language the seller reads');
        $this->assertSame('Order ' . self::KW_ORDER . ' was refunded to the buyer', $pushes[0]['message']['notification']['body']);
        $this->assertSame('customer-device', $pushes[1]['message']['token']);
        $this->assertSame(self::TITLE_IN_ARABIC, $pushes[1]['message']['notification']['title']);
    }

    /**
     * A push about an order sent while an API request is being served: there getDefaultLanguage() answers with the application locale and
     * not with the session, so the locale has to follow the order's recipient as well.
     */
    public function test_a_push_sent_while_an_api_request_is_served_is_written_in_the_language_of_its_recipient(): void
    {
        $this->switchStorefrontsOn();
        URL::setRequest(Request::create('https://shop.test/api/v1/order/place', 'POST'));
        App::setLocale('en');
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
        ]);

        event(new OrderStatusEvent(key: 'pending', type: 'customer', order: $order));

        $this->assertSame(self::TITLE_IN_ARABIC, $this->theOnePushSent()['message']['notification']['title']);
    }

    public function test_the_title_of_a_push_about_an_order_is_written_in_the_language_of_its_recipient(): void
    {
        $this->switchStorefrontsOn();
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
        ]);

        event(new OrderStatusEvent(key: 'pending', type: 'customer', order: $order));

        $this->assertSame(self::TITLE_IN_ARABIC, $this->theOnePushSent()['message']['notification']['title']);
        $this->assertNull(session('local'), 'the language the sender was given is put back');
    }

    public function test_a_queued_order_email_is_written_in_the_language_of_its_recipient(): void
    {
        Mail::fake();
        $this->switchStorefrontsOn();
        $this->seedOrderPlacedEmailTemplate(titleInEnglish: 'Your order is placed', titleInArabic: 'تم إنشاء طلبك');
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
        ]);

        $this->placeOrderMailFor($order);

        $this->assertSame('تم إنشاء طلبك', $this->titleOfTheEmailSent());
        $this->assertNull(session('local'), 'the language the worker was given is put back');
    }

    public function test_the_storefront_and_the_language_are_put_back_when_a_notification_fails(): void
    {
        $this->switchStorefrontsOn();
        $this->serveRequestFrom('AE');
        App::setLocale('en');
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
        ]);

        try {
            OrderNotificationContext::within(order: $order, userType: 'customer', callback: static fn () => throw new RuntimeException('the push failed'));
            $this->fail('the failure was swallowed');
        } catch (RuntimeException) {
            $this->assertSame('AE', StorefrontContext::current()->country);
            $this->assertSame('en', App::getLocale());
            $this->assertNull(session('local'));
        }
    }

    public function test_an_order_whose_record_is_not_a_country_code_is_notified_as_the_default_storefront(): void
    {
        $this->switchStorefrontsOn();
        $this->serveRequestFrom('KW');

        foreach ([null, '', 'ZZZ', ['KW'], 7] as $record) {
            $country = OrderNotificationContext::within(
                order: ['storefront_country' => $record],
                userType: 'customer',
                callback: static fn (): string => StorefrontContext::current()->country,
            );

            $this->assertSame('SA', $country, var_export($record, true));
        }
    }
}
