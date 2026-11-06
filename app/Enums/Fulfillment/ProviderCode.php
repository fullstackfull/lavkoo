<?php

namespace App\Enums\Fulfillment;

/**
 * The fulfillment owners a product can have. A product with no provider (NULL) is "in-house / default":
 * it follows the platform's existing order flow and is never touched by this integration.
 */
enum ProviderCode: string
{
    case M5azn = 'm5azn';
    case TorodPoint = 'torod_point';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $code): string => $code->value, self::cases());
    }

    /** URL form of a provider code: 'torod_point' ⇄ 'torod-point'. */
    public static function fromSlug(string $slug): ?self
    {
        return self::tryFrom(str_replace('-', '_', $slug));
    }

    public function slug(): string
    {
        return str_replace('_', '-', $this->value);
    }

    public static function isKnown(?string $value): bool
    {
        return $value !== null && self::tryFrom($value) !== null;
    }

    /** Short, stable code used in fulfillment references (order 100234 → "100234-MZ1"). */
    public function referenceCode(): string
    {
        return match ($this) {
            self::M5azn => 'MZ',
            self::TorodPoint => 'TP',
        };
    }

    public function label(): string
    {
        return (string) config('fulfillment.providers.' . $this->value . '.label', $this->value);
    }
}
