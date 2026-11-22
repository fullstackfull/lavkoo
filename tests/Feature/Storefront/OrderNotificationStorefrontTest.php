<?php

namespace Tests\Feature\Storefront;

use App\Events\OrderEditDuePaymentEvent;
use App\Events\OrderEditEvent;
use App\Events\OrderEditReturnPaymentEvent;
use App\Events\OrderStatusEvent;
use App\Events\RefundEvent;
use App\Models\Order;
use App\Services\Storefront\OrderNotificationContext;
use App\Services\Storefront\PaymentStorefront;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontCurrency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
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

    public function test_the_refund_the_edit_and_the_return_payment_listeners_each_build_their_push_in_the_language_of_the_orders_recipient(): void
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

    /**
     * The seller and the buyer of one order read different languages, so a notification to the seller that took the buyer's language, or the
     * other way round, shows in the title of the push that left. Both recipients are on the same order and the same refund.
     */
    public function test_a_refund_notifies_the_seller_in_the_sellers_language_and_the_buyer_in_the_buyers(): void
    {
        $this->switchStorefrontsOn();
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->makeCustomer(['cm_firebase_token' => 'customer-device', 'app_language' => 'en'])->id,
            'seller_id' => $this->sellerWhoReads('sa')->id,
            'seller_is' => 'seller',
        ]);

        event(new RefundEvent(status: 'refunded', order: $order, refund: ['id' => 5], orderDetails: null));

        $pushes = $this->pushesSent();
        $this->assertCount(2, $pushes);
        $this->assertSame('seller-device', $pushes[0]['message']['token']);
        $this->assertSame(self::TITLE_IN_ARABIC, $pushes[0]['message']['notification']['title'], 'the seller is written to in the language the seller reads');
        $this->assertSame('Order ' . self::KW_ORDER . ' was refunded to the buyer', $pushes[0]['message']['notification']['body']);
        $this->assertSame('customer-device', $pushes[1]['message']['token']);
        $this->assertSame(self::TITLE_IN_ENGLISH, $pushes[1]['message']['notification']['title'], 'and the buyer in the buyer\'s, which is not the seller\'s');
    }

    /** The refund branch for a request the platform turned down, which sends its own key to the buyer. */
    public function test_a_rejected_refund_notifies_the_buyer_in_the_buyers_own_language(): void
    {
        $this->switchStorefrontsOn();
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
            'seller_is' => 'admin',
        ]);

        event(new RefundEvent(status: 'rejected', order: $order, refund: ['id' => 5], orderDetails: null));

        $push = $this->theOnePushSent();
        $this->assertSame('customer-device', $push['message']['token']);
        $this->assertSame(self::TITLE_IN_ARABIC, $push['message']['notification']['title']);
        $this->assertSame('Order ' . self::KW_ORDER . ' refund was rejected', $push['message']['notification']['body']);
    }

    /**
     * The second e-mail the order-placed flow sends: OrderManager raises one for the buyer and one for the shop, whose userType is 'vendor'
     * unless the shop is the platform itself. That recipient's own language has to be bound as the seller's is.
     */
    public function test_the_order_received_email_of_a_vendor_is_written_in_the_language_the_vendor_reads(): void
    {
        Mail::fake();
        $this->switchStorefrontsOn();
        $this->seedEmailTemplate(templateName: 'order-received', userType: 'vendor', titleInEnglish: 'A new order', titleInArabic: 'طلب جديد');
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->makeCustomer(['cm_firebase_token' => 'customer-device', 'app_language' => 'en'])->id,
            'seller_id' => $this->sellerWhoReads('sa')->id,
            'seller_is' => 'seller',
        ]);

        $this->placeOrderMailFor($order, userType: 'vendor', templateName: 'order-received');

        $this->assertSame('طلب جديد', $this->titleOfTheEmailSent());
    }

    /**
     * A promoter is reached behind the buyer, through the referral record, and reads the platform's own language: the buyer's language is
     * not theirs. This promoter has chosen none, so the sender falls back to the language of the sending process, which must still be the
     * platform's and not the buyer's.
     */
    public function test_a_promoters_push_about_a_buyers_order_is_written_in_the_platform_language_not_the_buyers(): void
    {
        $this->switchStorefrontsOn();
        $buyer = $this->customerWhoReadsArabic();
        $this->promoterWhoReads(customerId: $buyer->id, language: null);
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: ['customer_id' => $buyer->id]);

        event(new OrderStatusEvent(key: 'your_referred_customer_has_been_place_order', type: 'promoter', order: $order));

        $push = $this->theOnePushSent();
        $this->assertSame('promoter-device', $push['message']['token']);
        $this->assertSame(
            str_replace('{orderId}', (string) self::KW_ORDER, self::REFERRAL_TEXT_IN_ENGLISH),
            $push['message']['notification']['body'],
            'the promoter is told in the platform language, although the buyer reads Arabic',
        );
    }

    /**
     * The money of the order, not the money of whatever market the sending process was narrowed to. A panel screen or a job wrapped in
     * money.market sets StorefrontCurrency's own market, and that market outranks the storefront a context binds, so the order's money has
     * to be put in force by name as well. Measured before this was done: the same push carried 112.5 (review A F5, review B F3).
     */
    public function test_a_push_carries_the_orders_money_even_inside_a_process_narrowed_to_another_markets_money(): void
    {
        $this->switchStorefrontsOn();
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
            'delivery_man_id' => $this->deliveryMan(id: 1, token: 'kw-rider', language: null)->id,
            'deliveryman_charge' => self::CHARGE_STORED,
        ]);

        app(StorefrontCurrency::class)->within(country: 'SA', callback: static function () use ($order): void {
            event(new OrderStatusEvent(key: 'new_order_assigned_message', type: 'delivery_man', order: $order));
        });

        $this->assertSame(self::CHARGE_AS_THE_ORDER_HOLDS_IT, $this->theOnePushSent()['message']['data']['deliveryman_charge']);
    }

    /**
     * A recipient who has chosen no language has none bound for them, so the sender takes the language exactly as it would have. The
     * sending process's own language must still come back: getDefaultLanguage() writes the session language when it is empty and translate()
     * rewrites the locale on every call, so without this one order's notification decides the next one's language (review A F7, review B F8).
     */
    public function test_a_notification_to_a_recipient_who_has_chosen_no_language_leaves_the_senders_own_language_untouched(): void
    {
        $this->switchStorefrontsOn();
        App::setLocale('en');
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->makeCustomer(['cm_firebase_token' => 'customer-device', 'app_language' => null])->id,
        ]);

        event(new OrderStatusEvent(key: 'pending', type: 'customer', order: $order));

        $this->assertCount(1, $this->pushesSent());
        $this->assertNull(session('local'), 'nothing the send settled on is left in the session');
        $this->assertSame('en', App::getLocale(), 'and the locale the sender was given is back');
    }

    /**
     * The same leak across several sends, where it bites hardest. translate() rewrites the application locale on every call, and on an /api
     * request getDefaultLanguage() reads that locale back, so a leftover walks: sa becomes ar, ar becomes es, and es is a locale the
     * project's own language-file restorer does not cover, so the next unknown key is written into the working tree and stays there.
     * Measured on the base branch, where the listeners are unwrapped: six sends end at locale 'es' and leave resources/lang/es modified
     * (review B F8). Here the locale a notification was given comes back after every one of them.
     */
    public function test_the_locale_does_not_walk_from_one_send_to_the_next_on_an_api_request(): void
    {
        $this->switchStorefrontsOn();
        URL::setRequest(Request::create('https://shop.test/api/v1/order/place', 'POST'));
        App::setLocale('sa');
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->makeCustomer(['cm_firebase_token' => 'customer-device', 'app_language' => null])->id,
        ]);

        for ($send = 0; $send < 4; $send++) {
            event(new OrderStatusEvent(key: 'pending', type: 'customer', order: $order));
        }

        $this->assertCount(4, $this->pushesSent());
        $this->assertSame('sa', App::getLocale(), 'the locale the sender was given, four sends later');
    }

    /**
     * The due-payment listener's own wrap. Its one production dispatcher raises a key that PushNotificationTrait's map does not hold, so no
     * push has ever left it and no test of that path can tell a wrapped listener from an unwrapped one (the fact is pinned next door, in
     * OrderNotificationTodayTest). The event and the listener here are the real ones; only the key is one the map does hold, which is what
     * makes the wrap itself measurable. The missing entry stays I1-03's work: adding it would start sending a notification that has never
     * been sent.
     */
    public function test_the_due_payment_listener_builds_its_push_in_the_language_of_the_recipient_for_a_key_the_platform_can_send(): void
    {
        $this->switchStorefrontsOn();
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
        ]);

        event(new OrderEditDuePaymentEvent(notification: (object) ['key' => 'order_edit_message', 'type' => 'customer', 'order' => $order]));

        $push = $this->theOnePushSent();
        $this->assertSame('customer-device', $push['message']['token']);
        $this->assertSame(self::TITLE_IN_ARABIC, $push['message']['notification']['title']);
    }

    /** The till's download e-mail is about one order too, and it is queued: the seventh listener (review A F1). */
    public function test_the_digital_product_download_email_is_written_in_the_language_of_the_buyer(): void
    {
        Mail::fake();
        $this->switchStorefrontsOn();
        $this->seedEmailTemplate(templateName: 'digital-product-download', userType: 'customer', titleInEnglish: 'Your download', titleInArabic: 'التحميل');
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
        ]);

        $this->sendTheDownloadMailFor($order);

        $this->assertSame('التحميل', $this->titleOfTheEmailSent());
    }

    /**
     * A record that is a country code the platform does not configure any more: narrowing needs only the code, so that code is bound, but it
     * has no currency in force, so the amounts stay the platform's (review A F6, review B F7).
     */
    public function test_an_order_recorded_in_a_country_the_platform_does_not_configure_is_notified_in_the_platform_money(): void
    {
        $this->switchStorefrontsOn();
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
            'delivery_man_id' => $this->deliveryMan(id: 1, token: 'kw-rider', language: null)->id,
            'deliveryman_charge' => self::CHARGE_STORED,
        ]);
        DB::table('orders')->where('id', self::KW_ORDER)->update(['storefront_country' => 'OM']);

        event(new OrderStatusEvent(key: 'new_order_assigned_message', type: 'delivery_man', order: Order::findOrFail(self::KW_ORDER)));

        $this->assertSame(self::CHARGE_CONVERTED_TO_THE_PLATFORM_CURRENCY, $this->theOnePushSent()['message']['data']['deliveryman_charge']);
    }

    /**
     * A market whose currency was switched off in the panel. The base layer's contract is that such a storefront keeps the platform's
     * settings and may not sell, so the amounts in its notifications are the platform's conversion, not the order's own money, however the
     * order's record reads (review A F6).
     */
    public function test_an_order_of_a_market_whose_currency_is_switched_off_is_notified_in_the_platform_money(): void
    {
        $this->switchStorefrontsOn();
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
            'delivery_man_id' => $this->deliveryMan(id: 1, token: 'kw-rider', language: null)->id,
            'deliveryman_charge' => self::CHARGE_STORED,
        ]);
        $this->assertSame('KWD', DB::table('orders')->where('id', self::KW_ORDER)->value('currency_code'));
        DB::table('currencies')->where('code', 'KWD')->update(['status' => 0]);
        Cache::flush();

        event(new OrderStatusEvent(key: 'new_order_assigned_message', type: 'delivery_man', order: $order));

        $this->assertSame(self::CHARGE_CONVERTED_TO_THE_PLATFORM_CURRENCY, $this->theOnePushSent()['message']['data']['deliveryman_charge']);
    }

    /**
     * A recordless order notified from inside a payment that was taken in another market. The platform sends 30 here today, because the
     * payment's own storefront is bound and the amount is read as that market's money; this binds the default storefront instead, so an
     * order whose amounts are in the platform's money is priced in the platform's money. A deliberate difference from the base branch, which
     * review A measured at 30 on fd40399c and 112.5 here (review A F4).
     */
    public function test_a_recordless_order_is_notified_in_the_platform_money_even_inside_a_payment_of_another_market(): void
    {
        $this->switchStorefrontsOn();
        $this->insertOrder(self::SA_ORDER, [
            'customer_id' => $this->customerWhoReadsArabic()->id,
            'delivery_man_id' => $this->deliveryMan(id: 1, token: 'legacy-rider', language: null)->id,
            'deliveryman_charge' => self::CHARGE_STORED,
        ]);
        $order = Order::findOrFail(self::SA_ORDER);

        PaymentStorefront::within(additionalData: [PaymentStorefront::KEY => 'KW'], callback: static function () use ($order): void {
            event(new OrderStatusEvent(key: 'new_order_assigned_message', type: 'delivery_man', order: $order));
        });

        $this->assertNull(DB::table('orders')->where('id', self::SA_ORDER)->value('storefront_country'));
        $this->assertSame(self::CHARGE_CONVERTED_TO_THE_PLATFORM_CURRENCY, $this->theOnePushSent()['message']['data']['deliveryman_charge']);
    }

    /**
     * The body of the e-mail, not only its title. The order-placed design puts every amount of the order through webCurrencyConverter and
     * setCurrencySymbol, and those read the currency settings the bound storefront answers for, so the whole body now reads in the order's
     * own money. Review B (F2) measured the mechanism and found this stated nowhere; it is a real change and here it is driven end to end.
     */
    public function test_the_email_body_of_an_order_of_a_market_with_its_own_currency_shows_the_money_the_order_holds(): void
    {
        $this->captureTheEmailsTheMailerRenders();
        $this->switchStorefrontsOn();
        $this->seedOrderPlacedEmailTemplate(titleInEnglish: 'Your order is placed', titleInArabic: 'تم إنشاء طلبك', design: 'order-place');
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
        ]);

        $this->placeOrderMailFor($order);

        $body = $this->bodyOfTheEmailTheMailerSent();
        $this->assertStringContainsString(self::LINE_AS_THE_ORDER_HOLDS_IT, $body, "the order's line in the order's own money, with the dinar's decimals");
        $this->assertStringNotContainsString(self::LINE_CONVERTED_TO_THE_PLATFORM_CURRENCY, $body, 'and not the platform conversion');
    }

    /**
     * The storefront itself, for an order really sold in one, while the request is being served from another. Nothing a sender puts on the
     * wire reads the bound storefront on this base — the senders read the order's own relations, the notification messages and the e-mail
     * templates, and none of those is narrowed by country — so this is asserted where the binding is visible: what StorefrontCatalog answers
     * inside the send, which is what any catalog read added inside a notification would follow. The record is the one the platform's own
     * snapshot writer wrote when the order was sold.
     */
    public function test_a_notification_is_served_in_the_storefront_the_order_records_not_the_one_the_request_serves(): void
    {
        $this->switchStorefrontsOn();
        $this->serveRequestFrom('AE');
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
        ]);

        $served = OrderNotificationContext::within(
            order: $order,
            userType: 'customer',
            callback: static fn (): array => ['bound' => StorefrontContext::current()->country, 'catalog' => StorefrontCatalog::country()],
        );

        $this->assertSame('KW', DB::table('orders')->where('id', self::KW_ORDER)->value('storefront_country'), 'which is what the sale recorded');
        $this->assertSame(['bound' => 'KW', 'catalog' => 'KW'], $served);
        $this->assertSame('AE', StorefrontContext::current()->country, 'and the request keeps its own storefront');
    }

    /**
     * What else the order's money in force brings with it, recorded because it surprises: StorefrontCurrency answers ten business settings
     * for a storefront that trades in its own currency, not three. Inside a notification about a Kuwaiti order the wallet reads closed and
     * every amount threshold reads the neutral value, because those rows are typed in the platform's money and may not be read as dinars.
     * Nothing a sender does reads them today — the pushes and the e-mail read the currency settings only — so this is PR 87's contract
     * reaching into notifications, pinned so that anything added inside a wrapped listener meets it knowingly (review B F11).
     */
    public function test_the_orders_money_in_force_also_closes_the_wallet_and_neutralises_the_amount_thresholds(): void
    {
        $this->switchStorefrontsOn();
        DB::table('business_settings')->updateOrInsert(['type' => 'wallet_status'], ['value' => json_encode(1)]);
        DB::table('business_settings')->updateOrInsert(['type' => 'minimum_order_amount'], ['value' => json_encode(50)]);
        Cache::flush();
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
        ]);

        $inside = OrderNotificationContext::within(
            order: $order,
            userType: 'customer',
            callback: static fn (): array => ['wallet' => getWebConfig('wallet_status'), 'minimum' => getWebConfig('minimum_order_amount')],
        );

        $this->assertSame(['wallet' => '0', 'minimum' => '0'], $inside);
        $this->assertSame(['wallet' => '1', 'minimum' => '50'], [
            'wallet' => getWebConfig('wallet_status'), 'minimum' => getWebConfig('minimum_order_amount'),
        ], 'and the platform reads its own again afterwards');
    }

    /**
     * A recipient row whose language is blank. Nothing a sender writes differs — every reader of a blank language falls back to the humanised
     * key or the untranslated row — so this is asserted at the service's own seam, where the blank is visible: what must not happen is a
     * blank locale and a blank session language being bound around the send (review A F8, review B F6).
     */
    public function test_a_recipient_whose_language_is_blank_has_nothing_bound_for_them(): void
    {
        $this->switchStorefrontsOn();
        App::setLocale('en');

        foreach (['', '   '] as $blank) {
            $bound = OrderNotificationContext::within(
                order: ['storefront_country' => 'KW', 'customer' => ['app_language' => $blank]],
                userType: 'customer',
                callback: static fn (): array => ['locale' => App::getLocale(), 'session' => session('local')],
            );

            $this->assertSame(['locale' => 'en', 'session' => null], $bound, var_export($blank, true));
        }
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
