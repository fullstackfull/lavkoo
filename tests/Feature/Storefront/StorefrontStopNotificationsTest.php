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
use App\Services\Storefront\StorefrontPushTopics;
use App\Services\Storefront\StorefrontPreflight;
use App\Services\Storefront\StorefrontTaxApproval;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsSixStorefrontCheckoutWorld;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\FakesPushNotifications;

/**
 * I1-07.1: what a customer, a vendor and a delivery man are told about an order of a storefront that may not sell. No listener, push helper or controller that sends these notifications reads the state of a
 * storefront, and until this class no test sent one for a storefront that may not sell. Each driven case runs in every way the selling gate closes a storefront and, as its control, in two storefronts that sell,
 * so that the answers asserted are the answers of a storefront that sells. The states: the three stop modes on a storefront other than the default one (browse only, maintenance, redirect) and browse only on the
 * default one (I2-17 allows nothing else); a storefront found in draft or in ready after an order of it exists (no transition of the lifecycle leads back there: it is what a lost record, or an older record put back,
 * leaves); and an active storefront that has lost what it needs to sell, so that the selling gate closes it with no transition. Of the four prerequisites of StorefrontPreflight two are withdrawn together in each of
 * those cases: the legal texts with their review (grandfathering excuses both, and takes both away), and the currency with the tax decision. None of the four is withdrawn alone. Every case ends with the new sale a
 * shopper had waiting: refused in each state, taken in the controls.
 *
 * Driven over HTTP on an order of the shop or of a vendor that the real checkout of the API placed before the state, by the staff member who may act on it, with Firebase faked (Tests\Support\FakesPushNotifications).
 * What each step must have sent is asserted exactly: the device of each person named below got one message for that order, of the type and with the key the step raises and the text the operator switched on for it.
 * The status of an order, and the customer is told in each: moved by the administrator (confirmed, processing, out for delivery, delivered; canceled), by the vendor from the vendor panel (confirmed, delivered), from the
 * status route and from the order update route of the seller API v3 (confirmed, delivered) and from the seller API v2 (confirmed, out for delivery: that route refuses to deliver an unpaid order), and by the delivery man
 * from the delivery-man API (out for delivery, delivered). When the administrator cancels the order of a vendor that has a delivery man, the vendor and the delivery man are told as well. The refund of a line, and the
 * customer is told in each: rejected and refunded by the administrator on an order of the shop, rejected by the administrator on an order of a vendor, rejected by the vendor in the panel, in the seller API v3 and in the
 * seller API v2. On an order of a vendor the vendor is told as well, in every one of these but the seller API v2, whose route raises no event for it. The refund the customer asks for while the storefront is in the
 * state, from the app and from the website: the vendor's device got one message, naming the order and the refund (RefundListener gives it the key of a status change by the staff, see NEW_REFUND_KEY). The amount of an
 * edited order that the administrator returns to the customer.
 *
 * E-mail: the application sends no e-mail for the status of an order, for a refund or for a due payment (the listeners of these events only push; the only order mails are the one that tells a customer an order
 * was placed and the one that tells a vendor or the staff a new order came in, both sent when the order is made), so there is no mail that a stop could have cut. After every step the test asserts that nothing
 * reached the transport of the application, the array transport of the test environment, which keeps what the default mailer is given whatever its form: a mailable, a raw text or a bare view. It is a tripwire:
 * the day a step sends a mail, the test fails until that mail is asserted here in every state like the push is. Nothing here proves that a mail is sent, and a mail sent through a mailer other than the default one
 * is not seen.
 *
 * The due payment: the customer chooses cash for the due amount of an edited order of a vendor on the website, and the event that asks for the vendor's push is asserted, once, for that order, in every state, with the
 * listener of the event running. That the push itself is sent is NOT asserted and is not true today: the key of that message (order_edit_due_payment_message) has no entry in the lookup of
 * PushNotificationTrait::pushNotificationMessage, so the push is dropped in a storefront that sells as well as in one that may not. The test asserts that no push is sent, in every state, as a characterisation:
 * the day the key is mapped it fails, and the assertion becomes one message to the vendor. The customer gets no message of a due payment from this path: the only recipient it names is the vendor.
 *
 * Not driven: the MyFatoorah refund, the bulk status route, the assignment of a delivery man, the expected delivery date and the delivery charge, the notification of an order edit and the reward of a referral; the
 * approval of a refund, and its payment by a vendor; the push to a vendor when the administrator delivers its order, and the pushes to a delivery man that a cancellation by the vendor or by the delivery man raises;
 * a queued listener run by a worker (the queue is sync here); the delivery of the message by Firebase to the device (the stub answers 200).
 */
class StorefrontStopNotificationsTest extends ApiAddressTestCase
{
    use BuildsSixStorefrontCheckoutWorld;
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;
    use FakesPushNotifications;

    private const PERSON = 'The head of the operations team';

    private const REASON = 'Decision of the operations meeting';

    /** A state a storefront is found in, besides the lifecycle ones: it is active, and its legal texts are no longer accepted, nor is their review on record. */
    private const LEGAL_TEXTS_WITHDRAWN = 'legal_texts_withdrawn';

    /** A state a storefront is found in, besides the lifecycle ones: it is active, and its currency is no longer active, nor is its tax decision on record. */
    private const CURRENCY_AND_TAX_WITHDRAWN = 'currency_and_tax_withdrawn';

    /** The default guard of the website. After a request of the app the auth manager keeps the api guard as the default, and that guard erases an Authorization header that is not one of its own tokens, as the staff tokens of the apps are not. */
    private const WEBSITE_GUARD = 'web';

    private const CUSTOMER_DEVICE = 'device-of-the-customer';

    private const VENDOR_DEVICE = 'device-of-the-vendor';

    private const DELIVERY_MAN_DEVICE = 'device-of-the-delivery-man';

    private const VENDOR_ID = 5;

    private const DELIVERY_MAN_ID = 1;

    private const DELIVERY_MAN_TOKEN = 'token-of-the-delivery-man-of-the-test-world';

    private const VENDOR_TOKEN = 'token-of-the-vendor-of-the-test-world-1';

    private const PLACE_ORDER = '/api/v1/customer/order/place';

    private const REFUND_STORE = '/api/v1/customer/order/refund-store';

    private const DELIVERY_MAN_STATUS = '/api/v2/delivery-man/update-order-status';

    private const VENDOR_API_V2_STATUS = '/api/v2/seller/orders/order-detail-status/';

    private const VENDOR_API_V3_STATUS = '/api/v3/seller/orders/order-detail-status/';

    private const VENDOR_API_V3_ORDER_UPDATE = '/api/v3/seller/orders/order-detail-info-update';

    private const VENDOR_API_V2_REFUND = '/api/v2/seller/refund/refund-status-update';

    private const VENDOR_API_V3_REFUND = '/api/v3/seller/refund/refund-status-update';

    private const DUE_PAYMENT = '/customer/customer-order-edit-pay-amount';

    /** The key RefundListener gives the vendor's message about a new request. Its branch for a request ('refund_request_message') is never reached, because the branch before it takes every status but a refusal and a payment: a request is announced as a status change by the staff. */
    private const NEW_REFUND_KEY = 'refund_request_status_changed_by_admin';

    /** The product each storefront sells in this world: the shop's own and a vendor's, the price of the first in its own currency and that currency. */
    private const PRODUCTS = [
        'KW' => ['house' => 3, 'vendor' => 7, 'price' => 30, 'currency' => 'KWD'],
        'SA' => ['house' => 1, 'vendor' => 8, 'price' => 100, 'currency' => 'SAR'],
    ];

    /** The key of the text an operator switched on for a message, where it is not the key of the message itself: the status an order is given. */
    private const TEXT_OF_KEY = [
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
        self::NEW_REFUND_KEY => 'A refund of order #{orderId} was asked for',
        'refund_request_canceled_message' => 'The refund of order #{orderId} was refused',
        'order_refunded_message' => 'The refund of order #{orderId} was paid',
        'order_canceled' => 'The order #{orderId} was canceled',
        'order_edit_due_payment_message' => 'The due amount of order #{orderId} is to be paid in cash',
    ];

    private const DELIVERY_MAN_TEXTS = [
        'order_canceled' => 'The order #{orderId} was canceled: do not deliver it',
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
        $this->buildThePushWorld(texts: ['customer' => self::CUSTOMER_TEXTS, 'seller' => self::VENDOR_TEXTS, 'delivery_man' => self::DELIVERY_MAN_TEXTS]);
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
            'Kuwait, active but its legal texts and their review withdrawn' => ['KW', self::LEGAL_TEXTS_WITHDRAWN],
            'Kuwait, active but its currency and its tax decision withdrawn' => ['KW', self::CURRENCY_AND_TAX_WITHDRAWN],
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
        Schema::create('deliveryman_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('delivery_man_id');
            $table->unsignedBigInteger('order_id');
            $table->string('description');
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
        DB::table('delivery_men')->insert(['id' => self::DELIVERY_MAN_ID, 'f_name' => 'Driver', 'auth_token' => self::DELIVERY_MAN_TOKEN, 'fcm_token' => self::DELIVERY_MAN_DEVICE]);
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

    /** A customer signed in on the website: the website's middleware for a customer lets an active account through only. */
    private function asTheCustomerOnTheWebsite(User $customer): static
    {
        DB::table('users')->where('id', $customer->id)->update(['is_active' => 1]);

        return $this->asStaff()->actingAs($customer->refresh(), 'customer');
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

        $this->startCountingTheMail();
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

    /** The customer asks for the refund of a line through the real route of the app: the request the staff then decides on. The vendor's push about it is left to be read. */
    private function askForARefund(User $customer, string $country, int $lineId): int
    {
        $this->asTheApp(customer: $customer, country: $country)
            ->postJson(self::REFUND_STORE, ['order_details_id' => $lineId, 'amount' => self::PRODUCTS[$country]['price'], 'refund_reason' => 'The box arrived damaged'])
            ->assertOk();

        return (int) DB::table('refund_requests')->where('order_details_id', $lineId)->value('id');
    }

    /** @return array{0: int, 1: int} the order and the refund the customer asked for on it before the state, with the vendor's push about the request read away: the decision is what the step sends */
    private function placeAnOrderWithARefundAsked(string $country, bool $soldByTheVendor = false): array
    {
        [$customer, $orderId] = $this->placeAnOrder(country: $country, soldByTheVendor: $soldByTheVendor);
        $refundId = $this->askForARefund(customer: $customer, country: $country, lineId: $this->deliverTheOrder(orderId: $orderId));
        $this->takeThePushes();

        return [$orderId, $refundId];
    }

    /** A shopper whose cart is full while the storefront still sells, and then the state the storefront is put in, if there is one: the new sale the state is asked to refuse. */
    private function stopWithANewSaleWaiting(string $country, ?string $state): User
    {
        $shopper = $this->makeCustomer();
        $this->fillTheCartFrom(customer: $shopper, country: $country, productId: self::PRODUCTS[$country]['house']);

        if ($state !== null) {
            $this->putTheStorefrontIn(country: $country, state: $state);
        }

        $this->assertTheStorefrontIsInTheState(country: $country, state: $state);
        $this->readAwayTheMaintenanceEdge(country: $country, state: $state);

        return $shopper;
    }

    /**
     * The one push the STATE itself sends, read away before the step under test runs: a storefront paused in maintenance tells that market's
     * sellers so their app can show the maintenance screen (K-11), and that message belongs to StorefrontMaintenancePushTest, not to any
     * assertion here about what a step tells a device.
     *
     * Asserted rather than discarded, in both directions: the maintenance state sends exactly that one message to exactly that market's
     * topic, and every other state sends nothing at all. Reading it away silently would let the message change, or reach another market,
     * with eighteen tests here staying green.
     */
    private function readAwayTheMaintenanceEdge(string $country, ?string $state): void
    {
        $sent = $this->takeThePushes();

        if ($state !== StorefrontLifecycle::MAINTENANCE) {
            $this->assertSame([], $sent, 'a state that is not a maintenance tells the sellers nothing');

            return;
        }

        $this->assertSame(
            [[StorefrontPushTopics::sellerMaintenance(country: $country), translate('Maintenance_Mode_start')]],
            array_map(static fn (array $push) => [$push['topic'] ?? null, $push['data']['title'] ?? null], $sent),
            'the maintenance of a market is told to that market\'s sellers, and to no device and no other market',
        );
    }

    private function putTheStorefrontIn(string $country, string $state): void
    {
        $lifecycle = app(StorefrontLifecycle::class);

        match ($state) {
            StorefrontLifecycle::BROWSE_ONLY, StorefrontLifecycle::MAINTENANCE, StorefrontLifecycle::REDIRECT => $lifecycle->pause(country: $country, mode: $state, by: self::PERSON, reason: self::REASON),
            StorefrontLifecycle::DRAFT => $this->loseTheLifecycleRecord(country: $country),
            StorefrontLifecycle::READY => $this->restoreAnOlderRecordOf(country: $country),
            self::LEGAL_TEXTS_WITHDRAWN => config(['gcc.storefronts.legal.grandfathered' => array_values(array_diff((array) config('gcc.storefronts.legal.grandfathered'), [$country]))]),
            self::CURRENCY_AND_TAX_WITHDRAWN => $this->withdrawTheCurrencyAndTheTaxDecisionOf(country: $country),
        };
    }

    /** The storefront is in the state the case says it is in: a draft is a draft, a storefront that has lost prerequisites is still active, and the prerequisites that are not met are the ones the case withdrew. */
    private function assertTheStorefrontIsInTheState(string $country, ?string $state): void
    {
        $expected = match ($state) {
            StorefrontLifecycle::BROWSE_ONLY, StorefrontLifecycle::MAINTENANCE, StorefrontLifecycle::REDIRECT => StorefrontLifecycle::PAUSED,
            StorefrontLifecycle::DRAFT, StorefrontLifecycle::READY => $state,
            default => StorefrontLifecycle::ACTIVE,
        };
        $withdrawn = match ($state) {
            self::LEGAL_TEXTS_WITHDRAWN => [StorefrontPreflight::LEGAL_TEXTS, StorefrontPreflight::LEGAL_REVIEW],
            self::CURRENCY_AND_TAX_WITHDRAWN => [StorefrontPreflight::CURRENCY, StorefrontPreflight::TAX],
            default => [],
        };
        $status = app(StorefrontLifecycle::class)->statusOf($country);

        $this->assertSame([$expected, in_array($state, StorefrontLifecycle::MODES, true) ? $state : null], [$status['state'], $status['mode']], "{$country} is not in the state the case says");
        $this->assertSame($withdrawn, app(StorefrontPreflight::class)->unmet(country: $country, currencyCode: self::PRODUCTS[$country]['currency']), "{$country} does not lack what the case says");
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

    /** The currency of the storefront is switched off and its tax decision is revoked, through the service that records and revokes it: two things that take a storefront that sells out of the selling gate with no change of its lifecycle. */
    private function withdrawTheCurrencyAndTheTaxDecisionOf(string $country): void
    {
        DB::table('currencies')->where('code', self::PRODUCTS[$country]['currency'])->update(['status' => 0]);
        cache()->forget('active_currency_list');
        app(StorefrontTaxApproval::class)->revoke(country: $country, revokedBy: self::PERSON);
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

    /** From here on the mail that reaches the transport of the application is counted: the mail of the checkout that placed the order is not. */
    private function startCountingTheMail(): void
    {
        config(['mail.driver' => 'array']);
        app('mail.manager')->purge();
        $this->mailTransport()->flush();
    }

    private function mailTransport(): ArrayTransport
    {
        return app('mail.manager')->mailer()->getSymfonyTransport();
    }

    private function assertNoOrderMailWasSent(): void
    {
        $this->assertCount(0, $this->mailTransport()->messages(), 'a mail reached the transport of the application');
    }

    /** @return array{type: string, key: string, text: string, refund_id: string} the message the customer's device is to get: the key the step raised and the text the operator switched on for it */
    private function toTheCustomer(string $key): array
    {
        return ['type' => 'order', 'key' => $key, 'text' => self::CUSTOMER_TEXTS[self::TEXT_OF_KEY[$key] ?? $key], 'refund_id' => ''];
    }

    /** @return array{type: string, key: string, text: string, refund_id: string} the message the vendor's device is to get: one about a refund when the refund is named, about an order otherwise */
    private function toTheVendor(string $key, ?int $refundId = null): array
    {
        return ['type' => $refundId === null ? 'order' : 'refund', 'key' => $key, 'text' => self::VENDOR_TEXTS[self::TEXT_OF_KEY[$key] ?? $key], 'refund_id' => (string) $refundId];
    }

    /** @return array{type: string, key: string, text: string, refund_id: string} the message the delivery man's device is to get: its payload carries no key of the message */
    private function toTheDeliveryMan(string $key): array
    {
        return ['type' => 'order', 'key' => '', 'text' => self::DELIVERY_MAN_TEXTS[self::TEXT_OF_KEY[$key] ?? $key], 'refund_id' => ''];
    }

    /**
     * Every device named got exactly one message in the step that has just run, for this order, of the type and with the key and the text the step is to send, and no mail reached the transport of the application.
     *
     * @param array<string, array{type: string, key: string, text: string, refund_id: string}> $told the message each device was to get, by device
     */
    private function assertTheDevicesWereToldOnce(int $orderId, array $told): void
    {
        $sent = $this->takeThePushes();

        foreach ($told as $device => $expected) {
            $text = str_replace('{orderId}', (string) $orderId, $expected['text']);
            // A push to a TOPIC carries no token, so it is not a push to any device (K-11). Reading the key outright used to end the test with
            // "Undefined array key" the moment such a message was in the batch.
            $toTheDevice = array_values(array_filter($sent, fn (array $push) => ($push['token'] ?? null) === $device));

            $this->assertCount(1, $toTheDevice, "{$device} is told once: {$text}");
            $this->assertSame(
                [$expected['type'], (string) $orderId, $expected['key'], $text, $expected['refund_id']],
                [$toTheDevice[0]['data']['type'], $toTheDevice[0]['data']['order_id'], $toTheDevice[0]['data']['message_key'], $toTheDevice[0]['notification']['body'], $toTheDevice[0]['data']['refund_id']],
                "what {$device} is told",
            );
        }

        $this->assertNoOrderMailWasSent();
    }

    private function assertTheCustomerWasToldOnce(int $orderId, string $messageKey): void
    {
        $this->assertTheDevicesWereToldOnce(orderId: $orderId, told: [self::CUSTOMER_DEVICE => $this->toTheCustomer(key: $messageKey)]);
    }

    /** The customer is told of the status an order was just given. */
    private function assertTheCustomerWasToldOfTheStatus(int $orderId, string $status): void
    {
        $this->assertSame($status, DB::table('orders')->where('id', $orderId)->value('order_status'), "the order is {$status}");
        $this->assertTheCustomerWasToldOnce(orderId: $orderId, messageKey: $status);
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
        $this->assertANewSaleFollowsTheState(shopper: $shopper, country: $country, state: $state);
    }

    #[DataProvider('everyStateThatMayNotSellAndItsControl')]
    public function test_the_vendor_and_the_delivery_man_are_told_as_well_when_the_administrator_cancels_an_earlier_order_of_the_vendor_in_every_state_that_may_not_sell(string $country, ?string $state): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [, $orderId] = $this->placeAnOrder(country: $country, soldByTheVendor: true);
        DB::table('orders')->where('id', $orderId)->update(['delivery_man_id' => self::DELIVERY_MAN_ID]);
        $shopper = $this->stopWithANewSaleWaiting(country: $country, state: $state);

        $this->asTheAdministrator()->postJson(route('admin.orders.status'), ['id' => $orderId, 'order_status' => 'canceled'])->assertOk()->assertJsonPath('status', 1);

        $this->assertSame('canceled', DB::table('orders')->where('id', $orderId)->value('order_status'), 'the order is canceled');
        $this->assertTheDevicesWereToldOnce(orderId: $orderId, told: [
            self::CUSTOMER_DEVICE => $this->toTheCustomer(key: 'canceled'),
            self::VENDOR_DEVICE => $this->toTheVendor(key: 'canceled'),
            self::DELIVERY_MAN_DEVICE => $this->toTheDeliveryMan(key: 'canceled'),
        ]);
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

        $this->assertANewSaleFollowsTheState(shopper: $shopper, country: $country, state: $state);
    }

    #[DataProvider('everyStateThatMayNotSellAndItsControl')]
    public function test_the_customer_is_told_of_the_steps_that_the_vendor_gives_an_earlier_order_from_the_order_update_of_the_seller_api_v3_in_every_state_that_may_not_sell(string $country, ?string $state): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [, $orderId] = $this->placeAnOrder(country: $country, soldByTheVendor: true);
        $shopper = $this->stopWithANewSaleWaiting(country: $country, state: $state);

        foreach (['confirmed', 'delivered'] as $status) {
            $this->asTheVendorInTheSellerApp()->postJson(self::VENDOR_API_V3_ORDER_UPDATE, ['order_id' => $orderId, 'payment_status' => 'unpaid', 'order_status' => $status])->assertOk()->assertJsonPath('success', 1);

            $this->assertTheCustomerWasToldOfTheStatus(orderId: $orderId, status: $status);
        }

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

        $this->assertANewSaleFollowsTheState(shopper: $shopper, country: $country, state: $state);
    }

    // ---- the refund of a line ----------------------------------------------------------------------------------------------------------------

    #[DataProvider('everyStateThatMayNotSellAndItsControl')]
    public function test_the_customer_is_told_when_the_administrator_rejects_a_refund_asked_for_before_the_stop_in_every_state_that_may_not_sell(string $country, ?string $state): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [$orderId, $refundId] = $this->placeAnOrderWithARefundAsked(country: $country);
        $shopper = $this->stopWithANewSaleWaiting(country: $country, state: $state);

        $this->asTheAdministrator()->postJson(route('admin.refund-section.refund.refund-status-update'), ['id' => $refundId, 'refund_status' => 'rejected', 'rejected_note' => 'The box was opened'])
            ->assertOk()->assertJsonPath('message', translate('refund_status_updated') . '.');

        $this->assertSame('rejected', DB::table('refund_requests')->where('id', $refundId)->value('status'));
        $this->assertTheCustomerWasToldOnce(orderId: $orderId, messageKey: 'refund_request_canceled_message');
        $this->assertANewSaleFollowsTheState(shopper: $shopper, country: $country, state: $state);
    }

    #[DataProvider('everyStateThatMayNotSellAndItsControl')]
    public function test_the_customer_is_told_when_the_administrator_refunds_a_refund_asked_for_before_the_stop_in_every_state_that_may_not_sell(string $country, ?string $state): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [$orderId, $refundId] = $this->placeAnOrderWithARefundAsked(country: $country);
        $shopper = $this->stopWithANewSaleWaiting(country: $country, state: $state);

        $this->asTheAdministrator()->postJson(route('admin.refund-section.refund.refund-status-update'), [
            'id' => $refundId, 'refund_status' => 'refunded', 'approved_note' => 'The box was damaged', 'payment_method' => 'cash', 'payment_info' => 'Paid at the counter',
        ])->assertOk()->assertJsonPath('message', translate('refund_status_updated') . '.');

        $this->assertSame('refunded', DB::table('refund_requests')->where('id', $refundId)->value('status'));
        $this->assertTheCustomerWasToldOnce(orderId: $orderId, messageKey: 'order_refunded_message');
        $this->assertANewSaleFollowsTheState(shopper: $shopper, country: $country, state: $state);
    }

    #[DataProvider('everyStateThatMayNotSellAndItsControl')]
    public function test_the_customer_and_the_vendor_are_told_when_the_administrator_rejects_a_refund_of_an_order_of_the_vendor_in_every_state_that_may_not_sell(string $country, ?string $state): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [$orderId, $refundId] = $this->placeAnOrderWithARefundAsked(country: $country, soldByTheVendor: true);
        $shopper = $this->stopWithANewSaleWaiting(country: $country, state: $state);

        $this->asTheAdministrator()->postJson(route('admin.refund-section.refund.refund-status-update'), ['id' => $refundId, 'refund_status' => 'rejected', 'rejected_note' => 'The box was opened'])
            ->assertOk()->assertJsonPath('message', translate('refund_status_updated') . '.');

        $this->assertSame('rejected', DB::table('refund_requests')->where('id', $refundId)->value('status'));
        $this->assertTheDevicesWereToldOnce(orderId: $orderId, told: [
            self::CUSTOMER_DEVICE => $this->toTheCustomer(key: 'refund_request_canceled_message'),
            self::VENDOR_DEVICE => $this->toTheVendor(key: 'refund_request_canceled_message', refundId: $refundId),
        ]);
        $this->assertANewSaleFollowsTheState(shopper: $shopper, country: $country, state: $state);
    }

    #[DataProvider('everyStateThatMayNotSellAndItsControl')]
    public function test_the_customer_and_the_vendor_are_told_when_the_vendor_rejects_a_refund_from_the_vendor_panel_in_every_state_that_may_not_sell(string $country, ?string $state): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [$orderId, $refundId] = $this->placeAnOrderWithARefundAsked(country: $country, soldByTheVendor: true);
        $shopper = $this->stopWithANewSaleWaiting(country: $country, state: $state);

        $this->asTheVendor()->postJson(route('vendor.refund.update-status'), ['id' => $refundId, 'refund_status' => 'rejected', 'rejected_note' => 'The box was opened'])
            ->assertOk()->assertJsonPath('message', translate('refund_status_updated') . '!!');

        $this->assertSame('rejected', DB::table('refund_requests')->where('id', $refundId)->value('status'));
        $this->assertTheDevicesWereToldOnce(orderId: $orderId, told: [
            self::CUSTOMER_DEVICE => $this->toTheCustomer(key: 'refund_request_canceled_message'),
            self::VENDOR_DEVICE => $this->toTheVendor(key: 'refund_request_canceled_message', refundId: $refundId),
        ]);
        $this->assertANewSaleFollowsTheState(shopper: $shopper, country: $country, state: $state);
    }

    #[DataProvider('everyStateThatMayNotSellAndItsControl')]
    public function test_the_customer_and_the_vendor_are_told_when_the_vendor_rejects_a_refund_from_the_seller_api_v3_in_every_state_that_may_not_sell(string $country, ?string $state): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [$orderId, $refundId] = $this->placeAnOrderWithARefundAsked(country: $country, soldByTheVendor: true);
        $shopper = $this->stopWithANewSaleWaiting(country: $country, state: $state);

        $this->asTheVendorInTheSellerApp()->postJson(self::VENDOR_API_V3_REFUND, ['refund_request_id' => $refundId, 'refund_status' => 'rejected', 'note' => 'The box was opened'])
            ->assertOk()->assertJsonPath('message', 'refund status updated successfully!');

        $this->assertSame('rejected', DB::table('refund_requests')->where('id', $refundId)->value('status'));
        $this->assertTheDevicesWereToldOnce(orderId: $orderId, told: [
            self::CUSTOMER_DEVICE => $this->toTheCustomer(key: 'refund_request_canceled_message'),
            self::VENDOR_DEVICE => $this->toTheVendor(key: 'refund_request_canceled_message', refundId: $refundId),
        ]);
        $this->assertANewSaleFollowsTheState(shopper: $shopper, country: $country, state: $state);
    }

    #[DataProvider('everyStateThatMayNotSellAndItsControl')]
    public function test_the_customer_is_told_when_the_vendor_rejects_a_refund_from_the_seller_api_v2_in_every_state_that_may_not_sell(string $country, ?string $state): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [$orderId, $refundId] = $this->placeAnOrderWithARefundAsked(country: $country, soldByTheVendor: true);
        $shopper = $this->stopWithANewSaleWaiting(country: $country, state: $state);

        $this->asTheVendorInTheSellerApp()->postJson(self::VENDOR_API_V2_REFUND, ['refund_request_id' => $refundId, 'refund_status' => 'rejected', 'note' => 'The box was opened'])
            ->assertOk()->assertJsonPath('message', 'refund status updated successfully!');

        $this->assertSame('rejected', DB::table('refund_requests')->where('id', $refundId)->value('status'));
        $this->assertTheCustomerWasToldOnce(orderId: $orderId, messageKey: 'refund_request_canceled_message');
        $this->assertANewSaleFollowsTheState(shopper: $shopper, country: $country, state: $state);
    }

    #[DataProvider('everyStateThatMayNotSellAndItsControl')]
    public function test_the_vendor_is_told_when_the_customer_asks_for_a_refund_from_the_app_during_the_stop_in_every_state_that_may_not_sell(string $country, ?string $state): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [$customer, $orderId] = $this->placeAnOrder(country: $country, soldByTheVendor: true);
        $lineId = $this->deliverTheOrder(orderId: $orderId);
        $shopper = $this->stopWithANewSaleWaiting(country: $country, state: $state);

        $refundId = $this->askForARefund(customer: $customer, country: $country, lineId: $lineId);

        $this->assertTheDevicesWereToldOnce(orderId: $orderId, told: [self::VENDOR_DEVICE => $this->toTheVendor(key: self::NEW_REFUND_KEY, refundId: $refundId)]);
        $this->assertANewSaleFollowsTheState(shopper: $shopper, country: $country, state: $state);
    }

    #[DataProvider('everyStateThatMayNotSellAndItsControl')]
    public function test_the_vendor_is_told_when_the_customer_asks_for_a_refund_on_the_website_during_the_stop_in_every_state_that_may_not_sell(string $country, ?string $state): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [$customer, $orderId] = $this->placeAnOrder(country: $country, soldByTheVendor: true);
        $lineId = $this->deliverTheOrder(orderId: $orderId);
        $shopper = $this->stopWithANewSaleWaiting(country: $country, state: $state);

        $this->asTheCustomerOnTheWebsite($customer)->post(route('refund-store'), ['order_details_id' => $lineId, 'amount' => self::PRODUCTS[$country]['price'], 'refund_reason' => 'The box arrived damaged'])
            ->assertRedirect(route('account-order-details', ['id' => $orderId]));

        $refundId = (int) DB::table('refund_requests')->where('order_details_id', $lineId)->value('id');
        $this->assertNotSame(0, $refundId, 'the refund is asked for');
        $this->assertTheDevicesWereToldOnce(orderId: $orderId, told: [self::VENDOR_DEVICE => $this->toTheVendor(key: self::NEW_REFUND_KEY, refundId: $refundId)]);
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
        $this->assertTheCustomerWasToldOnce(orderId: $orderId, messageKey: 'order_edit_return_amount_message');
        $this->assertANewSaleFollowsTheState(shopper: $shopper, country: $country, state: $state);
    }

    // ---- the due payment ---------------------------------------------------------------------------------------------------------------------

    #[DataProvider('everyStateThatMayNotSellAndItsControl')]
    public function test_the_push_of_a_due_payment_is_asked_for_once_and_reaches_no_device_in_every_state_that_may_not_sell(string $country, ?string $state): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [$customer, $orderId] = $this->placeAnOrder(country: $country, soldByTheVendor: true);
        OrderEditHistory::create(['order_id' => $orderId, 'order_amount' => self::PRODUCTS[$country]['price'], 'order_due_amount' => 10, 'order_due_payment_status' => 'unpaid']);
        $shopper = $this->stopWithANewSaleWaiting(country: $country, state: $state);
        $asked = [];
        Event::listen(OrderEditDuePaymentEvent::class, function (OrderEditDuePaymentEvent $event) use (&$asked) {
            $asked[] = [$event->notification->key, $event->notification->type, (int) $event->notification->order->id];
        });

        $this->asTheCustomerOnTheWebsite($customer)->post(self::DUE_PAYMENT, ['order_id' => $orderId, 'payment_method' => 'cash_on_delivery', 'payment_platform' => 'web'])->assertRedirect();

        $this->assertSame('cash_on_delivery', OrderEditHistory::where('order_id', $orderId)->value('order_due_payment_method'), 'the customer chose cash for the due amount');
        $this->assertSame([['order_edit_due_payment_message', 'seller', $orderId]], $asked, 'the push of the due payment is asked for once, for the vendor of that order');
        $this->assertSame([], $this->takeThePushes(), 'the push of a due payment reaches no device today, in a storefront that sells as well as in one that may not: its key has no entry in PushNotificationTrait::pushNotificationMessage. Once it has one, assert the one message to the vendor here, in every state');
        $this->assertNoOrderMailWasSent();
        $this->assertANewSaleFollowsTheState(shopper: $shopper, country: $country, state: $state);
    }
}
