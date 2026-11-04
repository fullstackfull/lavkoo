<?php

namespace App\Http\Controllers\Admin\Telemetry;

use App\Http\Controllers\BaseController;
use App\Services\DeveloperPortal\PortalReference;
use App\Services\DeveloperPortal\WebhookContract;
use App\Services\Platform\Policy;
use App\Services\DeveloperPortal\RequestDebugger;
use App\Services\DeveloperPortal\ApiConsole;
use App\Services\DeveloperPortal\ApiManifest;
use App\Services\DeveloperPortal\ApiSnapshotService;
use App\Services\DeveloperPortal\Generators\OpenApiGenerator;
use App\Services\DeveloperPortal\ConsoleGuard;
use App\Services\DeveloperPortal\DeveloperPortalPermissionService;
use App\Services\DeveloperPortal\Generators\PostmanGenerator;
use App\Services\DeveloperPortal\PortalNavigation;
use App\Services\Telemetry\DeveloperPortalService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Developer Portal.
 *
 * Thin by design: every screen's data comes from a service, and the controller's whole job is to
 * decide which section is open and hand it what it asked for. The portal is a reader — nothing
 * here writes to the API it documents, and the one thing it does write (an API snapshot) is an
 * explicit act with its own button.
 */
class DeveloperPortalController extends BaseController
{
    public function __construct(
        private readonly DeveloperPortalService $portal,
        private readonly ApiManifest $manifest,
        private readonly ApiSnapshotService $snapshots,
        private readonly DeveloperPortalPermissionService $permissions,
    ) {
    }

    public function index(?Request $request = null, ?string $type = null): View|JsonResponse|RedirectResponse
    {
        $request ??= request();

        if (!$this->permissions->canView()) {
            return redirect()->route('admin.dashboard.index');
        }

        $section = $type ?: $this->stringOr($request->query('section'), 'overview');

        if (!PortalNavigation::has($section)) {
            $section = 'overview';
        }

        $capabilities = $this->portal->capabilities();

        return view('admin-views.telemetry.developer', [
            'section' => $section,
            'meta' => PortalNavigation::meta($section),
            'navigation' => PortalNavigation::grouped($capabilities),
            'capabilities' => $capabilities,
            'manifest' => $this->manifest->get(),
            'data' => $this->dataFor($section, $request),
        ]);
    }

    /** One endpoint's page: reference, examples, live health and history in one place. */
    public function endpoint(Request $request, string $id): View|RedirectResponse
    {
        if (!$this->permissions->canView()) {
            return redirect()->route('admin.dashboard.index');
        }

        $endpoint = $this->portal->endpoint($id);

        if ($endpoint === null) {
            return redirect()->route('admin.developer.section', ['section' => 'explorer'])
                ->with('error', translate('that_endpoint_is_no_longer_in_the_route_table'));
        }

        return view('admin-views.telemetry.developer-endpoint', [
            'endpoint' => $endpoint,
            'manifest' => $this->manifest->get(),
            'navigation' => PortalNavigation::grouped($this->portal->capabilities()),
            // Drawn or not drawn, rather than drawn and refused: a send button that always answers
            // 403 teaches an operator to ignore the refusal rather than to ask for the grant.
            'mayUseConsole' => $this->permissions->canUseConsole(),
        ]);
    }

    /**
     * Find an endpoint by the path another section knows it by.
     *
     * Monitoring keys everything by route pattern; the portal keys everything by a hash of the
     * methods and the URI, which nothing outside the manifest can compute. Rather than teach
     * Monitoring how portal ids are made — a coupling that would break the first time the scheme
     * changed — it links here and the portal does the lookup. A path it cannot place lands on the
     * explorer with the search already filled in, which is the useful answer rather than a 404.
     */
    public function lookup(Request $request): RedirectResponse
    {
        if (!$this->permissions->canView()) {
            return redirect()->route('admin.dashboard.index');
        }

        $path = $this->stringOr($request->query('path'));
        $method = $this->stringOr($request->query('method'));
        $endpoint = $path === '' ? null : $this->manifest->findByPath($path, $method !== '' ? $method : null);

        if ($endpoint === null) {
            return redirect()->route('admin.developer.section', [
                'section' => 'explorer',
                'search' => mb_substr($path, 0, 191),
            ]);
        }

        return redirect()->route('admin.developer.endpoint', ['id' => $endpoint['id']]);
    }

    /** The generated OpenAPI document. */
    public function openapi(Request $request, OpenApiGenerator $generator): StreamedResponse
    {
        abort_unless($this->permissions->canView(), 403);

        $filters = $this->filters($request);
        $format = $request->query('format') === 'yaml' ? 'yaml' : 'json';

        $body = $format === 'yaml' ? $generator->toYaml($filters) : $generator->toJson($filters);
        $name = 'openapi-' . ($filters['audience'] ?? $filters['version'] ?? 'all') . '.' . $format;

        return response()->streamDownload(
            fn () => print($body),
            $name,
            ['Content-Type' => $format === 'yaml' ? 'application/yaml' : 'application/json'],
        );
    }

    /** The generated Postman collection. */
    public function postman(Request $request, PostmanGenerator $generator): StreamedResponse
    {
        abort_unless($this->permissions->canView(), 403);

        $filters = $this->filters($request);
        $body = $generator->toJson($filters);

        return response()->streamDownload(
            fn () => print($body),
            'postman-' . ($filters['audience'] ?? 'all') . '.json',
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * Freeze the current API surface and record what changed.
     *
     * The one write in the portal, and deliberately manual as well as scheduled: somebody about
     * to ship a risky change should be able to mark the line themselves.
     */
    public function snapshot(Request $request): RedirectResponse
    {
        // A snapshot writes the baseline every later run is compared against, so it is granted
        // rather than inherited from the module.
        if (!$this->permissions->canSnapshot()) {
            return redirect()->route('admin.developer.section', ['section' => 'changelog'])
                ->with('error', translate('you_do_not_have_permission_to_capture_an_api_snapshot'));
        }

        $label = $this->stringOr($request->input('label')) ?: 'manual-' . now()->format('Y-m-d H:i');
        $result = $this->snapshots->captureAndRecord($label, auth('admin')->id());

        if ($result['unavailable'] ?? false) {
            return redirect()->route('admin.developer.section', ['section' => 'changelog'])
                ->with('error', translate('the_snapshot_tables_are_not_installed_so_nothing_was_captured')
                    . ' — php artisan migrate');
        }

        $message = $result['first']
            ? translate('first_snapshot_captured_there_is_nothing_to_compare_it_against_yet')
            : translate('snapshot_captured') . ': ' . $result['changes'] . ' ' . translate('change_s_recorded')
                . ($result['breaking'] > 0 ? ' — ' . $result['breaking'] . ' ' . translate('breaking') : '');

        return redirect()->route('admin.developer.section', ['section' => 'changelog'])->with('success', $message);
    }

    /**
     * Send one request from the console and return what came back.
     *
     * Whether it may be sent at all is ConsoleGuard's decision, made from the endpoint's own facts;
     * this method's job is the three things the guard cannot know — that the endpoint exists, that
     * the operator confirmed a write in this browser, and that nobody is driving the console like
     * an API client.
     */
    public function try(Request $request, string $id, ApiConsole $console, ConsoleGuard $guard): JsonResponse
    {
        // The console sends real, authenticated requests at this installation. Reading the
        // documentation never carried that, and now it does not grant it either.
        if (!$this->permissions->canUseConsole()) {
            return response()->json([
                'ok' => false,
                'message' => translate('you_do_not_have_permission_to_send_requests_from_the_console'),
            ], 403);
        }

        $endpoint = $this->manifest->endpoint($id);

        if ($endpoint === null) {
            return response()->json(['ok' => false, 'message' => translate('that_endpoint_is_not_in_the_manifest')], 404);
        }

        $method = strtoupper($this->stringOr($request->input('method'), $endpoint['methods'][0] ?? 'GET'));
        $verdict = $guard->verdict($endpoint, $method);

        if (!$verdict['allowed']) {
            return response()->json([
                'ok' => false,
                'tier' => $verdict['tier'],
                'message' => translate($verdict['reason_key']),
                'remedy' => $verdict['remedy'],
            ], 403);
        }

        // The confirmation is checked here rather than in the guard because it is a fact about this
        // submission, not about the endpoint: the guard answers "may this ever be sent", and a
        // typed word answers "did a person mean to send it now".
        if ($verdict['needs_confirmation']
            && strtoupper($this->stringOr($request->input('confirm'))) !== $guard->confirmationFor($method)) {
            return response()->json([
                'ok' => false,
                'tier' => $verdict['tier'],
                'message' => translate('type_the_method_name_to_confirm_a_write'),
                'remedy' => $guard->confirmationFor($method),
            ], 422);
        }

        // Stored, then environment, then the shipped default — the same precedence the two console
        // switches use, so all four portal settings behave alike.
        $policy = app(Policy::class);
        $perMinute = $policy->isSet('developer_console_rate_limit')
            ? $policy->int('developer_console_rate_limit')
            : max(1, (int) config('developer_portal.console.rate_limit_per_minute', 20));
        $key = 'developer-console:' . (auth('admin')->id() ?: 'unknown');

        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            return response()->json([
                'ok' => false,
                'message' => translate('the_console_is_rate_limited_wait_a_moment'),
                'remedy' => RateLimiter::availableIn($key) . 's',
            ], 429);
        }

        RateLimiter::hit($key, 60);

        $token = $this->stringOr($request->input('token'));

        return response()->json($console->send(
            endpoint: $endpoint,
            method: $method,
            pathParameters: (array) $request->input('path', []),
            payload: (array) $request->input('payload', []),
            token: $token !== '' ? $token : null,
        ));
    }

    /** Rebuild the manifest by hand, for the moment after a deployment. */
    public function refresh(): RedirectResponse
    {
        if (!$this->permissions->canView()) {
            return redirect()->route('admin.dashboard.index');
        }

        $this->manifest->forget();
        $this->manifest->get();

        return back()->with('success', translate('the_api_manifest_was_rebuilt_from_the_live_route_table'));
    }

    // -------------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function dataFor(string $section, Request $request): array
    {
        return match ($section) {
            'overview' => $this->portal->overview(),
            'quick_start' => ['base_url' => $this->manifest->get()['base_url']],
            'explorer' => $this->portal->explorer(
                $this->filters($request),
                perPage: 50,
                page: max(1, (int) $request->query('page', 1)),
            ),
            'customer_app' => $this->portal->explorer(['audience' => 'customer_app'] + $this->filters($request), 200),
            'vendor_app' => $this->portal->explorer(['audience' => 'vendor_app'] + $this->filters($request), 300),
            'delivery_app' => $this->portal->explorer(['audience' => 'delivery_app'] + $this->filters($request), 100),
            'partner' => $this->portal->explorer(['audience' => 'partner'] + $this->filters($request), 100),
            'authentication', 'errors', 'rate_limits', 'pagination', 'uploads' => $this->portal->conventions($section),
            'versions' => $this->portal->versions(),
            'changelog' => $this->portal->changelog($this->stringOr($request->query('severity')) ?: null),
            'deprecations' => ['endpoints' => $this->portal->deprecations()],
            'quality' => $this->portal->quality(),
            'health' => ['api' => $this->portal->apiHealth($this->stringOr($request->query('range'), '24h'))],
            // The section the navigation has always declared. It answers "what happened to THIS
            // request", which is what the Errors advice tells developers to keep the id for.
            'debugger' => ['lookup' => app(RequestDebugger::class)->lookup($this->stringOr($request->query('request_id')))],
            // The three sections the navigation has always declared and that opened onto an empty
            // card. A placeholder is worse than an unlisted section: a developer who clicks
            // "Models and enums" and finds nothing concludes the API has no documented shapes.
            'webhooks' => ['contract' => app(WebhookContract::class)->describe()],
            'models' => app(PortalReference::class)->models(),
            'integrations' => app(PortalReference::class)->integrations(),
            'settings' => [
                'portal' => app(Policy::class)->all('developer'),
                'fields' => \App\Services\Platform\PolicyRegistry::GROUPS['developer']['policies'],
                'snapshots' => $this->portal->capabilities()['snapshots'] ?? false,
            ],
            default => [],
        };
    }

    /**
     * The filter set every list and download reads, string-only by construction.
     *
     * Whatever survives here is lowercased, uppercased and concatenated into filenames further
     * down, so a value that is not a string has to be dropped at this one point rather than
     * defended against at each of the places it would otherwise be cast.
     *
     * @return array<string, string>
     */
    private function filters(Request $request): array
    {
        return array_filter([
            'search' => $this->stringOr($request->query('search')),
            'audience' => $this->stringOr($request->query('audience')),
            'version' => $this->stringOr($request->query('version')),
            'group' => $this->stringOr($request->query('group')),
            'method' => $this->stringOr($request->query('method')),
            'visibility' => $this->stringOr($request->query('visibility')),
            'auth' => $this->stringOr($request->query('auth')),
        ], static fn (string $value) => $value !== '');
    }

    /**
     * One request value as a string, or the fallback when it is not one.
     *
     * Every parameter here comes off a URL or a form somebody can hand-edit, and any of them can
     * arrive as an array — `?section[]=x`. Casting one to a string is a warning this application's
     * handler turns into a throw, so an unreadable value would take the whole section down instead
     * of simply not being applied. A filter nobody can spell is not a filter.
     */
    private function stringOr(mixed $value, string $fallback = ''): string
    {
        return is_string($value) ? trim($value) : $fallback;
    }
}
