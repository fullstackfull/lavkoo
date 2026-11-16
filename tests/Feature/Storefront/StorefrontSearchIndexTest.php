<?php

namespace Tests\Feature\Storefront;

use App\Services\Search\ProductSearchService;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * The search index knows no country, and a search keeps only the best 500 ids it finds. A storefront has to be narrowed before that cap, or
 * the oldest and largest catalogue fills the whole pool and a later storefront is left with a fraction of its own matches.
 */
class StorefrontSearchIndexTest extends TestCase
{
    use BuildsStorefrontCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontCatalogTables();
        $this->runStorefrontCountryMigration();
        Schema::dropIfExists('product_search_index');
        Schema::create('product_search_index', function ($table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('locale', 20);
            $table->string('name_normalized')->nullable();
            $table->text('text_normalized')->nullable();
            $table->timestamps();
        });

        // The Saudi catalogue is older, so its ids are lower and win every tie on the way to the cap.
        foreach ([1 => 'SA', 2 => 'SA', 3 => 'KW', 4 => 'KW'] as $id => $country) {
            $this->insertCatalogProduct(id: $id, name: "Vitamin tablet {$id}", country: $country);
            DB::table('product_search_index')->insert([
                'product_id' => $id, 'locale' => 'en', 'name_normalized' => "vitamin tablet {$id}",
                'text_normalized' => $id === 3 ? 'vitamin tablet kuwaitonly' : "vitamin tablet {$id}",
            ]);
        }

        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.KW.active' => true]);
    }

    private function serveRequestFrom(string $country): void
    {
        app()->instance(StorefrontContext::class, StorefrontResolver::fromConfig()->context(explicitChoice: $country));
    }

    private function resolvedIds(int $limit): array
    {
        $ids = app(ProductSearchService::class)->productIds('vitamin', null, $limit);
        sort($ids);

        return $ids;
    }

    public function test_a_storefront_fills_the_pool_with_its_own_products_first(): void
    {
        $this->serveRequestFrom('KW');
        $this->assertSame([3, 4], $this->resolvedIds(limit: 2), 'the cap is applied after the storefront, not before');

        $this->serveRequestFrom('SA');
        $this->assertSame([1, 2], $this->resolvedIds(limit: 2));
    }

    public function test_a_term_that_only_another_country_has_is_not_found_by_this_storefront(): void
    {
        $this->serveRequestFrom('SA');
        $this->assertSame(['kuwaitonly'], app(ProductSearchService::class)->unmatchedTerms('kuwaitonly'));

        $this->serveRequestFrom('KW');
        $this->assertSame([], app(ProductSearchService::class)->unmatchedTerms('kuwaitonly'));
    }

    public function test_a_request_that_is_not_a_storefront_request_searches_every_country(): void
    {
        $this->assertSame([1, 2, 3, 4], $this->resolvedIds(limit: 10));
    }

    public function test_while_storefronts_are_off_the_search_is_not_narrowed(): void
    {
        config(['gcc.storefronts.enabled' => false]);
        $this->serveRequestFrom('KW');

        $this->assertSame([1, 2, 3, 4], $this->resolvedIds(limit: 10));
    }
}
