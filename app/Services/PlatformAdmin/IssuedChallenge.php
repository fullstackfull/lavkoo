<?php

namespace App\Services\PlatformAdmin;

use Carbon\CarbonInterface;

/** A challenge that was just given: the plain value, which exists here and in the answer of the first step and nowhere else, and when it is over. */
final readonly class IssuedChallenge
{
    public function __construct(public string $plain, public CarbonInterface $expiresAt)
    {
    }
}
