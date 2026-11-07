<?php

namespace App\Services\SalesChannels\Contracts;

use App\Models\ExternalOrderMapping;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Dispatch\ChannelRecipient;
use App\Services\SalesChannels\Exceptions\RecipientUnavailable;

/** A channel that can say, at dispatch time, who an order is to be delivered to. Gated like no other read: see ChannelOperations (`buyerData`). */
interface ReadsRecipient
{
    /**
     * @throws RecipientUnavailable when the channel will not, or cannot, say
     * @throws \App\Services\SalesChannels\Exceptions\ChannelUnavailableException when a switch or approval stops the read before anything is sent
     */
    public function recipient(SalesChannelAccount $account, ExternalOrderMapping $mapping): ChannelRecipient;
}
