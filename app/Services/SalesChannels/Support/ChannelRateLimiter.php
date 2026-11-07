<?php

namespace App\Services\SalesChannels\Support;

use App\Services\SalesChannels\Exceptions\ChannelRateLimitedException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

/**
 * Keeps this host inside a channel's published rate limit instead of finding it out from a 429.
 *
 * A fixed window per key: `max` calls per `perSeconds`. When the window is full the caller waits for the next one if that is a short wait, or gets a
 * ChannelRateLimitedException to hand back to its queue if it is a long one. The count lives in the cache, so it is shared by every worker on
 * the host; a cache that cannot count simply lets the call through (the channel's own limit and the retry back-off still apply).
 */
class ChannelRateLimiter
{
    public function acquire(string $key, int $max, int $perSeconds): void
    {
        $window = intdiv(time(), max(1, $perSeconds));
        $cacheKey = "channel-rate:{$key}:{$window}";

        try {
            Cache::add($cacheKey, 0, $perSeconds + 1);
            $count = (int) Cache::increment($cacheKey);
        } catch (\Throwable) {
            return;
        }
        if ($count <= $max) {
            return;
        }

        $wait = ($window + 1) * $perSeconds - time();
        $wait = max(1, $wait);
        if ($wait > (int) config('sales_channels.http.max_wait_seconds', 3)) {
            throw new ChannelRateLimitedException($wait);
        }
        Sleep::for($wait)->seconds();
    }
}
