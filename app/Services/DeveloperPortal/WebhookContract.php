<?php

namespace App\Services\DeveloperPortal;

use App\Models\SellerWebhook;
use App\Services\Marketplace\SellerWebhookDispatcher;
use App\Services\Platform\Policy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What an integrator needs before they can receive a webhook from this marketplace.
 *
 * The portal declared a `webhooks` section, its capability probe returned true so the entry rendered
 * enabled, and it opened onto an empty card — while a complete signed-delivery system sat behind it:
 * six events, an HMAC signature, an SSRF-guarded dialler, a retry ledger and an auto-disable rule.
 * Everything an integrator has to know was in the code and nowhere they could read it.
 *
 * Read from the running system rather than written down beside it. The event list is the
 * dispatcher's own constant, the retry policy is the live policy values, and the failure limit is
 * the model's — so this page cannot describe a delivery guarantee the platform does not make.
 */
class WebhookContract
{
    /** What each event means, in the integrator's terms rather than the emitter's. */
    private const EVENT_MEANING = [
        'order.placed' => 'a_customer_placed_an_order_containing_at_least_one_of_your_products',
        'order.status_changed' => 'an_order_of_yours_moved_to_a_new_status',
        'order.refund_requested' => 'a_customer_asked_for_a_refund_on_one_of_your_lines',
        'product.stock_low' => 'one_of_your_products_fell_under_the_low_stock_threshold',
        'product.hidden_by_rule' => 'one_of_your_automation_rules_hid_a_listing',
        'payout.status_changed' => 'a_payout_of_yours_moved_to_a_new_status',
    ];

    public function __construct(private readonly Policy $policy)
    {
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        $backoffMinutes = $this->policy->int('webhook_backoff_minutes');
        $maxAttempts = $this->policy->int('webhook_max_attempts');

        return [
            'events' => array_map(
                static fn (string $event): array => [
                    'event' => $event,
                    'meaning' => self::EVENT_MEANING[$event] ?? null,
                ],
                SellerWebhookDispatcher::EVENTS,
            ),
            'signature' => [
                'header' => 'X-Seller-Signature',
                'algorithm' => 'HMAC-SHA256',
                // Over the exact bytes sent, which is the part integrators get wrong: verifying a
                // re-serialisation of the payload fails on a difference of one space.
                'signed_over' => 'the_exact_request_body_as_bytes_not_a_reserialisation_of_it',
                'secret_shown' => 'once_when_the_endpoint_is_created_and_never_again',
                'other_headers' => ['X-Seller-Event', 'X-Seller-Delivery'],
            ],
            'retries' => [
                'max_attempts' => $maxAttempts,
                'first_retry_minutes' => $backoffMinutes,
                'backoff' => 'doubling',
                'timeout_seconds' => $this->policy->int('webhook_timeout_seconds'),
                // The honest total, computed rather than asserted: an integrator planning an
                // outage window needs the number, not the word "several".
                'total_window_minutes' => $this->retryWindowMinutes($backoffMinutes, $maxAttempts),
                'sweep' => 'seller:retry-webhooks, every five minutes',
            ],
            'auto_disable' => [
                'after_consecutive_failures' => SellerWebhook::FAILURE_LIMIT,
                'cleared_by' => 're_saving_the_endpoint_which_resets_its_failure_run',
            ],
            'destination_rules' => [
                'https_only' => true,
                'refused' => 'private_addresses_loopback_and_cloud_metadata_endpoints',
                'redirects' => 'not_followed',
            ],
            'delivery_health' => $this->health(),
        ];
    }

    /**
     * How the endpoints on this deployment are actually doing.
     *
     * A contract page that only states the promise is a document; this is the same page saying
     * whether the promise is being kept here.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        if (!Schema::hasTable('seller_webhooks') || !Schema::hasTable('seller_webhook_deliveries')) {
            return ['state' => 'unavailable'];
        }

        return [
            'state' => 'ok',
            'endpoints' => (int) DB::table('seller_webhooks')->count(),
            'active' => (int) DB::table('seller_webhooks')->where('status', SellerWebhook::STATUS_ACTIVE)->count(),
            'auto_disabled' => (int) DB::table('seller_webhooks')->where('status', SellerWebhook::STATUS_DISABLED)->count(),
            'pending' => (int) DB::table('seller_webhook_deliveries')->whereNotNull('next_attempt_at')->count(),
            'failed' => (int) DB::table('seller_webhook_deliveries')->where('status', 'failed')->count(),
            'delivered_today' => (int) DB::table('seller_webhook_deliveries')
                ->where('status', 'delivered')
                ->where('updated_at', '>=', now()->startOfDay())
                ->count(),
        ];
    }

    /** Sum of a doubling backoff over the attempts a delivery gets. */
    private function retryWindowMinutes(int $backoffMinutes, int $maxAttempts): int
    {
        $total = 0;

        for ($attempt = 1; $attempt < max(1, $maxAttempts); $attempt++) {
            $total += $backoffMinutes * (2 ** ($attempt - 1));
        }

        return $total;
    }
}
