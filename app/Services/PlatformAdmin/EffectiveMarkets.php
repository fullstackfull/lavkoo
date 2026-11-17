<?php

namespace App\Services\PlatformAdmin;

/**
 * The markets one admin reaches through the platform admin API: what their grant names, what the staff country scope (GCC-14c) leaves of it, and the countries that scope
 * restricts them to, if it restricts them at all.
 */
final readonly class EffectiveMarkets
{
    /**
     * @param list<string> $granted the markets the grant names, in the order of the configuration (every market for the platform owner)
     * @param list<string> $markets the markets of the grant that the staff scope leaves them, in the same order
     * @param list<string>|null $staffCountries the countries the staff scope restricts them to (none at all is an empty list), or null when it does not restrict them
     */
    public function __construct(public array $granted, public array $markets, public ?array $staffCountries)
    {
    }

    /** Whether the admin is restricted in the admin panel, and so is no platform owner here whatever countries their grant names. */
    public function restrictedByStaffScope(): bool
    {
        return $this->staffCountries !== null;
    }

    /** Whether the staff scope takes a market away that the grant gave. */
    public function narrowsTheGrant(): bool
    {
        return $this->markets !== $this->granted;
    }
}
