<?php

namespace App\Services\Fulfillment\Data;

final readonly class PreflightResult
{
    private function __construct(
        public bool $ok,
        public ?string $holdReason,
        public ?string $detail,
    ) {
    }

    public static function passed(): self
    {
        return new self(true, null, null);
    }

    public static function hold(string $holdReason, ?string $detail = null): self
    {
        return new self(false, $holdReason, $detail);
    }
}
