<?php

namespace Tests\Support\Concurrency;

use App\Enums\SessionKey;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MaintenanceModeMiddleware;
use App\Http\Middleware\ModulePermissionMiddleware;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Http\Controllers\RestAPI\v3\seller\POSController;
use App\Models\Admin;
use App\Models\Seller;
use Closure;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The point-of-sale operations the till race runs, one per worker process.
 *
 * `placeSale` posts to the panel's own place-order URL through the HTTP kernel, so the race is run against the route the
 * panel's JavaScript calls and not against a service picked out of it. The panels' heavy request middleware is replaced with a
 * pass-through, the same set `Tests\Feature\Pos\PosTillTestCase` switches off, because a licence check and a telemetry writer
 * have nothing to do with who wins a race for the last unit on the shelf.
 */
final class PosOperations
{
    /** The middleware a panel request carries that these tests have no use for. */
    private const PASS_THROUGH = [
        ActivationCheckMiddleware::class, ModulePermissionMiddleware::class, MaintenanceModeMiddleware::class,
        Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
        RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
    ];

    /**
     * Post one sale to a till's place-order route.
     *
     * @param array{till: string, product: int, quantity: int, amount: float, seller: int} $args
     * @return array{status: int, orders: int, stock: int, movements: int, body: string}
     */
    public static function placeSale(array $args): array
    {
        $till = (string) $args['till'];
        self::passMiddlewareThrough();
        self::signIn(till: $till, sellerId: (int) ($args['seller'] ?? 0));

        $request = Request::create(
            uri: $till === 'admin' ? '/admin/pos/order-place' : '/vendor/pos/order-place',
            method: 'POST',
            parameters: [
                'amount' => $args['amount'],
                'type' => (string) ($args['type'] ?? 'cash'),
                'fulfillment' => 'instant',
                'paid_amount' => $args['amount'],
            ],
        );
        self::seedCartIntoTheSession($request, (int) $args['product'], (int) $args['quantity'], (int) ($args['customer'] ?? 0));

        $response = app(Kernel::class)->handle($request);

        return [
            'status' => $response->getStatusCode(),
            'orders' => (int) DB::table('orders')->count(),
            'stock' => (int) DB::table('products')->where('id', $args['product'])->value('current_stock'),
            'movements' => (int) DB::table('stock_movements')->where('product_id', $args['product'])->count(),
            'body' => mb_substr((string) $response->getContent(), 0, 200),
        ];
    }

    /**
     * Post one sale to the SELLER APP's till, the third one, behind `POST /api/v3/seller/pos/place-order`.
     *
     * The seller API carries no session, so the cart travels in the request body as `modified_cart` and the signed-in seller
     * is the `seller` attribute the API middleware sets. The controller action is called with that Request, which is the
     * pattern this repository's own v3 seller tests use; the race is therefore over the sale, not over the route guard.
     *
     * @param array{product: int, quantity: int, amount: float, seller: int, customer?: int, payment_method?: string} $args
     * @return array{status: int, orders: int, stock: int, movements: int, body: string}
     */
    public static function placeSaleAtSellerApp(array $args): array
    {
        $request = Request::create(
            uri: '/api/v3/seller/pos/place-order',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'customer_id' => (int) ($args['customer'] ?? 0),
                'cart' => [],
                'payment_method' => (string) ($args['payment_method'] ?? 'cash'),
                'paid_amount' => $args['amount'],
                'fulfillment' => 'instant',
                'total_tax_amount' => 0,
                'modified_cart' => [[
                    'id' => (int) $args['product'],
                    'quantity' => (int) $args['quantity'],
                    'price' => 100.0,
                    'discounted_price' => 100.0,
                    'discount' => 0,
                    'applied_tax_amount' => 0,
                    'coupon_discount' => 0,
                    'extra_discount' => 0,
                    'variant' => null,
                    'variation' => [],
                ]],
            ]),
        );
        $request['seller'] = (new Seller())->forceFill(['id' => (int) $args['seller'], 'f_name' => 'Vendor']);

        $response = app(POSController::class)->place_order($request);

        return [
            'status' => $response->getStatusCode(),
            'orders' => (int) DB::table('orders')->count(),
            'stock' => (int) DB::table('products')->where('id', $args['product'])->value('current_stock'),
            'movements' => (int) DB::table('stock_movements')->where('product_id', $args['product'])->count(),
            'body' => mb_substr((string) $response->getContent(), 0, 200),
        ];
    }

    private static function signIn(string $till, int $sellerId): void
    {
        if ($till === 'admin') {
            Auth::guard('admin')->setUser((new Admin())->forceFill(['id' => 1, 'name' => 'Master Admin', 'status' => 1]));

            return;
        }

        Auth::guard('seller')->setUser((new Seller())->forceFill(['id' => $sellerId, 'f_name' => 'Vendor', 'status' => 'approved']));
    }

    /**
     * One cart line in the session the request will be served with, written directly rather than through the add-to-cart
     * route: a worker process posts one request and the race is in the place-order, not in the cart.
     *
     * It is written through the application's own session store and handler and the request is given its id in a cookie,
     * because `StartSession` resolves that same store and reads the id from the cookie. Handing the request a store of its own
     * instead does not work: the `session()` helper the controller calls reads the one the middleware started, so the cart
     * would arrive empty.
     */
    private static function seedCartIntoTheSession(Request $request, int $productId, int $quantity, int $customerId = 0): void
    {
        $cartId = $customerId > 0 ? 'saved-customer-' . $customerId : 'walk-in-customer-1';
        $session = app('session.store');
        $session->start();
        $session->put(SessionKey::CURRENT_USER, $cartId);
        $session->put(SessionKey::CART_NAME, [$cartId]);
        $session->put($cartId, [[
            'id' => $productId,
            'name' => 'Product ' . $productId,
            'quantity' => $quantity,
            'price' => 100.0,
            'discount' => 0,
            'tax' => 0,
            'variant' => null,
            'variations' => [],
            'productType' => 'physical',
            'customerId' => $customerId,
            'thumbnail' => null,
            'maximum_order_quantity' => 1000,
            'add_to_cart_time' => now(),
        ]]);
        $session->save();

        $request->cookies->set($session->getName(), $session->getId());
    }

    private static function passMiddlewareThrough(): void
    {
        $passThrough = new class () {
            public function handle(Request $request, Closure $next, ...$arguments): mixed
            {
                return $next($request);
            }
        };

        foreach (self::PASS_THROUGH as $middleware) {
            app()->instance($middleware, $passThrough);
        }
    }
}
