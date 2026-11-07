<?php

namespace App\Services\Address;

/**
 * What the delivery restriction page tells the administrator about the unified address.
 *
 * A ZIP allow-list cannot be evaluated for an address that carries only a National Address, because the ZIP is never derived from it. So
 * with the restriction on, the structured address cannot be switched on and, if it was already chosen, it is paused: the store keeps the
 * old address with its ZIP code until the restriction is turned off. The page says so, and shows how many saved Saudi addresses have no
 * ZIP. How the restrictions work is not changed here, and before the address migrations have run this adds nothing to the page.
 */
class DeliveryRestrictionNotice
{
    public const LEVEL_WARNING = 'warning';

    public const LEVEL_INFO = 'info';

    public function __construct(private readonly AddressModeGate $gate, private readonly SavedSaudiAddresses $addresses)
    {
    }

    /** @return array{level: string, zip_restriction: bool, mode_chosen: string, mode_in_force: string, saudi_without_zip: int}|null null when the page needs no word about it */
    public function forPage(): ?array
    {
        if (!AddressSchema::ready()) {
            return null;
        }

        $zipRestriction = $this->gate->zipRestrictionOn();
        $modeInForce = AddressSchema::mode();
        if (!$zipRestriction && $modeInForce === AddressSchema::MODE_OFF) {
            return null;
        }

        return [
            'level' => $zipRestriction ? self::LEVEL_WARNING : self::LEVEL_INFO,
            'zip_restriction' => $zipRestriction,
            'mode_chosen' => AddressSchema::configuredMode(),
            'mode_in_force' => $modeInForce,
            'saudi_without_zip' => $this->addresses->withoutZip()->count(),
        ];
    }
}
