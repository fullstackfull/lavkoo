<?php

namespace App\Services\DeveloperPortal\Support;

use Illuminate\Routing\Route;

/**
 * What a caller must present, and how often they may call.
 *
 * Read from the middleware the route actually runs, not from a convention. That distinction has
 * teeth in this application: the customer API authenticates with Passport tokens through
 * Authenticate:api, while the vendor API does NOT — SellerApiAuthMiddleware compares the bearer
 * value against a plain auth_token column on the seller row. A portal that assumed one mechanism
 * because the other was configured would send every vendor-app developer down the wrong path.
 *
 * The rate limit is read the same way: `throttle:20,1` on an auth route is a real number a client
 * needs to design its retry loop around, and it is on the route, not in a document.
 */
class AuthResolver
{
    /** Middleware that authenticates, and the scheme each one represents. */
    private const SCHEMES = [
        'App\Http\Middleware\Authenticate:api' => [
            'scheme' => 'bearer',
            'mechanism' => 'passport',
            'actor' => 'customer',
            'header' => 'Authorization: Bearer <access_token>',
            'note' => 'A Laravel Passport access token, issued by the customer login endpoints.',
        ],
        'App\Http\Middleware\Authenticate:sanctum' => [
            'scheme' => 'bearer',
            'mechanism' => 'sanctum',
            'actor' => 'customer',
            'header' => 'Authorization: Bearer <token>',
            'note' => 'A Laravel Sanctum token.',
        ],
        'App\Http\Middleware\SellerApiAuthMiddleware' => [
            'scheme' => 'bearer',
            'mechanism' => 'seller_token',
            'actor' => 'vendor',
            'header' => 'Authorization: Bearer <seller_auth_token>',
            'note' => 'The vendor auth_token returned by the seller login endpoint. This is NOT a Passport token and is not interchangeable with the customer one.',
        ],
        'App\Http\Middleware\DeliveryManAuth' => [
            'scheme' => 'bearer',
            'mechanism' => 'delivery_token',
            'actor' => 'delivery_man',
            'header' => 'Authorization: Bearer <delivery_man_token>',
            'note' => 'The delivery man auth token returned by the delivery app login endpoint.',
        ],
        'App\Http\Middleware\DeliverySyriaWebhookAuthMiddleware' => [
            'scheme' => 'shared_secret',
            'mechanism' => 'webhook_secret',
            'actor' => 'partner',
            'header' => 'A shared secret configured with the courier',
            'note' => 'Inbound courier webhook. The secret is configured in the Delivery Syria settings, never in the request.',
        ],
        'App\Http\Middleware\AdminMiddleware' => [
            'scheme' => 'session',
            'mechanism' => 'admin_session',
            'actor' => 'admin',
            'header' => 'Admin panel session cookie',
            'note' => 'Panel-only. Not reachable by an external integration.',
        ],
        'App\Http\Middleware\SellerMiddleware' => [
            'scheme' => 'session',
            'mechanism' => 'vendor_session',
            'actor' => 'vendor',
            'header' => 'Vendor panel session cookie',
            'note' => 'Panel-only. Not reachable by an external integration.',
        ],
        'App\Http\Middleware\CustomerMiddleware' => [
            'scheme' => 'session',
            'mechanism' => 'customer_session',
            'actor' => 'customer',
            'header' => 'Storefront session cookie',
            'note' => 'Storefront-only.',
        ],
    ];

    /**
     * The in-line authentication this application actually uses, and where to look for it.
     *
     * The v2 seller API declares no auth middleware at all — its controllers call
     * `Helpers::get_seller_by_token()` on the first line instead — so a resolver that read only
     * middleware told every reader that balance-withdraw and seller-update were public. That is the
     * single most dangerous claim a portal can make, and the one direction this class must never be
     * wrong in.
     *
     * Detected per controller by looking for the call rather than declared per namespace, because a
     * namespace rule would be wrong the moment one controller in it is genuinely public — which is
     * already the case: BrandController under the same prefix lists brands and authenticates
     * nothing. A list would have documented it as protected and it is not.
     *
     * @var array<string, array<string, string>>
     */
    private const INLINE_AUTHENTICATION = [
        'get_seller_by_token' => [
            'scheme' => 'bearer',
            'mechanism' => 'seller_token',
            'actor' => 'vendor',
            'header' => 'Authorization: Bearer <seller_auth_token>',
            'note' => 'Authenticated inside the controller through Helpers::get_seller_by_token(), not by middleware — the route group declares none. This is the vendor auth_token from the seller login endpoint, and it is NOT a Passport token.',
        ],
    ];

    /** Only these namespaces are read from disk; everything else authenticates through middleware. */
    private const INLINE_NAMESPACES = ['App\\Http\\Controllers\\RestAPI\\'];

    /** @var array<string, array<string, string>|null> one read per controller class, not per route. */
    private array $inlineCache = [];

    /** Middleware that makes authentication optional rather than required. */
    private const OPTIONAL = [
        'App\Http\Middleware\APIGuestMiddleware',
        'App\Http\Middleware\GuestMiddleware',
        'guestCheck',
        'apiGuestCheck',
    ];

    /**
     * @return array<string, mixed>
     */
    public function resolve(Route $route): array
    {
        $middleware = $this->middleware($route);
        $matched = null;
        $optional = false;

        foreach ($middleware as $item) {
            if (isset(self::SCHEMES[$item])) {
                $matched ??= self::SCHEMES[$item];

                continue;
            }

            // A guard this table does not name is still authentication. Keying only on the two
            // guards that happened to be listed documented fourteen protected endpoints —
            // auth:web and auth:customer — as needing no authentication at all, which is the one
            // direction this resolver must never be wrong in.
            $generic = $this->genericGuard($item);
            if ($generic !== null) {
                $matched ??= $generic;

                continue;
            }

            if (in_array($item, self::OPTIONAL, true)) {
                $optional = true;
            }
        }

        $matched ??= $this->inlineAuthentication($route);

        if ($matched === null) {
            return [
                'required' => false,
                'scheme' => 'none',
                'mechanism' => 'public',
                'actor' => 'anyone',
                'header' => null,
                // "Optional" is a real and different answer from "public": these endpoints behave
                // differently for a signed-in caller (a cart tied to an account rather than a guest
                // id), and a client that never sends a token silently gets the guest behaviour.
                'note' => $optional
                    ? 'Public, but behaves differently when a customer token is sent: without one the caller is treated as a guest.'
                    : 'No authentication required.',
                'optional_auth' => $optional,
            ];
        }

        return $matched + ['required' => true, 'optional_auth' => $optional];
    }

    /**
     * Authentication the route table cannot see, because it happens inside the action.
     *
     * @return array<string, string>|null
     */
    private function inlineAuthentication(Route $route): ?array
    {
        $controller = $route->getAction('controller');

        if (!is_string($controller) || !str_contains($controller, '@')) {
            return null;
        }

        $class = ltrim(explode('@', $controller)[0], '\\');

        if (array_key_exists($class, $this->inlineCache)) {
            return $this->inlineCache[$class];
        }

        $inNamespace = false;
        foreach (self::INLINE_NAMESPACES as $namespace) {
            $inNamespace = $inNamespace || str_starts_with($class, ltrim($namespace, '\\'));
        }

        if (!$inNamespace || !class_exists($class)) {
            return $this->inlineCache[$class] = null;
        }

        $file = (new \ReflectionClass($class))->getFileName();
        $source = $file !== false && is_readable($file) ? (string) file_get_contents($file) : '';

        foreach (self::INLINE_AUTHENTICATION as $marker => $scheme) {
            if (str_contains($source, $marker)) {
                return $this->inlineCache[$class] = $scheme;
            }
        }

        return $this->inlineCache[$class] = null;
    }

    /**
     * An authentication middleware whose guard this table does not describe.
     *
     * @return array<string, string|null>|null
     */
    private function genericGuard(mixed $item): ?array
    {
        if (!is_string($item)) {
            return null;
        }

        foreach (['App\\Http\\Middleware\\Authenticate', 'Illuminate\\Auth\\Middleware\\Authenticate'] as $class) {
            if ($item !== $class && !str_starts_with($item, $class . ':')) {
                continue;
            }

            $guard = str_contains($item, ':') ? substr($item, strpos($item, ':') + 1) : 'default';
            $session = in_array($guard, ['web', 'customer', 'seller', 'admin', 'default'], true);

            return [
                'scheme' => $session ? 'session' : 'bearer',
                'mechanism' => $guard . '_guard',
                'actor' => match ($guard) {
                    'customer', 'web' => 'customer',
                    'seller' => 'vendor',
                    'admin' => 'admin',
                    default => 'authenticated caller',
                },
                'header' => $session ? 'Session cookie' : 'Authorization: Bearer <token>',
                'note' => 'Authenticated through Laravel\'s "' . $guard . '" guard.',
            ];
        }

        return null;
    }

    /**
     * The permissions or scopes this route demands, taken from the module middleware.
     *
     * @return array<int, string>
     */
    public function permissions(Route $route): array
    {
        $permissions = [];

        foreach ($this->middleware($route) as $item) {
            if (!is_string($item)) {
                continue;
            }

            if (str_starts_with($item, 'module:')) {
                foreach (explode(',', substr($item, 7)) as $module) {
                    $permissions[] = trim($module);
                }
            }

            if (str_starts_with($item, 'can:')) {
                $permissions[] = trim(substr($item, 4));
            }

            // The real gate on the seller API. Fifty-three route groups declare `seller_can:` and
            // this method matched only `module:` and `can:`, so the scope column resolved empty for
            // all 537 endpoints — including the ones a seller-issued API key is refused by unless
            // the route declares one.
            if (str_starts_with($item, 'seller_can:')) {
                foreach (explode(',', substr($item, 11)) as $scope) {
                    $permissions[] = trim($scope);
                }
            }
        }

        return array_values(array_filter(array_unique($permissions)));
    }

    /**
     * The tightest rate limit on this route.
     *
     * Several throttles can apply at once — the api group's 3000/minute plus a 20/minute on the
     * login route — and the one a client will actually hit is the smallest. Reporting the group's
     * limit would tell a developer they have 3000 attempts at a password.
     *
     * @return array<string, mixed>|null
     */
    public function rateLimit(Route $route): ?array
    {
        $tightest = null;

        foreach ($this->middleware($route) as $item) {
            if (!is_string($item) || !preg_match('/ThrottleRequests:(.+)$|^throttle:(.+)$/', $item, $matches)) {
                continue;
            }

            $arguments = explode(',', $matches[1] !== '' ? $matches[1] : ($matches[2] ?? ''));

            if (!is_numeric($arguments[0] ?? null)) {
                // A named limiter (throttle:global) — report the name; its numbers live in the
                // RateLimiter definition rather than on the route.
                $tightest ??= ['limiter' => trim((string) ($arguments[0] ?? '')), 'requests' => null, 'minutes' => null];

                continue;
            }

            $requests = (int) $arguments[0];
            $minutes = (int) ($arguments[1] ?? 1);

            if ($tightest === null || ($tightest['requests'] ?? PHP_INT_MAX) > $requests) {
                $tightest = ['limiter' => null, 'requests' => $requests, 'minutes' => max(1, $minutes)];
            }
        }

        return $tightest;
    }

    /**
     * The middleware on a route, with aliases resolved to the classes they stand for.
     *
     * gatherMiddleware() returns what the route file wrote — `auth:api`, `module:reports` — which
     * is the alias, not the class. Matching on aliases alone would break the moment somebody
     * renames one in bootstrap/app.php, and matching on classes alone would match nothing at all.
     * So both spellings are returned: `auth:api` AND `App\Http\Middleware\Authenticate:api`.
     *
     * @return array<int, string>
     */
    public function middleware(Route $route): array
    {
        try {
            $aliases = app('router')->getMiddleware();
            $resolved = [];

            foreach ($route->gatherMiddleware() as $item) {
                if (!is_string($item)) {
                    continue;
                }

                $resolved[] = $item;

                [$name, $parameters] = array_pad(explode(':', $item, 2), 2, null);

                if (isset($aliases[$name]) && is_string($aliases[$name])) {
                    $resolved[] = $aliases[$name] . ($parameters !== null ? ':' . $parameters : '');
                }
            }

            return array_values(array_unique($resolved));
        } catch (\Throwable) {
            return [];
        }
    }

    /** The middleware as written in the route file, for the internal developer view. */
    public function declaredMiddleware(Route $route): array
    {
        try {
            return array_values(array_filter($route->gatherMiddleware(), static fn ($item) => is_string($item)));
        } catch (\Throwable) {
            return [];
        }
    }

    /** Every authentication scheme this API offers, for the Authentication section. */
    public function schemes(): array
    {
        return self::SCHEMES;
    }
}
