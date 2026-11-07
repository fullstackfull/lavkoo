<?php

namespace App\Enums\SalesChannels;

/**
 * HOW an order is fulfilled, which is a different question from who sells it (the channel), who ships it (the provider)
 * and where its stock sits (the inventory location).
 */
enum FulfillmentMode: string
{
    case Fba = 'fba';
    case Fbm = 'fbm';
    case Dropshipping = 'dropshipping';
    case ThirdParty = 'third_party';
    case ChannelManaged = 'channel_managed';
    case MerchantManaged = 'merchant_managed';
    /** The channel did not say, or said something this version does not understand: never treated as FBM. */
    case Unknown = 'unknown';

    /** A mode in which the sales channel (Amazon, Trendyol) ships the goods itself: no provider of ours may be sent the order. */
    public function isFulfilledByChannel(): bool
    {
        return in_array($this, [self::Fba, self::ChannelManaged], true);
    }

    public function labelKey(): string
    {
        return 'fulfillment_mode_' . $this->value;
    }
}
