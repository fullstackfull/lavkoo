<?php

namespace App\Http\Controllers\Api;

use App\Enums\Fulfillment\ProviderCode;
use App\Http\Controllers\Controller;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\WebhookEventProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Inbound provider webhooks: POST /api/fulfillment-webhooks/{provider}/{secret}/{type}
 *
 * The providers' webhooks carry no signature, so the endpoint is authenticated by a per-provider secret
 * that lives in the URL (LogesTechs can only be given a URL) or, where a provider can set headers, in
 * `Authorization: Bearer` / `X-Webhook-Secret`. The secret is compared in constant time, an optional IP
 * allow-list (addresses or CIDR ranges, IPv4 or IPv6) narrows who may call, and the request is refused unless integrations are switched on, the
 * provider is enabled, and its webhooks are enabled. Every failure is the same flat 401: nothing about
 * what was wrong is revealed.
 *
 * The handler itself only STORES the event (de-duplicated by a unique key) and queues it. Applying it —
 * matching, confirming terminal claims with the provider, the stale/terminal rules — happens later, so
 * the provider always gets a fast answer and a repeated delivery is harmless.
 */
class FulfillmentWebhookController extends Controller
{
    private const MAX_BODY_BYTES = 65536;

    public function __construct(
        private readonly ProviderConnectionManager $connections,
        private readonly WebhookEventProcessor $processor,
    ) {
    }

    public function receive(Request $request, string $provider, string $secret, string $type): JsonResponse
    {
        $code = ProviderCode::tryFrom(str_replace('-', '_', $provider));
        if ($code === null) {
            return response()->json(['success' => false], 404);
        }

        if (!$this->authorised($request, $code, $secret)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized request'], 401);
        }

        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return response()->json(['success' => false], 413);
        }

        $payload = $request->json()->all();
        if ($payload === []) {
            return response()->json(['success' => false, 'message' => 'Invalid payload'], 422);
        }

        $result = $this->processor->ingest($code, $type, $payload);

        return match ($result['status']) {
            'invalid' => response()->json(['success' => false, 'message' => 'Invalid payload'], 422),
            'unsupported' => response()->json(['success' => false], 404),
            default => response()->json(['success' => true, 'status' => $result['status']]),
        };
    }

    private function authorised(Request $request, ProviderCode $code, string $pathSecret): bool
    {
        $config = $this->connections->config($code);
        $expected = $config->secret('webhook_secret');

        if (!$this->connections->isGloballyEnabled() || !$config->enabled || !$config->bool('webhooks_enabled') || $expected === null) {
            return false;
        }

        $given = $request->bearerToken() ?? $request->header('X-Webhook-Secret') ?? $pathSecret;
        if (!is_string($given) || !hash_equals($expected, $given)) {
            return false;
        }

        $allowList = array_values(array_filter(array_map('trim', explode(',', $config->string('webhook_ip_allowlist')))));

        return $allowList === [] || IpUtils::checkIp((string) $request->ip(), $allowList);
    }
}
