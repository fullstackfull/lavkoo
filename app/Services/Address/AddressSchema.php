<?php

namespace App\Services\Address;

use App\Models\AddressCity;
use App\Models\BusinessSetting;
use Illuminate\Support\Facades\Schema;

/**
 * Is the structured address available on this database, and how strictly is it applied?
 *
 * Code is deployed before its migrations run and migrations run before the city list is imported, so every place that touches
 * the feature asks here first and otherwise behaves exactly as the store did before. The mode is a business setting an
 * administrator changes on purpose:
 *
 *   off     the old free-text address everywhere (the default)
 *   soft    customers on the website and staff in the panel must use the structured address for Saudi addresses; the mobile app
 *           and other older clients may still send the old fields, and those orders wait for review instead of failing
 *   strict  every client must send the structured address for Saudi addresses
 */
final class AddressSchema
{
    public const SETTING = 'unified_address_mode';

    public const MODE_OFF = 'off';

    public const MODE_SOFT = 'soft';

    public const MODE_STRICT = 'strict';

    public const MODES = [self::MODE_OFF, self::MODE_SOFT, self::MODE_STRICT];

    public const ADDRESS_COLUMNS = ['region_id', 'city_id', 'district', 'national_address'];

    private static ?bool $masters = null;

    private static ?bool $columns = null;

    private static ?bool $mappings = null;

    private static ?bool $usable = null;

    public static function mastersReady(): bool
    {
        return self::$masters ??= Schema::hasTable('address_regions') && Schema::hasTable('address_cities') && Schema::hasTable('address_city_aliases');
    }

    public static function columnsReady(): bool
    {
        if (self::$columns !== null) {
            return self::$columns;
        }

        if (!Schema::hasTable('shipping_addresses')) {
            return self::$columns = false;
        }

        foreach (self::ADDRESS_COLUMNS as $column) {
            if (!Schema::hasColumn('shipping_addresses', $column)) {
                return self::$columns = false;
            }
        }

        return self::$columns = true;
    }

    /** Provider mappings and the city sync history: needed by the shipping integrations, not by the address book. */
    public static function mappingsReady(): bool
    {
        return self::$mappings ??= Schema::hasTable('provider_city_mappings') && Schema::hasTable('provider_city_sync_runs');
    }

    public static function ready(): bool
    {
        return self::mastersReady() && self::columnsReady();
    }

    /** Has anyone imported (or added) a city yet? An empty list must never lock customers out of checkout. */
    public static function hasCities(): bool
    {
        if (!self::mastersReady()) {
            return false;
        }

        return self::$usable ??= AddressCity::query()->where('is_active', true)->exists();
    }

    public static function configuredMode(): string
    {
        $value = function_exists('getWebConfig') ? getWebConfig(name: self::SETTING) : null;

        return is_string($value) && in_array($value, self::MODES, true) ? $value : self::MODE_OFF;
    }

    /** The mode in force: `off` unless the schema, the city list and the setting all say otherwise. */
    public static function mode(): string
    {
        if (!self::ready() || !self::hasCities()) {
            return self::MODE_OFF;
        }

        return self::configuredMode();
    }

    public static function enabled(): bool
    {
        return self::mode() !== self::MODE_OFF;
    }

    public static function strict(): bool
    {
        return self::mode() === self::MODE_STRICT;
    }

    public static function setMode(string $mode): void
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('Unknown address mode.');
        }

        BusinessSetting::updateOrCreate(['type' => self::SETTING], ['value' => json_encode($mode)]);

        if (function_exists('clearWebConfigCacheKeys')) {
            clearWebConfigCacheKeys();
        }
    }

    public static function flush(): void
    {
        self::$masters = null;
        self::$columns = null;
        self::$mappings = null;
        self::$usable = null;
    }
}
