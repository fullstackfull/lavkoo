<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Support\ChannelCredentialStore;
use App\Services\SalesChannels\Trendyol\TrendyolWebhookIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Trendyol order-package webhooks: POST /api/sales-channel-webhooks/{account}
 *
 * Trendyol publishes no signature and no source addresses, so the endpoint is authenticated by the API key chosen at registration and sent in `x-api-key`,
 * compared in constant time. Every authentication failure (unknown account, no key registered, wrong key) is the same flat 401. A delivery is stored and applied
 * through the idempotent ingestor, so the repeated and out-of-order deliveries Trendyol documents are harmless; the answer is fast and carries only counts.
 */
class TrendyolWebhookController extends Controller
{
    public function __construct(
        private readonly TrendyolWebhookIngestor $ingestor,
        private readonly ChannelCredentialStore $credentials,
    ) {
    }

    public function receive(Request $request, int $account): JsonResponse
    {
        $channelAccount = SalesChannelAccount::where('id', $account)->where('channel', 'trendyol')->first();
        if ($channelAccount === null || !$this->authorised($request, $channelAccount)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized request'], 401);
        }

        if (strlen($request->getContent()) > (int) config('sales_channels.trendyol.webhook_max_bytes', 262144)) {
            return response()->json(['success' => false], 413);
        }

        $payload = $request->json()->all();
        if ($payload === []) {
            return response()->json(['success' => false, 'message' => 'Invalid payload'], 422);
        }

        return response()->json(['success' => true, 'counts' => $this->ingestor->ingest($channelAccount, $payload)]);
    }

    private function authorised(Request $request, SalesChannelAccount $account): bool
    {
        $expected = $this->credentials->get($account)->secret('webhook_api_key');
        $given = $request->header('x-api-key');

        return $expected !== null && is_string($given) && hash_equals($expected, $given);
    }
}
