<?php

namespace App\Services\Storefront;

use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Chooses the storefront country for a request. A choice the visitor made explicitly wins over the header an app sends, the
 * header wins over the path prefix, and the path prefix wins over the default. While storefronts are switched off, every request resolves to the default country, which is the
 * Saudi storefront the platform runs today. A country that is configured but not active is never chosen.
 */
final class StorefrontResolver
{
    public const HEADER = 'X-Storefront';

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

    public function resolve(?string $explicitChoice = null, ?string $path = null, ?string $headerChoice = null): string
    {
        if (!$this->enabled) {
            return $this->defaultCountry;
        }

        return $this->countryOfChoice($explicitChoice)
            ?? $this->countryOfChoice($headerChoice)
            ?? $this->countryOfPath($path)
            ?? $this->defaultCountry;
    }

    public function context(?string $explicitChoice = null, ?string $path = null, ?string $headerChoice = null): StorefrontContext
    {
        $country = $this->resolve(explicitChoice: $explicitChoice, path: $path, headerChoice: $headerChoice);

        return new StorefrontContext(country: $country, currency: $this->currencyOf($country), storefrontsEnabled: $this->enabled);
    }

    /** The storefront a request asks for: its explicit choice, then its header, then its path, then the default. */
    public function contextForRequest(Request $request): StorefrontContext
    {
        $explicitChoice = $request->query('storefront');
        $headerValues = $request->headers->all(self::HEADER);

        return $this->context(
            explicitChoice: is_string($explicitChoice) ? $explicitChoice : null,
            path: $request->path(),
            headerChoice: count($headerValues) === 1 && is_string($headerValues[0]) ? $headerValues[0] : null,
        );
    }

    /**
     * The context of a country this platform recorded itself, active or not. A visitor can only reach an active storefront, but a payment
     * that was taken there has to be finished there, even if the storefront was switched off in the meantime. Null for a code that is not configured.
     */
    public function contextOfRecordedCountry(?string $countryCode): ?StorefrontContext
    {
        $code = strtoupper(trim((string) $countryCode));

        if (!isset($this->countries[$code])) {
            return null;
        }

        return new StorefrontContext(country: $code, currency: $this->currencyOf($code), storefrontsEnabled: $this->enabled);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function defaultCountry(): string
    {
        return $this->defaultCountry;
    }

    /** Every configured country, active or not: invalidation has to reach a storefront that was switched off after its entries were cached. */
    public function configuredCountries(): array
    {
        return array_keys($this->countries);
    }

    /**
     * While storefronts are switched off only the default storefront is listed, because it is the only one a request can reach.
     *
     * @return list<array{country: string, path: string, currency: string, is_default: bool}>
     */
    public function activeStorefronts(): array
    {
        $listedCountries = $this->enabled
            ? array_keys(array_filter($this->countries, fn (string $code) => $this->isActive($code), ARRAY_FILTER_USE_KEY))
            : [$this->defaultCountry];

        return array_map(fn (string $code) => [
            'country' => $code,
            'path' => $this->countries[$code]['path'],
            'currency' => $this->countries[$code]['currency'],
            'is_default' => $code === $this->defaultCountry,
        ], $listedCountries);
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
