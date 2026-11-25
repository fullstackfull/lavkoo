<?php

namespace App\Http\Middleware;

use App\Exceptions\PlatformAdminApiException;
use App\Exceptions\PlatformAdminDormantException;
use App\Services\PlatformAdmin\PlatformAdminContract;
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
 *
 * It also refuses a shell older than the oldest contract level the server answers (PlatformAdminContract), and it does so HERE rather than behind
 * the token so that the sign-in is refused too: a build that cannot read the answers must not be given a session to read them with. No floor is
 * set by default, so this changes no request's outcome until somebody sets one.
 */
class PlatformAdminGate
{
    private const REQUEST_WINDOW_SECONDS = 60;

    public function __construct(
        private readonly PlatformAdminSettings $settings,
        private readonly PlatformAdminSchema $schema,
        private readonly PlatformAdminContract $contract,
        private readonly RateLimiter $limiter,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->settings->enabled()) {
            throw new PlatformAdminDormantException(path: $request->path());
        }

        if (!$this->schema->ready()) {
            return $this->privately(response: PlatformAdminResponses::unavailable());
        }

        $key = 'platform-admin:requests:' . ($request->ip() ?? 'unknown');
        if ($this->limiter->tooManyAttempts(key: $key, maxAttempts: $this->settings->requestsPerMinute())) {
            return $this->privately(response: PlatformAdminResponses::throttled(retryAfterSeconds: $this->limiter->availableIn(key: $key)));
        }
        $this->limiter->hit(key: $key, decaySeconds: self::REQUEST_WINDOW_SECONDS);

        // After the limiter and not before it, so a shell that retries a refusal in a loop is still bounded like any other caller. RETURNED rather
        // than thrown, like the 503 and the 429 above: an exception leaves this method, so `privately()` would never run on it and the refusal would
        // go out with the framework's `no-cache` instead of `no-store`. A refusal of this API is as private as an answer of it.
        if (!$this->contract->isSpokenBy(request: $request)) {
            $refusal = PlatformAdminApiException::contractTooOld(claimed: $this->contract->claimedBy(request: $request), minSupported: (int) $this->contract->minSupported());

            return $this->privately(response: $refusal->render());
        }

        return $this->privately(response: $next($request));
    }

    private function privately(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->setVary('Authorization', false);

        return $response;
    }
}
