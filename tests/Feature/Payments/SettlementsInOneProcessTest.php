<?php

namespace Tests\Feature\Payments;

use App\Events\OrderPlacedEvent;
use App\Services\Fulfillment\FulfillmentHooks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\KeepsOrderDebugLogClean;

/**
 * The scheduled recovery of lost payment callbacks settles many payments in one process, on one request. A guest settled after a registered
 * customer was read as that customer: the guest's own cart was never looked at, no order was built, and the payment was still marked applied.
 *
 * The same holds for everything else a settlement reads from the process instead of from the stored payment: the session's guest, address and
 * coupon, a `user` input on the callback request, the customer logged in on the browser that delivers it, and a callback that arrives twice.
 */
class SettlementsInOneProcessTest extends ApiAddressTestCase
{
    use KeepsOrderDebugLogClean;

    private const GUEST_ID = 9001;

    private const OTHER_GUEST_ID = 9002;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();
        $this->rememberOrderDebugLog();

        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Paracetamol', 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => 100, 'current_stock' => 10],
            ['id' => 2, 'name' => 'Ibuprofen', 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => 50, 'current_stock' => 10],
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreOrderDebugLog();

        parent::tearDown();
    }

    private function cartRow(int $customerId, bool $isGuest, int $productId, float $price): void
    {
        DB::table('carts')->insert([
            'customer_id' => $customerId, 'is_guest' => $isGuest, 'cart_group_id' => 'group-' . $customerId, 'product_id' => $productId, 'quantity' => 1, 'price' => $price,
            'seller_id' => 1, 'seller_is' => 'admin', 'is_checked' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $stored what the payment stored when it started */
    private function settle(array $stored, ?string $transactionId = null): void
    {
        digital_payment_success([
            'is_paid' => 1, 'payment_method' => 'ssl_commerz', 'transaction_id' => $transactionId ?? 'TXN-' . uniqid(),
            'additional_data' => json_encode($stored + ['order_note' => null, 'coupon_code' => null, 'payment_mode' => 'web', 'payment_request_from' => 'web', 'new_customer_id' => 0]),
        ]);
    }

    /** @return array<string, mixed> what a guest who registers an account while paying stores */
    private function newCustomerPayment(int $guestId, int $addressId): array
    {
        return [
            'customer_id' => $guestId, 'is_guest' => 1, 'is_guest_in_order' => 0, 'address_id' => $addressId, 'billing_address_id' => $addressId, 'new_customer_id' => $guestId,
            'new_customer_info' => ['name' => 'New', 'l_name' => 'Customer', 'email' => 'new-customer@example.test', 'phone' => '0559999999', 'password' => 'secret123', 'referral_code' => 'NEWREF1'],
        ];
    }

    /** @return array<string, mixed> what a guest who only pays stores */
    private function guestPayment(int $guestId, int $addressId): array
    {
        return ['customer_id' => $guestId, 'is_guest' => 1, 'is_guest_in_order' => 1, 'address_id' => $addressId, 'billing_address_id' => $addressId, 'new_customer_id' => null];
    }

    /** @return array<string, mixed> what a registered customer's web payment stores */
    private function registeredPayment(int $customerId, int|string $addressId = 0): array
    {
        return ['customer_id' => $customerId, 'is_guest' => 0, 'is_guest_in_order' => 0, 'address_id' => $addressId, 'billing_address_id' => $addressId];
    }

    private function createCouponTable(): void
    {
        Schema::table('coupons', function ($table) {
            $table->decimal('discount', 16, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->integer('limit')->default(5);
            $table->decimal('min_purchase', 16, 2)->default(0);
            $table->decimal('max_discount', 16, 2)->default(1000);
            $table->unsignedBigInteger('seller_id')->nullable()->default(0);
            $table->unsignedBigInteger('customer_id')->default(0);
            $table->string('coupon_bearer')->default('inhouse');
        });
        (require database_path('migrations/2025_07_24_104352_create_order_details_rewards_table.php'))->up();
        DB::table('coupons')->insert([
            'code' => 'SAVE10', 'coupon_type' => 'discount_on_purchase', 'status' => 1, 'start_date' => '2020-01-01', 'expire_date' => '2099-01-01',
            'discount' => 10, 'discount_type' => 'percentage', 'limit' => 5, 'min_purchase' => 0, 'max_discount' => 1000, 'seller_id' => 0, 'customer_id' => 0, 'coupon_bearer' => 'inhouse',
        ]);
    }

    private function failNextOrderNotification(): void
    {
        $armed = true;
        Event::listen(OrderPlacedEvent::class, function () use (&$armed) {
            if ($armed) {
                $armed = false;

                throw new RuntimeException('queue unavailable');
            }
        });
    }

    /** @return list<int> the products of one order */
    private function productsOf(object $order): array
    {
        return DB::table('order_details')->where('order_id', $order->id)->pluck('product_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int> the products each order was made of, in order */
    private function orderedProducts(): array
    {
        return DB::table('order_details')->orderBy('order_id')->pluck('product_id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_a_guest_settled_after_a_registered_customer_still_gets_the_guests_own_order(): void
    {
        $registered = $this->makeCustomer();
        $this->cartRow($registered->id, false, productId: 1, price: 100);
        $this->cartRow(self::GUEST_ID, true, productId: 2, price: 50);
        $address = $this->makeLegacyAddress(self::GUEST_ID, isGuest: true);

        $this->settle(['customer_id' => $registered->id, 'is_guest' => 0, 'is_guest_in_order' => 0]);
        $this->settle(['customer_id' => self::GUEST_ID, 'is_guest' => 1, 'is_guest_in_order' => 1, 'address_id' => $address->id, 'billing_address_id' => $address->id]);

        $this->assertSame([1, 2], $this->orderedProducts(), 'each payer got an order for the cart they paid for');
        $this->assertSame(0, DB::table('carts')->count(), 'both carts were emptied');
        $this->assertSame(1, DB::table('orders')->where('is_guest', 1)->count());
    }

    public function test_a_registered_customer_settled_after_a_guest_still_gets_the_customers_own_order(): void
    {
        $registered = $this->makeCustomer();
        $this->cartRow($registered->id, false, productId: 1, price: 100);
        $this->cartRow(self::GUEST_ID, true, productId: 2, price: 50);
        $address = $this->makeLegacyAddress(self::GUEST_ID, isGuest: true);

        $this->settle(['customer_id' => self::GUEST_ID, 'is_guest' => 1, 'is_guest_in_order' => 1, 'address_id' => $address->id, 'billing_address_id' => $address->id]);
        $this->settle(['customer_id' => $registered->id, 'is_guest' => 0, 'is_guest_in_order' => 0]);

        $this->assertSame([2, 1], $this->orderedProducts());
        $this->assertSame(0, DB::table('carts')->count());
    }

    public function test_two_registered_customers_settled_one_after_the_other_each_get_their_own_order(): void
    {
        $first = $this->makeCustomer();
        $second = $this->makeCustomer();
        $this->cartRow($first->id, false, productId: 1, price: 100);
        $this->cartRow($second->id, false, productId: 2, price: 50);

        $this->settle(['customer_id' => $first->id, 'is_guest' => 0, 'is_guest_in_order' => 0]);
        $this->settle(['customer_id' => $second->id, 'is_guest' => 0, 'is_guest_in_order' => 0]);

        $this->assertSame([1, 2], $this->orderedProducts());
    }

    public function test_the_addresses_of_a_guest_are_not_kept_by_the_registered_customer_settled_after(): void
    {
        $registered = $this->makeCustomer();
        $this->cartRow($registered->id, false, productId: 1, price: 100);
        $this->cartRow(self::GUEST_ID, true, productId: 2, price: 50);
        $guestAddress = $this->makeLegacyAddress(self::GUEST_ID, isGuest: true, overrides: ['contact_person_name' => 'GUEST ONE', 'address' => 'GUEST ONE STREET']);
        $ownAddress = $this->makeLegacyAddress($registered->id, isGuest: false, overrides: ['contact_person_name' => 'REGISTERED R', 'address' => 'R STREET']);

        $this->settle($this->guestPayment(self::GUEST_ID, $guestAddress->id));
        $this->settle($this->registeredPayment($registered->id, $ownAddress->id));

        $order = DB::table('orders')->where('customer_id', $registered->id)->first();
        $this->assertSame($ownAddress->id, (int) $order->shipping_address, 'the registered customer ships to their own address');
        $this->assertSame('REGISTERED R', json_decode($order->shipping_address_data, true)['contact_person_name'], 'and the snapshot is theirs, not the guest\'s');
    }

    public function test_an_app_payer_settled_after_a_guest_keeps_their_own_address(): void
    {
        $registered = $this->makeCustomer();
        $this->cartRow($registered->id, false, productId: 1, price: 100);
        $this->cartRow(self::GUEST_ID, true, productId: 2, price: 50);
        $guestAddress = $this->makeLegacyAddress(self::GUEST_ID, isGuest: true, overrides: ['contact_person_name' => 'GUEST ONE']);
        $ownAddress = $this->makeLegacyAddress($registered->id, isGuest: false, overrides: ['contact_person_name' => 'APP PAYER']);

        $this->settle($this->guestPayment(self::GUEST_ID, $guestAddress->id));
        $this->settle($this->registeredPayment($registered->id, $ownAddress->id) + ['payment_mode' => 'app', 'payment_request_from' => 'app']);

        $order = DB::table('orders')->where('customer_id', $registered->id)->first();
        $this->assertSame($ownAddress->id, (int) $order->shipping_address);
        $this->assertSame('APP PAYER', json_decode($order->shipping_address_data, true)['contact_person_name']);
    }

    public function test_a_guest_settled_after_another_guest_keeps_their_own_address(): void
    {
        $this->cartRow(self::GUEST_ID, true, productId: 1, price: 100);
        $this->cartRow(self::OTHER_GUEST_ID, true, productId: 2, price: 50);
        $first = $this->makeLegacyAddress(self::GUEST_ID, isGuest: true, overrides: ['contact_person_name' => 'GUEST ONE']);
        $second = $this->makeLegacyAddress(self::OTHER_GUEST_ID, isGuest: true, overrides: ['contact_person_name' => 'GUEST TWO']);

        $this->settle($this->guestPayment(self::GUEST_ID, $first->id));
        $this->settle($this->guestPayment(self::OTHER_GUEST_ID, $second->id));

        $order = DB::table('orders')->where('customer_id', self::OTHER_GUEST_ID)->first();
        $this->assertSame($second->id, (int) $order->shipping_address);
        $this->assertSame([2], $this->productsOf($order));
    }

    public function test_a_settlement_leaves_the_session_of_the_process_as_it_found_it(): void
    {
        $guestAddress = $this->makeLegacyAddress(self::GUEST_ID, isGuest: true);
        $this->cartRow(self::GUEST_ID, true, productId: 2, price: 50);
        session()->put(['guest_id' => 'browser-guest', 'address_id' => 77, 'order_note' => 'keep me']);

        $this->settle($this->guestPayment(self::GUEST_ID, $guestAddress->id));

        $this->assertSame('browser-guest', session('guest_id'), 'the guest of the browser session is still the guest of the browser session');
        $this->assertSame(77, session('address_id'));
        $this->assertFalse(session()->has('billing_address_id'), 'a billing choice is used up by the order, as it always was');
        $this->assertFalse(session()->has('newRegisterCustomerInfo'));
    }

    public function test_a_guest_who_registers_while_paying_gets_the_order_of_the_cart_they_paid_for(): void
    {
        $this->cartRow(self::OTHER_GUEST_ID, true, productId: 2, price: 50);
        $address = $this->makeLegacyAddress(self::OTHER_GUEST_ID, isGuest: true, overrides: ['contact_person_name' => 'GUEST TWO']);

        $this->settle($this->newCustomerPayment(self::OTHER_GUEST_ID, $address->id));

        $newCustomer = DB::table('users')->where('email', 'new-customer@example.test')->first();
        $this->assertNotNull($newCustomer, 'the account was created');
        $order = DB::table('orders')->where('customer_id', $newCustomer->id)->first();
        $this->assertNotNull($order, 'and the payment built its order, with no browser session to say whose cart it is');
        $this->assertSame([2], $this->productsOf($order));
        $this->assertSame(0, DB::table('carts')->count());
    }

    public function test_a_failed_settlement_does_not_decide_whose_cart_the_next_new_customer_pays_for(): void
    {
        $this->cartRow(self::GUEST_ID, true, productId: 1, price: 100);
        $this->cartRow(self::OTHER_GUEST_ID, true, productId: 2, price: 50);
        $first = $this->makeLegacyAddress(self::GUEST_ID, isGuest: true, overrides: ['contact_person_name' => 'GUEST ONE']);
        $second = $this->makeLegacyAddress(self::OTHER_GUEST_ID, isGuest: true, overrides: ['contact_person_name' => 'GUEST TWO']);

        $realHooks = $this->app->make(FulfillmentHooks::class);
        $this->app->instance(FulfillmentHooks::class, new class extends FulfillmentHooks {
            public function __construct() {}

            public function ordersPlaced(array $orderIds): void
            {
                throw new RuntimeException('fulfillment queue unavailable');
            }
        });
        try {
            $this->settle($this->guestPayment(self::GUEST_ID, $first->id));
            $this->fail('the first settlement was meant to fail after its order was committed');
        } catch (RuntimeException $failure) {
            $this->assertSame('fulfillment queue unavailable', $failure->getMessage());
        }
        $this->app->instance(FulfillmentHooks::class, $realHooks);

        $this->settle($this->newCustomerPayment(self::OTHER_GUEST_ID, $second->id));

        $newCustomer = DB::table('users')->where('email', 'new-customer@example.test')->first();
        $order = DB::table('orders')->where('customer_id', $newCustomer->id)->first();
        $this->assertNotNull($order);
        $this->assertSame([2], $this->productsOf($order), 'the new customer paid for the cart of the second guest');
        $this->assertSame('GUEST TWO', json_decode($order->shipping_address_data, true)['contact_person_name']);
    }

    public function test_the_coupon_of_a_failed_settlement_is_not_applied_to_the_next_payer(): void
    {
        $this->createCouponTable();
        $first = $this->makeCustomer();
        $second = $this->makeCustomer();
        $this->cartRow($first->id, false, productId: 1, price: 100);
        $this->cartRow($second->id, false, productId: 2, price: 50);

        $this->failNextOrderNotification();
        try {
            $this->settle($this->registeredPayment($first->id) + ['coupon_code' => 'SAVE10', 'payment_mode' => 'app', 'payment_request_from' => 'app']);
            $this->fail('the first settlement was meant to fail after its order was committed');
        } catch (RuntimeException $failure) {
            $this->assertSame('queue unavailable', $failure->getMessage());
        }

        $this->settle($this->registeredPayment($second->id) + ['payment_mode' => 'app', 'payment_request_from' => 'app']);

        $order = DB::table('orders')->where('customer_id', $second->id)->first();
        $this->assertNotSame('SAVE10', $order->coupon_code, 'the second payer entered no coupon');
        $this->assertSame(0.0, (float) $order->discount_amount);
    }

    public function test_a_web_payer_settled_without_a_browser_session_still_gets_the_coupon_they_paid_with(): void
    {
        $this->createCouponTable();
        $customer = $this->makeCustomer();
        $this->cartRow($customer->id, false, productId: 1, price: 100);

        $this->settle($this->registeredPayment($customer->id) + ['coupon_code' => 'SAVE10']);

        $order = DB::table('orders')->where('customer_id', $customer->id)->first();
        $this->assertSame('SAVE10', $order->coupon_code);
        $this->assertSame(10.0, (float) $order->discount_amount, 'the order is what the customer paid for, not more');
    }

    public function test_the_customer_logged_in_on_the_delivering_browser_is_not_the_payer(): void
    {
        $bystander = $this->makeCustomer();
        $this->cartRow($bystander->id, false, productId: 1, price: 100);
        $this->cartRow(self::GUEST_ID, true, productId: 2, price: 50);
        $address = $this->makeLegacyAddress(self::GUEST_ID, isGuest: true);

        $this->actingAs($bystander, 'customer');
        $this->settle($this->guestPayment(self::GUEST_ID, $address->id));

        $this->assertSame(0, DB::table('orders')->where('customer_id', $bystander->id)->count(), 'a callback delivered in the browser of another customer builds nothing for that customer');
        $order = DB::table('orders')->where('customer_id', self::GUEST_ID)->first();
        $this->assertSame([2], $this->productsOf($order));
        $this->assertSame(1, DB::table('carts')->where('customer_id', $bystander->id)->count(), 'and leaves their cart alone');
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function callbacksNamingAUser(): array
    {
        return [
            'form post, user in the query string' => ['post', ['query' => 'user[id]={victim}', 'json' => false]],
            'json post, user in the query string' => ['post', ['query' => 'user[id]={victim}', 'json' => true]],
            'get, user in the query string' => ['get', ['query' => 'user[id]={victim}', 'json' => false]],
            'json post, user in the body' => ['post', ['query' => '', 'json' => true, 'body' => ['user' => ['id' => '{victim}']]]],
            'form post, user in the body' => ['post', ['query' => '', 'json' => false, 'body' => ['user' => ['id' => '{victim}']]]],
        ];
    }

    /** @param array<string, mixed> $callback */
    #[\PHPUnit\Framework\Attributes\DataProvider('callbacksNamingAUser')]
    public function test_a_callback_cannot_name_the_payer_of_a_guest_payment(string $method, array $callback): void
    {
        $victim = $this->makeCustomer();
        $this->cartRow($victim->id, false, productId: 1, price: 100);
        $this->cartRow(self::GUEST_ID, true, productId: 2, price: 50);
        $address = $this->makeLegacyAddress(self::GUEST_ID, isGuest: true);
        $guestPayment = $this->guestPayment(self::GUEST_ID, $address->id);
        Route::any('/_settle', function (Request $request) use ($guestPayment) {
            $this->settle($guestPayment);

            return response()->json(['settled' => true]);
        });

        $uri = '/_settle' . ($callback['query'] === '' ? '' : '?' . str_replace('{victim}', (string) $victim->id, $callback['query']));
        $body = json_decode(str_replace('"{victim}"', (string) $victim->id, json_encode($callback['body'] ?? [])), true);
        $response = match (true) {
            $method === 'get' => $this->get($uri),
            $callback['json'] => $this->postJson($uri, $body),
            default => $this->post($uri, $body),
        };

        $response->assertOk();
        $this->assertSame([2], $this->orderedProducts(), 'the guest\'s own cart was ordered, whatever the request said');
        $this->assertSame(1, DB::table('carts')->where('customer_id', $victim->id)->where('is_guest', 0)->count(), 'the registered customer\'s cart is untouched');
    }

    public function test_the_same_paid_callback_delivered_again_builds_no_second_order_even_when_the_cart_was_refilled(): void
    {
        $customer = $this->makeCustomer();
        $this->cartRow($customer->id, false, productId: 1, price: 100);

        $this->settle($this->registeredPayment($customer->id), transactionId: 'TXN-ONCE');
        $this->cartRow($customer->id, false, productId: 2, price: 50);
        $this->settle($this->registeredPayment($customer->id), transactionId: 'TXN-ONCE');

        $this->assertSame([1], $this->orderedProducts(), 'one payment, one order');
        $this->assertSame(1, DB::table('carts')->where('customer_id', $customer->id)->count(), 'the new cart row was not consumed');
    }

    public function test_two_different_payments_each_build_their_own_order_even_for_the_same_customer(): void
    {
        $customer = $this->makeCustomer();
        $this->cartRow($customer->id, false, productId: 1, price: 100);
        $this->settle($this->registeredPayment($customer->id), transactionId: 'TXN-ONE');
        $this->cartRow($customer->id, false, productId: 2, price: 50);
        $this->settle($this->registeredPayment($customer->id), transactionId: 'TXN-TWO');

        $this->assertSame([1, 2], $this->orderedProducts());
    }
}
