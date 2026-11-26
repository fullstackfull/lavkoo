<?php

namespace Tests\Feature\Storefront;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsSixStorefrontCheckoutWorld;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\BuildsWhatOrderAnswersRead;
use Tests\Support\KeepsOrderDebugLogClean;

/**
 * GCC-05, read from the other end: what a customer's own order answers really carry of the storefront the order was sold on and the currency it
 * was sold in. The customer app reads three keys off an order, `storefront_country`, `currency_code` and `currency_rate`, and until now no test
 * drove a route to see whether the server sends them, so this drives every answer that carries an order to a customer, over HTTP, on an order a
 * real checkout of the storefront placed. Four of the five are driven signed in on the API guard a customer's token resolves to; the fifth, the
 * tracking by number and phone, refuses a caller signed in that way and is driven as the form it serves, which is pinned on its own below.
 *
 * Five answers carry an order: the order list, the order details, the order by its id, the tracking by id and the tracking by number and phone.
 * Each is driven on an order of Kuwait (a storefront with its own currency), on an order of the default storefront, and on an order that carries
 * no record, which must read exactly as it did before the feature existed. Two further answers of an order carry no order object at all (the
 * status history of the tracking and the order-again reply), which is pinned too, so the app is never written against a key that is not there.
 *
 * None of these answers is built by a serialiser that picks keys: each is the Eloquent model as it stands, so every column of `orders` appears in
 * the answer and the three keys arrive by themselves once the migration has run. That is pinned here on purpose (a column added to `orders`
 * changes the API of five customer answers without anybody writing a line of controller code) and it is also why the answer of an order with no
 * record carries the three keys as null rather than not at all: the pre-feature shape is the shape before the migration, and that is pinned too.
 */
class CustomerOrderMarketAnswerTest extends ApiAddressTestCase
{
    use BuildsSixStorefrontCheckoutWorld;
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;
    use BuildsWhatOrderAnswersRead;
    use KeepsOrderDebugLogClean;

    /** What a key reads as when the answer does not carry it at all, which `json()` cannot tell from a null. */
    private const ABSENT = '(absent)';

    private const MARKET_KEYS = ['storefront_country', 'currency_code', 'currency_rate'];

    private const PLACE_ORDER = '/api/v1/customer/order/place';

    private const ORDER_LIST = '/api/v1/customer/order/list?limit=10&offset=1';

    private const ORDER_DETAILS = '/api/v1/customer/order/details?order_id=';

    private const ORDER_BY_ID = '/api/v1/customer/order/get-order-by-id?order_id=';

    private const TRACK = '/api/v1/order/track?order_id=';

    private const TRACK_HISTORY = '/api/v1/order/track-order-details?order_id=';

    private const TRACK_BY_PHONE = '/api/v1/order/track-order';

    private const ORDER_AGAIN = '/api/v1/customer/order/again';

    /** The product of each storefront in the six-storefront world, and the currency its amounts are stored in. */
    private const PRODUCTS = [
        'SA' => ['id' => 1, 'currency' => 'SAR'],
        'KW' => ['id' => 3, 'currency' => 'KWD'],
    ];

    /** Every customer answer that carries an order, and where the order object sits in it ('' being the root of the answer). */
    private const ANSWERS = [
        'the order list' => [self::ORDER_LIST, 'orders.0'],
        'the order details' => [self::ORDER_DETAILS, '0.order'],
        'the order by its id' => [self::ORDER_BY_ID, ''],
        'the tracking of the order' => [self::TRACK, ''],
        'the tracking by number and phone' => [self::TRACK_BY_PHONE, '0.order'],
    ];

    /** The routes of the five answers above, as the route table spells them. The list ANSWERS drives is asserted to be exactly this one. */
    private const ANSWERS_WITH_AN_ORDER = [
        'GET|HEAD api/v1/customer/order/details',
        'GET|HEAD api/v1/customer/order/get-order-by-id',
        'GET|HEAD api/v1/customer/order/list',
        'GET|HEAD api/v1/order/track',
        'POST api/v1/order/track-order',
    ];

    /**
     * The rest of the customer-facing order surface. Each of these answers a message, a validation error, a file, a list of keys of its own or
     * an amount it works out, and never the order row; each was read in its controller, which is what this list rests on, and the two of them a
     * test here drives (the status history of the tracking and the order-again reply) are asserted to carry no order object anywhere.
     */
    private const ANSWERS_NO_ORDER_OBJECT = [
        // placing an order, paying for it and editing it: an id, a message, a method list or a link to a gateway
        'GET|HEAD api/v1/customer/order/place',
        'GET|HEAD api/v1/customer/order/place-by-wallet',
        'POST api/v1/customer/order/place-by-offline-payment',
        'GET|HEAD api/v1/customer/order/offline-payment-method-list',
        'POST api/v1/customer/order/again',
        'POST api/v1/edit-order/due-payment-by-cod',
        'POST api/v1/edit-order/due-payment-by-digital-payment',
        'POST api/v1/edit-order/due-payment-by-offline-payment',
        'POST api/v1/edit-order/due-payment-by-wallet',
        'POST api/v1/shipping-method/choose-for-order',
        // what became of an order: a status history, its shipments, a cancellation message, the bytes of an invoice
        'GET|HEAD api/v1/order/track-order-details',
        'GET|HEAD api/v1/order/cancel-order',
        'GET|HEAD api/v1/customer/order/fulfillments',
        'GET|HEAD api/v1/customer/order/generate-invoice',
        // the money of a refund: amounts worked out from one line of the order and named one by one
        'GET|HEAD api/v1/customer/order/refund',
        'GET|HEAD api/v1/customer/order/refund-details',
        'POST api/v1/customer/order/refund-store',
        // a review or a digital file of one line of an order
        'GET|HEAD api/v1/customer/order/deliveryman-review',
        'POST api/v1/customer/order/deliveryman-review/update',
        'POST api/v1/customer/order/deliveryman-reviews/submit',
        'GET|HEAD api/v1/products/review/{product_id}/{order_id}',
        // The optional {fileName?} segment comes from the digital-file lane (routes/rest_api/v1/api.php:439); the route is the same
        // one and stays in the same list, only its spelling moved.
        'GET|HEAD api/v1/customer/order/digital-product-download/{id}/{fileName?}',
        'GET|HEAD api/v1/customer/order/digital-product-download-otp-verify',
        'POST api/v1/customer/order/digital-product-download-otp-resend',
    ];

    /** The orders of a shop, which the customer app never reads: the seller and delivery-man API of the later versions. */
    private const SHOP_PREFIXES = ['api/v2/seller/', 'api/v2/delivery-man/', 'api/v3/seller/'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSixStorefrontCheckoutWorld();
        $this->addWhatTheOrderAnswersRead();
        $this->rememberOrderDebugLog();
    }

    protected function tearDown(): void
    {
        $this->restoreOrderDebugLog();

        parent::tearDown();
    }

    // ---- the world --------------------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string}> */
    public static function everyAnswerCarryingAnOrder(): array
    {
        return self::ANSWERS;
    }

    /** @return array{0: User, 1: int} a customer and the order a real checkout of the storefront saved for them */
    private function placeAnOrderOn(string $country): array
    {
        $customer = $this->makeCustomer();
        $this->fillTheCartFrom(customer: $customer, country: $country, productId: self::PRODUCTS[$country]['id']);

        $orderId = $this->asTheApp(customer: $customer, country: $country)->getJson(self::PLACE_ORDER)->assertOk()->json('order_ids.0');

        return [$customer, (int) $orderId];
    }

    private function asTheApp(User $customer, string $country): static
    {
        return $this->asCustomer($customer)->withHeader('X-Storefront', $country);
    }

    /** An order as it stands for every order placed before this feature: the three columns are there and empty. */
    private function forgetWhatTheOrderWasSoldIn(int $orderId): void
    {
        DB::table('orders')->where('id', $orderId)->update(['storefront_country' => null, 'currency_code' => null, 'currency_rate' => null]);
    }

    /** The database as it was before the migration ran: the three columns do not exist. */
    private function removeTheSnapshotColumns(): void
    {
        (require database_path('migrations/2026_11_26_100000_add_storefront_snapshot_to_orders_table.php'))->down();
    }

    private function answerOf(string $endpoint, User $customer, int $orderId, string $country): TestResponse
    {
        if ($endpoint === self::TRACK_BY_PHONE) {
            return $this->asTheTrackingForm(country: $country)->postJson($endpoint, ['order_id' => $orderId, 'phone_number' => $customer->phone])->assertOk();
        }

        return $this->asTheApp(customer: $customer, country: $country)
            ->getJson($endpoint === self::ORDER_LIST ? $endpoint : $endpoint . $orderId)
            ->assertOk();
    }

    /**
     * The tracking form, which carries no token and proves the order with the phone it was placed with. That is the only caller this answer
     * serves: it scopes a signed-in caller's order by the website's own `customer` guard, which an API token never fills, so the same request
     * with a bearer token is refused. Recorded as what the route does today, not endorsed.
     */
    private function asTheTrackingForm(string $country): static
    {
        $this->flushHeaders();
        auth()->forgetGuards();

        return $this->withHeader('X-Storefront', $country);
    }

    /** @return array<string, mixed> the order object inside the answer, decoded */
    private function orderIn(TestResponse $answer, string $path): array
    {
        $order = $path === '' ? $answer->json() : $answer->json($path);

        $this->assertIsArray($order, 'the answer carries an order object at ' . ($path === '' ? 'its root' : $path));

        return $order;
    }

    /**
     * What the order object of an answer carries of the three keys, self::ABSENT standing for a key it does not carry at all.
     *
     * @return array<string, mixed>
     */
    private function marketIn(TestResponse $answer, string $path): array
    {
        $order = $this->orderIn(answer: $answer, path: $path);
        $market = [];

        foreach (self::MARKET_KEYS as $key) {
            $market[$key] = array_key_exists($key, $order) ? $order[$key] : self::ABSENT;
        }

        return $market;
    }

    /**
     * The rate as the order object carries it, in the shape the engine sends it in: a decimal column reaches JSON as the string '1.00000000' on
     * MySQL and MariaDB and as the number 1 on SQLite. The app parses either, and pinning both says which engine sends which, so a change of the
     * column or of a cast that moved one of them is seen here rather than in the app.
     */
    private function assertRateIsOne(mixed $rate, string $because): void
    {
        $driver = DB::connection()->getDriverName();

        $this->assertSame(
            $driver === 'sqlite' ? 1 : '1.00000000',
            $rate,
            $because . ' (on ' . $driver . ' the rate arrived as ' . var_export($rate, true) . ')',
        );
    }

    /**
     * Every place one of the three keys stands in an answer, however deep, as dotted paths. An answer that carries no order object may carry
     * none of them anywhere either, or the app could be written against a key that is only there by accident.
     *
     * @return list<string>
     */
    private function marketKeysAnywhereIn(mixed $body, string $path = ''): array
    {
        if (!is_array($body)) {
            return [];
        }

        $found = [];

        foreach ($body as $key => $value) {
            $here = $path === '' ? (string) $key : $path . '.' . $key;

            if (in_array($key, self::MARKET_KEYS, true)) {
                $found[] = $here;
            }

            array_push($found, ...$this->marketKeysAnywhereIn(body: $value, path: $here));
        }

        return $found;
    }

    // ---- an order of a storefront with its own currency -------------------------------------------------------------------------------------

    #[DataProvider('everyAnswerCarryingAnOrder')]
    public function test_an_answer_of_a_kuwaiti_order_carries_the_storefront_the_currency_and_the_rate(string $endpoint, string $path): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [$customer, $orderId] = $this->placeAnOrderOn(country: 'KW');

        $market = $this->marketIn(answer: $this->answerOf(endpoint: $endpoint, customer: $customer, orderId: $orderId, country: 'KW'), path: $path);

        $this->assertSame('KW', $market['storefront_country']);
        $this->assertSame('KWD', $market['currency_code']);
        $this->assertRateIsOne(rate: $market['currency_rate'], because: 'a Kuwaiti order is stored in dinars, so its rate is one');
    }

    #[DataProvider('everyAnswerCarryingAnOrder')]
    public function test_an_answer_of_an_order_of_the_default_storefront_carries_the_platform_currency(string $endpoint, string $path): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [$customer, $orderId] = $this->placeAnOrderOn(country: 'SA');

        $market = $this->marketIn(answer: $this->answerOf(endpoint: $endpoint, customer: $customer, orderId: $orderId, country: 'SA'), path: $path);

        $this->assertSame('SA', $market['storefront_country']);
        $this->assertSame('SAR', $market['currency_code'], 'this platform runs one currency, so the order of the default storefront is known to be in it');
        $this->assertRateIsOne(rate: $market['currency_rate'], because: 'an order of the default storefront is stored in the platform currency, so its rate is one');
    }

    /** A platform that converts from a base currency cannot say which currency an order of the default storefront is shown in, so it records none. */
    #[DataProvider('everyAnswerCarryingAnOrder')]
    public function test_an_answer_of_a_default_storefront_order_on_a_converting_platform_carries_the_country_alone(string $endpoint, string $path): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        $this->setSetting('currency_model', json_encode('multi_currency'));
        [$customer, $orderId] = $this->placeAnOrderOn(country: 'SA');

        $market = $this->marketIn(answer: $this->answerOf(endpoint: $endpoint, customer: $customer, orderId: $orderId, country: 'SA'), path: $path);

        $this->assertSame(['storefront_country' => 'SA', 'currency_code' => null, 'currency_rate' => null], $market);
    }

    // ---- an order that carries no record ---------------------------------------------------------------------------------------------------

    #[DataProvider('everyAnswerCarryingAnOrder')]
    public function test_an_answer_of_an_order_with_no_record_carries_the_three_keys_empty(string $endpoint, string $path): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [$customer, $orderId] = $this->placeAnOrderOn(country: 'KW');
        $this->forgetWhatTheOrderWasSoldIn(orderId: $orderId);

        $market = $this->marketIn(answer: $this->answerOf(endpoint: $endpoint, customer: $customer, orderId: $orderId, country: 'KW'), path: $path);

        $this->assertSame(['storefront_country' => null, 'currency_code' => null, 'currency_rate' => null], $market);
    }

    /**
     * The Saudi storefront as it runs today, with both switches off: the real checkout records nothing, so every answer of the order carries the
     * three keys empty. The migration is the only part of this feature such a platform has run, and these are the answers it serves.
     */
    #[DataProvider('everyAnswerCarryingAnOrder')]
    public function test_an_answer_of_an_order_placed_with_both_switches_off_carries_the_three_keys_empty(string $endpoint, string $path): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        config(['gcc.storefronts.enabled' => false, 'gcc.storefronts.currency.enabled' => false]);
        [$customer, $orderId] = $this->placeAnOrderOn(country: 'SA');

        $market = $this->marketIn(answer: $this->answerOf(endpoint: $endpoint, customer: $customer, orderId: $orderId, country: 'SA'), path: $path);

        $this->assertSame(['storefront_country' => null, 'currency_code' => null, 'currency_rate' => null], $market);
    }

    /** The currency switch alone holds the record back: storefronts may be on, and until their money is on an order still records nothing. */
    #[DataProvider('everyAnswerCarryingAnOrder')]
    public function test_an_answer_of_an_order_placed_with_the_currency_switch_off_carries_the_three_keys_empty(string $endpoint, string $path): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        config(['gcc.storefronts.currency.enabled' => false]);
        [$customer, $orderId] = $this->placeAnOrderOn(country: 'SA');

        $market = $this->marketIn(answer: $this->answerOf(endpoint: $endpoint, customer: $customer, orderId: $orderId, country: 'SA'), path: $path);

        $this->assertSame(['storefront_country' => null, 'currency_code' => null, 'currency_rate' => null], $market);
    }

    /**
     * The shape before the feature existed: the migration has not run, so the three keys are not in the answer at all. Every other key of the
     * order object, and its value, is the same as with the columns there and empty, which is what makes the columns additive.
     */
    #[DataProvider('everyAnswerCarryingAnOrder')]
    public function test_an_answer_is_what_it_was_before_the_migration_ran_but_for_the_three_keys(string $endpoint, string $path): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [$customer, $orderId] = $this->placeAnOrderOn(country: 'KW');
        $this->forgetWhatTheOrderWasSoldIn(orderId: $orderId);
        $withTheColumns = $this->orderIn(answer: $this->answerOf(endpoint: $endpoint, customer: $customer, orderId: $orderId, country: 'KW'), path: $path);

        $this->removeTheSnapshotColumns();
        $beforeTheMigration = $this->orderIn(answer: $this->answerOf(endpoint: $endpoint, customer: $customer, orderId: $orderId, country: 'KW'), path: $path);

        $this->assertSame([], array_intersect(self::MARKET_KEYS, array_keys($beforeTheMigration)), 'before the migration the answer carries none of the three keys');
        $this->assertSame(array_keys($beforeTheMigration), array_values(array_diff(array_keys($withTheColumns), self::MARKET_KEYS)), 'the columns add three keys and move none');
        foreach ($beforeTheMigration as $key => $value) {
            $this->assertSame($value, $withTheColumns[$key], "the key {$key} is what it was before the migration ran, down to its type");
        }
    }

    // ---- the answers that carry no order object --------------------------------------------------------------------------------------------

    public function test_the_status_history_of_the_tracking_carries_none_of_the_three_keys(): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [$customer, $orderId] = $this->placeAnOrderOn(country: 'KW');

        $history = $this->asTheApp(customer: $customer, country: 'KW')->getJson(self::TRACK_HISTORY . $orderId)->assertOk();

        $this->assertSame(['history', 'is_digital_order'], array_keys((array) $history->json()));
        $this->assertSame([], $this->marketKeysAnywhereIn(body: $history->json()), 'nothing of the record reaches the app on an answer that carries no order');
    }

    public function test_the_order_again_reply_carries_no_order_and_none_of_the_three_keys(): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [$customer, $orderId] = $this->placeAnOrderOn(country: 'KW');

        $again = $this->asTheApp(customer: $customer, country: 'KW')->postJson(self::ORDER_AGAIN, ['order_id' => $orderId])->assertOk();

        $this->assertSame(['message'], array_keys((array) $again->json()));
        $this->assertSame([], $this->marketKeysAnywhereIn(body: $again->json()));
    }

    // ---- what the shape of the answers rests on --------------------------------------------------------------------------------------------

    /**
     * None of the five answers names the keys it sends: each hands back the Eloquent model, so a column added to `orders` appears in all five by
     * itself. That is how the three keys arrive, and it is also the risk: the next column added to the table is published to the apps with it.
     */
    #[DataProvider('everyAnswerCarryingAnOrder')]
    public function test_a_column_added_to_the_orders_table_appears_in_the_answer_by_itself(string $endpoint, string $path): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [$customer, $orderId] = $this->placeAnOrderOn(country: 'KW');
        Schema::table('orders', fn (Blueprint $table) => $table->string('a_column_nobody_published')->nullable());
        DB::table('orders')->where('id', $orderId)->update(['a_column_nobody_published' => 'in the answer']);

        $order = $this->orderIn(answer: $this->answerOf(endpoint: $endpoint, customer: $customer, orderId: $orderId, country: 'KW'), path: $path);

        $this->assertSame('in the answer', $order['a_column_nobody_published'] ?? null, 'the answer is the model as it stands, column for column');
    }

    /**
     * The fifth answer is the one of the five that is not driven signed in, because it refuses a signed-in caller. track_order scopes one's order
     * with auth('customer'), the website's own guard, which an API caller never fills: the id it compares is null, the order is not found, and the
     * caller is refused, while the same request with no caller at all is answered. That is a defect of the backend older than this feature and it
     * is pinned here as what the route does today, not endorsed: the app's own dioClient puts a bearer token on every request, so its track-order
     * screen is the refused caller, and while this stands the three keys never reach a signed-in caller of this answer.
     */
    public function test_the_tracking_by_number_and_phone_refuses_a_signed_in_caller_and_answers_the_form_without_one(): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        [$customer, $orderId] = $this->placeAnOrderOn(country: 'KW');
        $form = ['order_id' => $orderId, 'phone_number' => $customer->phone];

        $withTheToken = $this->asTheApp(customer: $customer, country: 'KW')->postJson(self::TRACK_BY_PHONE, $form);
        $withoutIt = $this->asTheTrackingForm(country: 'KW')->postJson(self::TRACK_BY_PHONE, $form);

        $withTheToken->assertStatus(403)->assertExactJson(['message' => 'Invalid Order Id or Phone Number']);
        $this->assertSame('KW', $this->marketIn(answer: $withoutIt->assertOk(), path: '0.order')['storefront_country']);
    }

    /**
     * The customer-facing order surface of the API: every route of every version whose path names an order, but for the shop's own. Each one is
     * classified above, as an answer that carries an order or as one that carries none, so a route added to the surface fails this test until
     * somebody says which it is, whatever action it points at. A customer answer that carries an order without naming one in its path is outside
     * this net, and that is the gap it leaves.
     */
    public function test_every_customer_route_that_names_an_order_is_classified_here(): void
    {
        $classified = [...self::ANSWERS_WITH_AN_ORDER, ...self::ANSWERS_NO_ORDER_OBJECT];
        sort($classified);

        $this->assertSame($classified, $this->customerOrderSurface(), 'a route of the customer order surface either answers with an order or does not: say which of the two lists it belongs in');
        $this->assertSame([], array_intersect(self::ANSWERS_WITH_AN_ORDER, self::ANSWERS_NO_ORDER_OBJECT));
    }

    /** What makes the classification cost something: a route classified as answering with an order is one of the answers driven here. */
    public function test_the_answers_driven_here_are_the_routes_classified_as_answering_with_an_order(): void
    {
        $driven = array_map(static fn (array $answer): string => self::routeOf($answer[0]), array_values(self::ANSWERS));
        sort($driven);

        $this->assertSame(self::ANSWERS_WITH_AN_ORDER, $driven);
    }

    /** What the net leaves out on purpose: every order route of a later version of the API is a shop's own, so the customer app reads none of it. */
    public function test_every_order_route_of_a_later_version_of_the_api_belongs_to_a_shop(): void
    {
        $orderRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route): bool => preg_match('#^api/v[2-9]/#', $route->uri()) === 1 && str_contains($route->uri(), 'order'));

        $customerFacing = $orderRoutes
            ->reject(fn (RoutingRoute $route): bool => Str::startsWith($route->uri(), self::SHOP_PREFIXES))
            ->map(fn (RoutingRoute $route): string => $route->uri())
            ->values()
            ->all();

        $this->assertSame([], $customerFacing);
        $this->assertGreaterThan(0, $orderRoutes->count(), "the later versions do answer with orders, and they are a shop's own");
    }

    /** @return list<string> every route of the API whose path names an order and is not a shop's own, as the route table spells it, sorted */
    private function customerOrderSurface(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api/v') && str_contains($route->uri(), 'order'))
            ->reject(fn (RoutingRoute $route): bool => Str::startsWith($route->uri(), self::SHOP_PREFIXES))
            ->map(fn (RoutingRoute $route): string => implode('|', $route->methods()) . ' ' . $route->uri())
            ->values()
            ->sort()
            ->values()
            ->all();
    }

    /** The route of an answer as the route table spells it: its path without the query, and the method it is driven with. */
    private static function routeOf(string $endpoint): string
    {
        return ($endpoint === self::TRACK_BY_PHONE ? 'POST' : 'GET|HEAD') . ' ' . ltrim(explode('?', $endpoint)[0], '/');
    }
}
