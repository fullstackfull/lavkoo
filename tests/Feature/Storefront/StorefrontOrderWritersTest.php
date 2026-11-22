<?php

namespace Tests\Feature\Storefront;

use App\Http\Middleware\EnsureStorefrontMaySell;
use App\Http\Middleware\ResolveStorefront;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Finder\Finder;
use Tests\Support\OrderWriterScanner;
use Tests\TestCase;

/**
 * I2-09: every writer of orders says what keeps a storefront that may not sell out of it. A scan of app/, Modules/ and routes/ finds every place that makes a row of `orders` (CREATORS) and every
 * piece of code that calls one (WRITERS: the action of a route, a console command, a payment hook), and the hand-written list says for each writer one of three things: the sale gate
 * (`storefront.sellable`, found on every one of its routes as the router resolves it, whether the route names the gate by its alias, by its class, with a parameter or through a group), an
 * exemption with its reason, or the criterion that will gate it (pending). The test fails when the scan finds a creator or a caller that is not on the list, when a gated writer has a route
 * without the gate, when an exemption has no reason that points at a document, a test or a criterion, and when the pending writers are not exactly the point-of-sale ones (I2-09.0), which gating one of them changes on purpose.
 *
 * The spellings the scan finds, and what it cannot see (a table named by a constant or a variable, a class named by a string or called through a callable, a receiver the file does not declare as
 * an Order, a builder of the model kept in a variable, a class that extends a creator's class and is called by its own name, another model over the table, a copy of a fetched order), are
 * pinned in OrderWriterScannerTest. The scan reads app/, Modules/ and routes/ and nothing else: not database/, the views, bootstrap/, config/, the tests directory of a module (not application
 * code), another application, or the database itself. A stop gate declared in the constructor of a controller is not read either: the routes are read as declared, as in
 * StorefrontSalesRoutesTest. Reorder is not a writer, it fills the cart, and the edit-order flows of the panels and of the seller API change an order that exists.
 */
class StorefrontOrderWritersTest extends TestCase
{
    private const SALE_GATE = EnsureStorefrontMaySell::class;

    private const STOREFRONT_RESOLVER = ResolveStorefront::class;

    private const CHECKOUT = 'customer checkout';

    private const POS = 'POS';

    private const MARKETPLACE_IMPORT = 'marketplace import';

    private const REORDER = 'reorder';

    private const STAFF = 'staff';

    private const OTHER = 'other';

    private const KINDS = [self::CHECKOUT, self::POS, self::MARKETPLACE_IMPORT, self::REORDER, self::STAFF, self::OTHER];

    private const SHORTEST_REASON = 60;

    /** What an exemption points at, besides saying why: a document of the repository, a test of it, or a criterion of the tracker. */
    private const REASON_POINTER = '/\bdocs\/[\w\/.-]+\.md\b|\b[A-Z]\w*Test\b|\b[A-Z]{1,3}\d*-\d{2}(?:\.\d+)?\b/';

    private const POS_CRITERION = 'I2-09.0';

    private const GENERATE_ORDER = 'App\Utils\OrderManager::generateOrder';

    private const REPOSITORY_ADD = 'App\Repositories\OrderRepository::add';

    private const SELLER_POS = 'App\Http\Controllers\RestAPI\v3\seller\POSController::place_order';

    private const MATERIALIZER_CLASS = 'App\Services\SalesChannels\Dispatch\ChannelOrderMaterializer';

    private const MATERIALIZER = self::MATERIALIZER_CLASS . '::insertOrder';

    private const DISPATCHER = 'App\Services\SalesChannels\Dispatch\ChannelOrderDispatcher::dispatch';

    /**
     * The places that make a row of orders, and how the code that places an order reaches each, from the creator outward: the types of the receiver (`A|B`) and the method called on it, and the
     * only code that may make that call (null for the last hop, whose callers are the writers below). A creator that is the action of a route itself has no hop. A method of the creator's own
     * class that calls it (`self::`, `static::`, `$this->`) is one more hop: the scan finds the call, and the test fails until the method is listed here with the code that may call it.
     */
    private const CREATORS = [
        self::GENERATE_ORDER => ['OrderManager::generateOrder' => null],
        self::REPOSITORY_ADD => ['OrderRepositoryInterface|OrderRepository::add' => null],
        self::SELLER_POS => [],
        self::MATERIALIZER => [
            'ChannelOrderMaterializer::insertOrder' => [self::MATERIALIZER_CLASS . '::makeOrder'],
            'ChannelOrderMaterializer::makeOrder' => [self::MATERIALIZER_CLASS . '::materialize'],
            'ChannelOrderMaterializer::materialize' => [self::DISPATCHER],
            'ChannelOrderDispatcher::dispatch' => null,
        ],
    ];

    /**
     * Every writer: the code that places an order. `entry_points` are the routes that run it, written as the route's name or, when it has none, its method and URI. `started_by` is the action of
     * the routes that start what a writer without a route settles. One of `gate`, `exempt` (a reason) or `pending` (the criterion that will gate it).
     */
    private const WRITERS = [
        'App\Http\Controllers\RestAPI\v1\OrderController::place_order' => [
            'creator' => self::GENERATE_ORDER, 'kind' => self::CHECKOUT, 'entry_points' => ['GET api/v1/customer/order/place'], 'gate' => true,
        ],
        'App\Http\Controllers\RestAPI\v1\OrderController::placeOrderByOfflinePayment' => [
            'creator' => self::GENERATE_ORDER, 'kind' => self::CHECKOUT, 'entry_points' => ['POST api/v1/customer/order/place-by-offline-payment'], 'gate' => true,
        ],
        'App\Http\Controllers\RestAPI\v1\OrderController::placeOrderByWallet' => [
            'creator' => self::GENERATE_ORDER, 'kind' => self::CHECKOUT, 'entry_points' => ['GET api/v1/customer/order/place-by-wallet'], 'gate' => true,
        ],
        'App\Http\Controllers\Web\WebController::getCashOnDeliveryCheckoutComplete' => [
            'creator' => self::GENERATE_ORDER, 'kind' => self::CHECKOUT, 'entry_points' => ['checkout-complete'], 'gate' => true,
        ],
        'App\Http\Controllers\Web\WebController::getOfflinePaymentCheckoutComplete' => [
            'creator' => self::GENERATE_ORDER, 'kind' => self::CHECKOUT, 'entry_points' => ['offline-payment-checkout-complete'], 'gate' => true,
        ],
        'App\Http\Controllers\Web\WebController::checkout_complete_wallet' => [
            'creator' => self::GENERATE_ORDER, 'kind' => self::CHECKOUT, 'entry_points' => ['checkout-complete-wallet'], 'gate' => true,
        ],
        'digital_payment_place_orders' => [
            'creator' => self::GENERATE_ORDER, 'kind' => self::CHECKOUT, 'entry_points' => [],
            'started_by' => 'App\Http\Controllers\Customer\PaymentController::payment',
            'exempt' => 'A payment gateway reports a payment that has already been taken, so the order has to follow it even when the storefront was stopped after the payment started (GCC-12 keeps payments and webhook processing running; StorefrontStopContinuityTest drives it). The payment is started by the routes of PaymentController::payment, which carry the sale gate, and the order is built in the storefront recorded with that payment (PaymentStorefront).',
        ],
        'App\Http\Controllers\Admin\POS\POSOrderController::placeOrder' => [
            'creator' => self::REPOSITORY_ADD, 'kind' => self::POS, 'entry_points' => ['admin.pos.place-order'], 'pending' => self::POS_CRITERION,
        ],
        'App\Http\Controllers\Vendor\POS\POSOrderController::placeOrder' => [
            'creator' => self::REPOSITORY_ADD, 'kind' => self::POS, 'entry_points' => ['vendor.pos.order-place'], 'pending' => self::POS_CRITERION,
        ],
        self::SELLER_POS => [
            'creator' => self::SELLER_POS, 'kind' => self::POS, 'entry_points' => ['POST api/v3/seller/pos/place-order'], 'pending' => self::POS_CRITERION,
        ],
        'App\Http\Controllers\Admin\SalesChannels\ChannelOrderController::dispatchToProvider' => [
            'creator' => self::MATERIALIZER, 'kind' => self::MARKETPLACE_IMPORT, 'entry_points' => ['admin.sales-channels.orders.dispatch'],
            'exempt' => 'A channel order is a sale the marketplace has made and been paid for; the internal order only fulfils it (docs/sales-channels/architecture.md, "Channel orders are not storefront orders"), it has no customer, and a dispatch is started by an administrator or by the schedule with no request bound to a storefront. The owner has recorded no rule for a stopped storefront: this is what the code does today, pinned by ChannelOrderSaleGateTest (I2-09.1), and whether a stopped storefront should also stop the dispatch is the owner\'s decision.',
        ],
        'App\Console\Commands\SalesChannelsDispatchCommand::dispatchOne' => [
            'creator' => self::MATERIALIZER, 'kind' => self::MARKETPLACE_IMPORT, 'entry_points' => [],
            'exempt' => 'The schedule (channels:dispatch, every five minutes, doing nothing unless every dispatch switch is on) runs the same dispatcher as the administrator\'s button, with no request and so no storefront; whatever the owner decides for the dispatch from the panel (I2-09.1, ChannelOrderSaleGateTest) holds for it.',
        ],
    ];

    /** I2-09.0: the point-of-sale writers. The sale gate does not cover them yet. Gating one of them takes it off this list and off the pending ones, on purpose. */
    private const POS_WRITERS = [
        'App\Http\Controllers\Admin\POS\POSOrderController::placeOrder',
        'App\Http\Controllers\RestAPI\v3\seller\POSController::place_order',
        'App\Http\Controllers\Vendor\POS\POSOrderController::placeOrder',
    ];

    /** @var array{writers: array<string, list<string>>, callers: array<string, list<string>>, files: array<string, int>}|null */
    private static ?array $application = null;

    // ---- the scan -------------------------------------------------------------------------------------------------------------------------

    public function test_every_place_that_makes_a_row_of_orders_is_on_the_list_and_every_listed_one_is_still_there(): void
    {
        $found = $this->theApplication()['writers'];
        $unlisted = array_values(array_diff(array_keys($found), array_keys(self::CREATORS)));
        $gone = array_values(array_diff(array_keys(self::CREATORS), array_keys($found)));

        $this->assertSame([], $unlisted, 'Code that makes a row of orders is not on the list (CREATORS, WRITERS). Say what keeps a storefront that may not sell out of it: the sale gate on its routes, the reason it is exempt, or the criterion that will gate it. Found: ' . json_encode(array_intersect_key($found, array_flip($unlisted))));
        $this->assertSame([], $gone, 'A listed creator no longer makes a row of orders (renamed, moved or removed): take it off the list, or rename it.');
    }

    public function test_every_caller_of_a_creator_is_a_listed_writer_and_every_listed_writer_still_calls_it(): void
    {
        $callers = $this->theApplication()['callers'];

        foreach (self::CREATORS as $creator => $hops) {
            $writers = $this->writersOf(creator: $creator);
            $this->assertNotSame([], $writers, "{$creator} has no writer on the list.");

            if ($hops === []) {
                $this->assertSame([$creator], $writers, "{$creator} is the action of its route: it is its own writer, and no other code places an order through it.");
            }

            foreach ($hops as $hop => $allowed) {
                $this->assertSame(
                    $allowed ?? $writers,
                    $callers[$hop],
                    "The code that calls [{$hop}] is not the code on the list for {$creator}: a new caller is a new way to place an order, and has to say what keeps a stopped storefront out of it (WRITERS), and a listed writer that no longer calls it comes off.",
                );
            }
        }
    }

    public function test_the_scan_reads_the_source_it_is_meant_to_so_a_wrong_root_cannot_make_it_pass(): void
    {
        $files = $this->theApplication()['files'];

        $this->assertGreaterThan(2000, array_sum($files));
        foreach (['app', 'Modules', 'routes'] as $root) {
            $this->assertGreaterThan(0, $files[$root] ?? 0, "The scan read no file of {$root}/.");
        }
        $this->assertNotSame([], $this->theApplication()['writers']);
        $this->assertNotSame([], $this->theApplication()['callers']['OrderManager::generateOrder']);
    }

    // ---- the list -------------------------------------------------------------------------------------------------------------------------

    public function test_every_writer_has_a_known_kind_a_creator_and_exactly_one_of_a_gate_a_reasoned_exemption_or_a_pending_criterion(): void
    {
        foreach (self::WRITERS as $writer => $entry) {
            $this->assertContains($entry['kind'], self::KINDS, "{$writer} has no known kind.");
            $this->assertArrayHasKey($entry['creator'], self::CREATORS, "{$writer} reaches a creator that is not listed.");

            $statuses = array_values(array_intersect(array_keys($entry), ['gate', 'exempt', 'pending']));
            $this->assertCount(1, $statuses, "{$writer} must say exactly one of gate, exempt or pending.");

            match ($statuses[0]) {
                'gate' => $this->assertTrue($entry['gate'], "{$writer} is gated: say gate => true."),
                'exempt' => $this->assertAReason(reason: $entry['exempt'], subject: "{$writer} is exempt"),
                'pending' => $this->assertMatchesRegularExpression('/^I\d+-\d{2}\.\d+$/', (string) $entry['pending'], "{$writer} is pending: name the criterion that will gate it."),
            };
        }
    }

    /** @return array<string, array{mixed, bool}> a reason, and whether an exemption may give it */
    public static function reasonsForAnExemption(): array
    {
        $said = 'A storefront that may not sell is let through here because the sale was made, and paid for, somewhere else; ';

        return [
            'a document of the repository' => [$said . 'see docs/sales-channels/architecture.md', true],
            'a test of the repository' => [$said . 'StorefrontOrderWritersTest pins it', true],
            'a criterion' => [$said . 'I2-09.1 asks the owner', true],
            'a criterion of the tracker with letters' => [$said . 'GCC-12 keeps it running', true],
            'no pointer' => [$said . 'and that is all there is to it', false],
            'too short' => ['because (I2-09.1)', false],
            'a document that is not there' => [$said . 'see docs/gcc/NO-SUCH-DOCUMENT.md', false],
            'a test that is not there' => [$said . 'NoSuchThingTest pins it', false],
            'a document that is there, and one that is not' => [$said . 'see docs/sales-channels/architecture.md and docs/gcc/NO-SUCH-DOCUMENT.md', false],
            'nothing' => [null, false],
            'a list' => [[$said . 'I2-09.1'], false],
        ];
    }

    #[DataProvider('reasonsForAnExemption')]
    public function test_a_reason_for_an_exemption_is_long_enough_and_points_at_what_records_it(mixed $reason, bool $accepted): void
    {
        if (!$accepted) {
            $this->expectException(AssertionFailedError::class);
        }

        $this->assertAReason(reason: $reason, subject: 'The writer is exempt');
    }

    public function test_the_entry_points_of_a_writer_are_the_routes_that_run_it_all_of_them_and_nothing_else(): void
    {
        foreach (self::WRITERS as $writer => $entry) {
            foreach ($entry['entry_points'] as $entryPoint) {
                $this->assertSame($writer, $this->actionOf(route: $this->routeOf(entryPoint: $entryPoint)), "[{$entryPoint}] does not run {$writer}.");
            }

            $this->assertEqualsCanonicalizing(
                $entry['entry_points'],
                array_map($this->entryPointOf(...), $this->routesRunning(action: $writer)),
                "A route runs {$writer} and is not on its list of entry points, or a listed entry point no longer runs it. A new route is a new way to place an order: list it, with the gate on it.",
            );
        }
    }

    public function test_a_gated_writer_carries_the_sale_gate_on_every_entry_point(): void
    {
        $gated = array_filter(self::WRITERS, static fn (array $entry): bool => isset($entry['gate']));

        $this->assertNotSame([], $gated);
        foreach ($gated as $writer => $entry) {
            $this->assertNotSame([], $entry['entry_points'], "{$writer} is gated and has no route to carry the gate.");
            foreach ($entry['entry_points'] as $entryPoint) {
                $this->assertTrue($this->carriesTheSaleGate(route: $this->routeOf(entryPoint: $entryPoint)), "[{$entryPoint}] places an order through {$writer}, which is listed as gated, and does not carry the sale gate (storefront.sellable).");
            }
        }
    }

    public function test_the_pending_writers_are_exactly_the_point_of_sale_ones_and_none_of_them_carries_the_gate_yet(): void
    {
        $pending = array_keys(array_filter(self::WRITERS, static fn (array $entry): bool => isset($entry['pending'])));
        sort($pending);

        $this->assertSame(self::POS_WRITERS, $pending, 'The pending list is not exactly the point-of-sale writers (I2-09.0). Gating one of them takes it off POS_WRITERS and off the pending list together, on purpose, and no other writer may be pending.');

        foreach ($pending as $writer) {
            $this->assertSame(self::POS, self::WRITERS[$writer]['kind'], "{$writer} is pending and is not a point-of-sale writer.");
            $this->assertSame(self::POS_CRITERION, self::WRITERS[$writer]['pending']);

            foreach (self::WRITERS[$writer]['entry_points'] as $entryPoint) {
                $this->assertFalse($this->carriesTheSaleGate(route: $this->routeOf(entryPoint: $entryPoint)), "[{$entryPoint}] now carries the sale gate: move {$writer} from pending to gate, and off POS_WRITERS.");
            }
        }
    }

    public function test_an_exempt_writer_is_one_the_gate_does_not_cover_and_what_a_writer_without_a_route_settles_was_started_behind_the_gate(): void
    {
        $started = 0;

        foreach (self::WRITERS as $writer => $entry) {
            if (!isset($entry['exempt'])) {
                continue;
            }

            if ($entry['entry_points'] !== []) {
                $open = array_filter($entry['entry_points'], fn (string $entryPoint): bool => !$this->carriesTheSaleGate(route: $this->routeOf(entryPoint: $entryPoint)));
                $this->assertNotSame([], $open, "Every route of {$writer} carries the sale gate, so it is not exempt: list it as gated.");
            }

            if (isset($entry['started_by'])) {
                $started++;
                $starters = $this->routesRunning(action: $entry['started_by']);
                $this->assertNotSame([], $starters, "No route runs [{$entry['started_by']}], where what {$writer} settles is started.");
                foreach ($starters as $route) {
                    $this->assertTrue($this->carriesTheSaleGate(route: $route), "[{$this->entryPointOf(route: $route)}] starts what {$writer} settles and does not carry the sale gate.");
                }
            }
        }

        $this->assertGreaterThan(0, $started);
    }

    public function test_current_behaviour_the_point_of_sale_routes_are_bound_to_no_storefront(): void
    {
        foreach (self::POS_WRITERS as $writer) {
            foreach (self::WRITERS[$writer]['entry_points'] as $entryPoint) {
                $this->assertNotContains(self::STOREFRONT_RESOLVER, $this->middlewareClassesOf(route: $this->routeOf(entryPoint: $entryPoint)), "[{$entryPoint}] runs the storefront resolver now, so its request is bound to a storefront and the sale gate would judge that one: what I2-09.0 has to decide changes.");
            }
        }
    }

    // ---- the gate, as the router resolves it ------------------------------------------------------------------------------------------------

    public function test_the_sale_gate_is_found_however_a_route_names_it_and_not_when_it_is_excluded(): void
    {
        Route::middlewareGroup('probe-sale-gate', ['storefront.sellable']);
        Route::middlewareGroup('probe-sale-gate-nested', ['probe-sale-gate']);
        Route::middleware('storefront.sellable')->group(static function (): void {
            Route::get('/__probe/in-a-group', static fn () => 'ok');
        });

        $withTheGate = [
            'its alias' => Route::get('/__probe/alias', static fn () => 'ok')->middleware('storefront.sellable'),
            'its class' => Route::get('/__probe/class', static fn () => 'ok')->middleware(EnsureStorefrontMaySell::class),
            'a parameter' => Route::get('/__probe/parameter', static fn () => 'ok')->middleware('storefront.sellable:anything'),
            'a middleware group' => Route::get('/__probe/group', static fn () => 'ok')->middleware('probe-sale-gate'),
            'a group in a group' => Route::get('/__probe/nested', static fn () => 'ok')->middleware('probe-sale-gate-nested'),
            'a route group' => $this->routeAt(uri: '__probe/in-a-group'),
        ];
        $withoutTheGate = [
            'nothing' => Route::get('/__probe/none', static fn () => 'ok'),
            'the maintenance gate' => Route::get('/__probe/maintenance', static fn () => 'ok')->middleware('storefront.available'),
            'the storefront resolver' => Route::get('/__probe/resolver', static fn () => 'ok')->middleware('storefront'),
            'the gate excluded by its alias' => Route::get('/__probe/excluded', static fn () => 'ok')->middleware('storefront.sellable')->withoutMiddleware('storefront.sellable'),
            'the gate excluded by its class' => Route::get('/__probe/excluded-class', static fn () => 'ok')->middleware('storefront.sellable')->withoutMiddleware(EnsureStorefrontMaySell::class),
        ];

        foreach ($withTheGate as $spelling => $route) {
            $this->assertTrue($this->carriesTheSaleGate(route: $route), "The sale gate named by {$spelling} must be found.");
        }
        foreach ($withoutTheGate as $spelling => $route) {
            $this->assertFalse($this->carriesTheSaleGate(route: $route), "A route with {$spelling} does not carry the sale gate.");
        }
    }

    // ---- helpers --------------------------------------------------------------------------------------------------------------------------

    /** @return list<string> the writers on the list that place an order through the creator, sorted */
    private function writersOf(string $creator): array
    {
        $writers = array_keys(array_filter(self::WRITERS, static fn (array $entry): bool => $entry['creator'] === $creator));
        sort($writers);

        return $writers;
    }

    private function assertAReason(mixed $reason, string $subject): void
    {
        $this->assertIsString($reason, "{$subject} without a reason.");
        $this->assertGreaterThanOrEqual(self::SHORTEST_REASON, mb_strlen(trim($reason)), "{$subject} without a reason: say why a storefront that may not sell can be let through.");
        $this->assertMatchesRegularExpression(self::REASON_POINTER, $reason, "{$subject} without a pointer: name the document, the test or the criterion that records why.");

        preg_match_all('#\bdocs/[\w/.-]+\.md\b#', $reason, $documents);
        foreach ($documents[0] as $document) {
            $this->assertFileExists(base_path($document), "{$subject}, and the reason names [{$document}], which is not a document of the repository.");
        }

        preg_match_all('/\b[A-Z]\w*Test\b/', $reason, $tests);
        foreach ($tests[0] as $test) {
            $this->assertTrue((new Finder())->files()->in(base_path('tests'))->name("{$test}.php")->hasResults(), "{$subject}, and the reason names [{$test}], which is not a test of the repository.");
        }
    }

    /** @return list<RoutingRoute> */
    private function routesRunning(string $action): array
    {
        return array_values(array_filter(Route::getRoutes()->getRoutes(), fn (RoutingRoute $route): bool => $this->actionOf(route: $route) === $action));
    }

    private function routeAt(string $uri): RoutingRoute
    {
        $routes = array_values(array_filter(Route::getRoutes()->getRoutes(), static fn (RoutingRoute $route): bool => $route->uri() === $uri));
        $this->assertCount(1, $routes, "No single route has the address [{$uri}].");

        return $routes[0];
    }

    private function routeOf(string $entryPoint): RoutingRoute
    {
        $routes = array_values(array_filter(Route::getRoutes()->getRoutes(), fn (RoutingRoute $route): bool => $this->entryPointOf(route: $route) === $entryPoint));
        $this->assertCount(1, $routes, "No single route goes by [{$entryPoint}]: write the name of a route or, when it has none, its method and URI.");

        return $routes[0];
    }

    private function entryPointOf(RoutingRoute $route): string
    {
        return $route->getName() ?? "{$route->methods()[0]} {$route->uri()}";
    }

    /** The action of a controller route as `Class::method`, the way the scan names the code that contains a call. */
    private function actionOf(RoutingRoute $route): ?string
    {
        $action = (string) $route->getActionName();

        return str_contains($action, '@') ? str_replace('@', '::', $action) : null;
    }

    private function carriesTheSaleGate(RoutingRoute $route): bool
    {
        return in_array(self::SALE_GATE, $this->middlewareClassesOf(route: $route), true);
    }

    /**
     * The middleware a route is declared with, resolved by the router to classes without their parameters, groups and exclusions included. Not gatherMiddleware(): that builds the controller of
     * every route, and some controllers need a service this test has no credentials for.
     *
     * @return list<string>
     */
    private function middlewareClassesOf(RoutingRoute $route): array
    {
        return collect(Route::resolveMiddleware(middleware: $route->middleware(), excluded: $route->excludedMiddleware()))
            ->filter(static fn (mixed $middleware): bool => is_string($middleware))
            ->map(static fn (string $middleware): string => strtok($middleware, ':'))
            ->values()
            ->all();
    }

    /** @return array{writers: array<string, list<string>>, callers: array<string, list<string>>, files: array<string, int>} */
    private function theApplication(): array
    {
        return self::$application ??= $this->scanTheApplication();
    }

    /** @return array{writers: array<string, list<string>>, callers: array<string, list<string>>, files: array<string, int>} */
    private function scanTheApplication(): array
    {
        $scanner = new OrderWriterScanner();
        $queries = [];
        foreach (self::CREATORS as $hops) {
            foreach (array_keys($hops) as $hop) {
                [$types, $method] = explode('::', $hop);
                $queries[$hop] = [explode('|', $types), $method];
            }
        }

        $writers = [];
        $callers = array_map(static fn (array $query): array => [], $queries);
        $files = [];

        foreach (['app', 'Modules', 'routes'] as $root) {
            $files[$root] = 0;
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($root), RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                $path = substr($file->getPathname(), strlen(base_path()) + 1);
                if ($file->getExtension() !== 'php' || preg_match('#^Modules/[^/]+/tests/#', $path) === 1) {
                    continue;
                }

                $files[$root]++;
                $found = $scanner->analyse(code: (string) file_get_contents($file->getPathname()), file: $path, callerQueries: $queries);
                foreach ($found['writers'] as $writer => $spellings) {
                    $writers[$writer] = [...($writers[$writer] ?? []), ...$spellings];
                }
                foreach ($found['callers'] as $hop => $owners) {
                    $callers[$hop] = [...$callers[$hop], ...$owners];
                }
            }
        }

        ksort($writers);

        return [
            'writers' => $writers,
            'callers' => array_map(static function (array $owners): array {
                $owners = array_values(array_unique($owners));
                sort($owners);

                return $owners;
            }, $callers),
            'files' => $files,
        ];
    }
}
