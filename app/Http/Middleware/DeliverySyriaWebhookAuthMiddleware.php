<?php

namespace App\Http\Middleware;

use App\Services\DeliverySyria\DeliverySyriaConfigService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the inbound Delivery Syria status webhook.
 *
 * The courier is not a platform user, so this is not Passport/`auth:api`. It is the same shape as the
 * payment-gateway IPN callbacks: a public route that verifies a shared secret itself. The request must
 * carry X-Platform: deliverysyria and a Bearer token equal to the admin-configured webhook token, and
 * the integration must be enabled. It fails closed — any mismatch, or an unconfigured integration, is a
 * flat 401 with no detail. The comparison is constant-time (hash_equals) so the token can't be probed
 * by timing.
 */
class DeliverySyriaWebhookAuthMiddleware
{
    public function __construct(
        private readonly DeliverySyriaConfigService $config,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $expected = $this->config->webhookToken();

        $platformOk = $request->header('X-Platform') === DeliverySyriaConfigService::INBOUND_PLATFORM;
        $tokenOk = $this->config->isEnabled()
            && $expected !== ''
            && is_string($request->bearerToken())
            && hash_equals($expected, (string) $request->bearerToken());

        if (!$platformOk || !$tokenOk) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized request',
            ], 401);
        }

        return $next($request);
    }
}
