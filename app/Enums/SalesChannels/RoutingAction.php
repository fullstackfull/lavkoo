<?php

namespace App\Enums\SalesChannels;

/** What a routing rule can decide about an order (or one of its lines). */
enum RoutingAction: string
{
    /** The application creates the fulfilment order at the chosen provider. */
    case RouteToProvider = 'route_to_provider';
    /** Someone else fulfils; the application follows the shipment and counts its cost. */
    case MonitorOnly = 'monitor_only';
    /** The channel ships it (Amazon FBA, Trendyol-managed): there is nothing for any provider to do. */
    case ChannelFulfills = 'channel_fulfills';
    case ManualReview = 'manual_review';
    case Hold = 'hold';

    public function executionPolicy(): ExecutionPolicy
    {
        return match ($this) {
            self::RouteToProvider => ExecutionPolicy::Managed,
            self::MonitorOnly, self::ChannelFulfills => ExecutionPolicy::ExternallyManaged,
            self::ManualReview, self::Hold => ExecutionPolicy::ManualReview,
        };
    }

    public function labelKey(): string
    {
        return 'routing_action_' . $this->value;
    }
}
