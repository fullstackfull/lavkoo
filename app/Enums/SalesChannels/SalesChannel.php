<?php

namespace App\Enums\SalesChannels;

/** Where a sale came from. The storefront is the application's own shop; the others are marketplaces we connect to. */
enum SalesChannel: string
{
    case Storefront = 'storefront';
    case Amazon = 'amazon';
    case Trendyol = 'trendyol';

    public function isExternal(): bool
    {
        return $this !== self::Storefront;
    }

    /** @return list<self> the channels that have a connector (and therefore accounts) */
    public static function connectable(): array
    {
        return [self::Amazon, self::Trendyol];
    }

    public function labelKey(): string
    {
        return 'sales_channel_' . $this->value;
    }
}
