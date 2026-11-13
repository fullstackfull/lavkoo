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

    public function test_switching_the_flag_on_enables_the_path_and_choice_rules(): void
    {
        config(['gcc.storefronts.enabled' => true]);

        $this->assertSame('AE', StorefrontResolver::fromConfig()->resolve(path: '/ae/cart'));
    }
}
