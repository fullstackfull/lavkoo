<?php

namespace App\Services\SalesChannels\Support;

use App\Services\SalesChannels\Inventory\InventoryLocationService;
use Illuminate\Support\Facades\Cache;

/**
 * What the modules need to exist before their first screen or scheduled run, and that nobody should have to create by hand: the places stock lives by default (Torod Point,
 * M5AZN, the own warehouse). The migrations only create tables, so this runs when a module page is opened or a scheduled command starts once they are in place. It only adds what is
 * missing, never changes what exists, and is repeated at most once an hour (clearing the cache makes it check again).
 */
final class SalesChannelSetup
{
    private const CACHE_KEY = 'sales_channels.default_locations_checked';

    public static function prepare(): void
    {
        Cache::remember(self::CACHE_KEY, now()->addHour(), static function (): bool {
            app(InventoryLocationService::class)->ensureDefaults();

            return true;
        });
    }
}
