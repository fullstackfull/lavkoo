<?php

namespace Tests\Unit\Storefront;

use App\Services\Storefront\StorefrontContext;
use Tests\TestCase;

class StorefrontCacheKeysTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['gcc.storefronts.enabled' => true]);
    }

    public function test_without_a_bound_context_the_saudi_storefront_is_current_and_keeps_its_keys(): void
    {
        $this->assertSame('SA', StorefrontContext::current()->country);
        $this->assertSame('storefront-key', StorefrontContext::current()->scopedCacheKey('storefront-key'));
    }

    public function test_a_bound_storefront_scopes_its_keys_while_storefronts_are_on(): void
    {
        app()->instance(StorefrontContext::class, new StorefrontContext('AE', 'AED', true));

        $this->assertSame('storefront:AE:k', StorefrontContext::current()->scopedCacheKey('k'));
    }

    public function test_storefronts_switched_off_keep_every_key_unscoped(): void
    {
        config(['gcc.storefronts.enabled' => false]);
        app()->instance(StorefrontContext::class, new StorefrontContext('AE', 'AED', false));

        $this->assertSame('k', StorefrontContext::current()->scopedCacheKey('k'));
    }

    public function test_invalidation_lists_the_saudi_key_and_one_key_per_other_configured_storefront(): void
    {
        $this->assertSame(
            ['k', 'storefront:AE:k', 'storefront:KW:k', 'storefront:QA:k', 'storefront:BH:k', 'storefront:OM:k'],
            StorefrontContext::keysForEveryStorefront('k'),
        );
    }
}
