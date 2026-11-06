<?php

namespace App\Services\SalesChannels\Support;

use App\Services\SalesChannels\Data\ChannelResponse;
use App\Services\SalesChannels\Exceptions\ChannelRateLimitedException;
use RuntimeException;

/** Turns a failed read into the exception the sync runner understands: a rate limit pauses the work, anything else is a failure with the channel's own reason. */
final class ChannelResponseGuard
{
    /**
     * @throws ChannelRateLimitedException
     * @throws RuntimeException
     */
    public static function assertOk(ChannelResponse $response, string $what, string $channelName): void
    {
        if ($response->ok()) {
            return;
        }
        if ($response->kind === ChannelResponse::RATE_LIMITED) {
            throw new ChannelRateLimitedException($response->retryAfterSeconds() ?? 60, "{$what}: {$channelName} asked us to slow down.");
        }

        throw new RuntimeException("{$what} failed (HTTP {$response->status}): " . $response->message());
    }
}
