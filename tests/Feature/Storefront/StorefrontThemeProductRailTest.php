<?php

namespace Tests\Feature\Storefront;

use App\Models\ThemeSection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsProductCatalog;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\BuildsThemeWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * GCC F-10: a product rail in a theme section shows only the products of the storefront that asks. A rail is the same section in every storefront, so what tells two storefronts' rails apart
 * is the catalogue each one reads: the web page draws the products of its own storefront, and the home payload tells the app to fetch them from an endpoint that answers for the storefront the app
 * names in its header. A storefront that is served the default storefront's theme still shows its own products in it, and one that sells nothing yet shows an empty rail, never another country's.
 */
class StorefrontThemeProductRailTest extends TestCase
{
    use BuildsProductCatalog;
    use BuildsStorefrontCatalog;
    use BuildsThemeWorld;
    use RestoresLanguageFiles;

    private const BRAND = 7;

    /** The products each storefront sells, by name. No name is part of another, so a page that holds a name shows that product. */
    private const PRODUCTS_OF = [
        'SA' => ['Saudi serum', 'Saudi cream'],
        'AE' => ['Emirati serum', 'Emirati cream'],
        'KW' => ['Kuwaiti serum', 'Kuwaiti cream'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCatalogTables();
        $this->runStorefrontCountryMigration();
        $this->registerMySqlLocate();
        $this->buildThemeWorld();
        $this->switchStorefrontThemesOn();
        $this->openEveryStorefront();
        $this->seedStoreSettings();
        $this->giveEachStorefrontItsProducts();
    }

    protected function tearDown(): void
    {
        $this->dropThemeWorld();
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function seedStoreSettings(): void
    {
        DB::table('currencies')->insert(['id' => 1, 'name' => 'USD', 'symbol' => '$', 'code' => 'USD', 'exchange_rate' => 1, 'status' => 1]);
        foreach (['system_default_currency' => '1', 'currency_model' => 'single_currency', 'currency_symbol_position' => 'left', 'decimal_point_settings' => '2', 'stock_limit' => '5'] as $type => $value) {
            $this->setBusinessSetting($type, $value);
        }
        $this->setBusinessSetting('language', [['id' => 1, 'name' => 'english', 'code' => 'en', 'status' => 1, 'default' => true]]);
        DB::table('brands')->insert(['id' => self::BRAND, 'name' => 'Glow', 'slug' => 'glow', 'status' => 1]);
        Cache::flush();
    }

    /** Two products for each storefront, with the ids 1 and 2 for the first, 3 and 4 for the second and so on, all of one brand and one category so that every source of a rail finds them. */
    private function giveEachStorefrontItsProducts(): void
    {
        $id = 0;
        foreach (self::PRODUCTS_OF as $country => $names) {
            foreach ($names as $name) {
                $this->insertProduct(id: ++$id, name: $name, extra: ['storefront_country' => $country, 'featured' => 1, 'brand_id' => self::BRAND]);
            }
        }
    }

    /**
     * The Saudi theme, which is the default storefront's, and the Emirati one, each with a product rail titled with the name of its theme, so that a page says which theme drew it. Kuwait and
     * the other storefronts have no theme of their own.
     *
     * @param array<string, mixed> $source what the merchant chose the rail to show
     */
    private function giveEveryThemeTheRail(array $source): void
    {
        foreach (['Saudi' => null, 'Emirati' => 'AE'] as $name => $country) {
            $version = $this->givePublishedVersion(theme: $this->giveTheme(name: $name, country: $country, active: true), revision: 1, spacerHeight: 10);

            ThemeSection::query()->create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => 'product_slider', 'sort_order' => 2, 'settings' => $source + ['title' => "{$name} picks", 'limit' => 10]]);
        }
    }

    /** @return array<string, array{0: array<string, mixed>}> every way a rail can be told what to show that both the web and the app can answer here */
    public static function whatARailCanShow(): array
    {
        return [
            'the featured products' => [['source' => 'featured']],
            'the newest products' => [['source' => 'new_arrival']],
            'the products of a category' => [['source' => 'category', 'source_id' => self::CATEGORY]],
            'the products of a brand' => [['source' => 'brand', 'source_id' => self::BRAND]],
            'products picked by hand, of every storefront' => [['source' => 'manual', 'product_ids' => '1,3,5,2,4,6']],
        ];
    }

    private function webPageOf(string $country): string
    {
        $this->serveRequestFromStorefront($country);

        return view('theme-sections.home')->render();
    }

    /** @return list<string> the names the app is given for the product rail of the home page: the payload says where the rail's data lives, and the app fetches it from there as the storefront it names */
    private function namesTheAppIsGivenFor(string $country): array
    {
        $storefront = ['X-Storefront' => $country];
        $rail = collect($this->getJson('/api/v1/theme/home', $storefront)->assertOk()->json('sections'))->firstWhere('type', 'product_slider');

        $this->assertSame('api', $rail['source']['kind']);
        $names = array_column($this->getJson($rail['source']['endpoint'] . '?' . http_build_query($rail['source']['params']), $storefront)->assertOk()->json('products'), 'name');
        sort($names);

        return $names;
    }

    /** @return list<string> the names of the products of every storefront but this one */
    private function productsOfTheOtherStorefronts(string $country): array
    {
        return array_merge(...array_values(array_diff_key(self::PRODUCTS_OF, [$country => true])));
    }

    // ---- the web page -------------------------------------------------------------------------------------------------------------------------

    #[DataProvider('whatARailCanShow')]
    public function test_the_rail_of_the_web_page_draws_the_products_of_the_storefront_that_asks_and_no_other(array $source): void
    {
        $this->giveEveryThemeTheRail(source: $source);

        foreach (self::PRODUCTS_OF as $country => $own) {
            $page = $this->webPageOf(country: $country);

            foreach ($own as $name) {
                $this->assertStringContainsString($name, $page, "{$country} is not shown its own product {$name}.");
            }
            foreach ($this->productsOfTheOtherStorefronts(country: $country) as $name) {
                $this->assertStringNotContainsString($name, $page, "{$country} is shown {$name}, a product of another storefront.");
            }
        }
    }

    public function test_a_storefront_served_the_default_storefronts_theme_draws_the_rail_of_that_theme_with_its_own_products(): void
    {
        $this->giveEveryThemeTheRail(source: ['source' => 'new_arrival']);

        $kuwaitiPage = $this->webPageOf(country: 'KW');

        $this->assertStringContainsString('Saudi picks', $kuwaitiPage, 'Kuwait has no theme of its own, so it is served the Saudi one');
        $this->assertStringNotContainsString('Emirati picks', $kuwaitiPage);
        $this->assertStringContainsString('Emirati picks', $this->webPageOf(country: 'AE'));
        $this->assertStringContainsString('Kuwaiti serum', $kuwaitiPage);
        $this->assertStringNotContainsString('Saudi serum', $kuwaitiPage);
    }

    public function test_a_storefront_that_sells_nothing_yet_is_drawn_no_product_of_another_country(): void
    {
        $this->giveEveryThemeTheRail(source: ['source' => 'new_arrival']);

        $page = $this->webPageOf(country: 'QA');

        $this->assertStringContainsString('tbs-spacer', $page, 'the page of the Saudi theme is drawn');
        foreach (array_merge(...array_values(self::PRODUCTS_OF)) as $name) {
            $this->assertStringNotContainsString($name, $page);
        }
    }

    // ---- the app ------------------------------------------------------------------------------------------------------------------------------

    #[DataProvider('whatARailCanShow')]
    public function test_the_rail_the_app_is_told_to_fetch_holds_the_products_of_the_storefront_that_asks_and_no_other(array $source): void
    {
        $this->giveEveryThemeTheRail(source: $source);

        foreach (self::PRODUCTS_OF as $country => $own) {
            $names = $this->namesTheAppIsGivenFor(country: $country);

            sort($own);
            $this->assertSame($own, $names, "The rail of {$country} does not hold exactly its own products.");
        }
    }

    public function test_a_storefront_that_sells_nothing_yet_is_given_an_empty_rail_by_the_app_endpoint(): void
    {
        $this->giveEveryThemeTheRail(source: ['source' => 'new_arrival']);

        $home = $this->getJson('/api/v1/theme/home', ['X-Storefront' => 'QA'])->assertOk();

        $this->assertContains('product_slider', array_column($home->json('sections'), 'type'), 'the rail of the default storefront\'s theme is served');
        $this->assertSame([], $this->namesTheAppIsGivenFor(country: 'QA'));
    }
}
