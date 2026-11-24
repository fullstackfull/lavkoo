<?php

namespace App\Services\Shipping;

/**
 * What the shipping engine answered about one basket and one destination (H1-06): whether the order is local or crosses a border, whether
 * it may proceed, and every reason it may not.
 *
 * Every reason, not the first one: a customer told one of three problems fixes it and is refused again, and an operator reading a held
 * order wants the whole list. The reasons are translation keys, so the same decision reads in Arabic and in English.
 */
final readonly class EligibilityDecision
{
    public const LOCAL = 'local';

    public const CROSS_BORDER = 'cross_border';

    /** @param list<string> $reasons translation keys, empty when the order may proceed */
    public function __construct(
        public string $kind,
        public bool $allowed,
        public array $reasons = [],
    ) {
    }

    /** An order that stays inside one country and may proceed, which is every order this platform has ever taken. */
    public static function local(): self
    {
        return new self(kind: self::LOCAL, allowed: true);
    }

    /** @param list<string> $reasons */
    public static function refused(string $kind, array $reasons): self
    {
        return new self(kind: $kind, allowed: false, reasons: array_values(array_unique($reasons)));
    }

    public function isCrossBorder(): bool
    {
        return $this->kind === self::CROSS_BORDER;
    }

    /** The decision as it is stored on an order, so that what was decided stays decided when the settings change later. */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'allowed' => $this->allowed, 'reasons' => $this->reasons];
    }
}
