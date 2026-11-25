<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Address\ApiAddressTestCase;

/**
 * D1-14: one submit of a customer's checkout places one order, however many times that submit arrives.
 *
 * The cash-on-delivery, offline and wallet checkouts have no key of any kind. A customer who double-taps "place order", a phone that
 * retries a request whose answer was slow, a browser that re-posts on refresh: each arrival reads the cart, builds its own order and
 * takes its own stock. The order-integrity panel has said so for as long as it has existed -- `checkout_idempotency_key: not
 * configured` -- and it names `order_group_id` as the thing that does NOT answer it, because that separates the vendor split of one
 * basket and not one checkout from the next.
 *
 * The key is the client's `Idempotency-Key` header, the header the seller app's point-of-sale sale already honours. Whether the SERVER
 * should instead issue a per-checkout token is an open owner decision, because that needs a release of both apps; honouring the header
 * needs none. So the first tests are the dormancy: a checkout that sends no header, and a database that has not run the migration, place
 * orders exactly as they do today -- and the test that measures the duplicate is in here too, so this is not a guard against something
 * that never happened.
 *
 * The repeat is ANSWERED WITH the first submit's orders rather than refused, which is what matters to the person who tapped twice: they
 * meant one order, and the second tap hands back that order instead of an error or a second one.
 *
 * Two submits arriving at the same INSTANT is tests/Feature/Concurrency/CheckoutSubmitConcurrencyTest, which needs a real engine.
 */
class OneOrderPerCheckoutSubmitTest extends ApiAddressTestCase
{
    private const COD = '/api/v1/customer/order/place';

    private const KEY = 'submit-7f3a9c';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();
        $this->runTheCheckoutKeyMigration();
    }

    /** The table exactly as production gets it: the real migration, never a hand-built copy of it. */
    private function runTheCheckoutKeyMigration(): void
    {
        (require database_path('migrations/2026_12_27_100100_create_checkout_idempotency_keys_table.php'))->up();
    }

    private function aCartRow(int $customerId, int $productId, string $group): void
    {
        DB::table('carts')->insert([
            'customer_id' => $customerId, 'is_guest' => false, 'cart_group_id' => $group, 'product_id' => $productId, 'quantity' => 1,
            'price' => 100, 'seller_id' => 1, 'seller_is' => 'admin', 'is_checked' => 1, 'product_type' => 'physical',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function aProduct(int $productId): void
    {
        if (!DB::table('products')->where('id', $productId)->exists()) {
            DB::table('products')->insert([
                'id' => $productId, 'name' => 'Product ' . $productId, 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => 100, 'current_stock' => 50,
            ]);
        }
    }

    /** A customer with one line in the cart, ready to check out. */
    private function aCustomerWithACart(): User
    {
        $customer = $this->makeCustomer();
        $this->aProduct(1);
        $this->aCartRow($customer->id, productId: 1, group: 'group-' . $customer->id);

        return $customer;
    }

    /** Submits the cash-on-delivery checkout, with or without the key the app would send. */
    private function submit(User $customer, ?string $key = self::KEY): array
    {
        $headers = $key === null ? [] : ['Idempotency-Key' => $key];
        $response = $this->asCustomer($customer)->withHeaders($headers)->getJson(self::COD);

        return json_decode($response->assertOk()->getContent(), true) ?? [];
    }

    /** @return list<int> the orders of one customer, oldest first */
    private function ordersOf(User $customer): array
    {
        return DB::table('orders')->where('customer_id', $customer->id)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /** @return array<string, mixed>|null the one key row, if there is one */
    private function theKeyRow(): ?array
    {
        $row = DB::table('checkout_idempotency_keys')->first();

        return $row === null ? null : ['scope' => $row->customer_scope, 'key' => $row->idempotency_key, 'orders' => json_decode((string) $row->order_ids, true)];
    }

    public function test_a_checkout_that_sends_no_key_places_its_order_and_claims_nothing(): void
    {
        $customer = $this->aCustomerWithACart();

        $this->submit($customer, key: null);

        $this->assertCount(1, $this->ordersOf($customer));
        $this->assertSame(0, DB::table('checkout_idempotency_keys')->count(), 'nothing is claimed for a checkout that names no submit');
    }

    public function test_without_a_key_a_second_submit_of_a_refilled_cart_places_a_second_order(): void
    {
        $customer = $this->aCustomerWithACart();
        $this->submit($customer, key: null);
        $this->aCartRow($customer->id, productId: 1, group: 'group-' . $customer->id);

        $this->submit($customer, key: null);

        $this->assertCount(2, $this->ordersOf($customer), 'this is the duplicate the key exists to stop, measured rather than described');
    }

    public function test_the_first_submit_places_its_order_and_the_key_names_it(): void
    {
        $customer = $this->aCustomerWithACart();

        $this->submit($customer);

        $orders = $this->ordersOf($customer);
        $this->assertCount(1, $orders);
        $this->assertSame(['scope' => 'customer:' . $customer->id, 'key' => self::KEY, 'orders' => $orders], $this->theKeyRow());
    }

    public function test_a_repeat_of_one_submit_places_no_second_order_even_with_a_refilled_cart(): void
    {
        $customer = $this->aCustomerWithACart();
        $this->submit($customer);
        $firstOrder = $this->ordersOf($customer);
        $this->aCartRow($customer->id, productId: 1, group: 'group-' . $customer->id);

        $this->submit($customer);

        $this->assertSame($firstOrder, $this->ordersOf($customer), 'one submit, one order');
        $this->assertSame(1, DB::table('carts')->where('customer_id', $customer->id)->count(), 'and the refilled line was not consumed');
        $this->assertSame(50 - 1, (int) DB::table('products')->where('id', 1)->value('current_stock'), 'the stock was taken once');
    }

    public function test_the_repeat_is_answered_with_the_order_the_first_submit_placed(): void
    {
        $customer = $this->aCustomerWithACart();
        $firstAnswer = $this->submit($customer);
        $this->aCartRow($customer->id, productId: 1, group: 'group-' . $customer->id);

        $repeatAnswer = $this->submit($customer);

        $this->assertSame($firstAnswer['order_ids'] ?? null, $repeatAnswer['order_ids'] ?? null, 'the customer who tapped twice is handed the order they meant');
        $this->assertNotEmpty($repeatAnswer['order_ids'] ?? [], 'and is not handed a refusal');
    }

    public function test_a_second_submit_with_a_key_of_its_own_is_a_second_order(): void
    {
        $customer = $this->aCustomerWithACart();
        $this->submit($customer, key: 'first-attempt');
        $this->aCartRow($customer->id, productId: 1, group: 'group-' . $customer->id);

        $this->submit($customer, key: 'second-attempt');

        $this->assertCount(2, $this->ordersOf($customer), 'a new attempt is a new submit, which is what a per-attempt key means');
    }

    public function test_two_customers_sending_the_same_key_each_get_their_own_order(): void
    {
        $first = $this->aCustomerWithACart();
        $second = $this->makeCustomer(overrides: ['email' => 'second@example.test']);
        $this->aCartRow($second->id, productId: 1, group: 'group-' . $second->id);

        $this->submit($first);
        $this->submit($second);

        $this->assertCount(1, $this->ordersOf($first));
        $this->assertCount(1, $this->ordersOf($second), 'a key is one customer\'s submit, not the platform\'s');
        $this->assertSame(2, DB::table('checkout_idempotency_keys')->count());
    }

    public function test_one_submit_of_a_basket_of_two_sellers_records_both_of_its_orders(): void
    {
        $customer = $this->makeCustomer();
        $this->aProduct(1);
        $this->aProduct(2);
        $this->aCartRow($customer->id, productId: 1, group: 'group-' . $customer->id . '-a');
        $this->aCartRow($customer->id, productId: 2, group: 'group-' . $customer->id . '-b');

        $this->submit($customer);

        $orders = $this->ordersOf($customer);
        $this->assertCount(2, $orders, 'one submit of two cart groups places one order each');
        $this->assertSame($orders, $this->theKeyRow()['orders'], 'and the key names both, which is why the column is a list');
    }

    /**
     * Driven through the order writer rather than the endpoint, because the endpoint refuses an empty cart with a 403 before the writer is
     * reached: the release happens inside the writer's transaction, which is the only place it can.
     */
    public function test_a_submit_that_places_nothing_gives_its_key_back(): void
    {
        $customer = $this->makeCustomer();
        request()->merge(['user' => $customer]);
        request()->setUserResolver(fn () => $customer);
        request()->headers->set('Idempotency-Key', self::KEY);

        $orderIds = \App\Utils\OrderManager::generateOrder(data: [
            'is_guest' => 0, 'customer_id' => $customer->id, 'order_status' => 'pending', 'payment_method' => 'cash_on_delivery',
            'payment_status' => 'unpaid', 'transaction_ref' => '', 'requestObj' => request(),
        ]);

        $this->assertSame([], $orderIds, 'an empty cart places nothing');
        $this->assertSame(0, DB::table('checkout_idempotency_keys')->count(), 'and does not burn the key: it was not a submit that was honoured');
    }

    /**
     * The order-integrity panel's own reading, which is where this gap has been reported from all along. Read through `gaps()`, the method
     * that builds what the page renders, rather than through the one figure: a reading nothing renders is not a reading.
     */
    public function test_the_order_integrity_panel_stops_reporting_the_key_as_not_configured(): void
    {
        $customer = $this->aCustomerWithACart();
        $this->submit($customer);

        $reading = $this->theIdempotencyReadingOfThePanel();

        $this->assertSame('ok', $reading['state'], 'the page said not_configured while the table was absent, and that is no longer the deployment it is on');
        $this->assertSame(1, $reading['value'], 'one submit named itself');
        $this->assertStringContainsString('Idempotency-Key', (string) $reading['note']);
    }

    public function test_the_panel_still_reports_not_configured_on_a_database_without_the_table(): void
    {
        Schema::dropIfExists('checkout_idempotency_keys');

        $reading = $this->theIdempotencyReadingOfThePanel();

        $this->assertSame('not_configured', $reading['state']);
        $this->assertStringContainsString('checkout_idempotency_keys', (string) $reading['remedy'], 'and names the migration that fixes it');
    }

    /** @return array<string, mixed> what the panel publishes about the checkout key */
    private function theIdempotencyReadingOfThePanel(): array
    {
        $panel = app(\App\Services\Monitoring\Panels\OrderIntegrityPanel::class);
        $gaps = (new \ReflectionMethod($panel, 'gaps'))->invoke($panel);

        return $gaps['fields']['checkout_idempotency_key']->jsonSerialize();
    }

    public function test_a_database_that_has_not_run_the_migration_behaves_exactly_as_today(): void
    {
        Schema::dropIfExists('checkout_idempotency_keys');
        $customer = $this->aCustomerWithACart();
        $this->submit($customer);
        $this->aCartRow($customer->id, productId: 1, group: 'group-' . $customer->id);

        $this->submit($customer);

        $this->assertCount(2, $this->ordersOf($customer), 'the header is honoured only once the table exists, so nothing changes before the migration runs');
    }
}
