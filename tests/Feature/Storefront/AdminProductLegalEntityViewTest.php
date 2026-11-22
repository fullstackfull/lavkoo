<?php

namespace Tests\Feature\Storefront;

use App\Models\Product;
use App\Services\Storefront\LegalEntityRegistry;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Tests\Support\BuildsLegalEntityWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * What the admin sees of the company that sells a product (GCC-13, D1-03): the registered company's name beside the country, read only, because it
 * follows from the country and is not typed. Nothing is drawn while legal entities are off or while the registry holds no company for that country,
 * so the screen of the live Saudi platform is the screen it is today.
 */
class AdminProductLegalEntityViewTest extends TestCase
{
    use BuildsLegalEntityWorld;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        View::getFinder()->prependLocation(base_path('tests/Support/views'));
        $this->buildStorefrontCatalogTables();
        $this->buildProductRelationTables();
        $this->runStorefrontCountryMigration();
        (require database_path('migrations/2026_11_27_100000_create_legal_entities_table.php'))->up();
        (require database_path('migrations/2026_11_27_100200_add_legal_entity_to_products_table.php'))->up();
        StorefrontCatalog::flush();
        app(LegalEntityRegistry::class)->flush();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('legal_entities');
        StorefrontCatalog::flush();
        app(LegalEntityRegistry::class)->flush();
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function renderCountryField(array $data = []): string
    {
        return trim(view('admin-views.product.partials._storefront-country', $data)->render());
    }

    private function productOfCountry(int $id, string $country, ?int $company = null): Product
    {
        $this->insertCatalogProduct(id: $id, name: "Serum {$id}", country: $country);
        DB::table('products')->where('id', $id)->update(['legal_entity_id' => $company]);

        return Product::withoutGlobalScopes()->findOrFail($id);
    }

    // ---- the way back ----------------------------------------------------------------------------------------------------------

    public function test_nothing_about_a_company_is_drawn_while_legal_entities_are_off(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $this->registerEntity(country: 'SA');
        app(LegalEntityRegistry::class)->flush();

        $html = $this->renderCountryField();

        $this->assertStringContainsString('name="storefront_country"', $html, 'the country field is still there');
        $this->assertStringNotContainsString('selling-company', $html);
        $this->assertStringNotContainsString('Lavkoo SA Trading Co.', $html);
    }

    public function test_the_switch_on_with_an_empty_registry_draws_the_screen_the_switch_off_draws(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $withTheSwitchOff = $this->renderCountryField();

        config(['gcc.storefronts.legal_entities.enabled' => true]);
        app(LegalEntityRegistry::class)->flush();

        $this->assertSame($withTheSwitchOff, $this->renderCountryField(), 'an empty registry draws nothing of its own');
    }

    public function test_a_country_without_a_registered_company_draws_no_company(): void
    {
        $this->turnLegalEntitiesOn();
        $this->registerEntity(country: 'KW');

        $html = $this->renderCountryField();

        $this->assertStringNotContainsString('selling-company', $html, 'the default storefront is Saudi and no Saudi company is registered');
    }

    // ---- what is drawn once a company is registered ----------------------------------------------------------------------------

    public function test_a_new_product_shows_the_company_registered_for_the_default_storefront(): void
    {
        $this->turnLegalEntitiesOn();
        $this->registerEntity(country: 'SA');

        $html = $this->renderCountryField();

        $this->assertStringContainsString('id="selling-company"', $html);
        $this->assertStringContainsString('Lavkoo SA Trading Co.', $html);
        $this->assertStringContainsString('disabled', $html, 'the company is read only: it follows from the country');
        $this->assertStringNotContainsString('name="legal_entity_id"', $html, 'nothing about a company is posted back');
    }

    public function test_an_existing_product_shows_the_company_it_names_rather_than_the_countrys_current_one(): void
    {
        $this->turnLegalEntitiesOn();
        $kuwait = $this->registerEntity(country: 'KW');
        $this->registerEntity(country: 'SA');
        $product = $this->productOfCountry(id: 2, country: 'KW', company: $kuwait->id);

        $html = $this->renderCountryField(['product' => $product]);

        $this->assertStringContainsString('Lavkoo KW Trading Co.', $html);
        $this->assertStringNotContainsString('Lavkoo SA Trading Co.', $html);
    }

    public function test_a_product_that_names_a_company_the_registry_no_longer_holds_shows_none(): void
    {
        $this->turnLegalEntitiesOn();
        $kuwait = $this->registerEntity(country: 'KW');
        $product = $this->productOfCountry(id: 3, country: 'KW', company: $kuwait->id);
        $kuwait->update(['status' => false]);
        app(LegalEntityRegistry::class)->flush();

        $html = $this->renderCountryField(['product' => $product]);

        $this->assertStringNotContainsString('selling-company', $html);
        $this->assertStringNotContainsString('Lavkoo KW Trading Co.', $html);
    }

    public function test_the_words_the_admin_reads_are_in_the_language_files(): void
    {
        $this->turnLegalEntitiesOn();
        $this->registerEntity(country: 'SA');
        $before = array_map(fn (string $locale) => md5_file(base_path("resources/lang/{$locale}/new-messages.php")), ['en', 'sa', 'ae']);

        $this->renderCountryField();

        $after = array_map(fn (string $locale) => md5_file(base_path("resources/lang/{$locale}/new-messages.php")), ['en', 'sa', 'ae']);
        $this->assertSame($before, $after, 'rendering added an untranslated key to a language file');
    }
}
