<?php

namespace App\Services\Address;

use App\Services\AuditLogger;
use InvalidArgumentException;

/**
 * Decides whether the store may start asking for the structured address, and records who changed the mode.
 *
 * Turning it off is always allowed. Turning it on (soft or strict) is refused while it could lock customers out or be wrong:
 *
 *   - the migrations have not run, so there is nowhere to keep a region or city;
 *   - no active city exists, so nobody could finish an address;
 *   - the ZIP-code delivery restriction is on. A ZIP allow-list cannot be evaluated for an address that carries only a National Address
 *     (the ZIP is never derived from it), so while it is on the store keeps the old address with its ZIP code (AddressSchema::mode() is
 *     off). The administrator turns that restriction off first (stage S1 of docs/address/zip-compatibility.md).
 */
class AddressModeGate
{
    public const ZIP_RESTRICTION_SETTING = AddressSchema::ZIP_RESTRICTION_SETTING;

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /** Is the ZIP-code delivery restriction switched on? Read the way the checkout and the account pages read it. */
    public function zipRestrictionOn(): bool
    {
        return AddressSchema::zipRestrictionOn();
    }

    /** @return list<string> translation keys of what keeps the store from using the mode; empty when it may */
    public function problems(string $mode): array
    {
        if ($mode === AddressSchema::MODE_OFF) {
            return [];
        }

        AddressSchema::flush();
        if (!AddressSchema::ready()) {
            return ['address_mode_blocked_not_migrated'];
        }

        $problems = [];
        if (!AddressSchema::hasCities()) {
            $problems[] = 'address_mode_blocked_no_cities';
        }
        if ($this->zipRestrictionOn()) {
            $problems[] = 'address_mode_blocked_zip_restriction';
        }

        return $problems;
    }

    /**
     * Changes the mode when the gate allows it.
     *
     * @return list<string> what stopped it (nothing changed), or an empty list when the mode is now the one asked for
     */
    public function switchTo(string $mode): array
    {
        if (!in_array($mode, AddressSchema::MODES, true)) {
            throw new InvalidArgumentException('Unknown address mode.');
        }

        $problems = $this->problems($mode);
        if ($problems !== []) {
            return $problems;
        }

        $before = AddressSchema::configuredMode();
        if ($before === $mode) {
            return [];
        }

        AddressSchema::setMode($mode);
        AddressSchema::flush();
        $this->audit->record(action: 'address.mode_changed', before: ['mode' => $before], after: ['mode' => $mode]);

        return [];
    }
}
