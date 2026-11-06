<?php

namespace App\Services\Address;

use App\Models\AddressCity;
use Illuminate\Database\Eloquent\Model;

/**
 * What an order remembers about where it was sent, and how the pages read it back.
 *
 * `orders.shipping_address_data` and `billing_address_data` hold a copy of the address as it was when the order was placed, so
 * editing or deleting the address later never rewrites a past order. `build()` makes that copy: every old key (including `zip`)
 * exactly as before, plus the structured fields and the NAMES of the region and city at that moment, so the order still reads
 * correctly after a city is renamed or switched off. The readers below take either the decoded snapshot or an address row.
 */
final class AddressSnapshot
{
    public const SCHEMA_UNIFIED = 'unified';

    public const SCHEMA_LEGACY = 'legacy';

    /**
     * @param object|array<string, mixed> $address a ShippingAddress, a row or an array
     * @return array<string, mixed>
     */
    public static function build(object|array $address): array
    {
        $data = self::toArray($address);
        $data['zip'] = $data['zip'] ?? null;

        $cityId = isset($data['city_id']) && (int) $data['city_id'] > 0 ? (int) $data['city_id'] : null;
        $city = $cityId === null || !AddressSchema::mastersReady() ? null : AddressCity::query()->with('region')->find($cityId);

        $data['city_id'] = $city?->id;
        $data['region_id'] = $city?->region_id;
        $data['city_name_ar'] = $city?->name_ar;
        $data['city_name_en'] = $city?->name_en;
        $data['region_name_ar'] = $city?->region?->name_ar;
        $data['region_name_en'] = $city?->region?->name_en;
        $data['district'] = $data['district'] ?? null;
        $data['national_address'] = $city === null ? null : ($data['national_address'] ?? null);
        $data['address_schema'] = $city !== null && $data['national_address'] !== null ? self::SCHEMA_UNIFIED : self::SCHEMA_LEGACY;

        return $data;
    }

    /** @param object|array<string, mixed> $snapshot */
    public static function isUnified(object|array $snapshot): bool
    {
        return self::value($snapshot, 'address_schema') === self::SCHEMA_UNIFIED;
    }

    /** The city as the snapshot names it in the given language, or the old free-text city of a legacy address. */
    public static function cityName(object|array $snapshot, ?string $locale = null): ?string
    {
        $structured = self::value($snapshot, self::isEnglish($locale) ? 'city_name_en' : 'city_name_ar');

        return self::filled($structured) ? (string) $structured : self::text(self::value($snapshot, 'city'));
    }

    public static function regionName(object|array $snapshot, ?string $locale = null): ?string
    {
        $structured = self::value($snapshot, self::isEnglish($locale) ? 'region_name_en' : 'region_name_ar');

        return self::filled($structured) ? (string) $structured : self::text(self::value($snapshot, 'state'));
    }

    public static function nationalAddress(object|array $snapshot): ?string
    {
        return self::text(self::value($snapshot, 'national_address'));
    }

    public static function district(object|array $snapshot): ?string
    {
        return self::text(self::value($snapshot, 'district'));
    }

    /** The postal code exactly as it was typed, or null: never anything derived from the National Address. */
    public static function zip(object|array $snapshot): ?string
    {
        return self::text(self::value($snapshot, 'zip'));
    }

    /** @param object|array<string, mixed> $snapshot */
    public static function value(object|array $snapshot, string $key): mixed
    {
        return is_array($snapshot) ? ($snapshot[$key] ?? null) : ($snapshot->{$key} ?? null);
    }

    /** @return array<string, mixed> */
    private static function toArray(object|array $address): array
    {
        return match (true) {
            is_array($address) => $address,
            $address instanceof Model => $address->attributesToArray(),
            default => get_object_vars($address),
        };
    }

    private static function isEnglish(?string $locale): bool
    {
        return str_starts_with(strtolower($locale ?? app()->getLocale()), 'en');
    }

    private static function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private static function text(mixed $value): ?string
    {
        return self::filled($value) ? trim((string) $value) : null;
    }
}
