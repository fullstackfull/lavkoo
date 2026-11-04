<?php

namespace App\Services\DeveloperPortal;

use App\Models\SellerWebhook;
use App\Models\VendorPayoutRequest;
use App\Services\Marketplace\SellerWebhookDispatcher;
use App\Services\Payments\GatewayReadiness;
use Illuminate\Support\Facades\Route;

/**
 * The three reference sections the portal navigation declared and never built.
 *
 * Each opened onto a placeholder, which is worse than not listing them: a developer who clicks
 * "Models and enums" and finds an empty card concludes the API has no documented shapes, when the
 * shapes are declared as constants all over the codebase.
 *
 * Everything here is read from the running system — the enum values are the classes' own constants,
 * the inbound endpoints are the route table, the outbound services are what the shop has configured.
 * A reference page that is written down separately from what it describes is wrong within a month.
 */
class PortalReference
{
    /**
     * Enumerations a client has to switch on, and where each one is declared.
     *
     * Chosen by what a caller receives and must branch on, not by what exists: an internal constant
     * an integrator never sees is not a contract.
     *
     * @var array<string, array{class: class-string, constant: string, means: string}>
     */
    private const ENUMS = [
        'payout_status' => [
            'class' => VendorPayoutRequest::class,
            'constant' => 'STATUSES',
            'means' => 'where_a_sellers_withdrawal_has_got_to',
        ],
        'webhook_event' => [
            'class' => SellerWebhookDispatcher::class,
            'constant' => 'EVENTS',
            'means' => 'the_events_an_endpoint_may_subscribe_to',
        ],
        'webhook_status' => [
            'class' => SellerWebhook::class,
            'constant' => 'SELLER_SETTABLE_STATUSES',
            'means' => 'the_states_a_seller_may_put_their_own_endpoint_into',
        ],
    ];

    public function __construct(private readonly GatewayReadiness $gateways)
    {
    }

    /** @return array<string, mixed> */
    public function models(): array
    {
        $enums = [];

        foreach (self::ENUMS as $name => $declaration) {
            $values = defined($declaration['class'] . '::' . $declaration['constant'])
                ? constant($declaration['class'] . '::' . $declaration['constant'])
                : null;

            // A constant that has been renamed is reported as missing rather than skipped: a
            // reference silently one enum short is how a client ends up unable to parse a status.
            $enums[] = [
                'name' => $name,
                'means' => $declaration['means'],
                'declared_in' => $declaration['class'] . '::' . $declaration['constant'],
                'values' => is_array($values) ? array_values($values) : null,
            ];
        }

        return ['enums' => $enums];
    }

    /**
     * Every endpoint an outside system POSTs INTO this shop.
     *
     * They are the reason "integrations" is not an empty page: twelve payment callbacks and a
     * courier status webhook sit outside the `api/` prefix, so the explorer, the OpenAPI export and
     * the coverage score all skip them — and they are the routes most likely to be pointed at the
     * wrong host during a migration.
     *
     * @return array<string, mixed>
     */
    public function integrations(): array
    {
        $inbound = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (!$this->isInbound($uri)) {
                continue;
            }

            $inbound[] = [
                'uri' => $uri,
                'methods' => array_values(array_diff($route->methods(), ['HEAD'])),
                'name' => $route->getName(),
                // Whether anything checks who is calling. A callback with no guard is a URL anybody
                // can POST an order state into, and that is worth naming on this page.
                'guarded' => $this->isGuarded($route),
            ];
        }

        usort($inbound, static fn (array $a, array $b): int => strcmp($a['uri'], $b['uri']));

        return [
            'inbound' => $inbound,
            'outbound' => [
                'payment_gateways' => $this->gateways->all(),
                'webhook_events' => SellerWebhookDispatcher::EVENTS,
            ],
        ];
    }

    private function isInbound(string $uri): bool
    {
        return str_contains($uri, 'callback')
            || str_contains($uri, 'webhook')
            || str_contains($uri, 'update-status')
            || str_contains($uri, 'ipn');
    }

    private function isGuarded(\Illuminate\Routing\Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (!is_string($middleware)) {
                continue;
            }

            if (str_contains($middleware, 'Auth') || str_contains($middleware, 'auth')) {
                return true;
            }
        }

        return false;
    }
}
