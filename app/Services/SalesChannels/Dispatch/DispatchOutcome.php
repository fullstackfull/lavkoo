<?php

namespace App\Services\SalesChannels\Dispatch;

/** What one attempt to turn a channel order into a provider shipment came to. */
final readonly class DispatchOutcome
{
    public const MATERIALIZED = 'materialized';
    public const ALREADY_DONE = 'already_done';
    public const BLOCKED = 'blocked';
    public const BUSY = 'busy';
    public const WAITING = 'waiting';

    /** @param list<string> $blockers */
    private function __construct(public string $state, public ?string $reason = null, public array $blockers = [], public ?int $internalOrderId = null, public ?string $provider = null)
    {
    }

    public static function materialized(int $internalOrderId, string $provider): self
    {
        return new self(self::MATERIALIZED, internalOrderId: $internalOrderId, provider: $provider);
    }

    public static function alreadyDone(int $internalOrderId): self
    {
        return new self(self::ALREADY_DONE, internalOrderId: $internalOrderId);
    }

    /** @param list<string> $blockers */
    public static function blocked(string $reason, array $blockers = []): self
    {
        return new self(self::BLOCKED, $reason, $blockers === [] ? [$reason] : $blockers);
    }

    public static function busy(): self
    {
        return new self(self::BUSY);
    }

    public static function waiting(string $reason): self
    {
        return new self(self::WAITING, $reason);
    }

    public function done(): bool
    {
        return in_array($this->state, [self::MATERIALIZED, self::ALREADY_DONE], true);
    }
}
