<?php

namespace App\Services\SalesChannels\Support;

use Illuminate\Support\Arr;

/**
 * A snapshot column holds two kinds of data: what the channel said (replaced whenever the channel says something new) and what this application recorded beside it
 * (what was already told to the channel, the price a person set, the state of a publish, the shipment an order was linked to). A sync must never wipe the second kind
 * by writing the first, so every key the application writes is listed here and carried over.
 */
final class SnapshotState
{
    /** @var list<string> order mappings: notifications sent to the channel and the shipment link */
    public const ORDER = ['channel_notified', 'channel_notify_error', 'shipment_link'];

    /** @var list<string> listings: the price a person set and the state of each publish */
    public const LISTING = ['manual_price', 'publish'];

    /** @var list<string> order lines: the units each return claim gave back */
    public const ORDER_LINE = ['returned_by_claim'];

    /**
     * @param array<string, mixed>|null $current what the row holds now
     * @param array<string, mixed> $fromChannel what the channel just reported
     * @param list<string> $ownKeys the keys this application writes in this kind of snapshot
     * @return array<string, mixed> the channel's data, with the application's own entries kept as they are (they win over a channel key of the same name)
     */
    public static function refreshed(?array $current, array $fromChannel, array $ownKeys): array
    {
        return array_replace($fromChannel, Arr::only($current ?? [], $ownKeys));
    }
}
