<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Http\Middleware\SellerApiAuthMiddleware;
use App\Jobs\DeliverSellerWebhook;
use App\Models\SellerApiKey;
use App\Models\SellerWebhook;
use App\Models\SellerWebhookDelivery;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\Marketplace\SellerApiKeyService;
use App\Services\Marketplace\SellerPermissionService;
use App\Services\Marketplace\SellerPrincipal;
use App\Services\Marketplace\SellerWebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Keys and webhooks: how a seller's own systems talk to the marketplace, and it to them.
 *
 * One rule shapes everything here: a key cannot manage keys. An integration that leaks should cost
 * the seller what that integration could do, and if a leaked key could mint more keys — or delete
 * the webhook that would have told somebody — the limit on its scopes would be decorative. So the
 * writes on this controller require a person, and say so rather than pretending not to exist.
 */
class SellerIntegrationController extends Controller
{
    public function __construct(
        private readonly SellerApiKeyService $apiKeys,
        private readonly SellerPermissionService $permissions,
    ) {
    }

    #[ApiDoc(
        summary: 'The shop\'s API keys',
        description: 'Never the keys themselves — only the prefix that identifies each one, what it '
            . 'may do, and when it was last actually used. "Last used" comes from real traffic, which '
            . 'is what makes it worth reading when deciding whether a key is still needed.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function keys(Request $request): JsonResponse
    {
        return response()->json([
            'keys' => SellerApiKey::where('seller_id', $request->seller->id)
                ->orderByDesc('id')
                ->get()
                ->map(fn (SellerApiKey $key) => [
                    'id' => $key->id,
                    'name' => $key->name,
                    'prefix' => $key->prefix,
                    'scopes' => $key->scopes ?? [],
                    'last_used_at' => $key->last_used_at,
                    'last_used_ip' => $key->last_used_ip,
                    'expires_at' => $key->expires_at,
                    'revoked_at' => $key->revoked_at,
                    'usable' => $key->isUsable(),
                    'created_at' => $key->created_at,
                ])->values(),
            'grantable_scopes' => $this->grantableScopes($request),
        ], 200);
    }

    #[ApiDoc(
        summary: 'Issue a key',
        description: 'The key is in the response and nowhere else — not in a column, not in a later '
            . 'endpoint, not recoverable by the marketplace. Its scopes are narrowed to what the '
            . 'person issuing it actually holds, so creating a key is never a way around the '
            . 'permission model. A key cannot be used to issue keys.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function storeKey(Request $request): JsonResponse
    {
        $principal = $this->principal($request);

        if ($refusal = $this->refuseIntegration($principal)) {
            return $refusal;
        }

        $validator = validator($request->all(), [
            'name' => 'required|string|max:120',
            'scopes' => 'nullable|array',
            'scopes.*' => 'string',
            'expires_at' => 'nullable|date|after:now',
        ]);

        if ($validator->fails()) {
            return $this->refuse($validator->errors()->toArray());
        }

        $issued = $this->apiKeys->issue(
            principal: $principal,
            name: $request['name'],
            scopes: $request['scopes'] ?? [],
            expiresAt: $request['expires_at'] ?? null,
        );

        return response()->json([
            'message' => translate('api_key_issued'),
            'id' => $issued['key']->id,
            'prefix' => $issued['key']->prefix,
            'scopes' => $issued['key']->scopes,
            // Shown once. There is no endpoint that returns it again.
            'key' => $issued['plaintext'],
        ], 201);
    }

    #[ApiDoc(
        summary: 'Revoke a key',
        description: 'Takes effect on the very next request that carries it. A key cannot revoke keys, '
            . 'including itself — a leaked key must not be able to cover its tracks.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function revokeKey(Request $request, $id): JsonResponse
    {
        $principal = $this->principal($request);

        if ($refusal = $this->refuseIntegration($principal)) {
            return $refusal;
        }

        $key = SellerApiKey::where('seller_id', $request->seller->id)->find($id);

        if (!$key) {
            return $this->notFound('api_key_not_found');
        }

        $this->apiKeys->revoke($key, $principal);

        return response()->json(['message' => translate('api_key_revoked')], 200);
    }

    #[ApiDoc(
        summary: 'What a webhook can subscribe to',
        description: 'A fixed list. Every name here is raised from a real place in the application — '
            . 'an event a seller can subscribe to and never receive would be worse than one that is '
            . 'not offered.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function events(): JsonResponse
    {
        return response()->json(['events' => SellerWebhookDispatcher::EVENTS], 200);
    }

    #[ApiDoc(
        summary: 'The shop\'s webhook endpoints, and how they are doing',
        description: 'With the health of each: when it last succeeded, when it last failed, how many '
            . 'failures in a row, and — when the marketplace has switched it off — why. An endpoint '
            . 'that has never been called says so rather than showing a green tick it has not earned.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function webhooks(Request $request): JsonResponse
    {
        return response()->json([
            'webhooks' => SellerWebhook::where('seller_id', $request->seller->id)
                ->orderByDesc('id')
                ->get()
                ->map(fn (SellerWebhook $webhook) => $this->presentWebhook($webhook))
                ->values(),
        ], 200);
    }

    #[ApiDoc(
        summary: 'Add an endpoint',
        description: 'The signing secret is in the response and nowhere else. Every delivery carries '
            . 'an HMAC of its exact body under that secret, which is how the receiver tells our '
            . 'delivery from anybody else\'s POST to the same URL.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function storeWebhook(Request $request): JsonResponse
    {
        $principal = $this->principal($request);

        if ($refusal = $this->refuseIntegration($principal)) {
            return $refusal;
        }

        $validator = validator($request->all(), $this->webhookRules());

        if ($validator->fails()) {
            return $this->refuse($validator->errors()->toArray());
        }

        if ($refusal = $this->refuseDestination((string) $request['url'])) {
            return $refusal;
        }

        $secret = Str::random(48);

        $webhook = SellerWebhook::create([
            'seller_id' => $request->seller->id,
            'name' => $request['name'],
            'url' => $request['url'],
            'events' => array_values(array_intersect($request['events'], SellerWebhookDispatcher::EVENTS)),
            'secret' => $secret,
            'status' => SellerWebhook::STATUS_ACTIVE,
        ]);

        // Where a shop's order and payout events are sent. Only the two paths that switch an
        // endpoint OFF were audited — the dispatcher's auto-disable and the admin kill switch — so
        // creating one, or repointing a live one at a new destination, wrote nothing at all. That
        // is the shape an exfiltration of a shop's event stream would take.
        $this->recordWebhookChange('created', $webhook, after: ['url' => $webhook->url, 'events' => $webhook->events]);

        return response()->json([
            'message' => translate('webhook_created'),
            'webhook' => $this->presentWebhook($webhook),
            'secret' => $secret,
        ], 201);
    }

    #[ApiDoc(
        summary: 'Change an endpoint',
        description: 'A rewritten endpoint starts clean: its failure run is reset and any switch-off '
            . 'the marketplace applied is cleared, because the endpoint that was failing is not the '
            . 'endpoint that now exists.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function updateWebhook(Request $request, $id): JsonResponse
    {
        $principal = $this->principal($request);

        if ($refusal = $this->refuseIntegration($principal)) {
            return $refusal;
        }

        $webhook = $this->ownedWebhook($request, $id);

        if (!$webhook) {
            return $this->notFound('webhook_not_found');
        }

        $validator = validator($request->all(), $this->webhookRules());

        if ($validator->fails()) {
            return $this->refuse($validator->errors()->toArray());
        }

        $before = ['url' => $webhook->url, 'events' => $webhook->events, 'status' => $webhook->status];

        $webhook->forceFill([
            'name' => $request['name'],
            'url' => $request['url'],
            'events' => array_values(array_intersect($request['events'], SellerWebhookDispatcher::EVENTS)),
            'status' => SellerWebhook::STATUS_ACTIVE,
            'consecutive_failures' => 0,
            'disabled_at' => null,
            'disabled_reason' => null,
        ])->save();

        $this->recordWebhookChange('repointed', $webhook, $before, ['url' => $webhook->url, 'events' => $webhook->events]);

        return response()->json([
            'message' => translate('webhook_updated'),
            'webhook' => $this->presentWebhook($webhook),
        ], 200);
    }

    #[ApiDoc(
        summary: 'Switch an endpoint on or off',
        description: 'Only active and paused are settable. Disabled is the marketplace\'s answer to an '
            . 'endpoint that stopped answering — switching it back to active is how that is cleared, '
            . 'deliberately, with the reason still on screen.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function setWebhookStatus(Request $request, $id): JsonResponse
    {
        $principal = $this->principal($request);

        if ($refusal = $this->refuseIntegration($principal)) {
            return $refusal;
        }

        $webhook = $this->ownedWebhook($request, $id);

        if (!$webhook) {
            return $this->notFound('webhook_not_found');
        }

        $status = (string) $request->get('status');

        if (!in_array($status, SellerWebhook::SELLER_SETTABLE_STATUSES, true)) {
            return $this->refuse(['status' => translate('webhook_status_not_settable')]);
        }

        $before = ['status' => $webhook->status];

        $webhook->forceFill([
            'status' => $status,
            'consecutive_failures' => $status === SellerWebhook::STATUS_ACTIVE ? 0 : $webhook->consecutive_failures,
            'disabled_at' => null,
            'disabled_reason' => null,
        ])->save();

        $this->recordWebhookChange('status_changed', $webhook, $before, ['status' => $status]);

        return response()->json([
            'message' => translate('webhook_updated'),
            'webhook' => $this->presentWebhook($webhook),
        ], 200);
    }

    #[ApiDoc(
        summary: 'Remove an endpoint',
        description: 'Its deliveries stay. They are the record of what was sent, and removing the '
            . 'endpoint does not un-send it.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function destroyWebhook(Request $request, $id): JsonResponse
    {
        $principal = $this->principal($request);

        if ($refusal = $this->refuseIntegration($principal)) {
            return $refusal;
        }

        $webhook = $this->ownedWebhook($request, $id);

        if (!$webhook) {
            return $this->notFound('webhook_not_found');
        }

        $this->recordWebhookChange('deleted', $webhook, ['url' => $webhook->url, 'events' => $webhook->events]);

        $webhook->delete();

        return response()->json(['message' => translate('webhook_deleted')], 200);
    }

    #[ApiDoc(
        summary: 'Send a test delivery',
        description: 'A real delivery of a real event shape, queued the same way and signed the same '
            . 'way, so what the receiver sees in the test is what it will see in production. It is '
            . 'marked as a test in its payload and is the only delivery the marketplace ever '
            . 'originates itself.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function testWebhook(Request $request, $id): JsonResponse
    {
        // The only write on this controller that a key could still reach: it queues deliveries
        // against an endpoint the key can neither create nor disable.
        if ($refusal = $this->refuseIntegration($this->principal($request))) {
            return $refusal;
        }

        $webhook = $this->ownedWebhook($request, $id);

        if (!$webhook) {
            return $this->notFound('webhook_not_found');
        }

        $event = (string) $request->get('event', SellerWebhookDispatcher::EVENTS[0]);

        if (!in_array($event, SellerWebhookDispatcher::EVENTS, true)) {
            return $this->refuse(['event' => translate('webhook_unknown_event')]);
        }

        $delivery = SellerWebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'seller_id' => $request->seller->id,
            'event' => $event,
            'payload' => ['test' => true],
            'status' => SellerWebhookDelivery::STATUS_PENDING,
        ]);

        DeliverSellerWebhook::dispatch($delivery->id);

        return response()->json([
            'message' => translate('webhook_test_queued'),
            'delivery_id' => $delivery->id,
        ], 202);
    }

    #[ApiDoc(
        summary: 'What was sent, and what came back',
        description: 'Every attempt, kept whether it worked or not — including the response code and '
            . 'an excerpt of the body. A seller whose integration is quietly missing every third '
            . 'order needs to see the third one, and a counter on the endpoint cannot show them which.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function deliveries(Request $request): JsonResponse
    {
        $deliveries = SellerWebhookDelivery::where('seller_id', $request->seller->id)
            ->when($request->filled('webhook_id'), fn ($query) => $query->where('webhook_id', (int) $request->get('webhook_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->get('status')))
            ->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->get('limit', 25))));

        return response()->json([
            'total_size' => $deliveries->total(),
            'limit' => $deliveries->perPage(),
            'offset' => $deliveries->currentPage(),
            'deliveries' => collect($deliveries->items())->map(fn (SellerWebhookDelivery $delivery) => [
                'id' => $delivery->id,
                'webhook_id' => $delivery->webhook_id,
                'event' => $delivery->event,
                'status' => $delivery->status,
                'attempts' => $delivery->attempts,
                'response_code' => $delivery->response_code,
                'response_body' => $delivery->response_body,
                'error' => $delivery->error,
                'delivered_at' => $delivery->delivered_at,
                'next_attempt_at' => $delivery->next_attempt_at,
                'created_at' => $delivery->created_at,
            ])->all(),
        ], 200);
    }

    /**
     * Refuse a destination the platform must not dial.
     *
     * Validation can only say the string looks like an https URL. Whether it points at the cloud
     * metadata service or an internal admin panel is a question about the resolved address, and it
     * belongs here rather than in a rule string.
     */
    private function refuseDestination(string $url): ?JsonResponse
    {
        $verdict = app(SellerWebhookDispatcher::class)->mayDial($url);

        if ($verdict['allowed']) {
            return null;
        }

        return response()->json(['errors' => [
            ['code' => 'url', 'message' => translate('webhook_url_' . $verdict['reason'])],
        ]], 403);
    }

    private function webhookRules(): array
    {
        return [
            'name' => 'required|string|max:120',
            // https only: a signed delivery over plain http is signed plaintext, and the payload
            // carries order and payout details.
            'url' => 'required|url|starts_with:https://|max:500',
            'events' => 'required|array|min:1',
            'events.*' => 'string|in:' . implode(',', SellerWebhookDispatcher::EVENTS),
        ];
    }

    private function presentWebhook(SellerWebhook $webhook): array
    {
        return [
            'id' => $webhook->id,
            'name' => $webhook->name,
            'url' => $webhook->url,
            'events' => $webhook->events ?? [],
            'status' => $webhook->status,
            'consecutive_failures' => $webhook->consecutive_failures,
            'last_success_at' => $webhook->last_success_at,
            'last_failure_at' => $webhook->last_failure_at,
            'disabled_at' => $webhook->disabled_at,
            'disabled_reason' => $webhook->disabled_reason,
            // An endpoint nothing has been sent to has not earned a green tick.
            'never_called' => $webhook->last_success_at === null && $webhook->last_failure_at === null,
            'created_at' => $webhook->created_at,
        ];
    }

    /** @return array<int, string> */
    private function grantableScopes(Request $request): array
    {
        $principal = $this->principal($request);
        $all = $this->permissions->allKeys();

        if ($principal->isOwner()) {
            return $all;
        }

        return array_values(array_filter($all, fn (string $scope) => $principal->can($scope)));
    }

    /**
     * A key may read this controller. It may not write to it.
     *
     * Said out loud rather than answered with a bare 403, so somebody debugging an integration is
     * not left guessing whether their scopes are wrong.
     */
    private function refuseIntegration(SellerPrincipal $principal): ?JsonResponse
    {
        if ($principal->apiKey === null) {
            return null;
        }

        return response()->json(['errors' => [
            ['code' => 'api_key', 'message' => translate('api_keys_cannot_manage_api_keys')],
        ]], 403);
    }

    private function ownedWebhook(Request $request, $id): ?SellerWebhook
    {
        return SellerWebhook::where('seller_id', $request->seller->id)->find($id);
    }

    private function notFound(string $key): JsonResponse
    {
        return response()->json(['errors' => [
            ['code' => 'integration', 'message' => translate($key)],
        ]], 404);
    }

    private function refuse(array $errors): JsonResponse
    {
        $formatted = [];

        foreach ($errors as $field => $messages) {
            $formatted[] = ['code' => $field, 'message' => is_array($messages) ? $messages[0] : $messages];
        }

        return response()->json(['errors' => $formatted], 403);
    }

    private function principal(Request $request): SellerPrincipal
    {
        $principal = $request->attributes->get(SellerApiAuthMiddleware::PRINCIPAL);

        return $principal instanceof SellerPrincipal ? $principal : SellerPrincipal::owner($request->seller);
    }
    /**
     * One line per change to where a shop's events are sent.
     *
     * The URL is recorded in full on both sides. A repoint is the one webhook change that matters
     * to a fraud review, and "the endpoint changed" without the two addresses is not a finding.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function recordWebhookChange(string $event, SellerWebhook $webhook, ?array $before = null, ?array $after = null): void
    {
        app(AuditLogger::class)->record(
            action: 'integration.webhook_' . $event,
            subject: $webhook,
            before: $before,
            after: $after,
            context: ['seller_id' => $webhook->seller_id, 'name' => $webhook->name],
        );
    }

}
