<?php

namespace App\Services\Address;

use App\Enums\Address\AddressChannel;
use App\Services\ShippingAddressService;
use Illuminate\Validation\ValidationException;

/**
 * May this saved address be used for an order? An old Saudi address (a free-text city, or no National Address) is kept in the address
 * book untouched but must be completed before it ships again; the mobile app and other older clients are let through in `soft`
 * mode, and their orders wait for review instead of failing at the till.
 */
class CheckoutAddressGuard
{
    public function __construct(private readonly ShippingAddressService $addresses, private readonly AddressCatalog $catalog)
    {
    }

    /**
     * @param object|array<string, mixed> $address the saved address (a row, a model or an array)
     * @return list<string> translation keys of what is wrong; empty when the address may be used
     */
    public function problems(object|array $address, AddressChannel $channel): array
    {
        $country = is_array($address) ? ($address['country'] ?? null) : ($address->country ?? null);
        if (!$this->addresses->requiresStructured($country, $channel)) {
            return [];
        }

        $cityId = (int) (is_array($address) ? ($address['city_id'] ?? 0) : ($address->city_id ?? 0));
        $regionId = (int) (is_array($address) ? ($address['region_id'] ?? 0) : ($address->region_id ?? 0));
        if ($cityId < 1) {
            return ['address_complete_required'];
        }

        $problems = [];
        if ($this->catalog->activeCityInRegion($cityId, $regionId) === null) {
            $problems[] = 'address_city_invalid';
        }
        if (!NationalAddress::isValid(is_array($address) ? ($address['national_address'] ?? null) : ($address->national_address ?? null))) {
            $problems[] = 'address_national_address_invalid';
        }

        return $problems;
    }

    /** @throws ValidationException */
    public function assertUsable(object|array $address, AddressChannel $channel): void
    {
        $problems = $this->problems($address, $channel);
        if ($problems !== []) {
            throw ValidationException::withMessages(['address' => array_map(static fn (string $key): string => translate($key), $problems)]);
        }
    }
}
