<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;

/**
 * A guest order is stored with customer_id = guest_id and is_guest = 1, so registered customer 7 and guest 7 share a customer_id.
 * A signed-in customer must only reach orders placed while signed in (is_guest = 0); the guest keeps reaching its own order through
 * the guest_id path.
 */
class ApiCustomerOrderOwnershipTest extends ApiAddressTestCase
{
    private const SHARED_ID = 7;
    private const GUEST_ORDER = 701;
    private const GUEST_ORDER_LINE = 801;
    private const OWN_ORDER = 702;
    private const OWN_ORDER_LINE = 802;

    /** @var array<string, string> */
    private array $languageFileHashes = [];

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->languageFileHashes = $this->hashLanguageFiles();
        $this->createOrderPipelineTables();
        $this->createOrderReadTables();

        $this->customer = $this->makeCustomer(['id' => self::SHARED_ID]);
        $this->placeOrder(orderId: self::GUEST_ORDER, orderLineId: self::GUEST_ORDER_LINE, isGuest: true);
        $this->placeOrder(orderId: self::OWN_ORDER, orderLineId: self::OWN_ORDER_LINE, isGuest: false);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();
        $this->assertSame($this->languageFileHashes, $this->hashLanguageFiles(), 'resources/lang must be left as it was');

        parent::tearDown();
    }

    /** @return array<string, array{string, string, array<string, int|string>}> */
    public static function customerOrderEndpoints(): array
    {
        return [
            'refund store' => ['post', '/api/v1/customer/order/refund-store', ['order_details_id' => 'line', 'amount' => 1, 'refund_reason' => 'Damaged']],
            'refund request' => ['get', '/api/v1/customer/order/refund', ['order_details_id' => 'line']],
            'refund details' => ['get', '/api/v1/customer/order/refund-details', ['id' => 'line']],
            'cancel order' => ['get', '/api/v1/order/cancel-order', ['order_id' => 'order']],
            'track' => ['get', '/api/v1/order/track', ['order_id' => 'order']],
            'get order by id' => ['get', '/api/v1/customer/order/get-order-by-id', ['order_id' => 'order']],
            'invoice' => ['get', '/api/v1/customer/order/generate-invoice', ['order_id' => 'order']],
        ];
    }

    #[DataProvider('customerOrderEndpoints')]
    public function test_a_signed_in_customer_cannot_reach_the_guest_order_that_shares_its_id(string $method, string $uri, array $parameters): void
    {
        $response = $this->callAsCustomer(method: $method, uri: $uri, parameters: $this->resolve(parameters: $parameters, orderId: self::GUEST_ORDER, orderLineId: self::GUEST_ORDER_LINE));

        $response->assertNotFound();
        $this->assertSame('pending', DB::table('orders')->where('id', self::GUEST_ORDER)->value('order_status'));
        $this->assertSame(0, DB::table('refund_requests')->count());
    }

    public function test_a_signed_in_customer_still_reaches_its_own_order(): void
    {
        $this->callAsCustomer(method: 'get', uri: '/api/v1/order/track', parameters: ['order_id' => self::OWN_ORDER])
            ->assertOk()->assertJsonPath('id', self::OWN_ORDER);
        $this->callAsCustomer(method: 'get', uri: '/api/v1/customer/order/get-order-by-id', parameters: ['order_id' => self::OWN_ORDER])
            ->assertOk()->assertJsonPath('id', self::OWN_ORDER);
        $this->callAsCustomer(method: 'get', uri: '/api/v1/customer/order/refund', parameters: ['order_details_id' => self::OWN_ORDER_LINE])
            ->assertOk();
        $this->callAsCustomer(method: 'get', uri: '/api/v1/customer/order/refund-details', parameters: ['id' => self::OWN_ORDER_LINE])
            ->assertOk()->assertJsonPath('quntity', 1);
        $this->callAsCustomer(method: 'get', uri: '/api/v1/order/cancel-order', parameters: ['order_id' => self::OWN_ORDER])
            ->assertOk();

        $this->assertSame('canceled', DB::table('orders')->where('id', self::OWN_ORDER)->value('order_status'));
    }

    public function test_the_guest_still_reaches_its_own_order_through_the_guest_path(): void
    {
        $guestQuery = ['order_id' => self::GUEST_ORDER, 'guest_id' => self::SHARED_ID];

        $this->getJson('/api/v1/order/track?' . http_build_query($guestQuery))->assertOk()->assertJsonPath('id', self::GUEST_ORDER);
        $this->getJson('/api/v1/customer/order/get-order-by-id?' . http_build_query($guestQuery))->assertOk()->assertJsonPath('id', self::GUEST_ORDER);
    }

    private function callAsCustomer(string $method, string $uri, array $parameters): TestResponse
    {
        $client = $this->asCustomer($this->customer);

        return $method === 'post'
            ? $client->postJson($uri, $parameters)
            : $client->getJson($uri . '?' . http_build_query($parameters));
    }

    /** @param array<string, int|string> $parameters */
    private function resolve(array $parameters, int $orderId, int $orderLineId): array
    {
        return array_map(
            callback: static fn (int|string $value): int|string => match ($value) {
                'order' => $orderId,
                'line' => $orderLineId,
                default => $value,
            },
            array: $parameters,
        );
    }

    private function placeOrder(int $orderId, int $orderLineId, bool $isGuest): void
    {
        $this->insertOrder($orderId, [
            'customer_id' => self::SHARED_ID, 'is_guest' => $isGuest ? 1 : 0, 'order_status' => 'pending', 'payment_method' => 'cash_on_delivery',
            'order_amount' => 10, 'discount_amount' => 0,
        ]);
        DB::table('order_details')->insert([
            'id' => $orderLineId, 'order_id' => $orderId, 'qty' => 1, 'price' => 10, 'delivery_status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createOrderReadTables(): void
    {
        DB::table('business_settings')->insert(['type' => 'loyalty_point_status', 'value' => '0']);

        Schema::table('order_details', function (Blueprint $table) {
            $table->integer('refund_request')->default(0);
            $table->dateTime('refund_started_at')->nullable();
            $table->boolean('is_stock_decreased')->default(true);
        });

        Schema::create('delivery_men', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });

        Schema::create('order_delivery_verifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->timestamps();
        });

        Schema::create('order_edit_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->decimal('order_due_amount', 16, 2)->default(0);
            $table->string('order_due_payment_status')->nullable();
            $table->decimal('order_return_amount', 16, 2)->default(0);
            $table->string('order_return_payment_status')->nullable();
            $table->timestamps();
        });

        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_details_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('status')->nullable();
            $table->decimal('amount', 16, 2)->default(0);
            $table->text('refund_reason')->nullable();
            $table->longText('images')->nullable();
            $table->timestamps();
        });

        Schema::create('refund_statuses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('refund_request_id');
            $table->timestamps();
        });

        Schema::create('order_details_rewards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_details_id');
            $table->string('reward_type')->nullable();
            $table->decimal('reward_amount', 16, 2)->default(0);
            $table->timestamps();
        });
    }

    /** @return array<string, string> */
    private function hashLanguageFiles(): array
    {
        $hashes = [];
        foreach (glob(base_path('resources/lang/*/*.php')) as $path) {
            $hashes[$path] = (string) md5_file($path);
        }

        return $hashes;
    }
}
