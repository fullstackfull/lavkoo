<?php

namespace App\Services\PlatformAdmin;

use App\Enums\PlatformAdmin\MfaMethod;
use Carbon\CarbonInterface;

/**
 * How one try at a step-up ended: accepted, with the moment the proof stops being fresh; throttled, because the admin is locked out and the code was
 * not looked at; or refused, which is everything else and tells the caller nothing more about which part was wrong.
 */
final readonly class StepUpOutcome
{
    private function __construct(
        public bool $accepted = false,
        public ?CarbonInterface $freshUntil = null,
        public ?MfaMethod $method = null,
        public ?int $retryAfterSeconds = null,
    ) {
    }

    public static function accepted(?CarbonInterface $freshUntil, ?MfaMethod $method): self
    {
        return new self(accepted: true, freshUntil: $freshUntil, method: $method);
    }

    public static function throttled(int $retryAfterSeconds): self
    {
        return new self(retryAfterSeconds: $retryAfterSeconds);
    }

    public static function refused(): self
    {
        return new self();
    }
}
