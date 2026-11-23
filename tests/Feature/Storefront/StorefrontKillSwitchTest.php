<?php

namespace Tests\Feature\Storefront;

use App\Models\Product;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * How to switch a country off once it has products. Turning a country inactive keeps every storefront narrowed: the Saudi storefront never
 * shows its products and a visitor who asks for it is served the default storefront. Turning the flag off removes the narrowing for all
 * of them, so it is not a kill switch once another country has products. These tests pin both, so the runbook cannot drift from the code.
 */
class StorefrontKillSwitchTest extends TestCase
{
    use BuildsStorefrontCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontCatalogTables();
        $this->runStorefrontCountryMigration();
        $this->insertCatalogProduct(id: 1, name: 'Saudi serum');
        $this->insertCatalogProduct(id: 2, name: 'Emirati serum', country: 'AE');
    }

    private function serveRequestAskingFor(string $country): void
    {
        app()->instance(StorefrontContext::class, StorefrontResolver::fromConfig()->context(explicitChoice: $country));
    }

    private function visibleProductIds(): array
    {
        return Product::query()->active()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_deactivating_a_country_keeps_its_products_out_of_every_other_storefront(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.AE.active' => false]);

        $this->serveRequestAskingFor('SA');
        $this->assertSame([1], $this->visibleProductIds());
    }

    public function test_a_visitor_who_asks_for_a_deactivated_country_is_served_the_default_storefront_and_none_of_its_products(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.AE.active' => false]);

        $this->serveRequestAskingFor('AE');

        $this->assertSame('SA', StorefrontContext::current()->country);
        $this->assertSame([1], $this->visibleProductIds());
    }

    public function test_turning_the_flag_off_removes_the_narrowing_for_every_country_so_it_is_not_a_kill_switch(): void
    {
        config(['gcc.storefronts.enabled' => false, 'gcc.storefronts.countries.AE.active' => true]);

        $this->serveRequestAskingFor('SA');

        $this->assertSame([1, 2], $this->visibleProductIds());
    }
}
