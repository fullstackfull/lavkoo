<?php

namespace Tests\Feature\Address;

use App\Services\Address\AddressSchema;
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
}
