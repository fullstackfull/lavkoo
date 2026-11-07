<?php

namespace App\Services\Address;

/**
 * How the pages print the place of an order's address, read from the copy the order keeps (see AddressSnapshot), never from the
 * saved address it was made from.
 *
 * An order placed with the structured address names its region, city, district and National Address; an older one has a free-text
 * city and perhaps a ZIP code, and a copy older than that may lack some of those keys altogether. Every line is printed only when
 * the copy has something to say, so a page never shows an empty label or a dangling comma, and a missing key is never an error.
 */
final class AddressSnapshotLines
{
    private const SAME_ADDRESS_KEYS = ['address', 'city', 'zip', 'region_id', 'city_id', 'district', 'national_address'];

    /**
     * @param object|array<string, mixed>|null $snapshot the decoded copy an order keeps of an address
     * @return list<array{label: string, value: string}> label is a translation key; region, city (in the language of the page), district, National Address, ZIP code, in that order
     */
    public static function location(object|array|null $snapshot, ?string $locale = null): array
    {
        if (self::isBlank($snapshot)) {
            return [];
        }

        $lines = [
            'address_region' => AddressSnapshot::regionName($snapshot, $locale),
            'address_city' => AddressSnapshot::cityName($snapshot, $locale),
            'address_district' => AddressSnapshot::district($snapshot),
            'address_national_address' => AddressSnapshot::nationalAddress($snapshot),
            'zip_code' => AddressSnapshot::zip($snapshot),
        ];

        $printed = [];
        foreach ($lines as $label => $value) {
            if ($value !== null) {
                $printed[] = ['label' => $label, 'value' => $value];
            }
        }

        return $printed;
    }

    /**
     * Why a panel form asks for a region, city and National Address on an old copy: it was saved before they existed. Null when the copy
     * is already structured. The city the old copy names is printed with it, so the person completing it knows which place was meant.
     *
     * @param object|array<string, mixed>|null $snapshot
     */
    public static function completionNotice(object|array|null $snapshot, ?string $locale = null): ?string
    {
        if (self::isBlank($snapshot) || AddressSnapshot::isUnified($snapshot)) {
            return null;
        }

        $previousCity = AddressSnapshot::cityName($snapshot, $locale);

        return translate('address_copy_complete_required') . ($previousCity === null ? '' : ' ' . translate('address_previous_city') . ': ' . $previousCity);
    }

    /**
     * Do two copies name the same place? The page prints "same as shipping address" instead of the billing address when they do. A
     * copy older than the structured address has no region, city id or National Address, and those then count as equal on both sides.
     *
     * @param object|array<string, mixed>|null $first
     * @param object|array<string, mixed>|null $second
     */
    public static function same(object|array|null $first, object|array|null $second): bool
    {
        if (self::isBlank($first) || self::isBlank($second)) {
            return false;
        }

        foreach (self::SAME_ADDRESS_KEYS as $key) {
            if (self::comparable($first, $key) !== self::comparable($second, $key)) {
                return false;
            }
        }

        return true;
    }

    private static function comparable(object|array $snapshot, string $key): string
    {
        $value = AddressSnapshot::value($snapshot, $key);

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function isBlank(object|array|null $snapshot): bool
    {
        return $snapshot === null || (array) $snapshot === [];
    }
}
