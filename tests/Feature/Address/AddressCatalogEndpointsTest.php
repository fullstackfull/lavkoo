<?php

namespace Tests\Feature\Address;

use App\Services\Address\AddressSchema;
use Illuminate\Support\Facades\Schema;

class AddressCatalogEndpointsTest extends AddressTestCase
{
    /** @return list<string> the two public prefixes: the website and the REST API */
    private static function prefixes(): array
    {
        return ['/address', '/api/v1/address'];
    }

    public function test_the_regions_endpoints_list_active_regions_in_order(): void
    {
        $ids = $this->seedAddressCatalog();
        \App\Models\AddressRegion::whereKey($ids['region_MAK'])->update(['is_active' => false]);

        foreach (self::prefixes() as $prefix) {
            $response = $this->getJson($prefix . '/regions');

            $response->assertOk()->assertJsonCount(2, 'regions');
            $this->assertSame(['منطقة الرياض', 'المنطقة الشرقية'], array_column($response->json('regions'), 'name_ar'));
            $this->assertSame(['id', 'code', 'name_ar', 'name_en', 'name'], array_keys($response->json('regions.0')));
            $this->assertStringContainsString('max-age=300', (string) $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        }
    }

    public function test_the_cities_endpoints_filter_by_region_and_search_in_both_languages(): void
    {
        $ids = $this->seedAddressCatalog();

        foreach (self::prefixes() as $prefix) {
            $inRegion = $this->getJson($prefix . '/cities?region_id=' . $ids['region_EAS'])->assertOk()->json('cities');
            $this->assertEqualsCanonicalizing([$ids['dammam'], $ids['khobar'], $ids['hafuf_eas']], array_column($inRegion, 'id'));

            $this->assertSame([$ids['khobar']], array_column($this->getJson($prefix . '/cities?region_id=' . $ids['region_EAS'] . '&q=' . rawurlencode('الخبر'))->json('cities'), 'id'));
            $this->assertSame([$ids['khobar']], array_column($this->getJson($prefix . '/cities?region_id=' . $ids['region_EAS'] . '&q=al+khob')->json('cities'), 'id'));
            $this->assertSame([], $this->getJson($prefix . '/cities?region_id=' . $ids['region_RUH'] . '&q=' . rawurlencode('الخبر'))->json('cities'));
        }
    }

    public function test_the_typeahead_requests_never_become_the_page_a_customer_returns_to_after_signing_in(): void
    {
        $this->seedAddressCatalog();

        $this->withSession(['keep_customer_login_redirect_url' => 'http://localhost/shop-cart'])->getJson('/address/regions')->assertOk();
        $this->getJson('/address/cities?q=' . rawurlencode('الخبر'))->assertOk();

        $this->assertSame('http://localhost/shop-cart', session('keep_customer_login_redirect_url'));
        $this->assertSame([], array_values(array_filter((array) session('recent_user_routes_history', []), static fn (string $url): bool => str_contains($url, '/address/'))));
    }

    public function test_the_cities_endpoint_refuses_hostile_input_and_caps_the_page_size(): void
    {
        $this->seedAddressCatalog();

        $this->getJson('/address/cities?region_id=abc')->assertStatus(422);
        $this->getJson('/address/cities?region_id=-3')->assertStatus(422);
        $this->getJson('/address/cities?q=' . str_repeat('x', 101))->assertStatus(422);
        $this->getJson('/address/cities?limit=0')->assertStatus(422);
        $this->getJson('/address/cities?limit=100000')->assertOk();
        $this->assertCount(0, $this->getJson('/address/cities?q=' . rawurlencode("'; DROP TABLE address_cities; --"))->json('cities'));
        $this->assertTrue(Schema::hasTable('address_cities'));
    }

    public function test_the_endpoints_answer_with_empty_lists_before_the_migrations_have_run(): void
    {
        Schema::dropIfExists('address_city_aliases');
        Schema::dropIfExists('address_cities');
        Schema::dropIfExists('address_regions');
        AddressSchema::flush();

        $this->getJson('/address/regions')->assertOk()->assertExactJson(['regions' => []]);
        $this->getJson('/address/cities?region_id=1')->assertOk()->assertExactJson(['cities' => []]);
    }

    public function test_the_endpoints_are_rate_limited(): void
    {
        $this->seedAddressCatalog();

        for ($request = 0; $request < 120; $request++) {
            $this->getJson('/address/regions')->assertOk();
        }

        $this->getJson('/address/regions')->assertStatus(429);
    }
}
