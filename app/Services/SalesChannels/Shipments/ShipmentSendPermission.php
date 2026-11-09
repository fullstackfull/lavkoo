<?php

namespace App\Services\SalesChannels\Shipments;

/**
 * Whether this process may go on with telling a channel that goods shipped, decided by `ChannelShipmentSends` from the one unique row for the shipment.
 *
 * Three answers, and the third carries the thing that matters most: whether a send for this exact shipment MAY ALREADY HAVE LEFT. A fresh claim may send once the channel
 * says it holds no such shipment; a claim that follows an unsettled send may only ask, never send.
 */
final readonly class ShipmentSendPermission
{
    public const GO = 'go';

    /** Another process owns this shipment reference right now. Nothing is sent, and nothing needs a person: the other attempt is doing it. */
    public const BUSY = 'busy';

    /** The channel already reported this shipment. Terminal, and nothing is asked again. */
    public const CONFIRMED = 'confirmed';

    private function __construct(
        public string $state,
        public ?string $token = null,
        public bool $sendMayHaveLeft = false,
        public ?string $reason = null,
    ) {
    }

    public static function go(string $token, bool $sendMayHaveLeft, ?string $reason = null): self
    {
        return new self(self::GO, $token, $sendMayHaveLeft, $reason);
    }

    public static function busy(): self
    {
        return new self(self::BUSY);
    }

    public static function confirmed(): self
    {
        return new self(self::CONFIRMED);
    }

    public function mayGoOn(): bool
    {
        return $this->state === self::GO;
    }

    public function isBusy(): bool
    {
        return $this->state === self::BUSY;
    }

    public function isConfirmed(): bool
    {
        return $this->state === self::CONFIRMED;
    }

    /** May this process SEND, given the channel itself says it holds no such shipment? Only when no earlier send is unaccounted for. */
    public function maySend(): bool
    {
        return $this->mayGoOn() && !$this->sendMayHaveLeft;
    }
}
