<?php

namespace App\Services\PlatformAdmin;

use App\Models\PlatformAdminGrant;

/**
 * The markets the platform admin API reports on: every country of the storefront configuration, in the order the configuration lists
 * them, whether its storefront is open or not. Read from the configuration directly, so a market is reported even while storefronts are
 * switched off and the storefront resolver would serve the default one.
 */
final class PlatformMarkets
{
    private const FALLBACK_DEFAULT_COUNTRY = 'SA';

    /** @return array<string, array{code: string, path: ?string, currency: ?string, active: bool}> keyed by country code */
    public function all(): array
    {
        $markets = [];

        foreach ((array) config('gcc.storefronts.countries', []) as $code => $entry) {
            $code = strtoupper(trim((string) $code));

            if (preg_match('/^[A-Z]{2}$/', $code) !== 1 || !is_array($entry)) {
                continue;
            }

            $markets[$code] = [
                'code' => $code,
                'path' => is_string($entry['path'] ?? null) ? $entry['path'] : null,
                'currency' => is_string($entry['currency'] ?? null) ? $entry['currency'] : null,
                'active' => ($entry['active'] ?? false) === true,
            ];
        }

        return $markets;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->all());
    }

    /** The code of a configured market however it was typed, or null when it is not one. */
    public function normalise(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $code = strtoupper(trim($value));

        return in_array($code, $this->codes(), true) ? $code : null;
    }

    /**
     * The markets a grant lets its admin see: every configured market for the platform owner, and for anyone else the configured markets
     * their grant names, in the order of the configuration. A code the grant names that is not configured opens nothing.
     *
     * @return list<string>
     */
    public function allowedBy(PlatformAdminGrant $grant): array
    {
        $scope = $grant->countryList();

        return $scope === null ? $this->codes() : array_values(array_intersect($this->codes(), $scope));
    }

    public function defaultCountry(): string
    {
        $code = strtoupper(trim((string) config('gcc.storefronts.default_country')));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : self::FALLBACK_DEFAULT_COUNTRY;
    }

    public function storefrontsEnabled(): bool
    {
        return config('gcc.storefronts.enabled') === true;
    }

    public function legalEntity(): ?string
    {
        $entity = config('gcc.storefronts.legal.legal_entity');

        return is_string($entity) && trim($entity) !== '' ? $entity : null;
    }
}
