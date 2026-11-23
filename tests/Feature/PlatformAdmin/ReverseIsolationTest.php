<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\Seller;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\LoadsPassportKeys;
use Tests\Support\RestoresLanguageFiles;

/**
 * The other direction: what a platform admin token, and a platform admin, can do to the seller API, the customer API and the panels. Nothing.
 * The platform principal is not a seller and a seller can never become one, and none of the existing APIs changes what it answers because the
 * new one exists, switched on or off.
 */
class ReverseIsolationTest extends PlatformAdminTestCase
{
    use LoadsPassportKeys;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->loadPassportKeys();
        Schema::create('seller_wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            foreach (['withdrawn', 'commission_given', 'total_earning', 'pending_withdraw', 'delivery_charge_earned', 'collected_cash'] as $column) {
                $table->decimal($column, 24, 2)->default(0);
            }
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();
        $this->removePassportKeys();

        parent::tearDown();
    }

    public function test_a_platform_token_opens_no_route_of_the_seller_api_v3(): void
    {
        $refused = $this->assertTheTokenChangesNothing(area: 'seller_v3');

        $this->assertGreaterThan(100, $refused, 'the seller API has many authenticated routes, and they refuse the token as they refuse any stranger');
    }

    public function test_a_platform_token_opens_no_route_of_the_customer_api_v1(): void
    {
        $refused = $this->assertTheTokenChangesNothing(area: 'customer_v1');

        $this->assertGreaterThan(20, $refused);
    }

    public function test_a_platform_token_changes_nothing_about_what_the_seller_api_v2_the_panels_and_the_rest_answer(): void
    {
        $compared = 0;

        foreach (['seller_v2', 'admin_panel', 'vendor_panel', 'other_api'] as $area) {
            $this->assertTheTokenChangesNothing(area: $area, every: 7);
            $compared++;
        }

        $this->assertSame(4, $compared);
    }

    public function test_a_platform_token_is_not_an_admin_panel_session(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);

        foreach ($this->sample(routes: $this->routesOf(area: 'admin_panel'), every: 25) as $route) {
            [$method, $uri] = $this->requestTo($route);

            $status = $this->json($method, $uri, [], $this->bearer($token))->getStatusCode();

            $this->assertNotSame(200, $status, "{$method} {$uri}");
        }

        $this->assertGuest('admin');
    }

    public function test_a_seller_sign_in_never_returns_a_platform_token_and_the_seller_token_opens_nothing_here(): void
    {
        $password = Str::random(20);
        Seller::forceCreate(['f_name' => 'Owner', 'email' => 'seller@example.test', 'status' => 'approved', 'password' => Hash::make($password)]);

        $response = $this->postJson('/api/v3/seller/auth/login', ['email' => 'seller@example.test', 'password' => $password]);

        $response->assertOk();
        $token = $response->json('token');
        $this->assertSame(50, strlen($token));
        $this->assertStringStartsNotWith('lkpa_', $token);
        $this->assertSame(0, DB::table('platform_admin_tokens')->count(), 'a seller sign-in writes nothing to the token store of the platform admin');
        $this->assertSame(0, DB::table('platform_admin_audit_logs')->count());
        $this->assertNotNull(Seller::approved()->where('auth_token', $token)->first(), 'it is a real seller token');
        $this->assertSame(401, $this->json('GET', self::PREFIX . '/me', [], $this->bearer($token))->getStatusCode());
    }

    public function test_an_admin_who_is_also_a_seller_with_the_same_credentials_still_gets_only_a_seller_token(): void
    {
        $admin = $this->platformAdmin();
        Seller::forceCreate(['f_name' => 'Same person', 'email' => $admin['email'], 'status' => 'approved', 'password' => Hash::make($admin['password'])]);

        $response = $this->postJson('/api/v3/seller/auth/login', ['email' => $admin['email'], 'password' => $admin['password']]);

        $response->assertOk();
        $this->assertStringStartsNotWith('lkpa_', $response->json('token'));
        $this->assertSame(0, DB::table('platform_admin_tokens')->count());
        $this->assertSame(401, $this->json('GET', self::PREFIX . '/me', [], $this->bearer($response->json('token')))->getStatusCode());
    }

    public function test_the_credentials_of_an_admin_who_is_not_a_seller_do_not_sign_in_through_the_seller_login(): void
    {
        $admin = $this->platformAdmin();

        $response = $this->postJson('/api/v3/seller/auth/login', ['email' => $admin['email'], 'password' => $admin['password']]);

        $response->assertStatus(401);
        $this->assertArrayNotHasKey('token', $response->json());
        $this->assertSame(0, DB::table('platform_admin_tokens')->count());
    }

    public function test_the_platform_sign_in_does_not_accept_a_seller_and_makes_no_seller_row(): void
    {
        $password = Str::random(20);
        Seller::forceCreate(['f_name' => 'Owner', 'email' => 'seller@example.test', 'status' => 'approved', 'password' => Hash::make($password)]);
        $sellers = DB::table('sellers')->get()->toJson();

        $this->postJson(self::PREFIX . '/auth/login', ['email' => 'seller@example.test', 'password' => $password, 'device_name' => 'Pixel'])->assertStatus(401);

        $this->assertSame($sellers, DB::table('sellers')->get()->toJson(), 'the seller was not touched: no token, no sign-in time');
        $this->assertSame(0, DB::table('platform_admin_tokens')->count());
    }

    public function test_the_existing_apis_answer_the_same_with_the_platform_api_on_or_off(): void
    {
        $password = Str::random(20);
        Seller::forceCreate(['f_name' => 'Owner', 'email' => 'seller@example.test', 'status' => 'approved', 'password' => Hash::make($password)]);

        $answers = [];
        foreach ([false, true] as $enabled) {
            config(['gcc.platform_admin.enabled' => $enabled]);
            $this->releaseEveryRateLimit();
            $answers[$enabled] = [
                $this->normalised($this->getJson('/api/v1/storefronts')),
                $this->normalised($this->getJson('/api/v3/seller/seller-info', $this->bearer(Str::random(50)))),
                $this->normalised($this->postJson('/api/v3/seller/auth/login', ['email' => 'seller@example.test', 'password' => 'not the password'])),
                $this->normalised($this->postJson('/api/v3/seller/auth/login', ['email' => 'seller@example.test', 'password' => $password])),
                $this->normalised($this->getJson('/api/v1/notifications/seen', $this->bearer(Str::random(50)))),
            ];
        }

        $this->assertSame($answers[false], $answers[true]);
        $this->assertSame(401, $answers[true][1]['status']);
        $this->assertSame(['auth-001' => 'Your existing session token does not authorize you any more'], $answers[true][1]['body']);
        $this->assertSame(200, $answers[true][3]['status']);
    }

    public function test_the_platform_code_never_reaches_for_a_seller_credential_or_the_session_guards(): void
    {
        $files = array_merge(
            glob(app_path('Services/PlatformAdmin/*.php')) ?: [],
            glob(app_path('Http/Middleware/*PlatformAdmin*.php')) ?: [],
            glob(app_path('Http/Controllers/RestAPI/PlatformAdmin/v1/*.php')) ?: [],
            glob(app_path('Console/Commands/PlatformAdmin*.php')) ?: [],
        );
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $code = $this->codeWithoutComments((string) file_get_contents($file));
            foreach (['Seller', 'seller_', 'auth_token', 'Passport', 'Sanctum', 'Auth::', 'auth('] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, basename($file) . ' must not use ' . $forbidden);
            }
        }
    }

    /** The code of a file, without its comments: what it says about what it refuses is not what it does. */
    private function codeWithoutComments(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /**
     * Asks every route of the area (or one in `every`) with and without a platform token and says the two answers are the same, so that the
     * token is worth exactly what no token is worth. The limiters are emptied before each request, so one answer cannot be a throttle's.
     *
     * @return int how many of the answers were a refusal to a caller who is not signed in
     */
    private function assertTheTokenChangesNothing(string $area, int $every = 1): int
    {
        $token = $this->mintToken($this->platformAdmin());
        $refusals = 0;

        foreach ($this->sample(routes: $this->routesOf(area: $area), every: $every) as $route) {
            [$method, $uri] = $this->requestTo($route);

            $this->releaseEveryRateLimit();
            $without = $this->json($method, $uri);
            $this->releaseEveryRateLimit();
            $with = $this->json($method, $uri, [], $this->bearer($token));

            $this->assertSame($this->saying($without), $this->saying($with), "{$area}: {$method} {$uri}");
            $this->assertSame($without->headers->get('Location'), $with->headers->get('Location'), "{$area}: {$method} {$uri}");
            $refusals += in_array($with->getStatusCode(), [401, 403], true) ? 1 : 0;
        }

        return $refusals;
    }

    /** @return list<RoutingRoute> */
    private function routesOf(string $area): array
    {
        $routes = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            $declared = (array) $route->getAction('middleware');
            $belongs = match ($area) {
                'seller_v3' => str_starts_with($uri, 'api/v3/seller') && in_array('seller_api_auth', $declared, true),
                'customer_v1' => str_starts_with($uri, 'api/v1/') && in_array('auth:api', $declared, true),
                'seller_v2' => str_starts_with($uri, 'api/v2/seller'),
                'admin_panel' => str_starts_with($uri, 'admin/') && in_array('admin', $declared, true),
                'vendor_panel' => str_starts_with($uri, 'vendor/') && in_array('seller', $declared, true),
                'other_api' => str_starts_with($uri, 'api/v1/') && !in_array('auth:api', $declared, true) && !str_starts_with($uri, 'api/platform-admin'),
            };
            if ($belongs) {
                $routes[] = $route;
            }
        }
        $this->assertNotEmpty($routes, "no route in the area {$area}: the test would prove nothing");

        return $routes;
    }

    /**
     * @param list<RoutingRoute> $routes
     *
     * @return list<RoutingRoute>
     */
    private function sample(array $routes, int $every): array
    {
        return array_values(array_filter($routes, static fn ($route, int $index): bool => $index % $every === 0, ARRAY_FILTER_USE_BOTH));
    }

    /** @return array{status: int, body: mixed} */
    private function normalised(TestResponse $response): array
    {
        $body = json_decode($response->getContent(), true);
        if (is_array($body) && isset($body['token'])) {
            $body['token'] = strlen($body['token']) . ' characters';
        }

        return ['status' => $response->getStatusCode(), 'body' => $body];
    }
}
