<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\RestAPI\v3\seller\OrderEditController;
use App\Services\OrderEditService;
use ArrayObject;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\Support\BuildsSellerApiWorld;
use Tests\TestCase;

/**
 * The seller app edits an order, switches its due amount to cash on delivery and reads its status history by an order id the app
 * sends. The permission middleware only says that the caller may work on orders, so each handler must still answer only for an order
 * of the caller's own shop: another seller's order, and the in-house shop's order whose seller_id happens to equal this seller's id,
 * are not found, and nothing is read or written.
 *
 * Editing is tested with the order writers replaced by recorders (the real ones rewrite taxes, transactions and notifications, and
 * are tested with the edit-order feature): what is under test is which order the handler hands to them.
 */
class SellerApiOrderOwnershipTest extends TestCase
{
    use BuildsSellerApiWorld;

    private const OWN_ORDER = 100;
    private const IN_HOUSE_ORDER = 200;
    private const OTHER_SELLERS_ORDER = 300;

    private ArrayObject $edits;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSellerApiWorld();
        $this->createOrderTables();
        $this->seedOrders();
        $this->replaceTheOrderWriters();
    }

    protected function tearDown(): void
    {
        $this->tearDownSellerApiWorld();

        parent::tearDown();
    }

    // ---- editing ----

    public function test_validating_an_edit_of_another_sellers_order_is_not_found_and_summarises_nothing(): void
    {
        $response = $this->sellerApi('POST', 'orders/edit-order-validation', ['order_id' => self::OTHER_SELLERS_ORDER, 'products' => [['id' => 20, 'qty' => 1]]]);

        $this->assertSame([], $this->edits['summarised']);
        $this->assertNotFound($response, code: 'order');
    }

    public function test_submitting_an_edit_of_another_sellers_order_is_not_found_and_writes_nothing(): void
    {
        $response = $this->sellerApi('POST', 'orders/edit-order-submit', ['order_id' => self::OTHER_SELLERS_ORDER, 'products' => [['id' => 20, 'qty' => 1]]]);

        $this->assertSame([], $this->edits['written']);
        $this->assertNotFound($response, code: 'order');
    }

    public function test_an_edit_of_the_in_house_order_sharing_the_sellers_id_is_not_found_and_writes_nothing(): void
    {
        $validation = $this->sellerApi('POST', 'orders/edit-order-validation', ['order_id' => self::IN_HOUSE_ORDER, 'products' => [['id' => 20, 'qty' => 1]]]);
        $submission = $this->sellerApi('POST', 'orders/edit-order-submit', ['order_id' => self::IN_HOUSE_ORDER, 'products' => [['id' => 20, 'qty' => 1]]]);

        $this->assertSame([], $this->edits['summarised']);
        $this->assertSame([], $this->edits['written']);
        $this->assertNotFound($validation, code: 'order');
        $this->assertNotFound($submission, code: 'order');
    }

    public function test_the_seller_can_still_validate_and_submit_an_edit_of_their_own_order(): void
    {
        $validation = $this->sellerApi('POST', 'orders/edit-order-validation', ['order_id' => self::OWN_ORDER, 'products' => [['id' => 10, 'qty' => 1]]]);
        $submission = $this->sellerApi('POST', 'orders/edit-order-submit', ['order_id' => self::OWN_ORDER, 'products' => [['id' => 10, 'qty' => 1]]]);

        $this->assertSame(200, $validation->getStatusCode());
        $this->assertSame(200, $submission->getStatusCode());
        $this->assertSame([self::OWN_ORDER], $this->edits['summarised']);
        $this->assertSame([['order' => self::OWN_ORDER, 'edit_by' => 'seller', 'edited_user_id' => self::SELLER]], $this->edits['written']);
    }

    // ---- cash on delivery ----

    public function test_switching_another_sellers_order_to_cash_on_delivery_is_not_found_and_changes_nothing(): void
    {
        $response = $this->sellerApi('POST', 'orders/assign-order-in-cod', ['order_id' => self::OTHER_SELLERS_ORDER]);

        $this->assertSame('digital_payment', $this->duePaymentMethodOf(self::OTHER_SELLERS_ORDER));
        $this->assertNotFound($response, code: 'order');
    }

    public function test_switching_the_in_house_order_sharing_the_sellers_id_to_cash_on_delivery_changes_nothing(): void
    {
        $response = $this->sellerApi('POST', 'orders/assign-order-in-cod', ['order_id' => self::IN_HOUSE_ORDER]);

        $this->assertSame('digital_payment', $this->duePaymentMethodOf(self::IN_HOUSE_ORDER));
        $this->assertNotFound($response, code: 'order');
    }

    public function test_the_seller_can_still_switch_their_own_order_to_cash_on_delivery(): void
    {
        $response = $this->sellerApi('POST', 'orders/assign-order-in-cod', ['order_id' => self::OWN_ORDER]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('cash_on_delivery', $this->duePaymentMethodOf(self::OWN_ORDER));
        $this->assertSame('digital_payment', $this->duePaymentMethodOf(self::OTHER_SELLERS_ORDER));
    }

    // ---- status history ----

    public function test_the_status_history_of_another_sellers_order_is_not_found_and_not_disclosed(): void
    {
        $response = $this->sellerApi('GET', 'delivery-man/order-status-history/' . self::OTHER_SELLERS_ORDER);

        $this->assertStringNotContainsString('history-of-order-300', (string) $response->getContent());
        $this->assertNotFound($response, code: 'order');
    }

    public function test_the_status_history_of_the_in_house_order_sharing_the_sellers_id_is_not_found_and_not_disclosed(): void
    {
        $response = $this->sellerApi('GET', 'delivery-man/order-status-history/' . self::IN_HOUSE_ORDER);

        $this->assertStringNotContainsString('history-of-order-200', (string) $response->getContent());
        $this->assertNotFound($response, code: 'order');
    }

    public function test_the_seller_can_still_read_the_status_history_of_their_own_order(): void
    {
        $response = $this->sellerApi('GET', 'delivery-man/order-status-history/' . self::OWN_ORDER);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['history-of-order-100'], array_column($response->json(), 'status'));
    }

    // ---- helpers ----

    private function assertNotFound($response, string $code): void
    {
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame($code, $response->json('errors.0.code'));
    }

    private function duePaymentMethodOf(int $orderId): string
    {
        return (string) DB::table('order_edit_histories')->where('order_id', $orderId)->value('order_due_payment_method');
    }

    private function createOrderTables(): void
    {
        $this->createSellerApiTable('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is')->nullable();
            $table->unsignedBigInteger('shipping_method_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->string('order_status')->default('pending');
            $table->string('payment_status')->default('unpaid');
            $table->string('payment_method')->nullable();
            $table->string('order_type')->default('default_type');
            $table->decimal('order_amount', 16, 2)->default(0);
            $table->boolean('is_guest')->default(false);
            $table->timestamps();
        });
        $this->createSellerApiTable('order_edit_histories', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id');
            $table->string('order_due_payment_method')->nullable();
            $table->string('order_due_payment_status')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('order_status_histories', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_type')->nullable();
            $table->string('status')->nullable();
            $table->text('cause')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('order_details', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->integer('qty')->default(1);
            $table->decimal('price', 16, 2)->default(0);
            $table->timestamps();
        });

        // Tables the edit handlers load the order's relations from.
        $this->createSellerApiTable('shipping_methods', fn (Blueprint $table) => $table->string('title')->nullable());
        $this->createSellerApiTable('shops', function (Blueprint $table) {
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('delivery_men', function (Blueprint $table) {
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('f_name')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('offline_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id');
            $table->timestamps();
        });
        $this->createSellerApiTable('order_delivery_verifications', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id');
            $table->string('image')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('colors', function (Blueprint $table) {
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('storages', function (Blueprint $table) {
            $table->string('data_type');
            $table->unsignedBigInteger('data_id');
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });
    }

    private function seedOrders(): void
    {
        $order = fn (int $id, int $sellerId, string $sellerIs) => DB::table('orders')->insert(['id' => $id, 'seller_id' => $sellerId, 'seller_is' => $sellerIs, 'order_status' => 'pending']);
        $order(self::OWN_ORDER, self::SELLER, 'seller');
        $order(self::IN_HOUSE_ORDER, self::IN_HOUSE_ID, 'admin');
        $order(self::OTHER_SELLERS_ORDER, self::OTHER_SELLER, 'seller');

        foreach ([self::OWN_ORDER, self::IN_HOUSE_ORDER, self::OTHER_SELLERS_ORDER] as $orderId) {
            DB::table('order_edit_histories')->insert(['order_id' => $orderId, 'order_due_payment_method' => 'digital_payment', 'order_due_payment_status' => 'unpaid']);
            DB::table('order_status_histories')->insert(['order_id' => $orderId, 'user_id' => 1, 'user_type' => 'seller', 'status' => 'history-of-order-' . $orderId]);
        }
    }

    /** The edit service answers "success" so the handlers go on to the writers, and both writers only record the order they were handed. */
    private function replaceTheOrderWriters(): void
    {
        $this->edits = new ArrayObject(['summarised' => [], 'written' => []]);
        $edits = $this->edits;

        $this->app->bind(OrderEditService::class, fn () => new class extends OrderEditService {
            public function __construct()
            {
            }

            public function getFormatAPIProductsForEditOrder(object|array $request, object|array $order = [], object|array|null $colors = []): array
            {
                return ['status' => 'success', 'product_list' => []];
            }
        });

        $this->app->bind(OrderEditController::class, function ($app) use ($edits) {
            $dependencies = array_map(
                fn ($parameter) => $app->make($parameter->getType()->getName()),
                (new ReflectionClass(OrderEditController::class))->getConstructor()->getParameters(),
            );

            return new class($edits, ...$dependencies) extends OrderEditController {
                public function __construct(private readonly ArrayObject $edits, ...$dependencies)
                {
                    parent::__construct(...$dependencies);
                }

                public function generateEditOrderSummary(object|array $request = [], object|array $order = [], object|array $editedOrder = [], object|array $data = []): array
                {
                    $this->edits['summarised'] = [...$this->edits['summarised'], $order['id']];

                    return ['status' => 'success', 'order_amount' => (float) $order['order_amount']];
                }

                public function generateEditOrder(object|array $request = [], object|array $order = [], object|array $editedOrder = [], object|array $data = []): array
                {
                    $this->edits['written'] = [...$this->edits['written'], ['order' => $order['id'], 'edit_by' => $data['edit_by'], 'edited_user_id' => $data['edited_user_id']]];

                    return ['status' => 'success', 'message' => 'recorded'];
                }
            };
        });
    }
}
