<?php

namespace App\Services\SalesChannels\Shipments;

use App\Enums\Fulfillment\ProviderCode;
use App\Enums\SalesChannels\ExecutionPolicy;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Models\ExternalOrderMapping;
use App\Models\ProviderFulfillment;
use App\Services\AuditLogger;
use App\Services\SalesChannels\Inventory\ChannelOrderReservations;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ties a channel order whose shipping someone else arranged to the shipment that already exists at the provider. It only RECORDS the link: it never creates, changes or cancels
 * anything at a provider, which is what makes "never a second shipment" a property of the design and not of a check.
 *
 * Refused outright: an order the application itself created a fulfilment for, an order the channel ships (FBA), a provider we do not integrate, and a shipment already linked to
 * another channel order or already the fulfilment of an order of the store. It does not ask the provider whether the shipment exists: the reference is what a person (or the bound
 * ExternalShipmentLookup) says. Searching for the shipment is left to that lookup; where it cannot search, or finds none or several, a person links it.
 */
class ExternalShipmentLinker
{
    public const LINKED = 'linked';
    public const NEEDS_REVIEW = 'needs_review';
    public const NOT_FOUND = 'not_found';
    public const UNAVAILABLE = 'lookup_unavailable';

    /** Review reasons this class raises or that a link resolves. */
    private const OWN_REASONS = ['ambiguous_shipment', 'shipment_linked_elsewhere', 'managed_dispatch_not_available', 'no_rule_matched'];

    public function __construct(
        private readonly ExternalShipmentLookup $lookup,
        private readonly AuditLogger $audit,
        private readonly ChannelOrderReservations $reservations,
    ) {
    }

    /** Looks for the order's shipment and links it only when the search is available and finds exactly one. */
    public function autoLink(ExternalOrderMapping $mapping): string
    {
        if ($mapping->shipment_link_state === ExternalOrderMapping::LINK_LINKED) {
            return self::LINKED;
        }
        if (!$this->lookup->available()) {
            return self::UNAVAILABLE;
        }

        $candidates = $this->lookup->candidates($mapping);
        $mapping->forceFill(['shipment_checked_at' => now()])->save();
        if ($candidates === []) {
            return self::NOT_FOUND;
        }
        if (count($candidates) > 1) {
            $mapping->forceFill(['shipment_link_state' => ExternalOrderMapping::LINK_NEEDS_REVIEW, 'review_reason' => 'ambiguous_shipment'])->save();

            return self::NEEDS_REVIEW;
        }

        $found = $candidates[0];
        try {
            $this->link($mapping, $found->provider, $found->reference, null, $found->trackingNumber, $found->carrier, $found->status);
        } catch (ShipmentLinkRefused) {
            $mapping->forceFill(['shipment_link_state' => ExternalOrderMapping::LINK_CONFLICT, 'review_reason' => 'shipment_linked_elsewhere'])->save();

            return self::NEEDS_REVIEW;
        }

        return self::LINKED;
    }

    /** @throws ShipmentLinkRefused */
    public function link(ExternalOrderMapping $mapping, string $provider, string $reference, ?int $adminId, ?string $trackingNumber = null, ?string $carrier = null, ?string $status = null): ExternalOrderMapping
    {
        $reference = trim($reference);
        if ($reference === '' || ProviderCode::tryFrom($provider) === null) {
            throw new ShipmentLinkRefused('A known provider and the shipment\'s reference are needed.');
        }

        return DB::transaction(function () use ($mapping, $provider, $reference, $adminId, $trackingNumber, $carrier, $status): ExternalOrderMapping {
            $locked = ExternalOrderMapping::whereKey($mapping->id)->lockForUpdate()->firstOrFail();
            $this->assertLinkable($locked, $provider, $reference);

            $snapshot = (array) $locked->snapshot;
            // What the order held before the link, so that unlinking puts it back: the channel's own tracking data share these columns, and the link clears the review reason it solves.
            $snapshot['shipment_link'] = ($locked->shipment_link_state === ExternalOrderMapping::LINK_LINKED ? ($snapshot['shipment_link'] ?? []) : [
                'previous' => [
                    'provider' => $locked->provider, 'tracking_number' => $locked->tracking_number, 'tracking_url' => $locked->tracking_url, 'carrier' => $locked->carrier,
                    'execution_policy' => $locked->execution_policy, 'review_reason' => $locked->review_reason,
                ],
            ]) + ['linked_at' => now()->toIso8601String(), 'by' => $adminId];

            try {
                $locked->forceFill([
                    'execution_policy' => ExecutionPolicy::ExternallyManaged->value,
                    'provider' => $provider,
                    'shipment_link_state' => ExternalOrderMapping::LINK_LINKED,
                    'shipment_reference' => $reference,
                    'shipment_status' => $status ?? $locked->shipment_status,
                    'tracking_number' => $trackingNumber ?? $locked->tracking_number,
                    'carrier' => $carrier ?? $locked->carrier,
                    'shipment_checked_at' => now(),
                    'review_reason' => in_array($locked->review_reason, self::OWN_REASONS, true) ? null : $locked->review_reason,
                    'snapshot' => $snapshot,
                ])->save();
            } catch (UniqueConstraintViolationException) {
                throw new ShipmentLinkRefused('That shipment is already linked to another order.');
            }
            $this->audit->record(action: 'sales_channel.shipment_linked', subject: $locked, context: ['mapping_id' => $locked->id, 'provider' => $provider, 'reference' => $reference, 'by' => $adminId ?? 'lookup']);
            // A provider holds the order now: its units have left our shelf as far as the stock offered is concerned.
            $this->reservations->sync($locked);

            return $locked;
        });
    }

    public function unlink(ExternalOrderMapping $mapping, ?int $adminId, string $reason): ExternalOrderMapping
    {
        $snapshot = (array) $mapping->snapshot;
        $previous = (array) ($snapshot['shipment_link']['previous'] ?? []);
        unset($snapshot['shipment_link']);

        $mapping->forceFill(array_merge([
            'shipment_link_state' => ExternalOrderMapping::LINK_UNLINKED,
            'shipment_reference' => null,
            'shipment_status' => null,
            'shipment_checked_at' => null,
            'review_reason' => $this->reasonAfterUnlink($mapping, $previous),
            'snapshot' => $snapshot,
        ], array_intersect_key($previous, array_flip(['provider', 'tracking_number', 'tracking_url', 'carrier', 'execution_policy']))))->save();
        $this->audit->record(action: 'sales_channel.shipment_unlinked', subject: $mapping, context: ['mapping_id' => $mapping->id, 'by' => $adminId, 'reason' => $reason]);
        $this->reservations->sync($mapping);

        return $mapping;
    }

    /** A reason a person still has to look at stays; the one the link solved comes back, because without the shipment the order waits for a person again. */
    private function reasonAfterUnlink(ExternalOrderMapping $mapping, array $previous): ?string
    {
        if ($mapping->review_reason !== null && !in_array($mapping->review_reason, self::OWN_REASONS, true)) {
            return $mapping->review_reason;
        }
        $before = $previous['review_reason'] ?? null;

        return in_array($before, self::OWN_REASONS, true) ? $before : null;
    }

    /** @throws ShipmentLinkRefused */
    private function assertLinkable(ExternalOrderMapping $mapping, string $provider, string $reference): void
    {
        if ($mapping->order_id !== null) {
            throw new ShipmentLinkRefused('The application already created a fulfilment for this order: it never gets a second shipment.');
        }
        if ($mapping->fulfillmentMode()->isFulfilledByChannel()) {
            throw new ShipmentLinkRefused('The channel ships this order itself; there is no provider shipment to link.');
        }
        if ($mapping->fulfillmentMode() === FulfillmentMode::Unknown) {
            throw new ShipmentLinkRefused('Who fulfils this order is not known yet.');
        }
        if ($mapping->shipment_link_state === ExternalOrderMapping::LINK_LINKED && ($mapping->shipment_reference !== $reference || $mapping->provider !== $provider)) {
            throw new ShipmentLinkRefused('This order is already linked to another shipment: unlink it first.');
        }
        $elsewhere = ExternalOrderMapping::where('provider', $provider)->where('shipment_reference', $reference)->where('id', '!=', $mapping->id)->exists();
        if ($elsewhere) {
            throw new ShipmentLinkRefused('That shipment is already linked to another order.');
        }
        if ($this->isAStoreOrdersShipment($provider, $reference)) {
            throw new ShipmentLinkRefused('That shipment is the fulfilment of an order of the store.');
        }
    }

    private function isAStoreOrdersShipment(string $provider, string $reference): bool
    {
        return Schema::hasTable('provider_fulfillments') && ProviderFulfillment::query()
            ->where('provider', $provider)
            ->where(static fn ($query) => $query->where('provider_reference', $reference)->orWhere('provider_secondary_reference', $reference))
            ->exists();
    }
}
