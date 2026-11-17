<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\StorefrontResolver;
use Tests\TestCase;

class StorefrontDefaultsTest extends TestCase
{
    public function test_the_shipped_configuration_is_switched_off_and_serves_the_saudi_storefront(): void
    {
        $this->assertFalse((bool) config('gcc.storefronts.enabled'));

        $this->assertSame('SA', StorefrontResolver::fromConfig()->resolve(explicitChoice: 'AE', path: '/ae/cart'));
    }

    public function test_only_the_saudi_storefront_ships_active(): void
    {
        $activeCountries = array_keys(array_filter(
            (array) config('gcc.storefronts.countries'),
            fn (array $country) => $country['active'] === true,
        ));

        $this->assertSame(['SA'], $activeCountries);
    }

    public function test_switching_the_flag_on_alone_keeps_inactive_countries_on_the_saudi_storefront(): void
    {
        config(['gcc.storefronts.enabled' => true]);

        $this->assertSame('SA', StorefrontResolver::fromConfig()->resolve(explicitChoice: 'AE', path: '/ae/cart'));
    }

    public function test_switching_the_flag_on_enables_the_path_and_choice_rules_for_an_active_country(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.AE.active' => true]);

        $this->assertSame('AE', StorefrontResolver::fromConfig()->resolve(path: '/ae/cart'));
    }
}
