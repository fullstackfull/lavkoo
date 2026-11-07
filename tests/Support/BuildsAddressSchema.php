<?php

namespace Tests\Support;

use App\Services\Address\AddressSchema;
use App\Services\Address\AddressText;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The address tests' database: an INFERRED copy of the store tables the address book touches (the installation dump that owns
 * them is not in the repository), then the feature's REAL migrations on top. Same limits as BuildsFulfillmentSchema: this proves
 * the feature's logic, not the live schema.
 */
trait BuildsAddressSchema
{
    public const ADDRESS_MIGRATIONS = [
        '2026_11_07_100000_create_address_master_tables.php',
        '2026_11_07_100100_add_unified_address_columns_to_shipping_addresses_table.php',
        '2026_11_07_100200_create_provider_city_mapping_tables.php',
        '2026_11_07_100300_add_directory_columns_to_provider_cities_table.php',
    ];

    protected function resetAddressTestDatabase(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite') {
            $database = (string) $connection->getDatabaseName();
            if (!app()->environment('testing') || !preg_match('/(^|_)test(s|ing)?($|_)/i', $database)) {
                throw new RuntimeException("Refusing to drop every table of '{$database}': the test database name must contain 'test' and APP_ENV must be 'testing'.");
            }
            Schema::dropAllTables();
        }

        Cache::flush();
        AddressSchema::flush();
    }

    /** The address book as the installation dump defines it, before any of this feature's migrations. */
    protected function createLegacyAddressTables(): void
    {
        if (!Schema::hasTable('business_settings')) {
            Schema::create('business_settings', function (Blueprint $table) {
                $table->id();
                $table->string('type')->nullable();
                $table->longText('value')->nullable();
                $table->timestamps();
            });
        }

        Schema::create('shipping_addresses', function (Blueprint $table) {
            $table->id();
            $table->string('customer_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('contact_person_name')->nullable();
            $table->string('email')->nullable();
            $table->string('address_type')->default('home');
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('zip')->nullable();
            $table->string('phone')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('latitude')->nullable();
            $table->string('longitude')->nullable();
            $table->boolean('is_billing')->default(false);
            $table->timestamps();
        });
    }

    protected function runAddressMigrations(): void
    {
        foreach (self::ADDRESS_MIGRATIONS as $file) {
            (require database_path('migrations/' . $file))->up();
        }
        AddressSchema::flush();
    }

    protected function buildAddressSchema(): void
    {
        $this->resetAddressTestDatabase();
        $this->createLegacyAddressTables();
        $this->runAddressMigrations();
    }

    /**
     * A small catalog with the cases that matter: a name shared by two cities of one region, a name shared across regions,
     * and the same city written in Arabic and English. Every city has a rank, like the registered cities of the real list (the
     * small places a district name can collide with have none).
     *
     * @return array<string, int> city ids by handle
     */
    protected function seedAddressCatalog(): array
    {
        $regions = [];
        foreach ([['RUH', 'منطقة الرياض', 'Riyadh', 1], ['MAK', 'منطقة مكة المكرمة', 'Makkah', 2], ['EAS', 'المنطقة الشرقية', 'Eastern Province', 3]] as [$code, $ar, $en, $sort]) {
            $regions[$code] = DB::table('address_regions')->insertGetId([
                'country_code' => 'SA', 'code' => 'SA-' . $code, 'name_ar' => $ar, 'name_en' => $en,
                'normalized_ar' => AddressText::key($ar), 'normalized_en' => AddressText::key($en),
                'sort_order' => $sort, 'is_active' => true, 'source' => 'test', 'source_ref' => (string) $sort,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $cities = [];
        foreach ([
            'riyadh' => ['RUH', 'الرياض', 'Riyadh'],
            'diriyah' => ['RUH', 'الدرعية', 'Ad Diriyah'],
            'hafuf_ruh' => ['RUH', 'الهفوف', 'Al Hafuf'],
            'jeddah' => ['MAK', 'جدة', 'Jeddah'],
            'makkah' => ['MAK', 'مكة المكرمة', 'Makkah'],
            'taif' => ['MAK', 'الطائف', 'At Taif'],
            'dammam' => ['EAS', 'الدمام', 'Ad Dammam'],
            'khobar' => ['EAS', 'الخبر', 'Al Khobar'],
            'hafuf_eas' => ['EAS', 'الهفوف', 'Al Hofuf'],
            'umm_a' => ['RUH', 'أم سليم', 'Umm Sulaym'],
            'umm_b' => ['RUH', 'أم سليم', 'Umm Sulaym'],
        ] as $handle => [$region, $ar, $en]) {
            $cities[$handle] = DB::table('address_cities')->insertGetId([
                'region_id' => $regions[$region], 'name_ar' => $ar, 'name_en' => $en,
                'normalized_ar' => AddressText::key($ar), 'normalized_en' => AddressText::key($en),
                'sort_order' => 50, 'is_active' => true, 'source' => 'test', 'source_ref' => $handle, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        AddressSchema::flush();

        return $cities + ['region_RUH' => $regions['RUH'], 'region_MAK' => $regions['MAK'], 'region_EAS' => $regions['EAS']];
    }
}
