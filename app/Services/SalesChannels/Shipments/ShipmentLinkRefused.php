<?php

namespace App\Services\SalesChannels\Shipments;

use RuntimeException;

/** A link that would break the rule "one shipment, one order" or "never a second shipment for an order that has one". */
final class ShipmentLinkRefused extends RuntimeException
{
}
