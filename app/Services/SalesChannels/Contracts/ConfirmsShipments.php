<?php

namespace App\Services\SalesChannels\Contracts;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Data\ShipmentConfirmation;

interface ConfirmsShipments
{
    /** Tells the channel an order we fulfil has shipped. A WRITE. Returns whether the channel took it. */
    public function confirmShipment(SalesChannelAccount $account, ShipmentConfirmation $confirmation): bool;
}
