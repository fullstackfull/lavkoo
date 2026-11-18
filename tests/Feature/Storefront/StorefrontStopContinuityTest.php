<?php

namespace Tests\Feature\Storefront;

use App\Enums\Fulfillment\EventOutcome;
use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\Order;
use App\Models\OrderEditHistory;
use App\Models\User;
use App\Services\Fulfillment\FulfillmentSchema;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\WebhookEventProcessor;
use App\Services\MyFatoorah\GatewayConfigRepository;
use App\Services\MyFatoorah\WebhookSignatureVerifier;
use App\Services\Storefront\StorefrontLifecycle;
use App\Utils\OrderManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\BuildsMyFatoorahSchema;
use Tests\Support\BuildsSixStorefrontCheckoutWorld;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\KeepsOrderDebugLogClean;

/**
 * I2-16: what a stop does to an order that was placed before it. A customer who bought from a storefront that a person has since paused (browse only, maintenance or redirect) is
 * still served: the refund, the cancellation, the pages and the tracking of the order, the payment the gateway reports after the stop, the due payment, the status the courier reports,
 * the support of the customer and the earnings of the order all answer as they do while the storefront sells, and a new sale of the same storefront is refused in the same case.
 *
 * Every case places its order through the real checkout of the API before the stop, stops the storefront through StorefrontLifecycle and drives the real routes. Each case is run for a
 * storefront that is not stopped as well (the control), so the answers asserted are the answers of a storefront that sells, and the new sale is taken there. The default storefront can
 * only be stopped browse only (I2-17), so it is the one case of it. Driven: the refund, the cancellation, the list, the details and the tracking of an order, the support tickets and the
 * chat, the due payment by cash and by transfer, the MyFatoorah payment (start, hosted page, signed webhook, return of the customer) and the Torod shipment webhook. Driven below the
 * route: the booking of the earnings of an order on delivery. Pinned structurally in the last section and not driven: the website's own pages of an order, the due payment by wallet and by
 * digital payment, the other gateways and couriers (a whole address family each), and the withdrawal, payout and settlement routes of sellers, vendors, couriers and the staff.
 */
class StorefrontStopContinuityTest extends ApiAddressTestCase
{
    use BuildsFulfillmentSchema;
    use BuildsMyFatoorahSchema;
    use BuildsSixStorefrontCheckoutWorld;
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;
    use KeepsOrderDebugLogClean;

    private const PERSON = 'The head of the operations team';

    private const REASON = 'Decision of the operations meeting';

    private const SALE_GATE = 'storefront.sellable';

    private const MAINTENANCE_GATE = 'storefront.available';

    private const GATEWAY_KEY = 'test-api-key';

    private const GATEWAY_SECRET = 'test-webhook-secret';

    private const COURIER_SECRET = 'hook-secret-abcdef';

    private const HOSTED_PAGE = 'https://sa.myfatoorah.com/KSA/ie/1';

    private const PLACE_ORDER = '/api/v1/customer/order/place';

    private const ORDER_LIST = '/api/v1/customer/order/list';

    private const ORDER_DETAILS = '/api/v1/customer/order/details';

    private const TRACK = '/api/v1/order/track';

    private const TRACK_HISTORY = '/api/v1/order/track-order-details';

    private const CANCEL = '/api/v1/order/cancel-order';

    private const REFUND_INFO = '/api/v1/customer/order/refund';

    private const REFUND_STORE = '/api/v1/customer/order/refund-store';

    private const DUE_BY_COD = '/api/v1/edit-order/due-payment-by-cod';

    private const DUE_BY_OFFLINE = '/api/v1/edit-order/due-payment-by-offline-payment';

    private const SUPPORT_TICKET = '/api/v1/customer/support-ticket';

    private const CHAT = '/api/v1/customer/chat';

    private const DIGITAL_PAYMENT = '/api/v1/digital-payment';

    private const GATEWAY_WEBHOOK = '/api/myfatoorah/webhook';

    private const GATEWAY_RETURN = '/payment/myfatoorah/callback';

    private const COURIER_WEBHOOK = '/api/fulfillment-webhooks/torod-point/' . self::COURIER_SECRET . '/lastmile';

    /** The product each storefront sells in this world, its price in its own currency and the currency its gateway is configured for. */
    private const PRODUCTS = [
        'KW' => ['id' => 3, 'price' => 30, 'currency' => 'KWD'],
        'SA' => ['id' => 1, 'price' => 100, 'currency' => 'SAR'],
    ];

    /** The routes that serve an order a customer already has: the apps' API and the website's own pages. None of them starts a sale. */
    private const SERVING_ACTIONS = [
        '\\v1\\OrderController@track_by_order_id',
        '\\v1\\OrderController@track_order_details_history',
        '\\v1\\OrderController@track_order',
        '\\v1\\OrderController@order_cancel',
        '\\v1\\OrderController@refund_request',
        '\\v1\\OrderController@store_refund',
        '\\v1\\OrderController@refund_details',
        '\\v1\\CustomerController@get_order_list',
        '\\v1\\CustomerController@get_order_details',
        '\\v1\\CustomerController@create_support_ticket',
        '\\v1\\CustomerController@get_support_tickets',
        '\\v1\\CustomerController@get_support_ticket_conv',
        '\\v1\\CustomerController@reply_support_ticket',
        '\\v1\\CustomerController@support_ticket_close',
        '\\v1\\ChatController@list',
        '\\v1\\ChatController@get_message',
        '\\v1\\ChatController@send_message',
        '\\v1\\ChatController@seen_message',
        '\\v1\\OrderEditController@duePaymentByCod',
        '\\v1\\OrderEditController@duePaymentByOfflinePayment',
        '\\v1\\OrderEditController@duePaymentByWallet',
        '\\v1\\OrderEditController@duePaymentByDigitalPayment',
        '\\Web\\UserProfileController@account_order',
        '\\Web\\UserProfileController@account_order_details',
        '\\Web\\UserProfileController@track_order_result',
        '\\Web\\UserProfileController@refund_request',
        '\\Web\\UserProfileController@store_refund',
        '\\Web\\UserProfileController@order_cancel',
        '\\Web\\UserProfileController@comment_submit',
        '\\Web\\UserProfileController@submitSupportTicket',
        '\\Web\\ChattingController@addMessage',
        '\\Customer\\PaymentController@customerOrderEditPayDueAmount',
        '\\Customer\\PaymentController@web_payment_success',
        '\\Customer\\PaymentController@success',
        '\\Customer\\PaymentController@fail',
    ];

    /** The addresses the gateways and the couriers call, and the one the app reads a payment from: a whole family each, so a new one is covered the day it exists. */
    private const GATEWAY_AND_WEBHOOK_PREFIXES = [
        'payment/',
        'api/v1/payment/',
        'api/myfatoorah/',
        'api/fulfillment-webhooks/',
        'api/sales-channel-webhooks/',
        'api/delivery-syria/',
    ];

    /** The routes that settle an earlier order with its seller, its vendor, its courier or the staff: earnings, transactions, withdrawals, payouts and settlements. */
    private const SETTLEMENT_ACTIONS = [
        '\\RestAPI\\v2\\seller\\SellerController@monthly_earning',
        '\\RestAPI\\v2\\seller\\SellerController@transaction',
        '\\RestAPI\\v2\\seller\\SellerController@withdraw_request',
        '\\RestAPI\\v3\\seller\\SellerController@getEarningStatics',
        '\\RestAPI\\v3\\seller\\SellerController@transaction',
        '\\RestAPI\\v3\\seller\\SellerController@withdraw_request',
        '\\RestAPI\\v3\\seller\\SellerPayoutController@store',
        '\\RestAPI\\v2\\delivery_man\\WithdrawController@sendWithdrawRequest',
        '\\Vendor\\WithdrawController@index',
        '\\Vendor\\DashboardController@getWithdrawRequest',
        '\\Vendor\\TransactionReportController@order_transaction_list',
        '\\Vendor\\Marketplace\\PayoutController@store',
        '\\Admin\\Vendor\\VendorController@withdrawStatus',
        '\\Admin\\TransactionReportController@order_transaction_list',
        '\\Admin\\Marketplace\\SettlementController@calculate',
        '\\Admin\\Marketplace\\SettlementController@approve',
        '\\Admin\\Marketplace\\SettlementController@markPaid',
        '\\Admin\\Marketplace\\PayoutController@approve',
        '\\Admin\\Marketplace\\PayoutController@markPaid',
        '\\Admin\\Deliveryman\\DeliverymanWithdrawController@updateStatus',
    ];

    private const SETTLEMENT_ADDRESS = '#withdraw|earning|settlement|payout|commission|transaction#i';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSixStorefrontCheckoutWorld();
        $this->addWhatAnEarlierOrderIsServedFrom();
        $this->rememberOrderDebugLog();
    }

    protected function tearDown(): void
    {
        $this->restoreOrderDebugLog();

        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: ?string}> the storefront and the way it is stopped; no stop is the control, a storefront that sells */
    public static function everyStopAndItsControl(): array
    {
        return [
            'Kuwait, not stopped' => ['KW', null],
            'the default storefront, not stopped' => ['SA', null],
            'Kuwait, browse only' => ['KW', StorefrontLifecycle::BROWSE_ONLY],
            'Kuwait, maintenance' => ['KW', StorefrontLifecycle::MAINTENANCE],
            'Kuwait, redirect' => ['KW', StorefrontLifecycle::REDIRECT],
            'the default storefront, browse only' => ['SA', StorefrontLifecycle::BROWSE_ONLY],
        ];
    }

    /** What the pages and the actions of an order that is placed read besides the order itself: the checkout world has no table for them. */
    private function addWhatAnEarlierOrderIsServedFrom(): void
    {
        Schema::table('order_details', function (Blueprint $table) {
            $table->tinyInteger('is_stock_decreased')->default(1);
            $table->tinyInteger('refund_request')->default(0);
            $table->dateTime('refund_started_at')->nullable();
        });
        Schema::table('reviews', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
        });
        Schema::table('products', function (Blueprint $table) {
            $table->text('colors')->nullable();
            $table->text('attributes')->nullable();
        });
        Schema::create('colors', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->timestamps();
        });
        Schema::create('order_delivery_verifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->text('image');
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
            $table->timestamps();
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
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('subject')->nullable();
            $table->string('type')->nullable();
            $table->string('priority')->nullable();
            $table->text('description')->nullable();
            $table->text('reply')->nullable();
            $table->string('status')->default('open');
            $table->text('attachment')->nullable();
            $table->timestamps();
        });
        Schema::create('support_ticket_convs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('support_ticket_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->text('customer_message')->nullable();
            $table->text('admin_message')->nullable();
            $table->integer('position')->default(0);
            $table->text('attachment')->nullable();
            $table->timestamps();
        });
        Schema::create('chattings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->text('message')->nullable();
            $table->text('attachment')->nullable();
            $table->boolean('sent_by_customer')->default(false);
            $table->boolean('sent_by_seller')->default(false);
            $table->boolean('sent_by_admin')->default(false);
            $table->boolean('sent_by_delivery_man')->default(false);
            $table->boolean('seen_by_customer')->default(true);
            $table->boolean('seen_by_seller')->default(true);
            $table->boolean('seen_by_admin')->default(true);
            $table->boolean('seen_by_delivery_man')->default(true);
            $table->string('notification_receiver')->nullable();
            $table->boolean('status')->default(true);
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
    }

    /** The request as the app sends it: the customer's token and the storefront it is showing. */
    private function asTheApp(User $customer, string $country): static
    {
        return $this->asCustomer($customer)->withHeader('X-Storefront', $country);
    }

    /** A request from outside the apps: a gateway, a courier or a browser coming back, with no token, no signed-in customer and no storefront header. */
    private function asAnOutsider(): static
    {
        $this->flushHeaders();
        auth()->forgetGuards();

        return $this;
    }

    /** @return array{0: User, 1: int} a customer and the order that the real checkout of the storefront saved for them */
    private function placeAnOrder(string $country): array
    {
        $customer = $this->makeCustomer();
        $this->fillTheCartFrom(customer: $customer, country: $country, productId: self::PRODUCTS[$country]['id']);

        $orderId = $this->asTheApp(customer: $customer, country: $country)->getJson(self::PLACE_ORDER)->assertOk()->json('order_ids.0');

        return [$customer, (int) $orderId];
    }

    /** A shopper whose cart is full while the storefront still sells, and then the stop, if there is one: the new sale the stop is asked to refuse. */
    private function stopWithANewSaleWaiting(string $country, ?string $mode): User
    {
        $shopper = $this->makeCustomer();
        $this->fillTheCartFrom(customer: $shopper, country: $country, productId: self::PRODUCTS[$country]['id']);

        if ($mode !== null) {
            app(StorefrontLifecycle::class)->pause(country: $country, mode: $mode, by: self::PERSON, reason: self::REASON);
        }

        return $shopper;
    }

    private function assertANewSaleFollowsTheStop(User $shopper, string $country, ?string $mode): void
    {
        $orders = DB::table('orders')->count();
        $stock = $this->stockOf(country: $country);

        $response = $this->asTheApp(customer: $shopper, country: $country)->getJson(self::PLACE_ORDER);

        if ($mode === null) {
            $response->assertOk();
            $this->assertSame($orders + 1, DB::table('orders')->count(), 'a storefront that sells takes the new sale');

            return;
        }

        $response->assertStatus(422)->assertJsonPath('code', 'storefront_not_sellable');
        $this->assertSame($orders, DB::table('orders')->count(), 'a refused sale saves no order');
        $this->assertSame($stock, $this->stockOf(country: $country), 'and takes no stock');
        $this->assertSame(1, DB::table('carts')->where('customer_id', $shopper->id)->count(), 'and keeps the cart');
    }

    private function markDelivered(int $orderId): void
    {
        DB::table('orders')->where('id', $orderId)->update(['order_status' => 'delivered']);
        DB::table('order_details')->where('order_id', $orderId)->update(['delivery_status' => 'delivered']);
    }

    private function stockOf(string $country): int
    {
        return (int) DB::table('products')->where('id', self::PRODUCTS[$country]['id'])->value('current_stock');
    }

    // ---- refunds ----------------------------------------------------------------------------------------------------------------------------

    #[DataProvider('everyStopAndItsControl')]
    public function test_a_refund_of_an_earlier_order_is_asked_for_and_stored_in_every_stop_mode(string $country, ?string $mode): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [$customer, $orderId] = $this->placeAnOrder(country: $country);
        $this->markDelivered(orderId: $orderId);
        $shopper = $this->stopWithANewSaleWaiting(country: $country, mode: $mode);
        $lineId = (int) DB::table('order_details')->where('order_id', $orderId)->value('id');
        $price = self::PRODUCTS[$country]['price'];

        $info = $this->asTheApp(customer: $customer, country: $country)->getJson(self::REFUND_INFO . '?order_details_id=' . $lineId)->assertOk();
        $this->assertFalse($info->json('already_requested'));
        $this->assertFalse($info->json('expired'));
        $this->assertEquals($price, $info->json('refund.refund_amount'));

        $stored = $this->asTheApp(customer: $customer, country: $country)
            ->postJson(self::REFUND_STORE, ['order_details_id' => $lineId, 'amount' => $price, 'refund_reason' => 'The box arrived damaged'])
            ->assertOk();

        $this->assertSame(translate('refunded_request_updated_successfully!!'), $stored->json());
        $request = DB::table('refund_requests')->first();
        $this->assertSame([1, $orderId, $customer->id, 'pending', 'The box arrived damaged'], [DB::table('refund_requests')->count(), (int) $request->order_id, (int) $request->customer_id, $request->status, $request->refund_reason]);
        $this->assertEquals($price, $request->amount);
        $this->assertSame(1, (int) DB::table('order_details')->where('id', $lineId)->value('refund_request'), 'the line says that a refund was asked for');
        $this->assertANewSaleFollowsTheStop(shopper: $shopper, country: $country, mode: $mode);
    }

    // ---- cancellations ----------------------------------------------------------------------------------------------------------------------

    #[DataProvider('everyStopAndItsControl')]
    public function test_a_customer_still_cancels_an_earlier_order_that_has_not_left_in_every_stop_mode(string $country, ?string $mode): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [$customer, $orderId] = $this->placeAnOrder(country: $country);
        $this->assertSame(9, $this->stockOf(country: $country), 'the order took one unit');
        $shopper = $this->stopWithANewSaleWaiting(country: $country, mode: $mode);

        $cancelled = $this->asTheApp(customer: $customer, country: $country)->getJson(self::CANCEL . '?order_id=' . $orderId)->assertOk();

        $this->assertSame(translate('order_canceled_successfully'), $cancelled->json());
        $this->assertSame('canceled', DB::table('orders')->where('id', $orderId)->value('order_status'));
        $this->assertSame('canceled', DB::table('order_details')->where('order_id', $orderId)->value('delivery_status'));
        $this->assertSame(10, $this->stockOf(country: $country), 'and the unit is back on the shelf');
        $this->assertANewSaleFollowsTheStop(shopper: $shopper, country: $country, mode: $mode);
    }

    // ---- the customer's pages ---------------------------------------------------------------------------------------------------------------

    #[DataProvider('everyStopAndItsControl')]
    public function test_the_list_the_details_and_the_tracking_of_an_earlier_order_are_served_in_every_stop_mode(string $country, ?string $mode): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [$customer, $orderId] = $this->placeAnOrder(country: $country);
        $shopper = $this->stopWithANewSaleWaiting(country: $country, mode: $mode);

        $list = $this->asTheApp(customer: $customer, country: $country)->getJson(self::ORDER_LIST . '?limit=10&offset=1')->assertOk();
        $this->assertSame([$orderId], collect($list->json('orders'))->pluck('id')->all());
        $this->assertSame($country, $list->json('orders.0.storefront_country'), 'the order still says the storefront it was sold in');

        $this->asTheApp(customer: $customer, country: $country)->getJson(self::ORDER_DETAILS . '?order_id=' . $orderId)->assertOk()->assertJsonPath('0.order_id', $orderId);
        $this->asTheApp(customer: $customer, country: $country)->getJson(self::TRACK . '?order_id=' . $orderId)->assertOk()->assertJsonPath('id', $orderId)->assertJsonPath('order_status', 'pending');
        $this->asTheApp(customer: $customer, country: $country)->getJson(self::TRACK_HISTORY . '?order_id=' . $orderId)->assertOk()->assertJsonPath('history.order_placed.status', true);
        $this->assertANewSaleFollowsTheStop(shopper: $shopper, country: $country, mode: $mode);
    }

    // ---- payments and their webhooks --------------------------------------------------------------------------------------------------------

    #[DataProvider('everyStopAndItsControl')]
    public function test_a_payment_started_before_the_stop_is_finished_by_its_gateway_after_it_in_every_stop_mode(string $country, ?string $mode): void
    {
        $this->skipInStrictModeUntilThePaymentStartIsFixed();
        [$customer, $reference] = $this->startAPayment(country: $country);
        $shopper = $this->stopWithANewSaleWaiting(country: $country, mode: $mode);

        $this->theGatewayReportsThePayment(reference: $reference, country: $country)->assertOk()->assertJson(['success' => true, 'status' => 'settled']);

        $order = DB::table('orders')->first();
        $this->assertSame(1, DB::table('orders')->count(), 'the customer paid, so the order exists');
        $this->assertSame([$customer->id, 'paid', 'fatoorah', 'PAY-1', $country, self::PRODUCTS[$country]['currency']], [(int) $order->customer_id, $order->payment_status, $order->payment_method, $order->transaction_ref, $order->storefront_country, $order->currency_code]);
        $this->assertSame(9, $this->stockOf(country: $country), 'it took its unit');
        $this->assertSame(0, DB::table('carts')->where('customer_id', $customer->id)->count(), 'and its cart');
        $this->assertSame(1, DB::table('payment_requests')->where('is_paid', 1)->count());
        $this->assertANewSaleFollowsTheStop(shopper: $shopper, country: $country, mode: $mode);
    }

    #[DataProvider('everyStopAndItsControl')]
    public function test_a_notice_the_gateway_repeats_after_the_stop_and_the_return_of_the_customer_are_answered_as_before(string $country, ?string $mode): void
    {
        $this->skipInStrictModeUntilThePaymentStartIsFixed();
        [, $reference] = $this->startAPayment(country: $country);
        $this->theGatewayReportsThePayment(reference: $reference, country: $country)->assertOk()->assertJson(['success' => true, 'status' => 'settled']);
        $shopper = $this->stopWithANewSaleWaiting(country: $country, mode: $mode);

        $this->theGatewayReportsThePayment(reference: $reference, country: $country)->assertOk()->assertJson(['success' => true, 'status' => 'duplicate']);
        $this->asAnOutsider()->get(self::GATEWAY_RETURN . '?reference=' . $reference . '&paymentId=PAY-1')->assertRedirectContains('payment-success');

        $this->assertSame(1, DB::table('orders')->count(), 'the repeated notice made no second order');
        $this->assertSame(9, $this->stockOf(country: $country), 'and took no second unit');
        $this->assertANewSaleFollowsTheStop(shopper: $shopper, country: $country, mode: $mode);
    }

    #[DataProvider('everyStopAndItsControl')]
    public function test_the_due_payment_of_an_earlier_order_is_chosen_in_every_stop_mode(string $country, ?string $mode): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [$customer, $orderId] = $this->placeAnOrder(country: $country);
        OrderEditHistory::create(['order_id' => $orderId, 'order_amount' => self::PRODUCTS[$country]['price'], 'order_due_amount' => 10, 'order_due_payment_status' => 'unpaid']);
        DB::table('offline_payment_methods')->insert(['id' => 1, 'method_name' => 'Bank transfer', 'method_informations' => json_encode([['customer_input' => 'transaction_id', 'customer_placeholder' => 'Transaction id']]), 'status' => 1]);
        $shopper = $this->stopWithANewSaleWaiting(country: $country, mode: $mode);

        $byCod = $this->asTheApp(customer: $customer, country: $country)->postJson(self::DUE_BY_COD, ['order_id' => $orderId, 'payment_method' => 'cash_on_delivery'])->assertOk();
        $this->assertSame(translate('payment_method_updated'), $byCod->json('message'));
        $this->assertSame('cash_on_delivery', OrderEditHistory::where('order_id', $orderId)->value('order_due_payment_method'));

        $byOffline = $this->asTheApp(customer: $customer, country: $country)->postJson(self::DUE_BY_OFFLINE, [
            'order_id' => $orderId, 'payment_method' => 'offline_payment', 'method_id' => 1, 'order_due_payment_note' => 'Paid by transfer', 'method_informations' => base64_encode(json_encode(['transaction_id' => 'TR-1'])),
        ])->assertOk();
        $this->assertSame(translate('Payment_Method_Updated'), $byOffline->json('message'));
        $history = OrderEditHistory::where('order_id', $orderId)->firstOrFail();
        $this->assertSame(['offline_payment', 'Paid by transfer', ['method_id' => 1, 'method_name' => 'Bank transfer', 'transaction_id' => 'TR-1']], [$history->order_due_payment_method, $history->order_due_payment_note, $history->order_due_payment_info]);
        $this->assertANewSaleFollowsTheStop(shopper: $shopper, country: $country, mode: $mode);
    }

    // ---- shipments ---------------------------------------------------------------------------------------------------------------------------

    #[DataProvider('everyStopAndItsControl')]
    public function test_the_status_the_courier_reports_for_an_earlier_order_is_taken_and_shown_in_every_stop_mode(string $country, ?string $mode): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [$customer, $orderId] = $this->placeAnOrder(country: $country);
        $reference = $this->handTheOrderToTheCourier(orderId: $orderId);
        $shopper = $this->stopWithANewSaleWaiting(country: $country, mode: $mode);

        $this->asAnOutsider()->postJson(self::COURIER_WEBHOOK, $this->lastmileNotice(reference: $reference, status: 'OUT_FOR_DELIVERY'))->assertOk()->assertJson(['success' => true, 'status' => 'accepted']);

        $eventId = (int) DB::table('provider_fulfillment_events')->value('id');
        $this->assertSame(EventOutcome::APPLIED, app(WebhookEventProcessor::class)->process(eventId: $eventId), 'the worker that applies the stored notice');
        $this->assertSame(FulfillmentStatus::OutForDelivery->value, DB::table('provider_fulfillments')->where('order_id', $orderId)->value('status'));
        $this->asTheApp(customer: $customer, country: $country)->getJson(self::TRACK . '?order_id=' . $orderId)->assertOk()
            ->assertJsonPath('fulfillments.shipments.0.status', 'on_the_way')
            ->assertJsonPath('fulfillments.shipments.0.tracking_number', 'KSA100422577363');
        $this->assertANewSaleFollowsTheStop(shopper: $shopper, country: $country, mode: $mode);
    }

    // ---- support ----------------------------------------------------------------------------------------------------------------------------

    #[DataProvider('everyStopAndItsControl')]
    public function test_the_customer_of_an_earlier_order_still_writes_to_support_in_every_stop_mode(string $country, ?string $mode): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [$customer, $orderId] = $this->placeAnOrder(country: $country);
        $this->asTheApp(customer: $customer, country: $country)
            ->postJson(self::SUPPORT_TICKET . '/create', ['subject' => 'Order ' . $orderId, 'type' => 'Complaint', 'priority' => 'Urgent', 'description' => 'Where is my order?'])
            ->assertOk();
        $ticketId = (int) DB::table('support_tickets')->value('id');
        $shopper = $this->stopWithANewSaleWaiting(country: $country, mode: $mode);

        $reply = $this->asTheApp(customer: $customer, country: $country)->postJson(self::SUPPORT_TICKET . '/reply/' . $ticketId, ['message' => 'Any news on my order?'])->assertOk();
        $this->assertSame('Support ticket reply sent.', $reply->json('message'));
        $this->assertSame(['Any news on my order?'], DB::table('support_ticket_convs')->where('support_ticket_id', $ticketId)->pluck('customer_message')->all());
        $this->assertSame('open', DB::table('support_tickets')->where('id', $ticketId)->value('status'));
        $conversation = $this->asTheApp(customer: $customer, country: $country)->getJson(self::SUPPORT_TICKET . '/conv/' . $ticketId)->assertOk();
        $this->assertSame(['Where is my order?', 'Any news on my order?'], collect($conversation->json())->pluck('customer_message')->all());

        $sent = $this->asTheApp(customer: $customer, country: $country)->postJson(self::CHAT . '/send-message/seller', ['id' => 0, 'message' => 'Hello support'])->assertOk();
        $this->assertSame('Hello support', $sent->json('message'));
        $this->assertSame([[$customer->id, 0, 'Hello support']], DB::table('chattings')->get()->map(fn (object $chat) => [(int) $chat->user_id, (int) $chat->admin_id, $chat->message])->all());
        $messages = $this->asTheApp(customer: $customer, country: $country)->getJson(self::CHAT . '/get-messages/seller/0?limit=10&offset=1')->assertOk();
        $this->assertSame(['Hello support'], collect($messages->json('message'))->pluck('message')->all());
        $this->assertANewSaleFollowsTheStop(shopper: $shopper, country: $country, mode: $mode);
    }

    // ---- settlements ------------------------------------------------------------------------------------------------------------------------

    #[DataProvider('everyStopAndItsControl')]
    public function test_the_earnings_of_an_earlier_order_are_booked_when_it_is_delivered_after_the_stop_in_every_stop_mode(string $country, ?string $mode): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [, $orderId] = $this->placeAnOrder(country: $country);
        $shopper = $this->stopWithANewSaleWaiting(country: $country, mode: $mode);
        $this->markDelivered(orderId: $orderId);
        $price = self::PRODUCTS[$country]['price'];

        OrderManager::getWalletManageOnOrderStatusChange(order: Order::findOrFail($orderId), received_by: 'delivery man');

        $transaction = DB::table('order_transactions')->where('order_id', $orderId)->first();
        $this->assertNotNull($transaction, 'the earnings of the order are booked');
        $this->assertSame(['disburse', 'delivery man'], [$transaction->status, $transaction->received_by]);
        $this->assertEquals($price, $transaction->seller_amount);
        $this->assertEquals($price, DB::table('admin_wallets')->where('admin_id', 1)->value('inhouse_earning'));
        $this->assertANewSaleFollowsTheStop(shopper: $shopper, country: $country, mode: $mode);
    }

    // ---- what is pinned and not driven --------------------------------------------------------------------------------------------------------

    public function test_no_route_that_serves_an_earlier_order_carries_the_sale_gate_or_the_maintenance_gate(): void
    {
        foreach (self::SERVING_ACTIONS as $action) {
            foreach ($this->routesRunning(action: $action) as $route) {
                $this->assertStopGatesAbsent(route: $route, found: $action);
            }
        }
    }

    public function test_no_gateway_callback_webhook_or_payment_route_carries_the_sale_gate_or_the_maintenance_gate(): void
    {
        foreach (self::GATEWAY_AND_WEBHOOK_PREFIXES as $prefix) {
            $routes = collect(Route::getRoutes()->getRoutes())->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), $prefix));

            $this->assertNotEmpty($routes, "No route has an address under [{$prefix}].");

            foreach ($routes as $route) {
                $this->assertStopGatesAbsent(route: $route, found: $prefix);
            }
        }
    }

    public function test_no_settlement_route_of_a_seller_a_vendor_a_courier_or_the_staff_carries_a_storefront_gate(): void
    {
        foreach (self::SETTLEMENT_ACTIONS as $action) {
            foreach ($this->routesRunning(action: $action) as $route) {
                $this->assertMatchesRegularExpression(self::SETTLEMENT_ADDRESS, $route->uri(), "The sweep by address below reaches {$action}.");
                $this->assertStopGatesAbsent(route: $route, found: $action);
            }
        }

        foreach (collect(Route::getRoutes()->getRoutes())->filter(fn (RoutingRoute $route) => preg_match(self::SETTLEMENT_ADDRESS, $route->uri()) === 1) as $route) {
            $this->assertStopGatesAbsent(route: $route, found: 'the sweep by address');
        }
    }

    /** @return list<RoutingRoute> every route that runs the action: the web and the API often share a controller method name. */
    private function routesRunning(string $action): array
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $candidate) => str_ends_with((string) $candidate->getActionName(), $action))
            ->values()
            ->all();

        $this->assertNotEmpty($routes, "No route runs [{$action}].");

        return $routes;
    }

    /** The middleware a route is declared with, not gatherMiddleware(): that builds the controller of every route, and some controllers need a service this test has no credentials for. */
    private function assertStopGatesAbsent(RoutingRoute $route, string $found): void
    {
        $this->assertNotContains(self::SALE_GATE, $route->middleware(), "{$route->methods()[0]} {$route->uri()} ({$found}) serves what a customer already has and must stay open when the storefront is stopped.");
        $this->assertNotContains(self::MAINTENANCE_GATE, $route->middleware(), "{$route->methods()[0]} {$route->uri()} ({$found}) must stay open in a maintenance.");
    }

    // ---- the gateway ------------------------------------------------------------------------------------------------------------------------

    private function skipInStrictModeUntilThePaymentStartIsFixed(): void
    {
        if (getenv('DB_STRICT_TESTS') === '1') {
            $this->markTestSkipped('The start of a digital payment reads the cart with a bare GROUP BY that a strict server refuses, as the checkout does with the use count of a coupon.');
        }
    }

    /**
     * A customer who has started a payment of the storefront's cart on its gateway: through the real route of the app, then the hosted page the way the customer's browser opens it. The money
     * is not taken yet. The gateway is configured the way an operator does it for the storefront, in the currency of its catalogue.
     *
     * @return array{0: User, 1: string} the customer and the reference the gateway knows the payment by
     */
    private function startAPayment(string $country): array
    {
        Schema::create('addon_settings', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('key_name')->nullable();
            $table->text('live_values')->nullable();
            $table->text('test_values')->nullable();
            $table->string('settings_type')->nullable();
            $table->string('mode')->default('test');
            $table->integer('is_active')->default(0);
            $table->text('additional_data')->nullable();
            $table->timestamps();
        });
        $this->runMyFatoorahMigrations();
        $this->digitalPaymentOn();
        config([
            'myfatoorah.enabled' => true,
            'myfatoorah.currency' => self::PRODUCTS[$country]['currency'],
            'myfatoorah.webhook.require_signature' => true,
            'myfatoorah.http.max_retries' => 0,
            'myfatoorah.http.retry_base_ms' => 1,
            'gcc.storefronts.currency.verified_three_decimal_gateways' => ['fatoorah'],
        ]);
        $gateway = app(GatewayConfigRepository::class);
        $gateway->save(active: true, mode: 'test', secrets: ['api_key' => self::GATEWAY_KEY, 'webhook_secret' => self::GATEWAY_SECRET], secretsMode: 'test');
        $gateway->forget();

        $customer = $this->makeCustomer();
        $this->fillTheCartFrom(customer: $customer, country: $country, productId: self::PRODUCTS[$country]['id']);
        $this->asTheApp(customer: $customer, country: $country)->postJson(self::DIGITAL_PAYMENT, [
            'payment_method' => 'fatoorah', 'payment_platform' => 'app', 'payment_request_from' => 'app', 'customer_id' => $customer->id, 'is_guest' => 0,
        ])->assertOk();

        Http::fake(['*/v3/payments' => Http::response(body: ['IsSuccess' => true, 'Data' => ['InvoiceId' => 'INV-1', 'PaymentId' => null, 'PaymentURL' => self::HOSTED_PAGE]], status: 201)]);
        $this->asAnOutsider()->get('/payment/myfatoorah/pay?payment_id=' . DB::table('payment_requests')->value('id'))->assertRedirect(self::HOSTED_PAGE);

        return [$customer, (string) DB::table('myfatoorah_payment_attempts')->value('external_identifier')];
    }

    /** The signed notice MyFatoorah sends when a payment is paid, and the answer of the gateway to the read that the notice makes the store do. */
    private function theGatewayReportsThePayment(string $reference, string $country): TestResponse
    {
        $amount = number_format(self::PRODUCTS[$country]['price'], 2, '.', '');
        $currency = self::PRODUCTS[$country]['currency'];
        $notice = [
            'Event' => ['Code' => 1, 'Name' => 'PAYMENT_STATUS_CHANGED', 'CountryIsoCode' => 'SAU', 'CreationDate' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'Reference' => 'EV-1'],
            'Data' => ['Invoice' => ['Id' => 'INV-1', 'Status' => 'PAID', 'ExternalIdentifier' => $reference], 'Transaction' => ['Status' => 'SUCCESS', 'PaymentId' => 'PAY-1']],
        ];
        Http::fake(['*/v3/payments/*' => Http::response(body: ['IsSuccess' => true, 'Message' => '', 'ValidationErrors' => null, 'Data' => [
            'Invoice' => ['Id' => 'INV-1', 'Status' => 'PAID', 'Reference' => '2026000001', 'ExternalIdentifier' => $reference, 'UserDefinedField' => null],
            'Transaction' => ['Id' => 'TXN-1', 'Status' => 'SUCCESS', 'PaymentMethod' => 'MADA', 'PaymentId' => 'PAY-1', 'ReferenceId' => 'REF-1', 'TrackId' => 'TRK-1', 'AuthorizationId' => 'AUTH-1', 'Error' => ['Code' => '', 'Message' => '', 'ResponseCode' => '00']],
            'Amount' => ['BaseCurrency' => $currency, 'ValueInBaseCurrency' => $amount, 'DisplayCurrency' => $currency, 'ValueInDisplayCurrency' => $amount, 'PayCurrency' => $currency, 'ValueInPayCurrency' => $amount, 'ServiceCharge' => '0.00', 'ReceivableAmount' => $amount],
        ]])]);
        $signature = app(WebhookSignatureVerifier::class)->expectedSignature(payload: $notice, eventCode: 1, secret: self::GATEWAY_SECRET);

        return $this->asAnOutsider()->postJson(self::GATEWAY_WEBHOOK, $notice, ['MyFatoorah-Signature' => $signature]);
    }

    // ---- the courier ------------------------------------------------------------------------------------------------------------------------

    /** The courier holds the order and has told the store so: a shipment of the provider, submitted. @return string the reference the courier knows it by */
    private function handTheOrderToTheCourier(int $orderId): string
    {
        $this->runFulfillmentMigrations();
        FulfillmentSchema::flush();
        config(['fulfillment.enabled' => true]);
        app(ProviderConnectionManager::class)->save(
            provider: ProviderCode::TorodPoint,
            enabled: true,
            baseUrl: null,
            settings: ['webhooks_enabled' => true, 'company_id' => '714'],
            secrets: ['webhook_secret' => self::COURIER_SECRET],
        );

        $reference = $orderId . '-TP1';
        DB::table('provider_fulfillments')->insert([
            'reference' => $reference, 'order_id' => $orderId, 'provider' => ProviderCode::TorodPoint->value, 'generation' => 1, 'idempotency_key' => hash('sha256', $reference),
            'status' => FulfillmentStatus::Submitted->value, 'provider_reference' => 'O0015030400001', 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $reference;
    }

    /** @return array<string, mixed> a last-mile notice as the courier sends it, naming the shipment by the invoice number the store gave it */
    private function lastmileNotice(string $reference, string $status): array
    {
        return [
            'packageId' => 368200054, 'newStatus' => $status, 'bundleId' => 0, 'barcode' => 'KSA100422577363', 'cod' => 0, 'invoiceNumber' => $reference,
            'time' => (int) (microtime(true) * 1000), 'paymentType' => 'cash',
        ];
    }
}
