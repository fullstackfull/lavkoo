<?php

namespace Tests\Feature\Catalog;

use App\Enums\Fulfillment\ProviderCode;
use App\Http\Controllers\Admin\Fulfillment\TorodMatchingController;
use App\Models\ProviderCatalogItem;
use App\Services\Fulfillment\CatalogStagingService;
use App\Services\Fulfillment\ProviderMappingService;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Http\Request;
use Tests\Feature\Fulfillment\FulfillmentTestCase;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\Support\BuildsCountryMatrix;

/**
 * E1, E2 across the six countries: a provider connection has no country of its own, so with the switch on the suggestions for a warehouse item and the
 * picker that matches one by hand serve the default storefront's country, whichever of the six that is, and never the twin of another country. With the
 * switch off they offer the twins of every country, as today.
 */
class SixCountryProviderPickersTest extends FulfillmentTestCase
{
    use BuildsCodeScopeCatalog, BuildsCountryMatrix;

    /** @var array<string, int> */
    private array $products = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->addStorefrontCountryColumn();
        foreach ($this->matrixCountries() as $index => $country) {
            $this->products[$country] = $this->makeProduct(100 + $index, null, [
                'name' => "Serum {$country}", 'code' => 'SKU-100', 'barcode' => '6291234567890', 'storefront_country' => $country,
            ]);
        }
        app(CatalogStagingService::class)->stage(ProviderCode::TorodPoint, 'api', [[
            'external_id' => '555', 'name' => 'Serum', 'sku' => 'SKU-100', 'barcode' => '6291234567890', 'stock' => 4, 'payload' => ['id' => 555, 'externalId' => '2'],
        ]]);
    }

    protected function tearDown(): void
    {
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    /** @return list<int> */
    private function suggested(): array
    {
        $suggestions = app(ProviderMappingService::class)->suggestionsFor(ProviderCatalogItem::where('external_id', '555')->firstOrFail());
        $ids = array_column($suggestions, 'product_id');
        sort($ids);

        return $ids;
    }

    /** @return list<int> */
    private function picked(string $term): array
    {
        $answer = app(TorodMatchingController::class)->productSearch(Request::create('/admin/fulfillment/torod/products', 'GET', ['q' => $term]));
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $answer->getData(true));
        sort($ids);

        return $ids;
    }

    private function makeDefault(string $country): void
    {
        config(["gcc.storefronts.countries.{$country}.active" => true, 'gcc.storefronts.default_country' => $country]);
    }

    public function test_with_the_switch_on_the_suggestions_and_the_picker_serve_the_default_storefronts_product_only_whichever_it_is(): void
    {
        $this->scopeCodesByCountry();
        $failures = [];
        foreach ($this->matrixCountries() as $default) {
            $this->makeDefault($default);
            $own = [$this->products[$default]];

            $this->matrixCase($failures, true, $this->suggested() === $own, "the default storefront is {$default}: the suggestion is its product only");
            foreach (['serum', 'SKU-100', '6291234567890', "Serum {$default}"] as $term) {
                $this->matrixCase($failures, true, $this->picked($term) === $own, "the default storefront is {$default}: the picker, asked for '{$term}', offers its product only");
            }
            foreach (array_diff($this->matrixCountries(), [$default]) as $other) {
                $this->matrixCase($failures, true, $this->picked("Serum {$other}") === [], "the default storefront is {$default}: the twin of {$other} is not offered");
            }
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_with_the_switch_off_the_suggestions_and_the_picker_offer_the_twins_of_every_country(): void
    {
        $all = array_values($this->products);
        sort($all);
        $failures = [];
        foreach ($this->matrixCountries() as $default) {
            $this->makeDefault($default);
            $this->matrixCase($failures, true, $this->suggested() === $all, "the default storefront is {$default}: every country's twin is suggested, as today");
            $this->matrixCase($failures, true, $this->picked('serum') === $all, "the default storefront is {$default}: the picker offers every country's twin, as today");
        }

        $this->assertMatrixHolds($failures);
    }
}
