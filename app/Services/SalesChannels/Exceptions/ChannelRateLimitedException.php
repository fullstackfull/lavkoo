<?php

namespace App\Services\SalesChannels\Exceptions;

use RuntimeException;

/** Our own limiter, or the channel's, says wait: nothing was sent (or the channel refused it). The caller retries later; `retryAfterSeconds` says when. */
class ChannelRateLimitedException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds, string $message = 'The channel rate limit was reached.')
    {
        parent::__construct($message);
    }
}
