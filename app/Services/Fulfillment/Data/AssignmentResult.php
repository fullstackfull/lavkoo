<?php

namespace App\Services\Fulfillment\Data;

final readonly class AssignmentResult
{
    private function __construct(
        public bool $ok,
        public ?string $reasonKey,
        public ?string $detail,
    ) {
    }

    public static function ok(): self
    {
        return new self(true, null, null);
    }

    public static function refused(string $reasonKey, ?string $detail = null): self
    {
        return new self(false, $reasonKey, $detail);
    }

    public function message(): string
    {
        return translate($this->reasonKey ?? 'ok') . ($this->detail ? ' (' . $this->detail . ')' : '');
    }
}
