<?php

namespace App\Services\Fulfillment\Data;

/**
 * `requested` is the most a cancel call can claim: the provider answered successfully, but a
 * cancellation is only real once the provider reports the shipment as cancelled (webhook or status poll).
 */
final readonly class CancellationResult
{
    public const REQUESTED = 'requested';
    public const REFUSED = 'refused';
    public const UNSUPPORTED = 'unsupported';
    public const FAILED = 'failed';

    private function __construct(
        public string $outcome,
        public ?string $message = null,
    ) {
    }

    public static function requested(?string $message = null): self
    {
        return new self(self::REQUESTED, $message);
    }

    public static function refused(string $message): self
    {
        return new self(self::REFUSED, $message);
    }

    public static function unsupported(string $message): self
    {
        return new self(self::UNSUPPORTED, $message);
    }

    public static function failed(string $message): self
    {
        return new self(self::FAILED, $message);
    }
}
