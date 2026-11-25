<?php

namespace Tests\Feature\Payments;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Http\Controllers\Admin\Order\RefundController;
use App\Http\Requests\Admin\RefundStatusRequest;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentItem;
use App\Services\Fulfillment\Data\SubmissionResult;
use App\Services\Fulfillment\FulfillmentRouter;
use App\Services\Fulfillment\FulfillmentSchema;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\ProviderOrderDispatcher;
use App\Services\Refund\RefundClaims;
use App\Services\Storefront\RefundPayout;
use App\Services\RefundStatusService;
use App\Services\RefundTransactionService;
use App\Utils\OrderManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\FakeTorodAdapter;
use Tests\Support\KeepsOrderDebugLogClean;

/**
 * D2-18: ONE payment carries through to fulfilment submission and a refund, without a second order and without a second charge.
 *
 * Every other test on this axis proves one hop. The paid callback becoming an order is pinned (PaidCallbackBuildsOneOrderTest), the
 * dispatcher's submission is pinned (Fulfillment DispatcherTest), the refund decision is pinned (the Refund suite) -- and each of those
 * builds its own order out of an INSERT, so no test has ever asked whether the thing a gateway paid for is the thing a courier is given
 * and the thing a refund gives back. Two tests that each assume the other's half prove no span: a second order written anywhere along
 * this chain, or a second charge, would be invisible to all three.
 *
 * So the whole chain runs here, in one process, against one payment row:
 *
 *   1. the gateway's settlement hook calls the real order writer with the payment's own row and reference;
 *   2. the real router splits the order's frozen ownership snapshots into a provider shipment;
 *   3. the real dispatcher submits that shipment to a FAKE courier (no test may reach a provider);
 *   4. the real admin refund decision refunds the line through the real controller.
 *
 * What is then asserted is the identity of the thing that moved -- the shipment's order and the refund's order are the order the payment
 * created -- and, after the whole lifecycle, that a second delivery of the same gateway callback adds NOTHING: no order, no charge, no
 * shipment, no refund. That last case is the one a per-hop test cannot express at all, because its order was never paid for.
 *
 * NOT proved here, deliberately: the cross-border half of D2-18 (D2-06's route eligibility does not exist), and the vendor-ledger
 * reversal, whose `order_item_commissions` table has no migration in this repository -- `RefundReversalService` answers null without it,
 * which is the shape a Saudi installation of today runs in.
 *
 * TEETH, measured on MariaDB 10.11.14 by breaking each guard in turn and restoring it:
 *
 *   - taking out `OneOrderPerPayment::mayBuild` in `OrderManager::generateOrder` -> 1 red (a second order, 100002, for one payment);
 *   - making `OrderItemOwnershipSnapshotter::capture` always answer null -> 2 red and 1 error (no shipment at all);
 *   - taking out the dispatcher's `provider_reference !== null` guard -> 1 red (the courier asked twice for one payment).
 *
 * And one mutation came back INERT, which is a finding rather than a tidy-up: widening `ProviderOrderDispatcher::isDispatchable()`'s
 * `default` from false to true changes NOTHING here, because the `provider_reference` guard five lines later catches the same case first.
 * The status gate is a second line of defence on this path, not the one that holds it; a test that claimed the status gate as its evidence
 * would be claiming coverage it does not have.
 *
 * ENGINES: 5 tests / 37 assertions green on MariaDB 10.11.14 (`-c phpunit.mysql.xml`) and on SQLite. The real engine was not a formality
 * here either: the refund fixture passed on MariaDB without `refund_requests.product_id` or `refund_reason`, because this platform sets
 * `strict => false`, and SQLite refused the same row outright. The fixture now fills every NOT NULL column the real migration declares.
 */
class PaymentReachesFulfilmentAndRefundTest extends ApiAddressTestCase
{
    use KeepsOrderDebugLogClean;

    private const REFERENCE = 'TXN-LIFECYCLE-ONE';

    /** `payment_requests.id` is a uuid on this platform: the payment module writes one row per payment. */
    private const PAYMENT_ROW = '6b1d9a04-3c77-4f0e-8a21-9d4c7e5b0f31';

    private const PRICE = 120.0;

    private FakeTorodAdapter $courier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPipelineTables();
        $this->createRefundDecisionTables();
        $this->runFulfilmentMigrations();
        $this->useTaxIncludedPrices();
        $this->rememberOrderDebugLog();
        $this->sellInRiyals();
        $this->useAFakeCourier();
        $this->storeThePaymentRow();
    }

    protected function tearDown(): void
    {
        $this->restoreOrderDebugLog();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- the world

    /**
     * One migration file, loaded once per process.
     *
     * The older migrations of this repository declare a NAMED class (`CreateRefundRequestsTable`), so `require`ing the file again for the
     * next test is a fatal error and not a failing test. The tables themselves are dropped between tests by the base case, so the object
     * is what has to be remembered, not its effect.
     *
     * @var array<string, object>
     */
    private static array $migrations = [];

    private function migration(string $file): object
    {
        if (isset(self::$migrations[$file])) {
            return self::$migrations[$file];
        }

        $loaded = require database_path('migrations/' . $file);

        // A newer migration file RETURNS its anonymous class; an older one declares a named class and returns nothing, and Laravel's own
        // migrator resolves that name from the file name the same way.
        return self::$migrations[$file] = is_object($loaded)
            ? $loaded
            : new ('\\' . Str::studly(implode('_', array_slice(explode('_', basename($file, '.php')), 4))))();
    }

    /**
     * The refund decision's own tables, from the REAL migrations wherever the repository has one.
     *
     * `createOrderPipelineTables()` builds the checkout's world and stops where the checkout stops, so the four tables an admin refund
     * writes are missing from it. They are added rather than redefined: a refund amount's column type is exactly the kind of thing an
     * inferred definition gets wrong, and `2026_01_22_164519_update_refund_requests_amount_column` exists precisely because it was once
     * wrong in production.
     */
    private function createRefundDecisionTables(): void
    {
        foreach ([
            '2022_02_14_114359_create_refund_requests_table.php',
            '2022_02_14_115757_add_refund_request_to_order_details_table.php',
            '2022_02_24_091236_add_multiple_column_to_refund_requests_table.php',
            '2024_02_01_200417_add_denied_count_and_approved_count_to_refund_requests_table.php',
            '2024_03_12_123322_update_images_column_in_refund_requests_table.php',
            '2026_01_22_164519_update_refund_requests_amount_column.php',
            '2022_02_24_103827_create_refund_statuses_table.php',
            '2022_02_15_121410_create_refund_transactions_table.php',
            '2022_03_01_121420_add_refund_id_to_refund_transactions_table.php',
            '2026_01_22_164609_update_refund_transactions_amount_column.php',
            '2026_12_05_100000_add_original_payment_to_refund_transactions_table.php',
            '2025_07_24_104352_create_order_details_rewards_table.php',
            '2026_01_11_211839_create_order_edit_histories_table.php',
        ] as $migration) {
            $this->migration($migration)->up();
        }

        // The seller's running total the refund decision debits. Inferred, like its siblings in the pipeline harness: the real table comes
        // from the installation dump and only this one column is read here.
        if (!Schema::hasTable('seller_wallets')) {
            Schema::create('seller_wallets', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('seller_id');
                $table->decimal('total_earning', 40, 2)->default(0);
                $table->decimal('withdrawn', 40, 2)->default(0);
                $table->timestamps();
            });
        }
    }

    /** The fulfilment feature's REAL migrations, which is how every fulfilment test gets its tables. */
    private function runFulfilmentMigrations(): void
    {
        FulfillmentSchema::flush();

        foreach ([
            '2026_11_05_100000_create_provider_fulfillment_tables.php',
            '2026_11_05_100100_add_fulfillment_provider_to_products_table.php',
        ] as $migration) {
            $this->migration($migration)->up();
        }

        config([
            'fulfillment.enabled' => true,
            'fulfillment.external_writes_enabled' => true,
            'fulfillment.currency' => 'SAR',
            'fulfillment.cod.allow_mixed' => false,
        ]);
    }

    /** A single-currency riyal store: the amounts the order stores ARE riyals, which is what a Saudi installation is. */
    private function sellInRiyals(): void
    {
        DB::table('currencies')->insertOrIgnore([
            'id' => 1, 'name' => 'Saudi Riyal', 'code' => 'SAR', 'symbol' => 'SAR', 'exchange_rate' => 1, 'status' => 1,
        ]);
        DB::table('business_settings')->insert([
            ['type' => 'currency_model', 'value' => json_encode('single_currency')],
            ['type' => 'system_default_currency', 'value' => json_encode(1)],
            ['type' => 'decimal_point_settings', 'value' => json_encode(2)],
            ['type' => 'loyalty_point_status', 'value' => json_encode(0)],
        ]);
        Cache::flush();
    }

    /** No test may ever reach a real courier: the adapter is a scriptable fake and the provider is switched on through its real manager. */
    private function useAFakeCourier(): void
    {
        config(['fulfillment.providers.torod_point.adapter' => FakeTorodAdapter::class]);
        $this->app->singleton(FakeTorodAdapter::class);
        $this->courier = $this->app->make(FakeTorodAdapter::class);

        app(ProviderConnectionManager::class)->save(
            provider: ProviderCode::TorodPoint,
            enabled: true,
            baseUrl: null,
            settings: ['dispatch_enabled' => true, 'auto_dispatch' => true, 'dry_run' => false],
            secrets: [],
        );
    }

    /** The payment the gateway took, in the table the payment module owns. */
    private function storeThePaymentRow(): void
    {
        DB::table('payment_requests')->insert([
            'id' => self::PAYMENT_ROW, 'payment_method' => 'ssl_commerz', 'transaction_id' => self::REFERENCE,
            'payment_amount' => self::PRICE, 'currency_code' => 'SAR', 'is_paid' => 1, 'additional_data' => '{}',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A seller's product owned by the courier, so the checkout freezes an ownership snapshot the router can read. */
    private function sellAProductTheCourierShips(): void
    {
        DB::table('sellers')->insertOrIgnore(['id' => 7, 'f_name' => 'Hana', 'status' => 'approved']);
        DB::table('seller_wallets')->insert(['seller_id' => 7, 'total_earning' => 500, 'withdrawn' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shops')->insertOrIgnore(['id' => 3, 'seller_id' => 7, 'author_type' => 'vendor', 'name' => 'Hana Pharmacy']);

        DB::table('products')->insert([
            'id' => 1, 'name' => 'Paracetamol', 'added_by' => 'seller', 'user_id' => 7, 'variation' => '[]',
            'unit_price' => self::PRICE, 'current_stock' => 10, 'fulfillment_provider' => ProviderCode::TorodPoint->value,
        ]);
    }

    private function fillTheCart(int $customerId): void
    {
        DB::table('carts')->insert([
            'customer_id' => $customerId, 'is_guest' => false, 'cart_group_id' => 'lifecycle-' . $customerId,
            'product_id' => 1, 'quantity' => 1, 'price' => self::PRICE, 'seller_id' => 7, 'seller_is' => 'seller',
            'is_checked' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------- the four hops

    /** Hop 1: the order writer exactly as the gateway's settlement hook calls it -- paid, carrying the reference and the payment's row. */
    private function settleThePayment(object $customer): array
    {
        request()->merge(['user' => $customer]);
        request()->setUserResolver(fn () => $customer);

        return OrderManager::generateOrder(data: [
            'is_guest' => 0, 'customer_id' => $customer->id, 'order_status' => 'confirmed', 'payment_method' => 'ssl_commerz',
            'payment_status' => 'paid', 'transaction_ref' => self::REFERENCE, 'payment_record_id' => self::PAYMENT_ROW,
            'requestObj' => request(), 'enforce_address_rules' => false, 'stock_policy' => 'allow_oversell',
        ]);
    }

    /** Hops 2 and 3: the real router, then the real dispatcher submitting to the fake courier. */
    private function shipTheOrder(int $orderId): ?ProviderFulfillment
    {
        $this->courier->submitQueue = [SubmissionResult::accepted('TRD-' . $orderId, providerInternalId: '900', trackingNumber: 'TRK-' . $orderId, providerCost: 15.0)];

        app(FulfillmentRouter::class)->route($orderId);

        $fulfillment = ProviderFulfillment::where('order_id', $orderId)->first();

        if ($fulfillment !== null) {
            app(ProviderOrderDispatcher::class)->dispatch($fulfillment->id);
        }

        return $fulfillment?->fresh();
    }

    /** Hop 4: the refund the customer asked for, decided by an administrator through the real controller. */
    private function refundTheLine(int $orderId, int $customerId): array
    {
        $line = DB::table('order_details')->where('order_id', $orderId)->first();

        // Every column the real `refund_requests` table declares NOT NULL is filled. MariaDB with this platform's `strict => false` accepts
        // a row without `product_id` and `refund_reason`; SQLite refuses it, so a fixture that skips them passes on one engine only.
        $refundId = DB::table('refund_requests')->insertGetId([
            'order_details_id' => $line->id, 'customer_id' => $customerId, 'order_id' => $orderId, 'product_id' => 1,
            'status' => 'approved', 'amount' => self::PRICE, 'refund_reason' => 'The box arrived open.',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $request = RefundStatusRequest::create('/admin/refund-section/refund/refund-status-update', 'POST', [
            'id' => $refundId, 'refund_status' => 'refunded', 'payment_method' => 'customer_wallet', 'payment_info' => 'wallet',
        ]);

        $answer = app(RefundController::class)->updateRefundStatus(
            $request,
            app(RefundStatusService::class),
            app(RefundTransactionService::class),
            app(RefundClaims::class),
        )->getData(true);

        return ['id' => $refundId, 'answer' => $answer];
    }

    /** The whole chain, from the payment the gateway took to the money going back. */
    private function runTheWholeLifecycle(): array
    {
        $customer = $this->makeCustomer();
        $this->sellAProductTheCourierShips();
        $this->fillTheCart($customer->id);

        $orderIds = $this->settleThePayment($customer);
        $this->assertCount(1, $orderIds, 'one payment, one order');

        $orderId = (int) $orderIds[0];
        $shipment = $this->shipTheOrder($orderId);
        $refund = $this->refundTheLine($orderId, $customer->id);

        return ['customer' => $customer, 'order_id' => $orderId, 'shipment' => $shipment, 'refund' => $refund];
    }

    private function chargeCount(): int
    {
        return DB::table('order_transactions')->count();
    }

    // ---------------------------------------------------------------- what the criterion asks

    public function test_one_payment_becomes_one_order_one_shipment_and_one_refund(): void
    {
        $lifecycle = $this->runTheWholeLifecycle();

        $this->assertSame(1, DB::table('orders')->count(), 'one payment must leave exactly one order behind');
        $this->assertSame(1, DB::table('provider_fulfillments')->count(), 'one order, one shipment');
        $this->assertSame(1, DB::table('refund_transactions')->count(), 'one refund decision, one refund transaction');
        $this->assertSame(1, $this->chargeCount(), 'one payment must be charged once');
        // The controller answers either a `message` or an `error`; the text itself is a translated phrase and is not what is pinned here.
        $this->assertArrayNotHasKey('error', $lifecycle['refund']['answer']);
        $this->assertArrayHasKey('message', $lifecycle['refund']['answer']);
    }

    public function test_the_shipment_the_courier_is_given_is_the_order_the_payment_created(): void
    {
        $lifecycle = $this->runTheWholeLifecycle();

        $shipment = $lifecycle['shipment'];
        $this->assertNotNull($shipment, 'the paid order must reach the courier');
        $this->assertSame($lifecycle['order_id'], (int) $shipment->order_id);
        $this->assertSame(FulfillmentStatus::Submitted, $shipment->status);
        $this->assertSame('TRD-' . $lifecycle['order_id'], $shipment->provider_reference);

        // The line the courier was given is the line the payment paid for, through the ownership snapshot the checkout froze -- not a
        // fresh look at the product, which is what makes a shipment in flight immune to a later reassignment.
        $item = ProviderFulfillmentItem::where('order_id', $lifecycle['order_id'])->firstOrFail();
        $this->assertSame((int) DB::table('order_details')->where('order_id', $lifecycle['order_id'])->value('id'), (int) $item->order_detail_id);
        $this->assertSame(ProviderCode::TorodPoint->value, $item->provider);

        $this->assertSame(['submit:' . $shipment->reference . ':1'], $this->courier->calls, 'the courier is asked once');
    }

    public function test_the_refund_names_the_order_and_the_payment_that_paid_for_it(): void
    {
        $lifecycle = $this->runTheWholeLifecycle();

        $transaction = DB::table('refund_transactions')->first();
        $this->assertSame($lifecycle['order_id'], (int) $transaction->order_id);
        $this->assertSame($lifecycle['refund']['id'], (int) $transaction->refund_id);
        $this->assertSame((float) self::PRICE, (float) $transaction->amount);
        $this->assertSame((int) DB::table('order_details')->where('order_id', $lifecycle['order_id'])->value('id'), (int) $transaction->order_details_id);

        // The refund's record of the ORIGINAL payment (D1-10) is empty, and that is the state this platform ships in: `RefundPayout`
        // answers nothing while `gcc.storefronts.refunds.from_original_payment` is off, so the lifecycle running here is the one a Saudi
        // installation runs today. Asserted rather than left unsaid, because a lifecycle test that quietly turned a dormant money switch
        // on would be proving a platform nobody is running.
        $this->assertFalse((bool) config(RefundPayout::SETTING), 'the original-payment payout ships off');
        $this->assertNull($transaction->original_payment_currency);
        $this->assertNull($transaction->original_payment_amount);

        // The order the refund names is the order the gateway's reference is on, so the whole chain is one payment and not two.
        $this->assertSame(self::REFERENCE, DB::table('orders')->where('id', $lifecycle['order_id'])->value('transaction_ref'));
        $this->assertSame(1, DB::table('payment_requests')->where('transaction_id', self::REFERENCE)->count(), 'one payment row');
        $this->assertSame(1, DB::table('orders')->where('transaction_ref', self::REFERENCE)->count(), 'and one order carrying it');
        $this->assertSame('refunded', DB::table('refund_requests')->where('id', $lifecycle['refund']['id'])->value('status'));
    }

    /**
     * The case no per-hop test can express: the gateway calls back again AFTER the order has shipped and been refunded.
     *
     * A retried webhook, an operator re-running a sweep and a customer reloading the return page all do this. The claim that stops a
     * second order is made on the payment's own row inside the order's transaction, so it still holds once the order has moved on --
     * which is the property this asserts, because a guard that only works before the first shipment is not a guard.
     */
    public function test_the_gateway_calling_back_again_after_the_whole_lifecycle_adds_nothing(): void
    {
        $lifecycle = $this->runTheWholeLifecycle();

        $before = [
            'orders' => DB::table('orders')->count(),
            'charges' => $this->chargeCount(),
            'shipments' => DB::table('provider_fulfillments')->count(),
            'refunds' => DB::table('refund_transactions')->count(),
            'items' => DB::table('provider_fulfillment_items')->count(),
        ];

        // The shopper went back and filled the cart again before the gateway's second delivery arrived, which is the worst case: there is
        // something to build an order out of.
        $this->fillTheCart($lifecycle['customer']->id);
        $second = $this->settleThePayment($lifecycle['customer']);

        $this->assertSame([], $second, 'a second delivery of one payment builds nothing');
        $this->assertSame($before['orders'], DB::table('orders')->count(), 'no second order');
        $this->assertSame($before['charges'], $this->chargeCount(), 'no second charge');
        $this->assertSame($before['shipments'], DB::table('provider_fulfillments')->count(), 'no second shipment');
        $this->assertSame($before['items'], DB::table('provider_fulfillment_items')->count(), 'no second line for the courier');
        $this->assertSame($before['refunds'], DB::table('refund_transactions')->count(), 'no second refund');
    }

    /** Asking the courier again for a shipment it has already taken must not send a second parcel for one payment. */
    public function test_the_courier_is_not_asked_twice_for_one_payment(): void
    {
        $lifecycle = $this->runTheWholeLifecycle();

        app(FulfillmentRouter::class)->route($lifecycle['order_id']);
        app(ProviderOrderDispatcher::class)->dispatch($lifecycle['shipment']->id);

        $this->assertSame(1, DB::table('provider_fulfillments')->count(), 'routing an order that is already routed creates nothing');
        $this->assertCount(
            1,
            array_filter($this->courier->calls, static fn (string $call): bool => str_starts_with($call, 'submit:')),
            'one payment, one submission',
        );
    }
}
