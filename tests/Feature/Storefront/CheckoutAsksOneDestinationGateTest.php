<?php

namespace Tests\Feature\Storefront;

use App\Models\User;
use App\Services\Shipping\CrossBorderPolicy;
use App\Services\Shipping\ProductDestinationPermission;
use App\Services\Shipping\RegulatedShippingGate;
use App\Services\Shipping\ShippingEligibility;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\BuildsAddressSchema;
use Tests\Support\BuildsApiAddressSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\Support\PlatformWriteScanner;
use Tests\TestCase;

/**
 * D1-17: whether a basket may be bought is decided by the delivery ADDRESS, through ONE gate that every order-creating channel goes
 * through -- not by where the visitor is, and not by a different check per surface.
 *
 * The gate is {@see ShippingEligibility}. It was asked on the REST checkout's three paths and on ONE of the website's three, and the
 * measurement that found it is the only reason this file exists: `OrderManager::generateOrder` is called from six controller methods and
 * from the payment settlement helper, and `crossBorderRefusal()` appeared in exactly one of the website's three. So the website's
 * offline-payment checkout and its wallet checkout accepted a basket the app refused.
 *
 * The address guard those two DID carry answers only the first of the gate's questions -- may this market send a parcel to that country
 * ({@see CrossBorderPolicy}, through CheckoutAddressGuard). It says nothing about what the PRODUCT is permitted to do, what the carrier is
 * contracted to carry, whether a regulated line has a recorded clearance, or whether the duty on the route can be determined at all. Half a
 * gate on two of six paths is what "decided in separate places" means in the criterion's own words.
 *
 * The first test is the dormancy, and it is the one that matters most: the cross-border engine ships OFF, so every checkout here behaves
 * exactly as it does today and the Saudi platform is untouched.
 */
class CheckoutAsksOneDestinationGateTest extends TestCase
{
    use BuildsAddressSchema;
    use BuildsApiAddressSchema;
    use RestoresLanguageFiles;

    /** The one call that asks the gate, per controller. Each is a private helper of that controller which calls ShippingEligibility. */
    private const THE_GATE_IS_ASKED_BY = [
        'App\Http\Controllers\Web\WebController' => 'crossBorderRefusal(',
        'App\Http\Controllers\RestAPI\v1\OrderController' => 'shippingRefusal(',
    ];

    /** Every checkout method that makes an order, and must therefore ask the gate before it does. */
    private const CHECKOUTS_THAT_MAKE_AN_ORDER = [
        'App\Http\Controllers\Web\WebController' => [
            'getCashOnDeliveryCheckoutComplete',
            'getOfflinePaymentCheckoutComplete',
            'checkout_complete_wallet',
        ],
        'App\Http\Controllers\RestAPI\v1\OrderController' => [
            'place_order',
            'placeOrderByOfflinePayment',
            'placeOrderByWallet',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        Http::preventStrayRequests();

        $this->buildAddressSchema();
        $this->createApiStoreTables();
        $this->createOrderPipelineTables();
        $this->useThrowawayPassportKeys();

        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('transaction_id')->nullable();
            $table->decimal('credit', 40, 20)->default(0);
            $table->decimal('debit', 40, 20)->default(0);
            $table->decimal('admin_bonus', 40, 20)->default(0);
            $table->decimal('balance', 40, 20)->default(0);
            $table->string('transaction_type')->nullable();
            $table->string('reference')->nullable();
            $table->string('payment_method')->nullable();
            $table->timestamps();
        });
        DB::table('business_settings')->insert(['type' => 'wallet_status', 'value' => '1']);
        Cache::flush();
        $this->useTaxIncludedPrices();

        (require database_path('migrations/2026_12_20_100000_create_product_destination_permissions_table.php'))->up();
        (require database_path('migrations/2026_12_20_100300_add_clearance_reference_to_product_destination_permissions.php'))->up();
        ProductDestinationPermission::forget();
        RegulatedShippingGate::forget();
    }

    protected function tearDown(): void
    {
        // A table this class built from its real migration is put back, or it is taken from every class that runs after this one.
        Schema::dropIfExists('product_destination_permissions');
        ProductDestinationPermission::forget();
        RegulatedShippingGate::forget();
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    // ---- the structural half: one gate, on every path that makes an order ------------------------------------------------------------

    /**
     * Every checkout method that calls `generateOrder` asks the gate first, read from the method's OWN source.
     *
     * The method rather than the file, because that is where the defect was: `crossBorderRefusal()` existed in WebController and was
     * called from one of its three checkouts, so a test reading the file would have passed while two paths were open.
     */
    public function test_every_checkout_that_makes_an_order_asks_the_one_gate(): void
    {
        $open = [];

        foreach (self::CHECKOUTS_THAT_MAKE_AN_ORDER as $controller => $methods) {
            foreach ($methods as $method) {
                if (!str_contains($this->sourceOf($controller, $method), self::THE_GATE_IS_ASKED_BY[$controller])) {
                    $open[] = "{$controller}::{$method}";
                }
            }
        }

        $this->assertSame([], $open, "a checkout that makes an order and does not ask the destination gate:\n - " . implode("\n - ", $open));
    }

    /** And the list is the real one: every method of those two controllers that makes an order is on it. */
    public function test_the_list_of_checkouts_is_every_method_of_those_controllers_that_makes_an_order(): void
    {
        foreach (self::CHECKOUTS_THAT_MAKE_AN_ORDER as $controller => $declared) {
            $found = [];

            foreach ((new ReflectionClass($controller))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() === $controller && str_contains($this->sourceOf($controller, $method->getName()), 'OrderManager::generateOrder(')) {
                    $found[] = $method->getName();
                }
            }

            sort($found);
            $expected = $declared;
            sort($expected);

            $this->assertSame($expected, $found, "{$controller} makes an order somewhere this list does not name: a new checkout has to ask the gate too");
        }
    }

    /** The helper each controller asks through really asks the one engine, so the test above is not reading a name that means nothing. */
    public function test_the_helper_each_controller_asks_through_is_the_one_engine(): void
    {
        $this->assertStringContainsString('ShippingEligibility', $this->sourceOf('App\Http\Controllers\Web\WebController', 'crossBorderRefusal'));
        $this->assertStringContainsString('ShippingEligibility', $this->sourceOf('App\Http\Controllers\RestAPI\v1\OrderController', 'shippingRefusal'));
    }

    /**
     * The one caller of `generateOrder` that is NOT a checkout, named here rather than left for somebody to wonder about: the payment
     * settlement helper. A gateway reports a payment that has already been taken, so the order has to follow it; the refusal belongs
     * before the payment, which is where the three checkout paths that start a digital payment ask it.
     */
    public function test_the_settlement_helper_is_the_only_other_place_that_makes_an_order(): void
    {
        $callers = [];

        foreach (['app', 'Modules'] as $root) {
            foreach ($this->phpFilesIn(base_path($root)) as $file) {
                // Comments STRIPPED, and that is load-bearing rather than tidiness: App\Services\Checkout\OrderRefused's docblock has to
                // NAME generateOrder() to explain what it carries a reason for, and a scan that read its own explanation would file it as a
                // seventh caller. Adding it to the list below instead would have made the list's own sentence false -- it makes no order.
                // Fifth occurrence of this shape in this programme (see EXECUTION_STATE.md); PlatformWriteScanner::codeOf() is the one
                // stripper, so this scan uses it rather than growing a second.
                if (str_contains(PlatformWriteScanner::codeOf((string) file_get_contents($file)), 'OrderManager::generateOrder(')) {
                    $callers[] = str_replace(base_path() . '/', '', $file);
                }
            }
        }

        sort($callers);

        // Measured in both directions, because a strip that excuses too much is worse than no strip: without codeOf() this scan reports
        // app/Services/Checkout/OrderRefused.php as a caller, and with it the set is exactly the three files that call it.
        $this->assertSame([
            'app/Http/Controllers/RestAPI/v1/OrderController.php',
            'app/Http/Controllers/Web/WebController.php',
            'app/Utils/module-helper.php',
        ], $callers, 'a new place that makes an order has to say whether it asks the destination gate');
    }

    // ---- the behavioural half: the two paths that were open --------------------------------------------------------------------------

    /** Dormancy, first: with the cross-border engine off -- which is how the platform ships -- a Kuwaiti address is paid for as it always was. */
    public function test_with_the_engine_off_the_website_wallet_checkout_places_the_order_as_it_always_did(): void
    {
        $customer = $this->shopperWithCart(walletBalance: 100);

        $this->completeWebWalletCheckout($customer, country: 'Kuwait')->assertRedirect(route('account-oder'));

        $this->assertSame(1, DB::table('orders')->count());
        $this->assertEquals(0.0, DB::table('users')->where('id', $customer->id)->value('wallet_balance'));
    }

    /**
     * The gap this change closes, measured through the real route: the market MAY sell into Kuwait, so the address guard those two
     * checkouts already carried lets the address through -- and nothing then asked whether the PRODUCT may go there. No permission row
     * means no (H1-03), which is what the app and the cash-on-delivery checkout have always refused on.
     *
     * Before the gate was added to this method, this order was placed and the wallet was debited.
     */
    public function test_the_website_wallet_checkout_refuses_a_product_that_may_not_go_abroad_and_takes_nothing_from_the_wallet(): void
    {
        $this->switchTheCrossBorderEngineOn(openTo: ['KW']);
        $customer = $this->shopperWithCart(walletBalance: 100);

        $response = $this->completeWebWalletCheckout($customer, country: 'Kuwait');

        $response->assertRedirect(route('checkout-payment'), 'sent back to the page they were on, as the engine refuses on every other path');
        $this->assertSame(0, DB::table('orders')->count(), 'nothing was written');
        $this->assertSame(0, DB::table('wallet_transactions')->count(), 'and the wallet was not touched');
        $this->assertEquals(100.0, DB::table('users')->where('id', $customer->id)->value('wallet_balance'));
        $this->assertNotSame([], array_column(session('toastr::messages', []), 'message'), 'and the customer is told why');
    }

    /**
     * The other half of the same question, and why half a gate looked like a whole one: a market that may not sell into that country at
     * all is refused by the ADDRESS guard, which those two checkouts did carry. That guard answers the first of the engine's five
     * questions and no more, which is why it is named here rather than relied on.
     */
    public function test_a_market_that_may_not_sell_there_is_refused_by_the_address_guard_before_the_gate_is_reached(): void
    {
        $this->switchTheCrossBorderEngineOn(openTo: []);
        $customer = $this->shopperWithCart(walletBalance: 100);

        $this->completeWebWalletCheckout($customer, country: 'Kuwait')->assertRedirect(route('checkout-details'));

        $this->assertSame(0, DB::table('orders')->count());
    }

    /** The home market is unaffected by the engine being on: a Saudi address crosses no border and is paid for. */
    public function test_a_home_market_address_is_unaffected_when_the_engine_is_on(): void
    {
        $this->switchTheCrossBorderEngineOn(openTo: ['KW']);
        $customer = $this->shopperWithCart(walletBalance: 100);

        $this->completeWebWalletCheckout($customer, country: 'Saudi Arabia')->assertRedirect(route('account-oder'));

        $this->assertSame(1, DB::table('orders')->count());
    }

    /** And a destination that is open AND permitted is bought, so the refusal is the engine's answer and not a blanket refusal of every address. */
    public function test_a_destination_that_is_open_and_permitted_is_bought(): void
    {
        $this->switchTheCrossBorderEngineOn(openTo: ['KW']);
        $customer = $this->shopperWithCart(walletBalance: 100);
        DB::table('product_destination_permissions')->insert([
            'product_id' => 1, 'destination_country' => 'KW', 'may_ship' => 1, 'may_export' => 1, 'may_import' => 1,
            'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->completeWebWalletCheckout($customer, country: 'Kuwait')->assertRedirect(route('account-oder'));

        $this->assertSame(1, DB::table('orders')->count());
    }

    // ---- the world -------------------------------------------------------------------------------------------------------------------

    /** @param list<string> $openTo the destinations the Saudi market may sell into */
    private function switchTheCrossBorderEngineOn(array $openTo): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.shipping.cross_border.enabled' => true,
            'gcc.shipping.cross_border.closed' => false,
            'gcc.shipping.cross_border.pairs' => $openTo === [] ? [] : ['SA' => $openTo],
        ]);
        CrossBorderPolicy::forget();
    }

    private function shopperWithCart(float|string $walletBalance): User
    {
        $customer = $this->makeCustomer(overrides: ['wallet_balance' => $walletBalance]);
        $this->putOneProductInTheCart(callerId: $customer->id);

        return $customer;
    }

    private function completeWebWalletCheckout(User $customer, string $country): TestResponse
    {
        $address = $this->makeLegacyAddress(customerId: $customer->id, overrides: ['country' => $country]);

        return $this->actingAs($customer, 'customer')
            ->withSession(['address_id' => $address->id, 'billing_address_id' => $address->id])
            ->from(route('checkout-payment'))
            ->post(route('checkout-complete-wallet'));
    }

    private function sourceOf(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $lines = (array) file((string) $reflection->getFileName());

        return implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
    }

    /** @return list<string> */
    private function phpFilesIn(string $directory): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
