<?php

namespace App\Services\SalesChannels\Contracts;

use App\Enums\SalesChannels\SalesChannel;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Data\ConnectionTestResult;

/**
 * A sales channel's side of the integration. A connector owns everything channel-specific (URLs, authentication, payload shapes, status vocabulary,
 * pagination) and nothing else: routing, idempotency, mapping, cost and the switches that decide whether a call may be made are channel-neutral and live outside it.
 *
 * Optional abilities are separate interfaces (ReadsListings, ReadsOrders...), so a connector says exactly what the channel's documentation lets it do.
 */
interface ChannelConnector
{
    public function channel(): SalesChannel;

    /** A read-only call that proves the credentials work and says what they are allowed to do. It creates and changes nothing at the channel. */
    public function testConnection(SalesChannelAccount $account): ConnectionTestResult;

    /**
     * What the official documentation establishes and what is built, for the admin panel: key => [documented, built, note]. `documented = false` means the channel
     * publishes no such call, never "not wanted".
     *
     * @return array<string, array{documented: bool, built: bool, note: string}>
     */
    public function capabilityMatrix(): array;
}
