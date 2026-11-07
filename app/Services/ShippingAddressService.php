<?php

namespace App\Services;

use App\Enums\Address\AddressChannel;
use App\Services\Address\AddressCatalog;
use App\Services\Address\AddressCountry;
use App\Services\Address\AddressSchema;
use App\Services\Address\AddressText;
use App\Services\Address\NationalAddress;
use App\Models\ShippingAddress;
use App\Services\Fulfillment\Support\SaudiPhone;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one place an address is turned into what the address book stores.
 *
 * Every entry point (checkout, the account address book, the panels, the REST API) asks `structuredAttributes()` for the region,
 * city, district and National Address columns, so there is a single set of rules whatever screen or client the address came from.
 * The old columns (`city`, `country`, `zip`, `address`) are still written for the readers that depend on them — `zip`
 * only ever from what the customer typed, never derived from anything else. `state` is left alone: the shipping zones read it, and
 * what a customer pays must not depend on which form created the address.
 */
class ShippingAddressService
{
    private const STRUCTURED_COLUMNS = ['region_id', 'city_id', 'district', 'national_address'];

    private const DISTRICT_MAX_LENGTH = 191;

    private const AMEND_ATTEMPTS = 3;

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
     * Writes an edit of a saved address. The row is read again under a lock and the structured columns are decided from it as it is now,
     * not as the request found it: an old-form edit that retypes the city clears the ids even when a backfill gave them a moment ago,
     * and two edits made from stale pages cannot leave the region of one beside the city of the other.
     *
     * @param array<string, mixed> $attributes the old columns the client sent; a column it left out keeps its value
     * @param Closure(ShippingAddress): array<string, mixed> $structuredFor the structured columns for the address as it is now (structuredAttributes())
     * @throws ValidationException
     */
    public function amend(ShippingAddress $address, array $attributes, Closure $structuredFor): ShippingAddress
    {
        return DB::transaction(function () use ($address, $attributes, $structuredFor): ShippingAddress {
            $current = ShippingAddress::query()->lockForUpdate()->findOrFail($address->getKey());
            $current->update(array_merge($attributes, $structuredFor($current)));

            return $current;
        }, attempts: self::AMEND_ATTEMPTS);
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
     * still send the old fields). Otherwise returns region_id, city_id, district, national_address plus the legacy `city`
     * and `country` text that go with them, or throws a ValidationException keyed by the input names. Nothing a client sends about
     * a provider (city ids of a courier) is ever read. An old-form edit that moves an address which already has a region and city
     * (matched by the backfill, or made while the mode was on) to another city or country returns those columns as null: the ids must
     * never name a place the address no longer says. A field the client did not send (the district) keeps its value, unless the city changed.
     *
     * @param array<string, mixed> $input region_id, city_id, district, national_address, country, phone
     * @param object|null $existing the saved address (or the order's copy) being edited, so a missing country or a legacy edit of a structured address is judged correctly
     * @param bool $mayKeepSwitchedOffPlace the copy an order holds may keep the region and city it already has after they were switched off: a phone number is corrected without moving the delivery
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function structuredAttributes(array $input, AddressChannel $channel, ?object $existing = null, bool $mayKeepSwitchedOffPlace = false): array
    {
        if (!AddressSchema::enabled()) {
            return $this->resetsStalePlace(input: $input, existing: $existing) ? $this->clearedPlace() : [];
        }

        $country = $input['country'] ?? $existing?->country ?? null;
        if (!AddressCountry::isSaudi($country)) {
            return $existing !== null && $this->isStructured($existing) ? $this->clearedPlace() : [];
        }

        if (!$this->sentStructuredFields($input) && $this->mayStayLegacy($channel)) {
            return $this->legacyClientEdit(input: $input, existing: $existing);
        }

        return $this->validated(input: $input, existing: $existing, mayKeepSwitchedOffPlace: $mayKeepSwitchedOffPlace);
    }

    /**
     * The phone of an edit that carries no location, judged by the rule a full edit of the same address meets: a Saudi mobile number, for a
     * Saudi address, on a channel that has to give the structured address. A client that may still send the old fields is not asked for more
     * than it ever was.
     *
     * @throws ValidationException
     */
    public function assertPhone(mixed $phone, ?string $country, AddressChannel $channel): void
    {
        if ($this->requiresStructured($country, $channel) && SaudiPhone::normalize(is_scalar($phone) ? (string) $phone : null) === null) {
            throw ValidationException::withMessages(['phone' => [translate('address_phone_invalid')]]);
        }
    }

    /** The columns of an address that no longer names a place of the list. */
    private function clearedPlace(): array
    {
        return array_fill_keys(self::STRUCTURED_COLUMNS, null);
    }

    /**
     * A client that predates the structured address edits a Saudi address while the store is in `soft` mode. It cannot state a region,
     * city or National Address, so it may only change an address that makes no claim on them: one with a National Address is refused
     * (the old fields would leave it half changed), one that merely has a city (matched by the backfill) keeps it for as long as the
     * old form still names the same city and country.
     */
    private function legacyClientEdit(array $input, ?object $existing): array
    {
        if ($existing === null || !$this->isStructured($existing)) {
            return [];
        }

        if (trim((string) ($existing->national_address ?? '')) !== '') {
            throw ValidationException::withMessages(['address' => [translate('address_update_app_required')]]);
        }

        return $this->resetsStalePlace(input: $input, existing: $existing) ? $this->clearedPlace() : [];
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
    private function validated(array $input, ?object $existing, bool $mayKeepSwitchedOffPlace): array
    {
        $errors = [];

        $regionId = $this->integer($input['region_id'] ?? null);
        $cityId = $this->integer($input['city_id'] ?? null);
        $keepsPlace = $mayKeepSwitchedOffPlace && $this->holdsPlace(existing: $existing, regionId: $regionId, cityId: $cityId);

        $region = $regionId === null ? null : $this->catalog->region($regionId, onlyActive: !$keepsPlace);
        if ($region === null) {
            $errors['region_id'] = [translate('address_region_invalid')];
        }

        $city = ($cityId === null || $region === null)
            ? null
            : ($keepsPlace ? $this->catalog->cityInRegion($cityId, $region->id) : $this->catalog->activeCityInRegion($cityId, $region->id));
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

        $attributes = [
            'region_id' => $region->id,
            'city_id' => $city->id,
            'district' => $district,
            'national_address' => $nationalAddress,
            'city' => $city->name_ar,
            'country' => AddressCountry::LEGACY_NAME,
        ];

        // A district the client did not send stays as it was, unless the address moved to another city: it belongs to the old one.
        if (!array_key_exists('district', $input) && (int) ($existing->city_id ?? 0) === $city->id) {
            unset($attributes['district']);
        }

        return $attributes;
    }

    private function holdsPlace(?object $existing, ?int $regionId, ?int $cityId): bool
    {
        return $existing !== null
            && $regionId !== null
            && $cityId !== null
            && (int) ($existing->region_id ?? 0) === $regionId
            && (int) ($existing->city_id ?? 0) === $cityId;
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
