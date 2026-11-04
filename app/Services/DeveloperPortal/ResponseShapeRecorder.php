<?php

namespace App\Services\DeveloperPortal;

use Illuminate\Http\Request;
use App\Services\Platform\PolicyRegistry;
use App\Services\Platform\Policy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Learns what each endpoint answers with, from the answers it gives.
 *
 * Every write happens after the response has been sent, and every one of them is optional: an
 * endpoint whose shape cannot be recorded still works, and a documentation table that is missing
 * does not make the API fail. The portal is allowed to know less; it is not allowed to cost
 * anything.
 *
 * Sampling is the reason this is affordable. A shape that has been seen recently is not looked at
 * again, so a busy endpoint is described once an hour rather than ten thousand times, and the cost
 * of the whole feature on a normal request is one array lookup.
 */
class ResponseShapeRecorder
{
    /** Responses larger than this are a file or a dump; describing one is not worth the memory. */
    private const MAX_BYTES = 512_000;

    /** How long a recorded shape is trusted before another sample is taken. */
    private const RESAMPLE_AFTER_MINUTES = 60;

    /** Status codes worth describing: the success, and the shapes of the failures clients handle. */
    private const STATUSES = [200, 201, 202, 401, 403, 404, 422];

    /** @var array<string, true>|null  shapes already fresh, read once per process */
    private ?array $fresh = null;

    private ?bool $ready = null;

    public function __construct(private readonly ResponseShape $shapes)
    {
    }

    public function record(Request $request, Response $response): void
    {
        if (!$this->shouldRecord($request, $response)) {
            return;
        }

        $route = $this->routeOf($request);
        $status = $response->getStatusCode();
        $key = $route . '|' . $request->getMethod() . '|' . $status;

        if (isset($this->freshShapes()[$key])) {
            return;
        }

        $decoded = json_decode((string) $response->getContent(), true);

        if (!is_array($decoded)) {
            return;
        }

        $shape = $this->shapes->of($decoded);

        if ($shape === null) {
            return;
        }

        $this->store($route, $request->getMethod(), $status, $shape);
        $this->fresh[$key] = true;
    }

    /** Everything observed, keyed by route and method, for the manifest to join on. */
    public function all(): array
    {
        if (!$this->ready()) {
            return [];
        }

        try {
            $rows = DB::table('api_response_shapes')->get();
        } catch (\Throwable) {
            return [];
        }

        $byEndpoint = [];

        foreach ($rows as $row) {
            $shape = json_decode((string) $row->shape, true);

            if (!is_array($shape)) {
                continue;
            }

            $byEndpoint[$row->route][$row->method][(int) $row->status] = [
                'shape' => $shape,
                'samples' => (int) $row->samples,
                'last_seen_at' => (string) $row->last_seen_at,
            ];
        }

        return $byEndpoint;
    }

    public function ready(): bool
    {
        if ($this->ready !== null) {
            return $this->ready;
        }

        try {
            return $this->ready = Schema::hasTable('api_response_shapes');
        } catch (\Throwable) {
            return $this->ready = false;
        }
    }

    // -------------------------------------------------------------------------------------------

    private function shouldRecord(Request $request, Response $response): bool
    {
        if (!$this->settingEnabled('developer_record_response_shapes', 'record_response_shapes') || !$this->ready()) {
            return false;
        }

        if (!$request->is('api/*') || $request->route() === null) {
            return false;
        }

        if (!in_array($response->getStatusCode(), self::STATUSES, true)) {
            return false;
        }

        if (!str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            return false;
        }

        // A streamed or file response has no content to read, and reading one would consume it.
        if (!$response instanceof \Illuminate\Http\JsonResponse
            && !$response instanceof \Illuminate\Http\Response) {
            return false;
        }

        return strlen((string) $response->getContent()) <= self::MAX_BYTES;
    }

    private function routeOf(Request $request): string
    {
        return '/' . ltrim((string) $request->route()?->uri(), '/');
    }

    /**
     * @return array<string, true>
     */
    private function freshShapes(): array
    {
        if ($this->fresh !== null) {
            return $this->fresh;
        }

        try {
            $rows = DB::table('api_response_shapes')
                ->where('last_seen_at', '>=', Carbon::now()->subMinutes(self::RESAMPLE_AFTER_MINUTES))
                ->get(['route', 'method', 'status']);
        } catch (\Throwable) {
            return $this->fresh = [];
        }

        $fresh = [];

        foreach ($rows as $row) {
            $fresh[$row->route . '|' . $row->method . '|' . (int) $row->status] = true;
        }

        return $this->fresh = $fresh;
    }

    /**
     * @param  array<string, mixed>  $shape
     */
    private function store(string $route, string $method, int $status, array $shape): void
    {
        try {
            $now = Carbon::now();

            $existing = DB::table('api_response_shapes')
                ->where('route', $route)->where('method', $method)->where('status', $status)
                ->first();

            if ($existing === null) {
                DB::table('api_response_shapes')->insertOrIgnore([
                    'route' => mb_substr($route, 0, 191),
                    'method' => $method,
                    'status' => $status,
                    'shape' => json_encode($shape),
                    'samples' => 1,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]);

                return;
            }

            // Merged, not replaced: one response with a null field and one without describe the
            // same endpoint, and taking only the newest would make the documentation flap.
            $merged = $this->shapes->merge(json_decode((string) $existing->shape, true), $shape);

            DB::table('api_response_shapes')->where('id', $existing->id)->update([
                'shape' => json_encode($merged),
                'samples' => (int) $existing->samples + 1,
                'last_seen_at' => $now,
            ]);
        } catch (\Throwable) {
            // Documentation is never the reason a request is remembered as having failed.
        }
    }
    /**
     * A portal switch, from the settings page, falling back to configuration.
     *
     * These four were env-only, so an operator could not see whether the console was enabled, and
     * turning writes off during an incident meant editing .env and clearing caches. The config
     * value stays the fallback, so an install that sets them by environment is unchanged.
     */
    private function settingEnabled(string $policy, string $configKey): bool
    {
        try {
            $settings = app(Policy::class);

            // Stored, then environment, then the shipped default. An install that turns the console
            // off in .env must not have it turned back on by a default nobody chose.
            if ($settings->isSet($policy)) {
                return (bool) $settings->get($policy);
            }
        } catch (\Throwable) {
            // No settings table yet — fall through to configuration.
        }

        return (bool) config('developer_portal.' . $configKey, PolicyRegistry::definition($policy)['default']);
    }

}
