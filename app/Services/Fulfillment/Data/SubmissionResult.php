<?php

namespace App\Services\Fulfillment\Data;

/**
 * The honest outcome of asking a provider to accept a fulfillment.
 *
 *  - accepted   the provider verifiably holds the order (a reference came back)
 *  - rejected   the provider definitively refused it; retrying the same payload will not help
 *  - retryable  the provider did not take the order and we know it (rate limit, refused connection)
 *  - ambiguous  we cannot tell — the request may or may not have been accepted. NEVER resend blindly.
 *  - dry_run    nothing was sent; the payload was built and validated only
 */
final readonly class SubmissionResult
{
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const RETRYABLE = 'retryable';
    public const AMBIGUOUS = 'ambiguous';
    public const DRY_RUN = 'dry_run';

    /** @param array<string, mixed> $meta */
    private function __construct(
        public string $outcome,
        public ?string $providerReference = null,
        public ?string $providerInternalId = null,
        public ?string $secondaryReference = null,
        public ?string $trackingNumber = null,
        public ?string $trackingUrl = null,
        public ?string $providerStatus = null,
        public ?float $providerCost = null,
        public ?string $errorCode = null,
        public ?string $message = null,
        public array $meta = [],
    ) {
    }

    /** @param array<string, mixed> $meta */
    public static function accepted(
        string $providerReference,
        ?string $providerInternalId = null,
        ?string $secondaryReference = null,
        ?string $trackingNumber = null,
        ?string $trackingUrl = null,
        ?string $providerStatus = null,
        ?float $providerCost = null,
        array $meta = [],
    ): self {
        return new self(
            outcome: self::ACCEPTED,
            providerReference: $providerReference,
            providerInternalId: $providerInternalId,
            secondaryReference: $secondaryReference,
            trackingNumber: $trackingNumber,
            trackingUrl: $trackingUrl,
            providerStatus: $providerStatus,
            providerCost: $providerCost,
            meta: $meta,
        );
    }

    public static function rejected(string $errorCode, string $message): self
    {
        return new self(outcome: self::REJECTED, errorCode: $errorCode, message: $message);
    }

    public static function retryable(string $errorCode, string $message): self
    {
        return new self(outcome: self::RETRYABLE, errorCode: $errorCode, message: $message);
    }

    public static function ambiguous(string $errorCode, string $message): self
    {
        return new self(outcome: self::AMBIGUOUS, errorCode: $errorCode, message: $message);
    }

    /** @param array<string, mixed> $meta */
    public static function dryRun(array $meta = []): self
    {
        return new self(outcome: self::DRY_RUN, message: 'dry_run', meta: $meta);
    }

    public function isAccepted(): bool
    {
        return $this->outcome === self::ACCEPTED;
    }
}
