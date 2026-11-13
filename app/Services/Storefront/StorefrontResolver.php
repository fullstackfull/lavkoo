<?php

namespace App\Services\Storefront;

use InvalidArgumentException;

/**
 * Chooses the storefront country for a request. A choice the visitor made explicitly wins over the path prefix, and the path
 * prefix wins over the default. While storefronts are switched off, every request resolves to the default country, which is the
 * Saudi storefront the platform runs today. A country that is configured but not active is never chosen.
 */
final class StorefrontResolver
{
    private readonly string $defaultCountry;

    /**
     * @param array<string, array{path: string, currency: string, active?: bool}> $countries keyed by ISO country code
     */
    public function __construct(
        private readonly bool $enabled,
        string $defaultCountry,
        private readonly array $countries,
    ) {
        $this->defaultCountry = $this->requireCountry($defaultCountry);

        if (!$this->isActive($this->defaultCountry)) {
            throw new InvalidArgumentException("Default storefront country [{$defaultCountry}] is not active.");
        }
    }

    public static function fromConfig(): self
    {
        return new self(
            enabled: (bool) config('gcc.storefronts.enabled'),
            defaultCountry: (string) config('gcc.storefronts.default_country'),
            countries: (array) config('gcc.storefronts.countries'),
        );
    }

    public function resolve(?string $explicitChoice = null, ?string $path = null): string
    {
        if (!$this->enabled) {
            return $this->defaultCountry;
        }

        return $this->countryOfChoice($explicitChoice)
            ?? $this->countryOfPath($path)
            ?? $this->defaultCountry;
    }

    public function context(?string $explicitChoice = null, ?string $path = null): StorefrontContext
    {
        $country = $this->resolve(explicitChoice: $explicitChoice, path: $path);

        return new StorefrontContext(country: $country, currency: $this->currencyOf($country), storefrontsEnabled: $this->enabled);
    }

    public function currencyOf(string $countryCode): string
    {
        return $this->countries[$this->requireCountry($countryCode)]['currency'];
    }

    private function countryOfChoice(?string $explicitChoice): ?string
    {
        $code = strtoupper(trim((string) $explicitChoice));

        return isset($this->countries[$code]) && $this->isActive($code) ? $code : null;
    }

    private function countryOfPath(?string $path): ?string
    {
        $firstSegment = strtolower((string) strtok(trim((string) $path, '/'), '/'));

        foreach ($this->countries as $code => $country) {
            if ($firstSegment !== '' && $country['path'] === $firstSegment && $this->isActive($code)) {
                return $code;
            }
        }

        return null;
    }

    private function isActive(string $countryCode): bool
    {
        return ($this->countries[$countryCode]['active'] ?? false) === true;
    }

    private function requireCountry(string $countryCode): string
    {
        $code = strtoupper(trim($countryCode));

        if (!isset($this->countries[$code])) {
            throw new InvalidArgumentException("Storefront country [{$countryCode}] is not configured.");
        }

        return $code;
    }
}
