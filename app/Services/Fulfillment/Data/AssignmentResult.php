<?php

namespace App\Services\Fulfillment\Data;

final readonly class AssignmentResult
{
    /**
     * @param int $keptWithTheOldProvider order lines that were already placed and stay with the provider they were placed with: the change is for what comes next
     * @param int $stockRewrittenFrom units the new provider's own stored figure put on the storefront's stock, or -1 when there was no figure to read
     */
    private function __construct(
        public bool $ok,
        public ?string $reasonKey,
        public ?string $detail,
        public int $keptWithTheOldProvider = 0,
        public int $stockRewrittenFrom = -1,
    ) {
    }

    public static function ok(int $keptWithTheOldProvider = 0, int $stockRewrittenFrom = -1): self
    {
        return new self(true, null, null, $keptWithTheOldProvider, $stockRewrittenFrom);
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
