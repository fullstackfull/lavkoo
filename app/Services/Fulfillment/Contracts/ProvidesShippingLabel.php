<?php

namespace App\Services\Fulfillment\Contracts;

use App\Models\ProviderFulfillment;

interface ProvidesShippingLabel
{
    /** A URL for the shipment's air waybill, fetched from the provider on demand. */
    public function labelUrl(ProviderFulfillment $fulfillment): ?string;
}
