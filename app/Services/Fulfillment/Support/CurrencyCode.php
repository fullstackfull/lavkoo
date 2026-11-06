<?php

namespace App\Services\Fulfillment\Support;

/**
 * The ISO code of a currency, whatever the store's currency table calls it.
 *
 * This platform's Saudi store keeps its riyal as "KSA" (a country code), and providers know it as "SAR". The two are one currency, so
 * the store's spelling is mapped to the ISO code (config `fulfillment.currency_aliases`) wherever the integration compares codes,
 * and a provider is never sent anything but the ISO code. The map is read-only: no row of the currency table is touched.
 */
final class CurrencyCode
{
    /** The ISO code for a code as the store spells it, or null when there is no code at all. */
    public static function canonical(mixed $code): ?string
    {
        if (!is_string($code) && !is_int($code)) {
            return null;
        }

        $normal = strtoupper(trim((string) $code));

        return $normal === '' ? null : (self::aliases()[$normal] ?? $normal);
    }

    /** The code providers are paid and collect in (FULFILLMENT_CURRENCY), as an ISO code even if the setting uses the store's spelling. */
    public static function provider(): string
    {
        return self::canonical(config('fulfillment.currency', 'SAR')) ?? 'SAR';
    }

    /**
     * Every spelling the store's currency table may use for this currency, the ISO code first.
     *
     * @return list<string>
     */
    public static function spellings(string $code): array
    {
        $canonical = self::canonical($code) ?? strtoupper(trim($code));
        $spellings = [$canonical];
        foreach (self::aliases() as $alias => $iso) {
            if ($iso === $canonical) {
                $spellings[] = $alias;
            }
        }

        return array_values(array_unique($spellings));
    }

    /** @return array<string, string> store spelling => ISO code, both upper-cased; a code that maps to itself, or to another spelling, is not an alias */
    private static function aliases(): array
    {
        $aliases = [];
        foreach ((array) config('fulfillment.currency_aliases', []) as $alias => $iso) {
            if (!is_string($iso) && !is_int($iso)) {
                continue;
            }
            $alias = strtoupper(trim((string) $alias));
            $iso = strtoupper(trim((string) $iso));
            if ($alias !== '' && $iso !== '' && $alias !== $iso) {
                $aliases[$alias] = $iso;
            }
        }

        // A code that is both a spelling and the thing a spelling stands for would make the map loop or chain: neither is a spelling of the other.
        return array_filter($aliases, static fn (string $iso): bool => !array_key_exists($iso, $aliases));
    }
}
