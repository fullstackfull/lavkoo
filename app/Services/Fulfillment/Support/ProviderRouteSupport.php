<?php

namespace App\Services\Fulfillment\Support;

use App\Services\Storefront\StorefrontResolver;

/**
 * The source-to-destination country routes a provider serves, and whether cash on delivery works on them (H1-02).
 *
 * Nothing compared an order's destination with the provider's own coverage: the preflight checked the mapping, the stock, the address, the
 * phone, the city and the cash-on-delivery setting, and a shipment to a country the carrier does not serve would have been sent and then
 * refused by the carrier -- after the customer paid. A route the operator has not declared is NOT served, which is the only safe default
 * for a fact that lives in a courier contract and in no repository: declaring coverage the carrier does not have is how a parcel is sold
 * and never delivered.
 *
 * **It ships declaring exactly today's platform.** Each provider's `routes` is a list of `SOURCE:DESTINATION` pairs; the default is the
 * Saudi route each provider already serves, so a Saudi shipment is unchanged and no other route is served until somebody writes the
 * carrier's real coverage down. `*` on either side of a pair means "any country", for an operator who has one carrier covering everything
 * and would rather say so than list thirty-six pairs.
 *
 * Which carrier really serves which country, under which contract and company id, is the owner's question Q-33: this holds the answer, it
 * does not invent one.
 */
final class ProviderRouteSupport
{
    private const ANY = '*';

    /** A provider with no declaration at all serves the platform's own market, which is what every provider does today. */
    public static function routesOf(string $providerCode): array
    {
        $declared = config("fulfillment.providers.{$providerCode}.routes");

        if (!is_array($declared)) {
            return [self::defaultRoute()];
        }

        $routes = [];
        foreach ($declared as $route) {
            $pair = self::pair($route);
            if ($pair !== null) {
                $routes[] = $pair;
            }
        }

        return $routes;
    }

    /** Whether this provider serves this route. A source or a destination nobody named is not served. */
    public static function serves(string $providerCode, ?string $source, ?string $destination): bool
    {
        $from = self::country($source);
        $to = self::country($destination);

        if ($from === null || $to === null) {
            return false;
        }

        foreach (self::routesOf($providerCode) as [$declaredFrom, $declaredTo]) {
            if (self::matches($declaredFrom, $from) && self::matches($declaredTo, $to)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether cash on delivery works on this route.
     *
     * Collecting cash abroad is a separate arrangement from carrying a parcel abroad, so it is declared separately and refused by default
     * on any route that is not the provider's own domestic one: a courier that cannot bank the cash in the destination country will carry
     * the parcel and hand the money back to nobody. A provider that says nothing allows it on a route whose source and destination are the
     * same country, which is the Saudi arrangement that runs today.
     */
    public static function collectsCashOn(string $providerCode, ?string $source, ?string $destination): bool
    {
        if (!self::serves($providerCode, $source, $destination)) {
            return false;
        }

        $from = self::country($source);
        $to = self::country($destination);
        $declared = config("fulfillment.providers.{$providerCode}.cash_on_delivery_routes");

        if (!is_array($declared)) {
            return $from === $to;
        }

        foreach ($declared as $route) {
            $pair = self::pair($route);
            if ($pair !== null && self::matches($pair[0], (string) $from) && self::matches($pair[1], (string) $to)) {
                return true;
            }
        }

        return false;
    }

    /** Whether a route leaves the country it starts in, which is what makes an order cross-border (H1-06 reads this). */
    public static function isCrossBorder(?string $source, ?string $destination): bool
    {
        $from = self::country($source);
        $to = self::country($destination);

        return $from !== null && $to !== null && $from !== $to;
    }

    /** @return array{0: string, 1: string}|null the pair as `FROM:TO`, or null when it is not one */
    private static function pair(mixed $route): ?array
    {
        if (!is_string($route)) {
            return null;
        }

        $parts = explode(':', strtoupper(trim($route)));

        if (count($parts) !== 2) {
            return null;
        }

        [$from, $to] = array_map('trim', $parts);

        return $from === '' || $to === '' ? null : [$from, $to];
    }

    private static function matches(string $declared, string $country): bool
    {
        return $declared === self::ANY || $declared === $country;
    }

    /** A country code as the platform writes them, or null when there is none to compare. */
    private static function country(?string $code): ?string
    {
        $country = strtoupper(trim((string) $code));

        return $country === '' ? null : $country;
    }

    /** @return array{0: string, 1: string} */
    private static function defaultRoute(): array
    {
        $home = StorefrontResolver::fromConfig()->defaultCountry();

        return [$home, $home];
    }
}
