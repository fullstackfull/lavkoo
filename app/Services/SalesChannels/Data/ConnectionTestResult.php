<?php

namespace App\Services\SalesChannels\Data;

use App\Enums\SalesChannels\ConnectionStatus;

/** The outcome of proving that an account's credentials work, and what they are allowed to do. A test only reads: it creates and changes nothing at the channel. */
final readonly class ConnectionTestResult
{
    /**
     * @param list<string> $granted what the credentials were found able to do
     * @param list<string> $missing what the account needs for its switched-on capabilities and does not have
     * @param array<string, mixed> $details non-secret facts worth showing (the seller's name, the marketplaces it sells in)
     */
    public function __construct(
        public ConnectionStatus $status,
        public string $message,
        public array $granted = [],
        public array $missing = [],
        public array $details = [],
        public ?string $externalAccountId = null,
    ) {
    }

    public function ok(): bool
    {
        return $this->status === ConnectionStatus::Ok;
    }

    public static function failed(string $message, ConnectionStatus $status = ConnectionStatus::Failed): self
    {
        return new self($status, $message);
    }
}
