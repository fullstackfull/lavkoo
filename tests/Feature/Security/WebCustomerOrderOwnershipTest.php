<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Address\StorefrontTestCase;
use Tests\Support\RestoresLanguageFiles;

/**
 * The website counterpart of ApiCustomerOrderOwnershipTest: a signed-in customer 7 must not reach guest 7's order (customer_id 7,
 * is_guest 1) through the account pages, and still reaches the orders it placed while signed in.
 */
class WebCustomerOrderOwnershipTest extends StorefrontTestCase
{
    use RestoresLanguageFiles;

    private const SHARED_ID = 7;
    private const GUEST_ORDER = 701;
    private const GUEST_ORDER_LINE = 801;
    private const OWN_ORDER = 702;
    private const OWN_ORDER_LINE = 802;

    /** @var array<string, string> */
    private array $languageFileHashes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->languageFileHashes = $this->hashLanguageFiles();
        $this->createOrderTables();

        $customer = User::unguarded(static fn () => User::create(['id' => self::SHARED_ID, 'f_name' => 'Sara', 'email' => 'sara7@example.test', 'is_active' => 1]));
        $this->signIn($customer);
        $this->placeOrder(orderId: self::GUEST_ORDER, orderLineId: self::GUEST_ORDER_LINE, isGuest: true);
        $this->placeOrder(orderId: self::OWN_ORDER, orderLineId: self::OWN_ORDER_LINE, isGuest: false);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();
        $this->assertSame($this->languageFileHashes, $this->hashLanguageFiles(), 'resources/lang must be left as it was');

        parent::tearDown();
    }

    public function test_the_refund_request_page_of_the_guest_order_is_not_found(): void
    {
        $this->get(route('refund-request', ['id' => self::GUEST_ORDER_LINE]))->assertNotFound();
    }

    public function test_the_refund_details_of_the_guest_order_are_not_found(): void
    {
        $this->get(route('refund-details', ['id' => self::GUEST_ORDER_LINE]))->assertNotFound();
    }

    public function test_the_invoice_of_the_guest_order_is_not_found(): void
    {
        $this->get(route('generate-invoice', ['id' => self::GUEST_ORDER]))->assertNotFound();
    }

    public function test_the_guest_order_cannot_be_cancelled(): void
    {
        $this->get(route('order-cancel', ['id' => self::GUEST_ORDER]))->assertRedirect();

        $this->assertSame('pending', DB::table('orders')->where('id', self::GUEST_ORDER)->value('order_status'));
        $this->assertSame(0, DB::table('order_status_histories')->count());
    }

    public function test_no_refund_can_be_filed_against_the_guest_order(): void
    {
        $this->post(route('refund-store'), ['order_details_id' => self::GUEST_ORDER_LINE, 'amount' => 1, 'refund_reason' => 'Damaged'])->assertRedirect();

        $this->assertSame(0, DB::table('refund_requests')->count());
        $this->assertSame(0, (int) DB::table('order_details')->where('id', self::GUEST_ORDER_LINE)->value('refund_request'));
    }

    public function test_the_customer_still_cancels_its_own_order(): void
    {
        $this->get(route('order-cancel', ['id' => self::OWN_ORDER]))->assertRedirect();

        $this->assertSame('canceled', DB::table('orders')->where('id', self::OWN_ORDER)->value('order_status'));
    }

    private function placeOrder(int $orderId, int $orderLineId, bool $isGuest): void
    {
        DB::table('orders')->insert([
            'id' => $orderId, 'customer_id' => self::SHARED_ID, 'is_guest' => $isGuest ? 1 : 0, 'order_status' => 'pending',
            'payment_method' => 'cash_on_delivery', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_details')->insert([
            'id' => $orderLineId, 'order_id' => $orderId, 'qty' => 1, 'price' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createOrderTables(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_method')->nullable();
        });

        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->integer('qty')->default(1);
            $table->decimal('price', 16, 2)->default(0);
            $table->decimal('tax', 16, 2)->default(0);
            $table->decimal('discount', 16, 2)->default(0);
            $table->string('delivery_status')->default('pending');
            $table->integer('refund_request')->default(0);
            $table->boolean('is_stock_decreased')->default(true);
            $table->timestamps();
        });

        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_type')->nullable();
            $table->string('status')->nullable();
            $table->text('cause')->nullable();
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
