<?php

namespace App\Services\DeveloperPortal\Support;

use App\Services\DeveloperPortal\ApiDoc;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

/**
 * Sorts 1,695 routes into the buckets a developer actually thinks in.
 *
 * The classification comes from three things in order of authority: an explicit ApiDoc attribute
 * on the controller method, the authentication the route requires, and the shape of its path. That
 * order matters — the path is a good heuristic and a bad rule, so it is the last resort rather
 * than the basis.
 *
 * Nothing is silently discarded. A route that cannot be placed is classified as `unclassified` and
 * counted, because a portal that hides what it did not understand is a portal that quietly stops
 * documenting new work.
 */
class EndpointClassifier
{
    /** Path prefix => audience, checked longest-first so v3/seller beats v3. */
    private const PATH_AUDIENCE = [
        'api/v3/seller' => ApiDoc::VENDOR_APP,
        'api/v2/seller' => ApiDoc::VENDOR_APP,
        'api/v1/seller' => ApiDoc::VENDOR_APP,
        'api/v2/delivery-man' => ApiDoc::DELIVERY_APP,
        'api/delivery-syria' => ApiDoc::PARTNER,
        'api/v1/customer' => ApiDoc::CUSTOMER_APP,
        'api/v2/customer' => ApiDoc::CUSTOMER_APP,
        'api/v1/auth' => ApiDoc::CUSTOMER_APP,
        'api/v1' => ApiDoc::CUSTOMER_APP,
        'api/v2' => ApiDoc::CUSTOMER_APP,
        'api/v3' => ApiDoc::CUSTOMER_APP,
        'admin' => ApiDoc::ADMIN,
        'vendor' => ApiDoc::VENDOR_APP,
        'deliveryman' => ApiDoc::DELIVERY_APP,
    ];

    /**
     * Path fragments that mean "an outside system POSTs into this".
     *
     * Matched on the path rather than declared per route, because the list is the gateway vendors'
     * to grow: a thirteenth gateway added next year would otherwise silently be undocumented again.
     */
    private const INBOUND_FRAGMENTS = ['callback', 'webhook', '/ipn', 'update-status'];

    /** The actor a route authenticates decides its audience more reliably than its path does. */
    private const ACTOR_AUDIENCE = [
        'vendor' => ApiDoc::VENDOR_APP,
        'delivery_man' => ApiDoc::DELIVERY_APP,
        'admin' => ApiDoc::ADMIN,
        'partner' => ApiDoc::PARTNER,
    ];

    /**
     * Resource groupings inside an audience, matched against the path after the version.
     *
     * Ordered: the first match wins, so 'auth' is tested before 'order' and a login route does not
     * land under Orders because its controller happens to mention one.
     */
    private const GROUPS = [
        'auth' => ['auth', 'login', 'register', 'token', 'otp', 'verify', 'password', 'social'],
        'profile' => ['customer/info', 'profile', 'account', 'address', 'settings'],
        'catalogue' => ['product', 'categor', 'brand', 'attribute', 'author', 'publishing'],
        'search' => ['search', 'suggestion'],
        'vendors' => ['seller', 'shop', 'vendor'],
        'cart' => ['cart', 'wishlist', 'compare'],
        'checkout' => ['checkout', 'shipping-method', 'coupon', 'vat-tax', 'digital-payment'],
        'orders' => ['order', 'refund', 'track'],
        'inventory' => ['stock', 'inventory', 'restock'],
        'promotions' => ['deal', 'flash', 'clearance', 'offer', 'featured'],
        'wallet' => ['wallet', 'loyalty', 'fund', 'transaction', 'withdraw'],
        'notifications' => ['notification', 'device', 'fcm', 'push'],
        'messaging' => ['chat', 'message', 'support', 'ticket', 'contact'],
        'content' => ['banner', 'page', 'faq', 'blog', 'business-pages'],
        'reviews' => ['review', 'rating'],
        'reports' => ['report', 'analytic', 'dashboard', 'statistic'],
        'delivery' => ['delivery', 'deliveryman'],
        'configuration' => ['config', 'setting', 'currenc', 'language', 'mapapi'],
    ];

    /**
     * @param  array<string, mixed>  $auth
     * @return array<string, string>
     */
    public function classify(Route $route, array $auth, ?ApiDoc $doc): array
    {
        $uri = trim($route->uri(), '/');
        $methods = array_values(array_diff($route->methods(), ['HEAD']));

        $audience = $doc?->audience
            ?? self::ACTOR_AUDIENCE[$auth['actor'] ?? ''] ?? null
            ?? $this->audienceFromPath($uri);

        return [
            'audience' => $audience ?? 'unclassified',
            'group' => $doc?->group ?? $this->group($uri, $route),
            'version' => $this->version($uri),
            'visibility' => $doc?->visibility ?? $this->visibility($audience, $auth, $methods),
            // Not merely "does the path start with api/". Twelve payment callbacks and the courier
            // status webhook sit under /payment/* and are as external as anything the API serves —
            // an outside system POSTs into them and money moves — so calling them panel routes made
            // the explorer, the OpenAPI export and the coverage score all skip the endpoints most
            // likely to be pointed at the wrong host during a migration.
            'surface' => str_starts_with($uri, 'api/') || $this->isInboundIntegration($uri) ? 'api' : 'panel',
        ];
    }

    /**
     * Which callers may see this documented.
     *
     * Internal by default, deliberately. Making a route partner-visible is a policy decision an
     * administrator takes with an ApiDoc attribute; inferring it would eventually publish an admin
     * endpoint to an outside integrator because its path looked harmless.
     *
     * @param  array<string, mixed>  $auth
     */
    private function visibility(?string $audience, array $auth, array $methods): string
    {
        if ($audience === ApiDoc::ADMIN) {
            return ApiDoc::INTERNAL;
        }

        // A public catalogue READ is the one thing safe to infer: it requires nothing, returns
        // nothing private, and is already served to anyone who loads the storefront.
        //
        // A public WRITE is not. Several endpoints here accept a guest — adding an address, adding
        // to a cart — and while they are genuinely reachable without a token, publishing them to
        // outside integrators is a policy decision somebody has to take, not one to infer from the
        // absence of a middleware. They stay internal until an ApiDoc attribute says otherwise.
        $readOnly = array_diff($methods, ['GET', 'HEAD', 'OPTIONS']) === [];

        if ($readOnly && ($auth['required'] ?? false) === false && $audience !== null && $audience !== 'unclassified') {
            return ApiDoc::PUBLIC_VISIBLE;
        }

        return ApiDoc::INTERNAL;
    }

    private function audienceFromPath(string $uri): ?string
    {
        foreach (self::PATH_AUDIENCE as $prefix => $audience) {
            if ($uri === $prefix || str_starts_with($uri, $prefix . '/')) {
                return $audience;
            }
        }

        return null;
    }

    /** The API version this route belongs to, or null for the panel routes. */
    public function version(string $uri): ?string
    {
        return preg_match('#^api/(v\d+)(/|$)#', $uri, $matches) === 1 ? $matches[1] : null;
    }

    private function group(string $uri, Route $route): string
    {
        // Strip the api/ and version segments so a group match is about the resource rather than
        // about which version it happens to live in.
        $path = preg_replace('#^api/(v\d+/)?#', '', $uri) ?? $uri;
        $path = strtolower($path);

        foreach (self::GROUPS as $group => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($path, $needle)) {
                    return $group;
                }
            }
        }

        // Fall back to the controller's own name, which is at least the application's word for
        // what this is, rather than an invented bucket.
        $action = $route->getAction('controller');

        if (is_string($action) && str_contains($action, '@')) {
            $controller = class_basename(explode('@', $action)[0]);

            return Str::snake(str_replace('Controller', '', $controller));
        }

        return 'other';
    }

    /** @return array<int, string> */
    public function audiences(): array
    {
        return [
            ApiDoc::CUSTOMER_APP,
            ApiDoc::VENDOR_APP,
            ApiDoc::DELIVERY_APP,
            ApiDoc::WEB,
            ApiDoc::ADMIN,
            ApiDoc::PARTNER,
            ApiDoc::PUBLIC_API,
            'unclassified',
        ];
    }
    /** Whether an outside system dials this route rather than a panel or an app. */
    private function isInboundIntegration(string $uri): bool
    {
        foreach (self::INBOUND_FRAGMENTS as $fragment) {
            if (str_contains($uri, $fragment)) {
                return true;
            }
        }

        return false;
    }

}
