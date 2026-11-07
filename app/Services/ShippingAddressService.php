<?php

namespace App\Services;

use App\Enums\Address\AddressChannel;
use App\Services\Address\AddressCatalog;
use App\Services\Address\AddressCountry;
use App\Services\Address\AddressSchema;
use App\Services\Address\AddressText;
use App\Services\Address\NationalAddress;
use App\Services\Fulfillment\Support\SaudiPhone;
use Illuminate\Validation\ValidationException;

/**
 * The one place an address is turned into what the address book stores.
 *
 * Every entry point (checkout, the account address book, the panels, the REST API) asks `structuredAttributes()` for the region,
 * city, district and National Address columns, so there is a single set of rules whatever screen or client the address came from.
 * The old columns (`city`, `state`, `country`, `zip`, `address`) are still written for the readers that depend on them — `zip`
 * only ever from what the customer typed, never derived from anything else.
 */
class ShippingAddressService
{
    private const STRUCTURED_COLUMNS = ['region_id', 'city_id', 'district', 'national_address'];

    private const DISTRICT_MAX_LENGTH = 191;

    public function __construct(private readonly AddressCatalog $catalog)
    {
    }

    public function getAddAddressData($request, $customerId, $addressType): array
    {
        $data = [
            'customer_id' => $customerId,

            'contact_person_name' => trim(
                ($request['f_name'] ?? '') . ' ' . ($request['l_name'] ?? '')
            ),

            'address_type' => $addressType,
            'address' => $request['address'],
            'city' => $request['city'] ?? null,
            'zip' => $request['zip_code'] ?? null,
            'country' => $request['country'] ?? null,
            'phone' => $request['phone'],
            'is_billing' => 0,
            'latitude' => 0,
            'longitude' => 0,
        ];

        return array_merge($data, $this->structuredAttributes(input: $data + $this->structuredInput($request), channel: AddressChannel::Panel));
    }

    /**
     * Must this address carry the structured Saudi fields, given the mode the store is in and who is entering it?
     */
    public function requiresStructured(?string $country, AddressChannel $channel): bool
    {
        return AddressSchema::enabled()
            && AddressCountry::isSaudi($country)
            && !$this->mayStayLegacy($channel);
    }

    /**
     * The structured columns for an address that is being saved, checked against the master lists.
     *
     * Returns [] when the structured address does not apply (mode off, a country other than Saudi Arabia, or a client that may
     * still send the old fields). Otherwise returns region_id, city_id, district, national_address plus the legacy `city`, `state`
     * and `country` text that go with them, or throws a ValidationException keyed by the input names. Nothing a client sends about
     * a provider (city ids of a courier) is ever read. With the mode off, an old-form edit that moves an address which already has a
     * region and city (matched by the backfill, or made while the mode was on) to another city or country returns those columns as
     * null: the ids must never name a place the address no longer says.
     *
     * @param array<string, mixed> $input region_id, city_id, district, national_address, country, phone
     * @param object|null $existing the saved address being edited, so a missing country or a legacy edit of a structured address is judged correctly
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function structuredAttributes(array $input, AddressChannel $channel, ?object $existing = null): array
    {
        if (!AddressSchema::enabled()) {
            return $this->resetsStalePlace(input: $input, existing: $existing) ? array_fill_keys([...self::STRUCTURED_COLUMNS, 'state'], null) : [];
        }

        $country = $input['country'] ?? $existing?->country ?? null;
        if (!AddressCountry::isSaudi($country)) {
            return $existing !== null && $this->isStructured($existing) ? array_fill_keys(self::STRUCTURED_COLUMNS, null) : [];
        }

        if (!$this->sentStructuredFields($input) && $this->mayStayLegacy($channel)) {
            if ($existing !== null && $this->isStructured($existing)) {
                throw ValidationException::withMessages(['address' => [translate('address_update_app_required')]]);
            }

            return [];
        }

        return $this->validated($input);
    }

    /** Does an old-form edit of a structured address change where it is? */
    private function resetsStalePlace(array $input, ?object $existing): bool
    {
        if ($existing === null || !$this->isStructured($existing)) {
            return false;
        }

        $city = $input['city'] ?? null;
        $country = $input['country'] ?? null;

        return (is_scalar($city) && AddressText::key((string) $city) !== AddressText::key((string) ($existing->city ?? '')))
            || (is_scalar($country) && AddressCountry::isSaudi((string) $country) !== AddressCountry::isSaudi($existing->country ?? null));
    }

    /** Does the saved address already use the structured fields? */
    public function isStructured(object|array $address): bool
    {
        $cityId = is_array($address) ? ($address['city_id'] ?? null) : ($address->city_id ?? null);

        return $cityId !== null && $cityId !== '' && (int) $cityId > 0;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validated(array $input): array
    {
        $errors = [];

        $region = $this->integer($input['region_id'] ?? null);
        $region = $region === null ? null : $this->catalog->region($region);
        if ($region === null) {
            $errors['region_id'] = [translate('address_region_invalid')];
        }

        $city = $this->integer($input['city_id'] ?? null);
        $city = ($city === null || $region === null) ? null : $this->catalog->activeCityInRegion($city, $region->id);
        if ($city === null) {
            $errors['city_id'] = [translate('address_city_invalid')];
        }

        $nationalAddress = NationalAddress::canonical(is_scalar($input['national_address'] ?? null) ? (string) $input['national_address'] : null);
        if ($nationalAddress === null) {
            $errors['national_address'] = [translate('address_national_address_invalid')];
        }

        $district = $this->district($input['district'] ?? null);
        if ($district === false) {
            $errors['district'] = [translate('address_district_invalid')];
        }

        if (array_key_exists('phone', $input) && SaudiPhone::normalize(is_scalar($input['phone']) ? (string) $input['phone'] : null) === null) {
            $errors['phone'] = [translate('address_phone_invalid')];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'region_id' => $region->id,
            'city_id' => $city->id,
            'district' => $district,
            'national_address' => $nationalAddress,
            'city' => $city->name_ar,
            'state' => $region->name_ar,
            'country' => AddressCountry::LEGACY_NAME,
        ];
    }

    /** @param array<string, mixed> $input */
    private function sentStructuredFields(array $input): bool
    {
        foreach (['region_id', 'city_id', 'national_address'] as $field) {
            if (isset($input[$field]) && $input[$field] !== '') {
                return true;
            }
        }

        return false;
    }

    private function mayStayLegacy(AddressChannel $channel): bool
    {
        return !AddressSchema::strict() && $channel->mayStayLegacyInSoftMode();
    }

    /** @return array<string, mixed> */
    private function structuredInput($request): array
    {
        $input = [];
        foreach (self::STRUCTURED_COLUMNS as $field) {
            if (isset($request[$field])) {
                $input[$field] = $request[$field];
            }
        }

        return $input;
    }

    private function integer(mixed $value): ?int
    {
        return (is_int($value) || (is_string($value) && preg_match('/^[1-9]\d{0,17}$/', $value) === 1)) ? (int) $value : null;
    }

    /** @return string|false|null the cleaned district, null when none was given, false when it is unusable */
    private function district(mixed $value): string|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            return false;
        }

        $district = trim((string) preg_replace('/[\p{C}\s]+/u', ' ', $value));

        return match (true) {
            $district === '' => null,
            mb_strlen($district) > self::DISTRICT_MAX_LENGTH, preg_match('/[<>]/', $district) === 1 => false,
            default => $district,
        };
    }
}
