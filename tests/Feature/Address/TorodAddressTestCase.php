<?php

namespace Tests\Feature\Address;

use App\Models\AddressCity;
use App\Services\Address\AddressSchema;
use App\Services\Address\AddressText;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Fulfillment\TorodTestCase;
use Tests\Support\BuildsAddressSchema;
use Tests\Support\BuildsTorodDirectory;

/**
 * The real Torod Point adapter against faked HTTP, on a database that has BOTH the fulfillment migrations and the address ones:
 * the store's city catalog, and Torod's cached directory with Riyadh listed twice (36189 in region 1204, 230194 in region 1265).
 */
abstract class TorodAddressTestCase extends TorodTestCase
{
    use BuildsAddressSchema;
    use BuildsTorodDirectory;

    /** The fulfillment tests seed their three cities here; this base seeds the address world and Torod's duplicated directory instead. */
    protected function seedCities(): void
    {
        $this->buildCityMappingWorld();
    }

    protected function tearDown(): void
    {
        AddressSchema::flush();

        parent::tearDown();
    }

    /**
     * A region and a city in Kuwait, test-only rows: the address importer writes Saudi Arabia only, which is H1-10's third criterion and
     * an owner decision (Q-07/Q-08). Here rather than in one test class because two of them need a city outside Saudi Arabia -- the
     * service's country stamp and the matching screen's listing.
     */
    protected function aKuwaitiCityNamed(string $arabic, string $english): AddressCity
    {
        $region = DB::table('address_regions')->insertGetId([
            'country_code' => 'KW', 'code' => 'KW-KU', 'name_ar' => 'الكويت', 'name_en' => 'Kuwait',
            'normalized_ar' => AddressText::key('الكويت'), 'normalized_en' => AddressText::key('Kuwait'),
            'sort_order' => 9, 'is_active' => true, 'source' => 'test', 'source_ref' => 'kw', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $id = DB::table('address_cities')->insertGetId([
            'region_id' => $region, 'name_ar' => $arabic, 'name_en' => $english,
            'normalized_ar' => AddressText::key($arabic), 'normalized_en' => AddressText::key($english),
            'sort_order' => 50, 'is_active' => true, 'source' => 'test', 'source_ref' => 'kw-city', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return AddressCity::query()->with('region')->findOrFail($id);
    }
}
