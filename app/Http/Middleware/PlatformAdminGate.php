<?php

namespace App\Http\Middleware;

use App\Exceptions\PlatformAdminDormantException;
use App\Services\PlatformAdmin\PlatformAdminResponses;
use App\Services\PlatformAdmin\PlatformAdminSchema;
use App\Services\PlatformAdmin\PlatformAdminSettings;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The door of the platform admin API (GCC-20), in front of every route of it.
 *
 * While the API is switched off it is a 404 like any route that does not exist, answered before anything is read, written or counted.
 * Switched on before its tables exist it is a 503, so that deploying the code ahead of the migrations breaks nothing. Past those two it
 * limits the requests of one address, which bounds the reads that looking up a token costs, and it makes every answer private: a phone or a
 * proxy must not keep one, and one shared cache must not hand it to somebody else.
 */
class PlatformAdminGate
{
    private const REQUEST_WINDOW_SECONDS = 60;

    public function __construct(
        private readonly PlatformAdminSettings $settings,
        private readonly PlatformAdminSchema $schema,
        private readonly RateLimiter $limiter,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->settings->enabled()) {
            throw new PlatformAdminDormantException(path: $request->path());
        }

        if (!$this->schema->ready()) {
            return $this->privately(PlatformAdminResponses::unavailable());
        }

        $key = 'platform-admin:requests:' . ($request->ip() ?? 'unknown');
        if ($this->limiter->tooManyAttempts($key, $this->settings->requestsPerMinute())) {
            return $this->privately(PlatformAdminResponses::throttled($this->limiter->availableIn($key)));
        }
        $this->limiter->hit($key, self::REQUEST_WINDOW_SECONDS);

        return $this->privately($next($request));
    }

    private function privately(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->setVary('Authorization', false);

        return $response;
    }
}
