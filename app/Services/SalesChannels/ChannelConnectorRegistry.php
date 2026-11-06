<?php

namespace App\Services\SalesChannels;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Contracts\ChannelConnector;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/** Finds the connector that speaks to an account's channel, and says honestly what it can do. */
final class ChannelConnectorRegistry
{
    public function __construct(private readonly Container $container)
    {
    }

    public function for(SalesChannelAccount $account): ChannelConnector
    {
        $class = config('sales_channels.connectors.' . $account->channel);
        if (!is_string($class) || !class_exists($class)) {
            throw new RuntimeException("No connector is registered for the channel '{$account->channel}'.");
        }

        return $this->container->make($class);
    }

    /** @param class-string $ability one of the Contracts\Reads* / Publishes* / Confirms* interfaces */
    public function supports(SalesChannelAccount $account, string $ability): bool
    {
        return $this->for($account) instanceof $ability;
    }

    /**
     * @template T of object
     * @param class-string<T> $ability
     * @return T
     * @throws RuntimeException when the channel's connector does not have the ability
     */
    public function able(SalesChannelAccount $account, string $ability): object
    {
        $connector = $this->for($account);
        if (!$connector instanceof $ability) {
            throw new RuntimeException("The {$account->channel} connector cannot do that: it does not implement " . class_basename($ability) . '.');
        }

        return $connector;
    }
}
