<?php

namespace App\Services\Fulfillment\Data;

/**
 * The right to ask a provider to cancel one shipment, given to one caller at a time.
 *
 * When refused, `reason` says why the caller must not call: it is also the answer handed back to whoever asked.
 */
final readonly class CancelLease
{
    public const ALREADY_REQUESTED = 'already_requested';
    public const BLOCKED = 'blocked';
    public const WAITING = 'attempt_in_progress_or_waiting';
    public const NOT_APPLICABLE = 'noop';

    private function __construct(
        public bool $granted,
        public int $attempts = 0,
        public ?string $reason = null,
    ) {
    }

    public static function granted(int $attempts): self
    {
        return new self(granted: true, attempts: $attempts);
    }

    public static function refused(string $reason): self
    {
        return new self(granted: false, reason: $reason);
    }
}
