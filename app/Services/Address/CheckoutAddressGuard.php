<?php

namespace App\Services\Address;

use App\Enums\Address\AddressChannel;
use App\Services\Shipping\CrossBorderPolicy;
use App\Services\Shipping\ShippingEligibility;
use App\Services\ShippingAddressService;
use App\Services\Storefront\StorefrontContext;
use Illuminate\Validation\ValidationException;

/**
 * May this saved address be used for an order? An old Saudi address (a free-text city, or no National Address) is kept in the address
 * book untouched but must be completed before it ships again; the mobile app and other older clients are let through in `soft`
 * mode, and their orders are accepted instead of failing at the till (at fulfilment their typed city is used only when it is certain).
 *
 * It also answers A2-12's question, which is about the storefront rather than about the address: a saved address in a country this
 * storefront is not open to cannot be ordered to, whatever is filled in on it. That is asked FIRST, because an address that cannot be
 * delivered to at all does not need its National Address checking, and it is asked of EVERY address and not only of the structured ones --
 * the countries that keep a free-text city are exactly the ones a Saudi storefront is not open to.
 *
 * The rule itself is {@see CrossBorderPolicy}'s, the same reading the checkout engine refuses with, so the address book and the order
 * cannot disagree about where a customer may be sent a parcel. While the cross-border engine is off -- which is how the platform ships --
 * this adds nothing: every saved address is deliverable, exactly as today.
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

        if (!$this->storefrontCanDeliverTo($country)) {
            return [CrossBorderPolicy::REFUSAL];
        }

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

    /**
     * Whether the storefront this request is being served from may send a parcel to that country (A2-12).
     *
     * Answers yes wherever the question does not arise: the cross-border engine off, which is every shop today, and a storefront nobody
     * resolved -- a panel or a console command, where the default storefront is the only honest answer and is also the one every such
     * caller has always had.
     */
    public function storefrontCanDeliverTo(?string $country): bool
    {
        if (!ShippingEligibility::isOn()) {
            return true;
        }

        return CrossBorderPolicy::allows(StorefrontContext::current()->country, $country);
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
