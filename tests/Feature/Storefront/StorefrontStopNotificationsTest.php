<?php

namespace Tests\Feature\Storefront;

use App\Events\OrderEditDuePaymentEvent;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MaintenanceModeMiddleware;
use App\Http\Middleware\ModulePermissionMiddleware;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Http\Middleware\SellerMiddleware;
use App\Http\Middleware\SellerStaffAccessMiddleware;
use App\Models\Admin;
use App\Models\OrderEditHistory;
use App\Models\Seller;
use App\Models\User;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontLifecycle;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsSixStorefrontCheckoutWorld;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\FakesPushNotifications;

/**
 * I1-07.1: what a customer and a vendor are told about an order of a storefront that may not sell. No listener, push helper or controller that sends these notifications reads the state of a storefront, and
 * until this class no test sent one for a storefront that may not sell. Each driven case runs in every state in which a storefront may not sell and, as its control, in two storefronts that sell, so that the
 * answers asserted are the answers of a storefront that sells: the three stop modes on a storefront other than the default one (browse only, maintenance, redirect) and browse only on the default one (I2-17
 * allows nothing else); a storefront found in draft or in ready after an order of it exists (no transition of the lifecycle leads back there: it is what a lost record, or an older record put back, leaves); and an
 * active storefront that has lost something it needs to sell (its legal texts are no longer accepted, so the selling gate closes it with no transition). Every case ends with the new sale a shopper had waiting:
 * refused in each state, taken in the controls.
 *
 * Driven over HTTP on an order of the shop or of a vendor that the real checkout of the API placed before the state, by the staff member who may act on it, with Firebase and the mail faked
 * (Tests\Support\FakesPushNotifications). The status of the order: moved by the administrator (confirmed, processing, out for delivery, delivered; canceled), by the vendor from the vendor panel and from the seller
 * API v3 (confirmed, delivered) and from the seller API v2 (confirmed, out for delivery: that route refuses to deliver an unpaid order), and by the delivery man from the delivery-man API (out for delivery,
 * delivered). The refund of a line: rejected and refunded by the administrator, rejected by the vendor. The amount of an edited order that the administrator returns to the customer. Each step asserts that the
 * route did its work, that the customer's device got exactly one message for that order carrying the key of the step and the text the operator switched on for it, and that no mail went out. The refund
 * the customer asks for while the storefront is in the state is driven as well: the vendor's device got exactly one message, naming the order and the refund (its key is not asserted: RefundListener gives a new
 * request the key of a status change by the staff, because its branch for a request is never reached).
 *
 * E-mail: the application sends no e-mail for the status of an order, for a refund or for a due payment (the only order mails are the one that tells a customer an order was placed and the one that tells a vendor or
 * the staff a new order came in, both sent when the order is made), so there is no mail path that a stop could have cut. Every step asserts that the faked mail stayed empty, as a tripwire: the day one of these steps
 * sends a mail, the test fails until that mail is asserted here in every state like the push is. The fake records mailables, which is how every order mail of the application is sent; a mail written as a raw text
 * or as a bare view would not be seen. Nothing here proves that a mail is sent.
 *
 * The due payment: the customer chooses cash for the due amount of an edited order of a vendor on the website, and the event that asks for the vendor's push is asserted, once, for that order, in every state. That
 * the push itself is sent is NOT asserted and is not true today: the key of that message (order_edit_due_payment_message) has no entry in the lookup of PushNotificationTrait::pushNotificationMessage, so the push is
 * dropped in a storefront that sells as well. The customer gets no message of a due payment from this path: the only recipient it names is the vendor.
 *
 * Not driven: the refund routes of the seller API (v2 and v3), the MyFatoorah refund, the bulk status route and the delivery-man assignment; the notification of an order edit; the pushes to a delivery man and to a
 * vendor that an order status raises (only the customer's device is asserted, except for the refund the customer asks for); a queued listener run by a worker (the queue is sync here); the delivery of the message by
 * Firebase to the device (the stub answers 200).
 */
class StorefrontStopNotificationsTest extends ApiAddressTestCase
{
    use BuildsSixStorefrontCheckoutWorld;
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;
    use FakesPushNotifications;

    private const PERSON = 'The head of the operations team';

    private const REASON = 'Decision of the operations meeting';

    /** A state a storefront is found in, besides the lifecycle ones: it is active, and one of the things it needs to sell is no longer there. */
    private const PREREQUISITE_WITHDRAWN = 'prerequisite_withdrawn';

    /** The default guard of the website. After a request of the app the auth manager keeps the api guard as the default, and that guard erases an Authorization header that is not one of its own tokens, as the staff tokens of the apps are not. */
    private const WEBSITE_GUARD = 'web';

    private const CUSTOMER_DEVICE = 'device-of-the-customer';

    private const VENDOR_DEVICE = 'device-of-the-vendor';

    private const VENDOR_ID = 5;

    private const DELIVERY_MAN_ID = 1;

    private const DELIVERY_MAN_TOKEN = 'token-of-the-delivery-man-of-the-test-world';

    private const VENDOR_TOKEN = 'token-of-the-vendor-of-the-test-world-1';

    private const PLACE_ORDER = '/api/v1/customer/order/place';

    private const REFUND_STORE = '/api/v1/customer/order/refund-store';

    private const DELIVERY_MAN_STATUS = '/api/v2/delivery-man/update-order-status';

    private const VENDOR_API_V2_STATUS = '/api/v2/seller/orders/order-detail-status/';

    private const VENDOR_API_V3_STATUS = '/api/v3/seller/orders/order-detail-status/';

    private const DUE_PAYMENT = '/customer/customer-order-edit-pay-amount';

    /** The product each storefront sells in this world: the shop's own and a vendor's, and the price of the first in its own currency. */
    private const PRODUCTS = [
        'KW' => ['house' => 3, 'vendor' => 7, 'price' => 30],
        'SA' => ['house' => 1, 'vendor' => 8, 'price' => 100],
    ];

    /** The status an order is given and the text an operator switched on for the message that tells the customer of it. */
    private const TEXT_OF_STATUS = [
        'confirmed' => 'order_confirmation_message',
        'processing' => 'order_processing_message',
        'out_for_delivery' => 'out_for_delivery_message',
        'delivered' => 'order_delivered_message',
        'canceled' => 'order_canceled',
    ];

    /** The steps of an order from the moment it is confirmed to the moment it is delivered. */
    private const STEPS_TO_DELIVERY = ['confirmed', 'processing', 'out_for_delivery', 'delivered'];

    /** The text the operator switched on for each message these steps send to the customer: {orderId} is replaced by the order. */
    private const CUSTOMER_TEXTS = [
        'order_confirmation_message' => 'Your order #{orderId} is confirmed',
        'order_processing_message' => 'Your order #{orderId} is being prepared',
        'out_for_delivery_message' => 'Your order #{orderId} is on its way',
        'order_delivered_message' => 'Your order #{orderId} was delivered',
        'order_canceled' => 'Your order #{orderId} was canceled',
        'refund_request_canceled_message' => 'The refund of order #{orderId} was refused',
        'order_refunded_message' => 'The refund of order #{orderId} was paid',
        'order_edit_return_amount_message' => 'An amount of order #{orderId} was returned to you',
    ];

    private const VENDOR_TEXTS = [
        'refund_request_status_changed_by_admin' => 'A refund of order #{orderId} was asked for',
        'refund_request_canceled_message' => 'The refund of order #{orderId} was refused',
        'order_refunded_message' => 'The refund of order #{orderId} was paid',
    ];

    /** The request middleware that needs the installation or is not what a staff test is about: the same ones the panel tests of the address work leave out. */
    private const STAFF_MIDDLEWARE_OFF = [
        AdminMiddleware::class, SellerMiddleware::class, SellerStaffAccessMiddleware::class, ActivationCheckMiddleware::class, MaintenanceModeMiddleware::class,
        ModulePermissionMiddleware::class, Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
        RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSixStorefrontCheckoutWorld();
        $this->buildThePushWorld(texts: ['customer' => self::CUSTOMER_TEXTS, 'seller' => self::VENDOR_TEXTS]);
        $this->addWhatTheStaffAndTheVendorsRead();
    }

    /** @return array<string, array{0: string, 1: ?string}> the storefront and the state it is in; no state is the control, a storefront that sells */
    public static function everyStateThatMayNotSellAndItsControl(): array
    {
        return [
            'Kuwait, not stopped' => ['KW', null],
            'the default storefront, not stopped' => ['SA', null],
            'Kuwait, browse only' => ['KW', StorefrontLifecycle::BROWSE_ONLY],
            'Kuwait, maintenance' => ['KW', StorefrontLifecycle::MAINTENANCE],
            'Kuwait, redirect' => ['KW', StorefrontLifecycle::REDIRECT],
            'the default storefront, browse only' => ['SA', StorefrontLifecycle::BROWSE_ONLY],
            'Kuwait, back to draft' => ['KW', StorefrontLifecycle::DRAFT],
            'Kuwait, back to ready' => ['KW', StorefrontLifecycle::READY],
            'Kuwait, active but a prerequisite withdrawn' => ['KW', self::PREREQUISITE_WITHDRAWN],
        ];
    }

    /** What the staff routes and the pages of an order read besides the order itself, and the vendor, the delivery man and the vendor's products the world has none of. */
    private function addWhatTheStaffAndTheVendorsRead(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->text('cause')->nullable();
            $table->decimal('edit_due_amount', 18, 12)->default(0);
            $table->decimal('edit_return_amount', 18, 12)->default(0);
            $table->decimal('deliveryman_charge', 16, 2)->default(0);
        });
        Schema::table('referral_customers', function (Blueprint $table) {
            $table->tinyInteger('ordered_notify')->default(0);
            $table->tinyInteger('delivered_notify')->default(0);
        });
        Schema::table('order_details', function (Blueprint $table) {
            $table->tinyInteger('is_stock_decreased')->default(1);
            $table->tinyInteger('refund_request')->default(0);
            $table->dateTime('refund_started_at')->nullable();
        });
        Schema::create('order_details_rewards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('order_details_id');
            $table->string('reward_type')->nullable();
            $table->json('reward_details')->nullable();
            $table->decimal('reward_amount', 18, 12)->nullable()->default(0);
            $table->tinyInteger('reward_delivered')->default(0);
            $table->timestamps();
        });
        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_details_id');
            $table->unsignedBigInteger('customer_id');
            $table->string('status');
            $table->integer('approved_count')->default(0);
            $table->integer('denied_count')->default(0);
            $table->decimal('amount', 16, 2)->default(0);
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('order_id');
            $table->longText('refund_reason');
            $table->text('images')->nullable();
            $table->text('approved_note')->nullable();
            $table->text('rejected_note')->nullable();
            $table->text('payment_info')->nullable();
            $table->string('change_by')->nullable();
            $table->timestamps();
        });
        Schema::create('refund_statuses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('refund_request_id')->nullable();
            $table->string('change_by')->nullable();
            $table->unsignedBigInteger('change_by_id')->nullable();
            $table->string('status')->nullable();
            $table->longText('message')->nullable();
            $table->timestamps();
        });
        Schema::create('refund_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('payment_for')->nullable();
            $table->unsignedBigInteger('payer_id')->nullable();
            $table->unsignedBigInteger('payment_receiver_id')->nullable();
            $table->string('paid_by')->nullable();
            $table->string('paid_to')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_status')->nullable();
            $table->decimal('amount', 16, 2)->nullable();
            $table->string('transaction_type')->nullable();
            $table->unsignedBigInteger('order_details_id')->nullable();
            $table->unsignedBigInteger('refund_id')->nullable();
            $table->timestamps();
        });
        Schema::create('delivery_men', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('auth_token')->nullable();
            $table->string('fcm_token')->nullable();
            $table->string('app_language')->default('en');
            $table->timestamps();
        });
        Schema::create('deliveryman_wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->decimal('current_balance', 16, 2)->default(0);
            $table->decimal('cash_in_hand', 16, 2)->default(0);
            $table->decimal('pending_withdraw', 16, 2)->default(0);
            $table->decimal('total_withdraw', 16, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('seller_wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->decimal('total_earning', 16, 2)->default(0);
            $table->decimal('withdrawn', 16, 2)->default(0);
            $table->decimal('commission_given', 16, 2)->default(0);
            $table->decimal('pending_withdraw', 16, 2)->default(0);
            $table->decimal('delivery_charge_earned', 16, 2)->default(0);
            $table->decimal('collected_cash', 16, 2)->default(0);
            $table->decimal('total_tax_collected', 16, 2)->default(0);
            $table->timestamps();
        });
        (require database_path('migrations/2026_01_11_211839_create_order_edit_histories_table.php'))->up();

        DB::table('admin_wallets')->insert(['admin_id' => 1, 'inhouse_earning' => 500, 'pending_amount' => 50]);
        DB::table('delivery_men')->insert(['id' => self::DELIVERY_MAN_ID, 'f_name' => 'Driver', 'auth_token' => self::DELIVERY_MAN_TOKEN, 'fcm_token' => 'device-of-the-delivery-man']);
        Schema::table('sellers', function (Blueprint $table) {
            $table->string('auth_token')->nullable();
        });
        DB::table('sellers')->insert(['id' => self::VENDOR_ID, 'f_name' => 'Vendor', 'status' => 'approved', 'auth_token' => self::VENDOR_TOKEN, 'cm_firebase_token' => self::VENDOR_DEVICE]);
        DB::table('shops')->insert(['seller_id' => self::VENDOR_ID, 'author_type' => 'vendor', 'name' => 'Pharmacy of the vendor']);

        $product = ['added_by' => 'seller', 'user_id' => self::VENDOR_ID, 'variation' => '[]', 'images' => '[]', 'thumbnail' => 'x.png', 'category_ids' => '[]', 'discount' => 0, 'discount_type' => 'flat', 'choice_options' => '[]', 'tax' => 0, 'tax_type' => 'percent', 'tax_model' => 'exclude', 'current_stock' => 10];
        DB::table('products')->insert([
            $product + ['id' => 7, 'code' => 'SKU-7', 'name' => 'Vendor product KW', 'slug' => 'vendor-product-kw', 'unit_price' => 30, 'storefront_country' => 'KW'],
            $product + ['id' => 8, 'code' => 'SKU-8', 'name' => 'Vendor product SA', 'slug' => 'vendor-product-sa', 'unit_price' => 100, 'storefront_country' => 'SA'],
        ]);
    }

    // ---- who asks ----------------------------------------------------------------------------------------------------------------------------

    /** The request as the app sends it: the customer's token and the storefront it is showing, and nobody signed in on the website from an earlier step. */
    private function asTheApp(User $customer, string $country): static
    {
        auth()->forgetGuards();

        return $this->asCustomer($customer)->withHeader('X-Storefront', $country);
    }

    /** A request from a staff member: no token of the app, no storefront header, no storefront bound (the routes of the staff have no storefront middleware) and the default guard of the website. */
    private function asStaff(bool $panel = true): static
    {
        $this->flushHeaders();
        auth()->forgetGuards();
        auth()->shouldUse(self::WEBSITE_GUARD);
        app()->forgetInstance(StorefrontContext::class);

        if ($panel) {
            $this->withoutMiddleware(self::STAFF_MIDDLEWARE_OFF);
        }

        return $this;
    }

    private function asTheAdministrator(): static
    {
        return $this->asStaff()->actingAs((new Admin())->forceFill(['id' => 1, 'name' => 'Master Admin']), 'admin');
    }

    private function asTheVendor(): static
    {
        return $this->asStaff()->actingAs((new Seller())->forceFill(['id' => self::VENDOR_ID, 'f_name' => 'Vendor']), 'seller');
    }

    private function asTheDeliveryMan(): static
    {
        return $this->asStaff(panel: false)->withHeader('Authorization', 'Bearer ' . self::DELIVERY_MAN_TOKEN);
    }

    private function asTheVendorInTheSellerApp(): static
    {
        return $this->asStaff(panel: false)->withHeader('Authorization', 'Bearer ' . self::VENDOR_TOKEN);
    }

    // ---- the order and the storefront --------------------------------------------------------------------------------------------------------

    /** @return array{0: User, 1: int} a customer with a device and the order that the real checkout of the storefront saved for them, of the shop itself or of the vendor */
    private function placeAnOrder(string $country, bool $soldByTheVendor = false): array
    {
        $customer = $this->makeCustomer(['cm_firebase_token' => self::CUSTOMER_DEVICE]);
        $this->fillTheCartFrom(customer: $customer, country: $country, productId: self::PRODUCTS[$country][$soldByTheVendor ? 'vendor' : 'house']);

        $orderId = (int) $this->asTheApp(customer: $customer, country: $country)->getJson(self::PLACE_ORDER)->assertOk()->json('order_ids.0');

        $order = DB::table('orders')->where('id', $orderId)->first();
        $this->assertSame([$customer->id, $country, $soldByTheVendor ? 'seller' : 'admin', 'pending'], [(int) $order->customer_id, $order->storefront_country, $order->seller_is, $order->order_status]);

        Mail::fake();
        $this->takeThePushes();

        return [$customer, $orderId];
    }

    /** @return int the line of the order that a refund is asked for: the order is delivered first, as a refund is only asked for on a delivered one */
    private function deliverTheOrder(int $orderId): int
    {
        DB::table('orders')->where('id', $orderId)->update(['order_status' => 'delivered']);
        DB::table('order_details')->where('order_id', $orderId)->update(['delivery_status' => 'delivered']);

        return (int) DB::table('order_details')->where('order_id', $orderId)->value('id');
    }

    /** The customer asks for the refund of a line through the real route of the app: the request the staff then decides on. */
    private function askForARefund(User $customer, string $country, int $lineId): int
    {
        $this->asTheApp(customer: $customer, country: $country)
            ->postJson(self::REFUND_STORE, ['order_details_id' => $lineId, 'amount' => self::PRODUCTS[$country]['price'], 'refund_reason' => 'The box arrived damaged'])
            ->assertOk();

        return (int) DB::table('refund_requests')->where('order_details_id', $lineId)->value('id');
    }

    /** A shopper whose cart is full while the storefront still sells, and then the state the storefront is put in, if there is one: the new sale the state is asked to refuse. */
    private function stopWithANewSaleWaiting(string $country, ?string $state): User
    {
        $shopper = $this->makeCustomer();
        $this->fillTheCartFrom(customer: $shopper, country: $country, productId: self::PRODUCTS[$country]['house']);

        if ($state !== null) {
            $this->putTheStorefrontIn(country: $country, state: $state);
        }

        $this->assertTheLifecycleMatches(country: $country, state: $state);

        return $shopper;
    }

    private function putTheStorefrontIn(string $country, string $state): void
    {
        $lifecycle = app(StorefrontLifecycle::class);

        match ($state) {
            StorefrontLifecycle::BROWSE_ONLY, StorefrontLifecycle::MAINTENANCE, StorefrontLifecycle::REDIRECT => $lifecycle->pause(country: $country, mode: $state, by: self::PERSON, reason: self::REASON),
            StorefrontLifecycle::DRAFT => $this->loseTheLifecycleRecord(country: $country),
            StorefrontLifecycle::READY => $this->restoreAnOlderRecordOf(country: $country),
            self::PREREQUISITE_WITHDRAWN => config(['gcc.storefronts.legal.grandfathered' => array_values(array_diff((array) config('gcc.storefronts.legal.grandfathered'), [$country]))]),
        };
    }

    /** The storefront is in the lifecycle state the case says it is in: a draft is a draft, and a storefront that has lost a prerequisite is still active. */
    private function assertTheLifecycleMatches(string $country, ?string $state): void
    {
        $expected = match ($state) {
            StorefrontLifecycle::BROWSE_ONLY, StorefrontLifecycle::MAINTENANCE, StorefrontLifecycle::REDIRECT => StorefrontLifecycle::PAUSED,
            StorefrontLifecycle::DRAFT, StorefrontLifecycle::READY => $state,
            default => StorefrontLifecycle::ACTIVE,
        };
        $status = app(StorefrontLifecycle::class)->statusOf($country);

        $this->assertSame([$expected, in_array($state, StorefrontLifecycle::MODES, true) ? $state : null], [$status['state'], $status['mode']], "{$country} is not in the state the case says");
    }

    /** No transition of the lifecycle leads back to a draft: this is the state a lost row or a restored older database leaves, and the code reads it as a storefront nobody prepared. */
    private function loseTheLifecycleRecord(string $country): void
    {
        DB::table('business_settings')->where('type', StorefrontLifecycle::settingName($country))->delete();
        clearWebConfigCacheKeys();
    }

    private function restoreAnOlderRecordOf(string $country): void
    {
        $this->loseTheLifecycleRecord(country: $country);
        app(StorefrontLifecycle::class)->markReady(country: $country, by: self::PERSON, reason: self::REASON);
    }

    private function assertANewSaleFollowsTheState(User $shopper, string $country, ?string $state): void
    {
        $orders = DB::table('orders')->count();
        $response = $this->asTheApp(customer: $shopper, country: $country)->getJson(self::PLACE_ORDER);

        if ($state === null) {
            $response->assertOk();
            $this->assertSame($orders + 1, DB::table('orders')->count(), 'a storefront that sells takes the new sale');

            return;
        }

        $response->assertStatus(422)->assertJsonPath('code', 'storefront_not_sellable');
        $this->assertSame($orders, DB::table('orders')->count(), 'a storefront that may not sell refuses it and saves no order');
        $this->assertSame(1, DB::table('carts')->where('customer_id', $shopper->id)->count(), 'and keeps the cart');
    }

    // ---- what was sent -----------------------------------------------------------------------------------------------------------------------

    /** The customer's device got exactly one message in the step that has just run: for this order, carrying the key the step raised and the text the operator switched on for it. */
    private function assertTheCustomerWasToldOnce(int $orderId, string $messageKey, string $textKey): void
    {
        $told = array_values(array_filter($this->takeThePushes(), fn (array $push) => $push['token'] === self::CUSTOMER_DEVICE));

        $this->assertCount(1, $told, "the customer is told once of [{$messageKey}]");
        $this->assertSame(
            ['order', (string) $orderId, $messageKey, str_replace('{orderId}', (string) $orderId, self::CUSTOMER_TEXTS[$textKey])],
            [$told[0]['data']['type'], $told[0]['data']['order_id'], $told[0]['data']['message_key'], $told[0]['notification']['body']],
        );
    }

    /** The customer is told of the status an order was just given. */
    private function assertTheCustomerWasToldOfTheStatus(int $orderId, string $status): void
    {
        $this->assertSame($status, DB::table('orders')->where('id', $orderId)->value('order_status'), "the order is {$status}");
        $this->assertTheCustomerWasToldOnce(orderId: $orderId, messageKey: $status, textKey: self::TEXT_OF_STATUS[$status]);
    }

    private function assertNoOrderMailWasSent(): void
    {
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    // ---- the status of an order --------------------------------------------------------------------------------------------------------------

    #[DataProvider('everyStateThatMayNotSellAndItsControl')]
    public function test_the_customer_is_told_of_every_step_that_the_administrator_gives_an_earlier_order_in_every_state_that_may_not_sell(string $country, ?string $state): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [, $orderId] = $this->placeAnOrder(country: $country);
        $shopper = $this->stopWithANewSaleWaiting(country: $country, state: $state);

        foreach (self::STEPS_TO_DELIVERY as $status) {
            $this->asTheAdministrator()->postJson(route('admin.orders.status'), ['id' => $orderId, 'order_status' => $status])->assertOk()->assertJsonPath('status', 1);

            $this->assertTheCustomerWasToldOfTheStatus(orderId: $orderId, status: $status);
        }

        $this->assertNoOrderMailWasSent();
        $this->assertANewSaleFollowsTheState(shopper: $shopper, country: $country, state: $state);
    }

    #[DataProvider('everyStateThatMayNotSellAndItsControl')]
    public function test_the_customer_is_told_when_the_administrator_cancels_an_earlier_order_in_every_state_that_may_not_sell(string $country, ?string $state): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [, $orderId] = $this->placeAnOrder(country: $country);
        $shopper = $this->stopWithANewSaleWaiting(country: $country, state: $state);

        $this->asTheAdministrator()->postJson(route('admin.orders.status'), ['id' => $orderId, 'order_status' => 'canceled'])->assertOk()->assertJsonPath('status', 1);

        $this->assertTheCustomerWasToldOfTheStatus(orderId: $orderId, status: 'canceled');
        $this->assertNoOrderMailWasSent();
        $this->assertANewSaleFollowsTheState(shopper: $shopper, country: $country, state: $state);
    }

    #[DataProvider('everyStateThatMayNotSellAndItsControl')]
    public function test_the_customer_is_told_of_the_steps_that_the_vendor_gives_an_earlier_order_from_the_vendor_panel_in_every_state_that_may_not_sell(string $country, ?string $state): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [, $orderId] = $this->placeAnOrder(country: $country, soldByTheVendor: true);
        $shopper = $this->stopWithANewSaleWaiting(country: $country, state: $state);

        foreach (['confirmed', 'delivered'] as $status) {
            $this->asTheVendor()->postJson(route('vendor.orders.status'), ['id' => $orderId, 'order_status' => $status])->assertOk()->assertJsonPath('status', 1);

            $this->assertTheCustomerWasToldOfTheStatus(orderId: $orderId, status: $status);
        }

        $this->assertNoOrderMailWasSent();
        $this->assertANewSaleFollowsTheState(shopper: $shopper, country: $country, state: $state);
    }

    #[DataProvider('everyStateThatMayNotSellAndItsControl')]
    public function test_the_customer_is_told_of_the_steps_that_the_vendor_gives_an_earlier_order_from_the_seller_api_v3_in_every_state_that_may_not_sell(string $country, ?string $state): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [, $orderId] = $this->placeAnOrder(country: $country, soldByTheVendor: true);
        $shopper = $this->stopWithANewSaleWaiting(country: $country, state: $state);

        foreach (['confirmed', 'delivered'] as $status) {
            $this->asTheVendorInTheSellerApp()->putJson(self::VENDOR_API_V3_STATUS . $orderId, ['order_status' => $status])->assertOk()->assertJsonPath('success', 1);

            $this->assertTheCustomerWasToldOfTheStatus(orderId: $orderId, status: $status);
        }

        $this->assertNoOrderMailWasSent();
        $this->assertANewSaleFollowsTheState(shopper: $shopper, country: $country, state: $state);
    }

    #[DataProvider('everyStateThatMayNotSellAndItsControl')]
    public function test_the_customer_is_told_of_the_steps_that_the_vendor_gives_an_earlier_order_from_the_seller_api_v2_in_every_state_that_may_not_sell(string $country, ?string $state): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [, $orderId] = $this->placeAnOrder(country: $country, soldByTheVendor: true);
        $shopper = $this->stopWithANewSaleWaiting(country: $country, state: $state);

        foreach (['confirmed', 'out_for_delivery'] as $status) {
            $this->asTheVendorInTheSellerApp()->putJson(self::VENDOR_API_V2_STATUS . $orderId, ['order_status' => $status])->assertOk()->assertJsonPath('success', 1);

            $this->assertTheCustomerWasToldOfTheStatus(orderId: $orderId, status: $status);
        }

        $this->assertNoOrderMailWasSent();
        $this->assertANewSaleFollowsTheState(shopper: $shopper, country: $country, state: $state);
    }

    #[DataProvider('everyStateThatMayNotSellAndItsControl')]
    public function test_the_customer_is_told_of_the_steps_that_the_delivery_man_gives_an_earlier_order_in_every_state_that_may_not_sell(string $country, ?string $state): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [, $orderId] = $this->placeAnOrder(country: $country);
        DB::table('orders')->where('id', $orderId)->update(['delivery_man_id' => self::DELIVERY_MAN_ID]);
        $shopper = $this->stopWithANewSaleWaiting(country: $country, state: $state);

        foreach (['out_for_delivery', 'delivered'] as $status) {
            $this->asTheDeliveryMan()->putJson(self::DELIVERY_MAN_STATUS, ['order_id' => $orderId, 'status' => $status])->assertOk()->assertJsonPath('message', 'Order status updated successfully!');

            $this->assertTheCustomerWasToldOfTheStatus(orderId: $orderId, status: $status);
        }

        $this->assertNoOrderMailWasSent();
        $this->assertANewSaleFollowsTheState(shopper: $shopper, country: $country, state: $state);
    }

    // ---- the refund of a line ----------------------------------------------------------------------------------------------------------------

    #[DataProvider('everyStateThatMayNotSellAndItsControl')]
    public function test_the_customer_is_told_when_the_administrator_rejects_a_refund_asked_for_before_the_stop_in_every_state_that_may_not_sell(string $country, ?string $state): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [$customer, $orderId] = $this->placeAnOrder(country: $country);
        $refundId = $this->askForARefund(customer: $customer, country: $country, lineId: $this->deliverTheOrder(orderId: $orderId));
        $shopper = $this->stopWithANewSaleWaiting(country: $country, state: $state);

        $this->asTheAdministrator()->postJson(route('admin.refund-section.refund.refund-status-update'), ['id' => $refundId, 'refund_status' => 'rejected', 'rejected_note' => 'The box was opened'])
            ->assertOk()->assertJsonPath('message', translate('refund_status_updated') . '.');

        $this->assertSame('rejected', DB::table('refund_requests')->where('id', $refundId)->value('status'));
        $this->assertTheCustomerWasToldOnce(orderId: $orderId, messageKey: 'refund_request_canceled_message', textKey: 'refund_request_canceled_message');
        $this->assertNoOrderMailWasSent();
        $this->assertANewSaleFollowsTheState(shopper: $shopper, country: $country, state: $state);
    }

    #[DataProvider('everyStateThatMayNotSellAndItsControl')]
    public function test_the_customer_is_told_when_the_administrator_refunds_a_refund_asked_for_before_the_stop_in_every_state_that_may_not_sell(string $country, ?string $state): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [$customer, $orderId] = $this->placeAnOrder(country: $country);
        $refundId = $this->askForARefund(customer: $customer, country: $country, lineId: $this->deliverTheOrder(orderId: $orderId));
        $shopper = $this->stopWithANewSaleWaiting(country: $country, state: $state);

        $this->asTheAdministrator()->postJson(route('admin.refund-section.refund.refund-status-update'), [
            'id' => $refundId, 'refund_status' => 'refunded', 'approved_note' => 'The box was damaged', 'payment_method' => 'cash', 'payment_info' => 'Paid at the counter',
        ])->assertOk()->assertJsonPath('message', translate('refund_status_updated') . '.');

        $this->assertSame('refunded', DB::table('refund_requests')->where('id', $refundId)->value('status'));
        $this->assertTheCustomerWasToldOnce(orderId: $orderId, messageKey: 'order_refunded_message', textKey: 'order_refunded_message');
        $this->assertNoOrderMailWasSent();
        $this->assertANewSaleFollowsTheState(shopper: $shopper, country: $country, state: $state);
    }

    #[DataProvider('everyStateThatMayNotSellAndItsControl')]
    public function test_the_customer_is_told_when_the_vendor_rejects_a_refund_asked_for_before_the_stop_in_every_state_that_may_not_sell(string $country, ?string $state): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [$customer, $orderId] = $this->placeAnOrder(country: $country, soldByTheVendor: true);
        $refundId = $this->askForARefund(customer: $customer, country: $country, lineId: $this->deliverTheOrder(orderId: $orderId));
        $shopper = $this->stopWithANewSaleWaiting(country: $country, state: $state);

        $this->asTheVendor()->postJson(route('vendor.refund.update-status'), ['id' => $refundId, 'refund_status' => 'rejected', 'rejected_note' => 'The box was opened'])
            ->assertOk()->assertJsonPath('message', translate('refund_status_updated') . '!!');

        $this->assertSame('rejected', DB::table('refund_requests')->where('id', $refundId)->value('status'));
        $this->assertTheCustomerWasToldOnce(orderId: $orderId, messageKey: 'refund_request_canceled_message', textKey: 'refund_request_canceled_message');
        $this->assertNoOrderMailWasSent();
        $this->assertANewSaleFollowsTheState(shopper: $shopper, country: $country, state: $state);
    }

    #[DataProvider('everyStateThatMayNotSellAndItsControl')]
    public function test_the_vendor_is_told_when_the_customer_asks_for_a_refund_during_the_stop_in_every_state_that_may_not_sell(string $country, ?string $state): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [$customer, $orderId] = $this->placeAnOrder(country: $country, soldByTheVendor: true);
        $lineId = $this->deliverTheOrder(orderId: $orderId);
        $shopper = $this->stopWithANewSaleWaiting(country: $country, state: $state);

        $refundId = $this->askForARefund(customer: $customer, country: $country, lineId: $lineId);

        $told = array_values(array_filter($this->takeThePushes(), fn (array $push) => $push['token'] === self::VENDOR_DEVICE));
        $this->assertCount(1, $told, 'the vendor is told once');
        $this->assertSame(['refund', (string) $orderId, (string) $refundId], [$told[0]['data']['type'], $told[0]['data']['order_id'], $told[0]['data']['refund_id']]);
        $this->assertNoOrderMailWasSent();
        $this->assertANewSaleFollowsTheState(shopper: $shopper, country: $country, state: $state);
    }

    // ---- the amount of an edited order -------------------------------------------------------------------------------------------------------

    #[DataProvider('everyStateThatMayNotSellAndItsControl')]
    public function test_the_customer_is_told_when_the_administrator_returns_the_amount_of_an_edited_earlier_order_in_every_state_that_may_not_sell(string $country, ?string $state): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [, $orderId] = $this->placeAnOrder(country: $country);
        DB::table('orders')->where('id', $orderId)->update(['edit_return_amount' => 5]);
        OrderEditHistory::create(['order_id' => $orderId, 'order_amount' => self::PRODUCTS[$country]['price'] - 5, 'order_return_amount' => 5, 'order_return_payment_status' => 'pending']);
        $shopper = $this->stopWithANewSaleWaiting(country: $country, state: $state);

        $this->asTheAdministrator()->post(route('admin.orders.customer-return-amount'), [
            'order_id' => $orderId, 'amount' => DB::table('orders')->where('id', $orderId)->value('edit_return_amount'), 'order_return_payment_method' => 'manually', 'order_return_payment_note' => 'Returned at the counter',
        ])->assertRedirect();

        $this->assertSame('returned', OrderEditHistory::where('order_id', $orderId)->value('order_return_payment_status'), 'the amount is returned');
        $this->assertTheCustomerWasToldOnce(orderId: $orderId, messageKey: 'order_edit_return_amount_message', textKey: 'order_edit_return_amount_message');
        $this->assertNoOrderMailWasSent();
        $this->assertANewSaleFollowsTheState(shopper: $shopper, country: $country, state: $state);
    }

    // ---- the due payment ---------------------------------------------------------------------------------------------------------------------

    #[DataProvider('everyStateThatMayNotSellAndItsControl')]
    public function test_the_push_of_a_due_payment_is_asked_for_once_when_the_customer_chooses_cash_in_every_state_that_may_not_sell(string $country, ?string $state): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [$customer, $orderId] = $this->placeAnOrder(country: $country, soldByTheVendor: true);
        OrderEditHistory::create(['order_id' => $orderId, 'order_amount' => self::PRODUCTS[$country]['price'], 'order_due_amount' => 10, 'order_due_payment_status' => 'unpaid']);
        $shopper = $this->stopWithANewSaleWaiting(country: $country, state: $state);
        Event::fake([OrderEditDuePaymentEvent::class]);

        $this->asStaff()->actingAs($customer, 'customer')->post(self::DUE_PAYMENT, ['order_id' => $orderId, 'payment_method' => 'cash_on_delivery', 'payment_platform' => 'web'])->assertRedirect();

        $this->assertSame('cash_on_delivery', OrderEditHistory::where('order_id', $orderId)->value('order_due_payment_method'), 'the customer chose cash for the due amount');
        Event::assertDispatchedTimes(OrderEditDuePaymentEvent::class, 1);
        Event::assertDispatched(OrderEditDuePaymentEvent::class, fn (OrderEditDuePaymentEvent $event) => [$event->notification->key, $event->notification->type, (int) $event->notification->order->id] === ['order_edit_due_payment_message', 'seller', $orderId]);
        $this->assertNoOrderMailWasSent();
        $this->assertANewSaleFollowsTheState(shopper: $shopper, country: $country, state: $state);
    }
}
