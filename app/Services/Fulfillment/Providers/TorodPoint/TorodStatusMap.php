<?php

namespace App\Services\Fulfillment\Providers\TorodPoint;

use App\Enums\Fulfillment\FulfillmentStatus;

/**
 * LogesTechs package statuses (Lastmile API v2 status list + webhook "Status in detail") translated to
 * the provider-neutral lifecycle.
 *
 * Deliberate choices:
 *  - Warehouse picking/packing (CREATED/PICKED/PACKED, from the Fulfillment webhook) is NOT here: it is
 *    display-only and never delivery.
 *  - COMPLETED means the package record was closed; without a delivered event first it is not proof of
 *    delivery, so it raises an attention flag. After DELIVERED it is ignored as a terminal state.
 *  - DELIVERED_TO_SENDER (back with us) is a return.
 *  - DRAFT is a package that has not been submitted: no state change.
 *  - The documentation lists SCANNED_BY_DRIVER_AND_IN_CAR twice ("Picked" and "Resolved Failure"); both
 *    describe a parcel the driver holds, so it is in transit either way.
 */
final class TorodStatusMap
{
    /** @var array<string, FulfillmentStatus> */
    private const MAP = [
        'PENDING_CUSTOMER_CARE_APPROVAL' => FulfillmentStatus::Submitted,
        'APPROVED_BY_CUSTOMER_CARE_AND_WAITING_FOR_DISPATCHER' => FulfillmentStatus::Processing,
        'ASSIGNED_TO_DRIVER_AND_PENDING_APPROVAL' => FulfillmentStatus::Processing,
        'ACCEPTED_BY_DRIVER_AND_PENDING_PICKUP' => FulfillmentStatus::Processing,
        'SCANNED_BY_DRIVER_AND_IN_CAR' => FulfillmentStatus::InTransit,
        'SCANNED_BY_HANDLER_AND_UNLOADED' => FulfillmentStatus::InTransit,
        'MOVED_TO_SHELF_AND_OUT_OF_HANDLER_CUSTODY' => FulfillmentStatus::InTransit,
        'TRANSFERRED_OUT' => FulfillmentStatus::InTransit,
        'EXPORTED_TO_THIRD_PARTY' => FulfillmentStatus::InTransit,
        'OUT_FOR_DELIVERY' => FulfillmentStatus::OutForDelivery,
        'POSTPONED_DELIVERY' => FulfillmentStatus::DeliveryPostponed,
        'FAILED' => FulfillmentStatus::DeliveryFailed,
        'DELIVERED_TO_RECIPIENT' => FulfillmentStatus::Delivered,
        'PARTIALLY_DELIVERED' => FulfillmentStatus::PartiallyDelivered,
        'RETURNED_BY_RECIPIENT' => FulfillmentStatus::Returned,
        'DELIVERED_TO_SENDER' => FulfillmentStatus::Returned,
        'CANCELLED' => FulfillmentStatus::Cancelled,
        'COMPLETED' => FulfillmentStatus::NeedsAttention,
        'OPENED_ISSUE_AND_WAITING_FOR_MANAGEMENT' => FulfillmentStatus::NeedsAttention,
        'REJECTED_BY_DRIVER_AND_PENDING_MANGEMENT' => FulfillmentStatus::NeedsAttention,
        'DAMAGED' => FulfillmentStatus::NeedsAttention,
        'LOST' => FulfillmentStatus::NeedsAttention,
        'SWAPPED' => FulfillmentStatus::NeedsAttention,
        'BROUGHT' => FulfillmentStatus::NeedsAttention,
    ];

    /** Statuses that, because they are final or financial, are confirmed with the provider before applying. */
    public const VERIFIED_BEFORE_APPLYING = [
        'DELIVERED_TO_RECIPIENT', 'RETURNED_BY_RECIPIENT', 'DELIVERED_TO_SENDER', 'CANCELLED', 'COMPLETED', 'PARTIALLY_DELIVERED', 'LOST', 'DAMAGED',
    ];

    public static function map(string $providerStatus): ?FulfillmentStatus
    {
        return self::MAP[strtoupper(trim($providerStatus))] ?? null;
    }

    /** @return list<string> */
    public static function known(): array
    {
        return array_keys(self::MAP);
    }
}
