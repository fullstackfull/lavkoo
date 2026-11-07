<?php

namespace App\Services\Address;

/**
 * The four migrations of the unified address and whether what each one adds is on this database.
 *
 * The deployment runs them by hand in one command with an explicit --path for each, so the admin pages and `address:status` say which of
 * them are still to run instead of failing on a table that is not there yet.
 */
final class AddressMigrations
{
    public const MIGRATIONS = [
        '2026_11_07_100000_create_address_master_tables',
        '2026_11_07_100100_add_unified_address_columns_to_shipping_addresses_table',
        '2026_11_07_100200_create_provider_city_mapping_tables',
        '2026_11_07_100300_add_directory_columns_to_provider_cities_table',
    ];

    /** @return array<string, bool> each migration, and whether the tables or columns it adds exist */
    public static function status(): array
    {
        AddressSchema::flush();

        return [
            self::MIGRATIONS[0] => AddressSchema::mastersReady(),
            self::MIGRATIONS[1] => AddressSchema::columnsReady(),
            self::MIGRATIONS[2] => AddressSchema::mappingsReady(),
            self::MIGRATIONS[3] => AddressSchema::directoryColumnsReady(),
        ];
    }
}
