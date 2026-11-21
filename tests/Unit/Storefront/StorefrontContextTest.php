<?php

namespace Tests\Unit\Storefront;

use App\Services\Storefront\StorefrontContext;
use PHPUnit\Framework\TestCase;

class StorefrontContextTest extends TestCase
{
    public function test_the_saudi_storefront_keeps_every_cache_key_unchanged(): void
    {
        $context = new StorefrontContext(country: 'SA', currency: 'SAR', storefrontsEnabled: true);

        $this->assertSame('x', $context->scopedCacheKey('x'));
    }

    public function test_every_cache_key_is_unchanged_while_storefronts_are_switched_off(): void
    {
        $context = new StorefrontContext(country: 'AE', currency: 'AED', storefrontsEnabled: false);

        $this->assertSame('x', $context->scopedCacheKey('x'));
    }

    public function test_another_active_storefront_gets_its_own_cache_key(): void
    {
        $context = new StorefrontContext(country: 'AE', currency: 'AED', storefrontsEnabled: true);

        $this->assertSame('storefront:AE:x', $context->scopedCacheKey('x'));
    }
}
