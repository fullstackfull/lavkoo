<?php

namespace App\Services\SalesChannels\Shipments;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\ExecutionPolicy;
use App\Models\ExternalOrderMapping;

/**
 * The scheduled search for shipments somebody else created (a channel account connected straight to the provider): it asks the lookup again and again for every order that is only
 * followed and not yet linked, links the one it can prove, and once an order has gone without a shipment for the whole waiting time hands it to a person instead of waiting for ever.
 * It also keeps the provider's status word of a linked shipment up to date. It reads, records and creates nothing at any provider.
 */
class ShipmentDiscovery
{
    public const REASON_NOT_FOUND = 'shipment_not_found';

    public function __construct(
        private readonly ExternalShipmentLookup $lookup,
        private readonly ExternalShipmentLinker $linker,
    ) {
    }

    /** @return array<string, int> */
    public function run(int $limit = 100): array
    {
        $counts = [ExternalShipmentLinker::LINKED => 0, ExternalShipmentLinker::NOT_FOUND => 0, ExternalShipmentLinker::NEEDS_REVIEW => 0, ExternalShipmentLinker::UNAVAILABLE => 0, 'handed_to_a_person' => 0, 'status_updated' => 0];
        if (!$this->lookup->available()) {
            $counts[ExternalShipmentLinker::UNAVAILABLE] = 1;

            return $counts;
        }

        $open = [ChannelOrderStatus::New->value, ChannelOrderStatus::Processing->value, ChannelOrderStatus::Shipped->value];
        $waiting = ExternalOrderMapping::with('account')->where('execution_policy', ExecutionPolicy::ExternallyManaged->value)->whereNull('order_id')
            ->where(static fn ($query) => $query->whereNull('shipment_link_state')->orWhere('shipment_link_state', ExternalOrderMapping::LINK_UNLINKED))
            ->whereIn('status', $open)->orderBy('id')->limit($limit)->get();
        foreach ($waiting as $mapping) {
            $outcome = $this->linker->autoLink($mapping);
            $counts[$outcome]++;
            if ($outcome === ExternalShipmentLinker::NOT_FOUND && $this->waitedLongEnough($mapping)) {
                $mapping->forceFill(['shipment_link_state' => ExternalOrderMapping::LINK_NEEDS_REVIEW, 'review_reason' => self::REASON_NOT_FOUND])->save();
                $counts['handed_to_a_person']++;
            }
        }

        $counts['status_updated'] = $this->refreshLinked($open, $limit);

        return $counts;
    }

    /** @param list<string> $open */
    private function refreshLinked(array $open, int $limit): int
    {
        if (!$this->lookup instanceof TorodShipmentLookup) {
            return 0;
        }

        $updated = 0;
        $linked = ExternalOrderMapping::where('execution_policy', ExecutionPolicy::ExternallyManaged->value)->whereNull('order_id')->where('provider', 'torod_point')
            ->where('shipment_link_state', ExternalOrderMapping::LINK_LINKED)->whereNotNull('shipment_reference')->whereIn('status', $open)->orderBy('id')->limit($limit)->get();
        foreach ($linked as $mapping) {
            $status = $this->lookup->statusOf((string) $mapping->shipment_reference);
            if ($status !== null && $status !== '' && $status !== $mapping->shipment_status) {
                $mapping->forceFill(['shipment_status' => $status, 'shipment_checked_at' => now()])->save();
                $updated++;
            }
        }

        return $updated;
    }

    private function waitedLongEnough(ExternalOrderMapping $mapping): bool
    {
        $since = $mapping->purchased_at ?? $mapping->created_at;

        return $since !== null && $since->lte(now()->subHours((int) config('sales_channels.shipment_discovery.window_hours', 36)));
    }
}
