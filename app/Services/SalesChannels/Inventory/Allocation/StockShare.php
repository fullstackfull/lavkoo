<?php

namespace App\Services\SalesChannels\Inventory\Allocation;

/**
 * How a listing takes its part of a pool. Nothing set means an equal part of what is left; the modes are applied in this order (see StockAllocator): fixed units first, then
 * the listings that fill by priority, then percentages of the pool, then the equal split among the rest.
 */
final class StockShare
{
    public const FIXED = 'fixed';
    public const PRIORITY = 'priority';
    public const PERCENT = 'percent';

    /** What a listing with no mode is reported as. */
    public const EQUAL = 'equal';

    /** @var list<string> */
    public const MODES = [self::FIXED, self::PRIORITY, self::PERCENT];

    /** The largest number of units a share, a reserve or a priority may name: a bigger one is a typing mistake, not a stock level. */
    public const MAX_UNITS = 1_000_000;
}
