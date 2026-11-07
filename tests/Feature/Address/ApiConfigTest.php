<?php

namespace Tests\Feature\Address;

use App\Http\Controllers\RestAPI\v1\ConfigController;
use App\Models\AddressCity;
use App\Services\Address\AddressSchema;
use Illuminate\Support\Facades\Schema;

/**
 * The block of GET /api/v1/config that tells an app how the Saudi address is asked for.
 */
class ApiConfigTest extends ApiAddressTestCase
{
    /** @return array{mode: string, national_address_pattern: string, regions_url: string, cities_url: string} */
    private function block(): array
    {
        return app(ConfigController::class)->unifiedAddressConfig();
    }

    public function test_the_block_is_off_until_the_administrator_turns_the_address_on(): void
    {
        $this->assertSame('off', $this->block()['mode']);

        foreach ([AddressSchema::MODE_SOFT, AddressSchema::MODE_STRICT, AddressSchema::MODE_OFF] as $mode) {
            $this->setAddressMode($mode);

            $this->assertSame($mode, $this->block()['mode']);
        }
    }

    public function test_the_block_stays_off_before_the_migrations_have_run(): void
    {
        $this->setAddressMode(AddressSchema::MODE_STRICT);
        Schema::dropIfExists('address_city_aliases');
        Schema::dropIfExists('address_cities');
        Schema::dropIfExists('address_regions');
        AddressSchema::flush();

        $this->assertSame('off', $this->block()['mode']);
    }

    public function test_the_block_stays_off_while_there_is_no_city_to_choose(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        AddressCity::query()->update(['is_active' => false]);
        AddressSchema::flush();

        $this->assertSame('off', $this->block()['mode']);
    }

    public function test_the_national_address_pattern_follows_the_configured_shape_and_matches_what_the_server_accepts(): void
    {
        $pattern = '/' . $this->block()['national_address_pattern'] . '/';

        foreach (['RAGI2929', 'ragi2929', 'KHBR1234'] as $valid) {
            $this->assertSame(1, preg_match($pattern, $valid), $valid);
        }
        foreach (['RAG2929', 'RAGI29290', 'RAGI 2929', '1234ABCD', '', 'RAGI292'] as $invalid) {
            $this->assertSame(0, preg_match($pattern, $invalid), $invalid);
        }

        config(['address.national_address.letters' => 3, 'address.national_address.digits' => 5]);
        $this->assertSame('^[A-Za-z]{3}[0-9]{5}$', $this->block()['national_address_pattern']);
    }

    public function test_the_two_urls_are_the_public_catalog_endpoints_and_answer(): void
    {
        $block = $this->block();

        $this->assertStringEndsWith('/api/v1/address/regions', $block['regions_url']);
        $this->assertStringEndsWith('/api/v1/address/cities', $block['cities_url']);
        $this->getJson($block['regions_url'])->assertOk()->assertJsonCount(3, 'regions');
        $this->getJson($block['cities_url'] . '?region_id=' . $this->ids['region_EAS'])->assertOk()->assertJsonCount(3, 'cities');
    }

    public function test_the_block_is_part_of_the_config_response_and_the_zip_restriction_keys_keep_their_place(): void
    {
        $source = (string) file_get_contents(base_path('app/Http/Controllers/RestAPI/v1/ConfigController.php'));

        $this->assertStringContainsString("'unified_address' => \$this->unifiedAddressConfig(),", $source);
        $this->assertStringContainsString("'delivery_zip_code_area_restriction' => (int)getWebConfig(name: 'delivery_zip_code_area_restriction'),", $source);
        $this->assertStringContainsString("'delivery_country_restriction' => (int)getWebConfig(name: 'delivery_country_restriction'),", $source);
    }
}
