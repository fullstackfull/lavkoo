<?php

namespace App\Services\Marketplace;

use App\Jobs\DeliverSellerWebhook;
use App\Services\Platform\Policy;
use App\Models\SellerWebhook;
use App\Models\SellerWebhookDelivery;
use App\Services\AuditLogger;
use App\Services\Security\OutboundUrlGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Telling a seller's own systems what happened in their shop.
 *
 * The catalogue of events is a fixed list rather than anything a caller can name, because an event
 * is a promise: once something subscribes to `order.placed`, that name and that payload shape have
 * to keep meaning the same thing. A dispatcher that forwarded any string it was handed would make
 * every internal rename a breaking change for somebody's integration.
 *
 * Every delivery is signed. Without a signature a webhook endpoint is a URL that does something when
 * anybody POSTs to it, and the seller has no way to tell our delivery from a forged one.
 *
 * An endpoint that keeps failing is disabled rather than retried for ever. A queue full of
 * deliveries to a URL that stopped existing months ago is a queue that stops delivering the ones
 * that would have worked.
 */
class SellerWebhookDispatcher
{
    /**
     * What a seller can subscribe to.
     *
     * Each of these is raised from a real place in the application. There is deliberately nothing
     * here that the platform does not actually emit — an event a seller can subscribe to and never
     * receive is worse than one that is not offered.
     */
    public const EVENTS = [
        'order.placed',
        'order.status_changed',
        'order.refund_requested',
        'product.stock_low',
        'product.hidden_by_rule',
        'payout.status_changed',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OutboundUrlGuard $guard,
        private readonly Policy $policy,
    ) {
    }

    /**
     * How many attempts a seller's endpoint gets before the event is lost.
     *
     * Exposed because it is a promise the marketplace makes about delivery, and both the seller's
     * integration screen and the retry sweep have to state the same number.
     */
    public function maxAttempts(): int
    {
        return $this->policy->int('webhook_max_attempts');
    }

    /**
     * May the platform dial this URL at all?
     *
     * Any endpoint that fetches a caller-supplied URL is an SSRF primitive: the request leaves from
     * inside the network, so it reaches cloud metadata, internal admin panels and databases that are
     * firewalled off from the internet. This repo already learned that on `/image-proxy` and fixed
     * it with the same guard; a seller-supplied webhook URL is the identical hazard.
     *
     * Checked at registration *and* again before every dial, because DNS can be re-pointed at a
     * private address between the two.
     *
     * @return array{allowed: bool, reason: string|null, host: string|null, ip: string|null}
     */
    public function mayDial(?string $url): array
    {
        return $this->guard->check($url);
    }

    /**
     * Queue this event for every endpoint of this seller that asked for it.
     *
     * Never throws into its caller. An order must not fail to be placed because a seller's own
     * server is down, and a checkout that rolled back over a webhook would be a far worse bug than
     * a missed notification.
     *
     * @return int  how many deliveries were queued
     */
    public function dispatch(int|string $sellerId, string $event, array $payload): int
    {
        if (!in_array($event, self::EVENTS, true) || !Schema::hasTable('seller_webhooks')) {
            return 0;
        }

        try {
            $webhooks = SellerWebhook::where('seller_id', $sellerId)
                ->where('status', SellerWebhook::STATUS_ACTIVE)
                ->get()
                ->filter(fn (SellerWebhook $webhook) => $webhook->wants($event));

            foreach ($webhooks as $webhook) {
                $delivery = SellerWebhookDelivery::create([
                    'webhook_id' => $webhook->id,
                    'seller_id' => $sellerId,
                    'event' => $event,
                    'payload' => $payload,
                    'status' => SellerWebhookDelivery::STATUS_PENDING,
                ]);

                DeliverSellerWebhook::dispatch($delivery->id);
            }

            return $webhooks->count();
        } catch (Throwable) {
            // The shop's work is not the seller's integration's hostage.
            return 0;
        }
    }

    /**
     * Make one attempt, and record what came back.
     *
     * @return bool  whether it landed
     */
    public function attempt(SellerWebhookDelivery $delivery): bool
    {
        $webhook = SellerWebhook::find($delivery->webhook_id);

        if (!$webhook) {
            $this->giveUp($delivery, 'webhook_removed');

            return false;
        }

        $body = json_encode([
            'event' => $delivery->event,
            'delivery_id' => $delivery->id,
            'occurred_at' => optional($delivery->created_at)->toIso8601String(),
            'data' => $delivery->payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $delivery->forceFill(['attempts' => $delivery->attempts + 1])->save();

        // Re-checked here, not only when the endpoint was registered: a hostname that resolved to a
        // public address then can be re-pointed at 169.254.169.254 afterwards.
        $destination = $this->mayDial($webhook->url);

        if (!$destination['allowed']) {
            $this->recordFailure($webhook, $delivery, null, 'destination_refused:' . $destination['reason']);

            return false;
        }

        try {
            $response = Http::withoutRedirecting()->withHeaders([
                'Content-Type' => 'application/json',
                'X-Seller-Event' => $delivery->event,
                'X-Seller-Delivery' => (string) $delivery->id,
                // HMAC over the exact bytes sent, so the receiver verifies what it actually got
                // rather than a re-serialisation of it that may differ by a space.
                'X-Seller-Signature' => hash_hmac('sha256', $body, $webhook->secret),
            ])->timeout($this->policy->int('webhook_timeout_seconds'))->withBody($body, 'application/json')->post($webhook->url);
        } catch (Throwable $exception) {
            $this->recordFailure($webhook, $delivery, null, $exception->getMessage());

            return false;
        }

        if ($response->successful()) {
            $delivery->forceFill([
                'status' => SellerWebhookDelivery::STATUS_DELIVERED,
                'response_code' => $response->status(),
                'delivered_at' => now(),
                'next_attempt_at' => null,
            ])->save();

            // One success clears the run. An endpoint that failed twice last week and works now is
            // not on its way to being disabled.
            $webhook->forceFill(['consecutive_failures' => 0, 'last_success_at' => now()])->save();

            return true;
        }

        $this->recordFailure($webhook, $delivery, $response->status(), null);

        return false;
    }

    private function recordFailure(
        SellerWebhook $webhook,
        SellerWebhookDelivery $delivery,
        ?int $status,
        ?string $error,
    ): void {
        $exhausted = $delivery->attempts >= $this->policy->int('webhook_max_attempts');

        $delivery->forceFill([
            'status' => $exhausted ? SellerWebhookDelivery::STATUS_FAILED : SellerWebhookDelivery::STATUS_PENDING,
            'response_code' => $status,
            // The status code, not the body. Persisting what answered would turn the delivery log
            // into a read-back channel for anything the platform's network can reach.
            'response_body' => null,
            'error' => $error === null ? null : mb_substr($error, 0, 500),
            // Backing off in powers of two: a server that is briefly overloaded should not be
            // hammered by the retry, and one that is down for an hour should still be reached when
            // it comes back.
            'next_attempt_at' => $exhausted ? null : now()->addMinutes($this->policy->int('webhook_backoff_minutes') * (2 ** ($delivery->attempts - 1))),
        ])->save();

        $failures = $webhook->consecutive_failures + 1;

        $webhook->forceFill([
            'consecutive_failures' => $failures,
            'last_failure_at' => now(),
        ])->save();

        if ($failures >= SellerWebhook::FAILURE_LIMIT && $webhook->status !== SellerWebhook::STATUS_DISABLED) {
            $webhook->forceFill([
                'status' => SellerWebhook::STATUS_DISABLED,
                'disabled_at' => now(),
                'disabled_reason' => 'webhook_disabled_repeated_failures',
            ])->save();

            $this->audit->record(
                action: 'seller.webhook_disabled',
                subject: ['type' => 'seller_webhook', 'id' => $webhook->id],
                context: ['seller_id' => $webhook->seller_id, 'failures' => $failures],
            );
        }
    }

    private function giveUp(SellerWebhookDelivery $delivery, string $reason): void
    {
        $delivery->forceFill([
            'status' => SellerWebhookDelivery::STATUS_FAILED,
            'error' => $reason,
            'next_attempt_at' => null,
        ])->save();
    }
}
