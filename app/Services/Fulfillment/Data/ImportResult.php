<?php

namespace App\Services\Fulfillment\Data;

final readonly class ImportResult
{
    public const IMPORTED = 'imported';
    public const UPDATED = 'updated';
    public const NEEDS_REVIEW = 'needs_review';
    public const BLOCKED = 'blocked';

    private function __construct(
        public string $outcome,
        public ?int $productId,
        public ?string $reason,
    ) {
    }

    public static function imported(int $productId): self
    {
        return new self(self::IMPORTED, $productId, null);
    }

    public static function updated(int $productId): self
    {
        return new self(self::UPDATED, $productId, null);
    }

    public static function needsReview(string $reason): self
    {
        return new self(self::NEEDS_REVIEW, null, $reason);
    }

    public static function blocked(string $reason): self
    {
        return new self(self::BLOCKED, null, $reason);
    }

    public function succeeded(): bool
    {
        return in_array($this->outcome, [self::IMPORTED, self::UPDATED], true);
    }
}
