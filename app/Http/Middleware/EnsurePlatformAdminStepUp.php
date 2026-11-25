<?php

namespace App\Http\Middleware;

use App\Exceptions\PlatformAdminApiException;
use App\Services\PlatformAdmin\PlatformAdminPrincipal;
use App\Services\PlatformAdmin\PlatformAdminSettings;
use App\Services\PlatformAdmin\PlatformAdminStepUp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a request through only when its session proved the second factor again within the last few minutes (`platform_admin_step_up`).
 *
 * It runs after the authentication and after the capability, so a session that may not reach the route at all is refused for that reason and never
 * told to step up. The answer is a 403 of its own, naming the minutes, and not the 401 of a failure to authenticate: the token is good and so is the
 * grant; what is missing is a proof made minutes ago.
 *
 * While the platform's second factor is switched off -- the one-step sign-in of a staging server -- there is no factor to prove and nothing is asked
 * for. With it required, which is what ships, every session must prove it again.
 */
class EnsurePlatformAdminStepUp
{
    public function __construct(private readonly PlatformAdminStepUp $stepUp, private readonly PlatformAdminSettings $settings)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->stepUp->isFresh(principal: PlatformAdminPrincipal::fromRequest(request: $request))) {
            throw PlatformAdminApiException::stepUpRequired(withinMinutes: $this->settings->stepUpMinutes());
        }

        return $next($request);
    }
}
