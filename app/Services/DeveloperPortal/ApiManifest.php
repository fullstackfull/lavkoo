<?php

namespace App\Services\DeveloperPortal;

use App\Services\DeveloperPortal\Support\AuthResolver;
use App\Services\DeveloperPortal\Support\EndpointClassifier;
use App\Services\DeveloperPortal\Support\RuleTranslator;
use App\Services\DeveloperPortal\Support\ValidationExtractor;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use ReflectionMethod;

/**
 * The normalised API surface: one record per endpoint, derived from the code that serves it.
 *
 * This is the whole portal's source of truth, and its central promise is that adding a route to
 * routes/rest_api/v1/api.php makes that route appear in the documentation with no further action —
 * with its middleware, its authentication, its rate limit, its parameters and its validation rules
 * already filled in. Nothing here is a list somebody has to remember to update.
 *
 * Building it costs real work: reflecting on 439 controller methods and reading their source to
 * recover inline validation rules is not something to do while a page is loading. So the manifest
 * is built once and cached, and the cache carries a FINGERPRINT of the live route table — the
 * count and a hash of every method+uri. If a deployment changes the routes, the fingerprint no
 * longer matches and the manifest rebuilds by itself. It can be stale for exactly as long as the
 * routes have not changed, which is another way of saying it cannot be stale at all.
 */
class ApiManifest
{
    private const CACHE_KEY = 'developer_portal:manifest';
    private const CACHE_SECONDS = 86400;

    /** @var array<string, mixed>|null */
    private ?array $manifest = null;

    public function __construct(
        private readonly AuthResolver $auth,
        private readonly EndpointClassifier $classifier,
        private readonly ValidationExtractor $validation,
        private readonly RuleTranslator $translator,
    ) {
    }

    /**
     * The manifest, built if necessary.
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $fingerprint = $this->fingerprint();

        try {
            $cached = Cache::get(self::CACHE_KEY);

            if (is_array($cached) && ($cached['fingerprint'] ?? null) === $fingerprint) {
                return $this->manifest = $cached;
            }
        } catch (\Throwable) {
            // A cache that cannot be read costs a rebuild, not an error page.
        }

        $manifest = $this->build($fingerprint);

        try {
            Cache::put(self::CACHE_KEY, $manifest, self::CACHE_SECONDS);
        } catch (\Throwable) {
            // Unstorable manifest: still correct, just rebuilt next time.
        }

        return $this->manifest = $manifest;
    }

    /** Throw the cached manifest away — called after a deployment or from the portal by hand. */
    public function forget(): void
    {
        $this->manifest = null;

        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Throwable) {
            // nothing cached
        }
    }

    /**
     * Every endpoint, optionally narrowed.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function endpoints(array $filters = []): array
    {
        $endpoints = $this->get()['endpoints'] ?? [];

        foreach (['audience', 'version', 'group', 'visibility', 'surface', 'stability'] as $field) {
            if (!empty($filters[$field])) {
                $endpoints = array_values(array_filter(
                    $endpoints,
                    static fn (array $endpoint) => ($endpoint[$field] ?? null) === $filters[$field],
                ));
            }
        }

        if (!empty($filters['method'])) {
            $method = strtoupper((string) $filters['method']);
            $endpoints = array_values(array_filter(
                $endpoints,
                static fn (array $endpoint) => in_array($method, $endpoint['methods'], true),
            ));
        }

        if (!empty($filters['auth'])) {
            $wantsAuth = $filters['auth'] === 'required';
            $endpoints = array_values(array_filter(
                $endpoints,
                static fn (array $endpoint) => (bool) ($endpoint['auth']['required'] ?? false) === $wantsAuth,
            ));
        }

        if (!empty($filters['search'])) {
            $needle = mb_strtolower((string) $filters['search']);
            $endpoints = array_values(array_filter($endpoints, static function (array $endpoint) use ($needle) {
                return str_contains(mb_strtolower($endpoint['path'] . ' ' . ($endpoint['name'] ?? '') . ' ' . ($endpoint['summary'] ?? '') . ' ' . $endpoint['group']), $needle);
            }));
        }

        return $endpoints;
    }

    /**
     * One endpoint by the path it is registered at.
     *
     * The id is a hash of the methods and the URI, so nothing outside this class can compute it —
     * which is why Monitoring, whose request buckets are keyed by route pattern rather than by a
     * portal id, could not link to an endpoint's documentation. This is how it asks.
     *
     * @return array<string, mixed>|null
     */
    public function findByPath(string $path, ?string $method = null): ?array
    {
        $path = '/' . ltrim(trim($path), '/');
        $method = $method === null ? null : strtoupper($method);
        $fallback = null;

        foreach ($this->get()['endpoints'] ?? [] as $endpoint) {
            if ($endpoint['path'] !== $path) {
                continue;
            }

            if ($method === null || in_array($method, $endpoint['methods'], true)) {
                return $endpoint;
            }

            // The same path registered for a different verb still documents the resource, so it is
            // a better answer than nothing — but only if no exact match turns up.
            $fallback ??= $endpoint;
        }

        return $fallback;
    }

    /** One endpoint by its stable id. */
    public function endpoint(string $id): ?array
    {
        foreach ($this->get()['endpoints'] ?? [] as $endpoint) {
            if ($endpoint['id'] === $id) {
                return $endpoint;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function build(string $fingerprint): array
    {
        $endpoints = [];

        foreach (RouteFacade::getRoutes() as $route) {
            $endpoint = $this->describe($route);

            if ($endpoint !== null) {
                $endpoints[] = $endpoint;
            }
        }

        usort($endpoints, static fn (array $a, array $b) => [$a['audience'], $a['group'], $a['path']] <=> [$b['audience'], $b['group'], $b['path']]);

        return [
            'fingerprint' => $fingerprint,
            'generated_at' => now()->toIso8601String(),
            // getAppVersion() returns the whole version.json record; the portal wants the number.
            'app_version' => $this->appVersion(),
            'release' => function_exists('getAppVersion') ? getAppVersion() : null,
            'base_url' => rtrim((string) config('app.url'), '/'),
            'endpoints' => $endpoints,
            'summary' => $this->summarise($endpoints),
        ];
    }

    /**
     * One route, fully described.
     *
     * @return array<string, mixed>|null
     */
    private function describe(Route $route): ?array
    {
        try {
            $uri = trim($route->uri(), '/');

            // Panel routes are in the manifest — an internal developer needs them, and the
            // documentation-quality screen counts them — but the noise is not.
            if ($this->isNoise($uri)) {
                return null;
            }

            $methods = array_values(array_diff($route->methods(), ['HEAD']));

            if ($methods === []) {
                return null;
            }

            [$controller, $action] = $this->target($route);
            $doc = $this->doc($controller, $action);
            $auth = $this->auth->resolve($route);
            $classification = $this->classifier->classify($route, $auth, $doc);
            $validation = $this->validation->forMethod($controller, $action);

            $stability = $doc?->stability ?? ApiDoc::STABLE;

            return [
                'id' => $this->id($methods, $uri),
                'methods' => $methods,
                'path' => '/' . $uri,
                'name' => $route->getName(),
                'summary' => $doc?->summary ?? $this->inferSummary($methods, $uri, $classification['group']),
                'description' => $doc?->description,
                'audience' => $classification['audience'],
                'group' => $classification['group'],
                'version' => $classification['version'],
                'visibility' => $classification['visibility'],
                'surface' => $classification['surface'],
                'stability' => $stability,
                'deprecated' => $stability === ApiDoc::DEPRECATED,
                'since' => $doc?->since,
                'deprecated_since' => $doc?->deprecatedSince,
                'sunset_at' => $doc?->sunsetAt,
                'replaced_by' => $doc?->replacedBy,
                'idempotent' => $doc?->idempotent ?? in_array('GET', $methods, true),
                'destructive' => $doc?->destructive ?? $this->looksDestructive($methods, $uri),
                'emits' => $doc?->emits ?? [],
                'depends_on' => $doc?->dependsOn ?? [],
                'auth' => $auth,
                'permissions' => array_values(array_unique(array_merge($this->auth->permissions($route), $doc?->scopes ?? []))),
                'rate_limit' => $this->auth->rateLimit($route),
                'middleware' => $this->auth->declaredMiddleware($route),
                'controller' => $controller,
                'action' => $action,
                'path_parameters' => $this->pathParameters($route),
                'body' => $validation['fields'],
                'body_source' => $validation['source'],
                'request_class' => $validation['class'],
                'response_example' => $doc?->responseExample,
                'documented' => $doc !== null,
            ];
        } catch (\Throwable) {
            // One unreadable route must never blank a portal that documents four hundred of them.
            return null;
        }
    }

    /**
     * Routes that exist for the framework rather than for a caller.
     */
    private function isNoise(string $uri): bool
    {
        foreach ([
            '_debugbar', '_ignition', 'sanctum/csrf-cookie', 'livewire',
            'storage', 'up', 'telemetry',
        ] as $prefix) {
            if ($uri === $prefix || str_starts_with($uri, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0: string|null, 1: string|null} */
    private function target(Route $route): array
    {
        $action = $route->getAction('controller');

        if (!is_string($action)) {
            return [null, null];
        }

        if (!str_contains($action, '@')) {
            // An invokable controller.
            return [$action, '__invoke'];
        }

        [$controller, $method] = explode('@', $action, 2);

        return [$controller, $method];
    }

    private function doc(?string $controller, ?string $action): ?ApiDoc
    {
        if ($controller === null || $action === null || !class_exists($controller)) {
            return null;
        }

        try {
            $reflection = new ReflectionMethod($controller, $action);
            $attributes = $reflection->getAttributes(ApiDoc::class);

            if ($attributes !== []) {
                return $attributes[0]->newInstance();
            }

            // A class-level attribute documents every method on a controller at once, which is how
            // a whole resource group gets an audience and a visibility in one line.
            $classAttributes = $reflection->getDeclaringClass()->getAttributes(ApiDoc::class);

            return $classAttributes === [] ? null : $classAttributes[0]->newInstance();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pathParameters(Route $route): array
    {
        $parameters = [];
        $optional = $route->uri();

        foreach ($route->parameterNames() as $name) {
            $isOptional = str_contains($optional, '{' . $name . '?}');
            $pattern = $route->wheres[$name] ?? null;

            $parameters[] = array_filter([
                'name' => $name,
                'in' => 'path',
                // A path parameter matching only digits is an id; anything else is a slug or a
                // code, and telling a client "integer" when the route accepts a slug is how a
                // generated SDK ends up rejecting valid URLs.
                'type' => $pattern !== null && preg_match('/^\[?0-9|\\\\d/', $pattern) === 1 ? 'integer' : 'string',
                'required' => !$isOptional,
                'pattern' => $pattern,
            ], static fn ($value) => $value !== null);
        }

        return $parameters;
    }

    /**
     * A one-line description when nobody has written one.
     *
     * Honest but plainly mechanical, so it reads as "nobody has documented this yet" rather than
     * as a real summary — which is exactly what the documentation-quality screen should surface.
     */
    private function inferSummary(array $methods, string $uri, string $group): string
    {
        $resource = Str::headline(str_replace(['-', '_'], ' ', last(array_filter(
            explode('/', preg_replace('#\{[^}]+\}#', '', $uri) ?? $uri),
        )) ?: $group));

        $verb = match ($methods[0] ?? 'GET') {
            'POST' => 'Create or submit',
            'PUT', 'PATCH' => 'Update',
            'DELETE' => 'Delete',
            default => 'Read',
        };

        return trim("{$verb} {$resource}");
    }

    /**
     * Would firing this from the console change something a merchant cannot undo?
     *
     * Used by the Try It console to require a deliberate confirmation. Erring toward caution: a
     * false positive costs one extra click, a false negative deletes a live product.
     */
    private function looksDestructive(array $methods, string $uri): bool
    {
        if (array_intersect(['DELETE', 'PUT', 'PATCH'], $methods) !== []) {
            return true;
        }

        if (!in_array('POST', $methods, true)) {
            return false;
        }

        foreach (['delete', 'remove', 'destroy', 'cancel', 'refund', 'payment', 'withdraw', 'transfer', 'settle', 'status', 'update', 'reset', 'revoke'] as $needle) {
            if (str_contains(strtolower($uri), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function id(array $methods, string $uri): string
    {
        return substr(sha1(implode(',', $methods) . ' ' . $uri), 0, 16);
    }

    /**
     * The application's version number.
     *
     * Public because the snapshot command needs the same answer, and it was building its own —
     * concatenating getAppVersion() straight into a string, which is an ARRAY, so
     * `php artisan api:snapshot` with no label died on "Array to string conversion".
     */
    public function appVersion(): ?string
    {
        if (!function_exists('getAppVersion')) {
            return null;
        }

        $release = getAppVersion();

        return is_array($release) ? (string) ($release['version'] ?? '') : (string) $release;
    }

    /**
     * A hash of the live route table, so the cache expires exactly when the API changes.
     */
    private function fingerprint(): string
    {
        $signature = [];

        foreach (RouteFacade::getRoutes() as $route) {
            // The controller and the middleware belong in here too. Hashing only the method, the
            // uri and the name meant the cache survived every change that does not move a route —
            // pointing a route at a different controller, adding auth middleware, changing a
            // FormRequest — so the portal kept describing an endpoint the application no longer
            // served, and the documentation drifted exactly where it is most dangerous.
            // getAction('middleware'), NOT gatherMiddleware(): the latter instantiates the
            // controller to ask for its middleware, so fingerprinting the route table would
            // construct four hundred controllers — and a controller whose constructor reads a
            // setting would then need a database just to decide whether a cache is stale.
            $middleware = (array) $route->getAction('middleware');

            $signature[] = implode(',', $route->methods())
                . ' ' . $route->uri()
                . ' ' . (string) $route->getName()
                . ' ' . $route->getActionName()
                . ' ' . implode(',', array_map(static fn ($one) => is_string($one) ? $one : gettype($one), $middleware));
        }

        sort($signature);

        // The application's own version too, so a deploy that changes a FormRequest's rules without
        // touching the route table still rebuilds.
        $signature[] = 'app:' . (string) $this->appVersion();

        return count($signature) . ':' . substr(sha1(implode("\n", $signature)), 0, 32);
    }

    /**
     * @param  array<int, array<string, mixed>>  $endpoints
     * @return array<string, mixed>
     */
    private function summarise(array $endpoints): array
    {
        $api = array_filter($endpoints, static fn (array $endpoint) => $endpoint['surface'] === 'api');

        $count = static fn (callable $predicate) => count(array_filter($api, $predicate));

        $byAudience = [];
        $byVersion = [];
        $byGroup = [];

        foreach ($api as $endpoint) {
            $byAudience[$endpoint['audience']] = ($byAudience[$endpoint['audience']] ?? 0) + 1;
            $byVersion[$endpoint['version'] ?? 'unversioned'] = ($byVersion[$endpoint['version'] ?? 'unversioned'] ?? 0) + 1;
            $byGroup[$endpoint['group']] = ($byGroup[$endpoint['group']] ?? 0) + 1;
        }

        arsort($byAudience);
        ksort($byVersion);
        arsort($byGroup);

        return [
            'total' => count($endpoints),
            'api' => count($api),
            'panel' => count($endpoints) - count($api),
            'public' => $count(static fn (array $endpoint) => ($endpoint['auth']['required'] ?? false) === false),
            'authenticated' => $count(static fn (array $endpoint) => ($endpoint['auth']['required'] ?? false) === true),
            'deprecated' => $count(static fn (array $endpoint) => $endpoint['deprecated']),
            'documented' => $count(static fn (array $endpoint) => $endpoint['documented']),
            'with_body_schema' => $count(static fn (array $endpoint) => $endpoint['body'] !== []),
            'unclassified' => $count(static fn (array $endpoint) => $endpoint['audience'] === 'unclassified'),
            'rate_limited' => $count(static fn (array $endpoint) => ($endpoint['rate_limit']['requests'] ?? null) !== null),
            'by_audience' => $byAudience,
            'by_version' => $byVersion,
            'by_group' => $byGroup,
        ];
    }
}
