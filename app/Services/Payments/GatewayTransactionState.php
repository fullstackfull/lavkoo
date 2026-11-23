<?php

namespace App\Services\Payments;

/**
 * What a gateway itself says about one of its transactions, obtained by the server (D2-11).
 *
 * Three outcomes, not two. `paid` is a confirmation; `refused` is the gateway saying the money did not move; `unknown` is everything else — a timeout,
 * a malformed answer, a transaction the gateway has no record of. An unknown outcome settles nothing AND fails nothing: telling a paying customer their
 * payment failed because a connection dropped is the worse of the two mistakes, and it is the mistake this project already refuses to make for
 * MyFatoorah and Paymera.
 */
final readonly class GatewayTransactionState
{
    public const PAID = 'paid';
    public const REFUSED = 'refused';
    public const UNKNOWN = 'unknown';

    private function __construct(
        public string $outcome,
        public ?string $reference = null,
        public mixed $minorUnits = null,
        public ?string $currency = null,
    ) {
    }

    public static function paid(string $reference, mixed $minorUnits, string $currency): self
    {
        return new self(outcome: self::PAID, reference: $reference, minorUnits: $minorUnits, currency: $currency);
    }

    public static function refused(?string $reference = null): self
    {
        return new self(outcome: self::REFUSED, reference: $reference);
    }

    public static function unknown(): self
    {
        return new self(outcome: self::UNKNOWN);
    }

    public function isPaid(): bool
    {
        return $this->outcome === self::PAID;
    }

    public function isRefused(): bool
    {
        return $this->outcome === self::REFUSED;
    }

    public function isUnknown(): bool
    {
        return $this->outcome === self::UNKNOWN;
    }
}
