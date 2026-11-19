<?php

namespace Tests\Feature\Storefront;

use App\Models\ThemeBlock;
use App\Models\ThemeSection;
use App\Services\Storefront\StorefrontContext;
use App\Services\Theme\SectionDataResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsProductCatalog;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\BuildsThemeWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * GCC F-10.2: a banner belongs to no country, but the product it points at does, so a card that a theme section draws from a banner is delivered to the storefronts that may sell that product and to no other.
 * Two kinds of section draw such cards: the one that shows the banners created in Promotion -> Banners (`store_banner`, which reads them through SectionDataResolver::dashboardBanners) and the sections whose
 * blocks are linked to one banner each (through blockCards): the four types of block that can be, in the section that holds each of them. Both are drawn by the web page and delivered to the app, from the one resolver. A banner that points at a category,
 * a brand or nothing is delivered everywhere, as it always was; so is every banner while storefronts are off, and to the admin panel, which works across every country.
 *
 * Which theme folder the dashboard banners come from is decided before the storefront narrows them: the folder the theme left behind is read only when the active folder holds no banner of the type at all, and
 * never because the ones it holds happen to point at another country's products.
 *
 * The six banners are told apart by their titles, and the list is in the order of their priority, which puts two foreign ones first: a section that is limited to two banners must still show two of the banners
 * its storefront is offered, so the limit counts what a storefront may show and not what it may not.
 */
class StorefrontThemeBannerCardsTest extends TestCase
{
    use BuildsProductCatalog;
    use BuildsStorefrontCatalog;
    use BuildsThemeWorld;
    use RestoresLanguageFiles;

    /** Every banner of the shop by title, in the order of its priority, with what it points at: a product (1 is Saudi, 2 Emirati, 3 Kuwaiti), a category, a brand or nothing. */
    private const BANNERS = [
        'Emirati serum offer' => ['product', 2],
        'Kuwaiti serum offer' => ['product', 3],
        'Saudi serum offer' => ['product', 1],
        'Category offer' => ['category', self::CATEGORY],
        'Brand offer' => ['brand', 7],
        'Plain offer' => [null, null],
    ];

    /** The sections whose blocks can be linked to a banner, by the type of block each holds. */
    private const TILE_SECTIONS = ['banner_mosaic' => 'mosaic_tile', 'hero_banner' => 'slide', 'promotional_banner' => 'banner', 'split_banner' => 'split'];

    /** The banners that point at no product: every storefront is delivered these. */
    private const BANNERS_OF_NO_PRODUCT = ['Category offer', 'Brand offer', 'Plain offer'];

    /** A banner the theme left behind: it belongs to a folder that is not the active one. */
    private const RETIRED = 'Retired offer';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCatalogTables();
        $this->runStorefrontCountryMigration();
        $this->buildThemeWorld();
        $this->switchStorefrontThemesOn();
        $this->openEveryStorefront();
        $this->giveTheProductsAndTheirBanners();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->dropThemeWorld();
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function giveTheProductsAndTheirBanners(): void
    {
        foreach ([1 => ['Saudi serum', 'SA'], 2 => ['Emirati serum', 'AE'], 3 => ['Kuwaiti serum', 'KW']] as $id => [$name, $country]) {
            $this->insertProduct(id: $id, name: $name, extra: ['storefront_country' => $country]);
        }

        $priority = 0;
        foreach (self::BANNERS as $title => [$resourceType, $resourceId]) {
            DB::table('banners')->insert([
                'banner_type' => 'Main Banner', 'theme' => 'default', 'published' => 1, 'priority' => ++$priority, 'title' => $title,
                'resource_type' => $resourceType, 'resource_id' => $resourceId, 'url' => '', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** @return int the id of the banner with this title */
    private function bannerId(string $title): int
    {
        return (int) DB::table('banners')->where('title', $title)->value('id');
    }

    /**
     * The Saudi theme, which is the default storefront's and the one every other storefront is served: a home page of the sections asked for, each of the type `store_banner` (the dashboard banners) or of a type
     * whose blocks are linked to banners (one block for each of the banners). The web page draws every section of the theme and a title in any of them would pass, so a test that tells them apart asks for one.
     *
     * @param list<string> $sections
     */
    private function givePublishedBannerTheme(int $limit = 6, array $sections = ['store_banner', 'banner_mosaic']): void
    {
        $version = $this->givePublishedVersion(theme: $this->giveTheme(name: 'Saudi', country: null, active: true), revision: 1, spacerHeight: 10);

        foreach ($sections as $position => $type) {
            $section = ThemeSection::query()->create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => $type, 'sort_order' => 2 + $position, 'settings' => $type === 'store_banner' ? ['banner_type' => 'Main Banner', 'layout' => 'grid', 'limit' => $limit] : []]);

            if (isset(self::TILE_SECTIONS[$type])) {
                $this->giveATileForEachBanner(section: $section, blockType: self::TILE_SECTIONS[$type]);
            }
        }
    }

    private function giveATileForEachBanner(ThemeSection $section, string $blockType): void
    {
        foreach (array_keys(self::BANNERS) as $order => $title) {
            ThemeBlock::query()->create(['theme_section_id' => $section->id, 'type' => $blockType, 'sort_order' => $order, 'settings' => ['banner_id' => $this->bannerId(title: $title), 'span' => 'small']]);
        }
    }

    /** @return array<string, array{0: string, 1: list<string>}> what each kind of storefront is offered, in the order of the priority of the banners: the banners of its own products and those of no product */
    public static function bannersOfEachStorefront(): array
    {
        return [
            'the default storefront' => ['SA', ['Saudi serum offer', ...self::BANNERS_OF_NO_PRODUCT]],
            'the Emirati storefront' => ['AE', ['Emirati serum offer', ...self::BANNERS_OF_NO_PRODUCT]],
            'the Kuwaiti storefront' => ['KW', ['Kuwaiti serum offer', ...self::BANNERS_OF_NO_PRODUCT]],
            'a storefront that sells nothing yet' => ['QA', self::BANNERS_OF_NO_PRODUCT],
        ];
    }

    /** @return array<string, array{0: string, 1: string, 2: list<string>}> each kind of section whose blocks are linked to banners, for each kind of storefront: the type of the section, the storefront and what it is offered */
    public static function tileSectionsOfEachStorefront(): array
    {
        $cases = [];
        foreach (self::bannersOfEachStorefront() as $storefront => [$country, $offered]) {
            foreach (array_keys(self::TILE_SECTIONS) as $type) {
                $cases["{$storefront}, {$type}"] = [$type, $country, $offered];
            }
        }

        return $cases;
    }

    /** @return list<string> the titles of the cards of the first section of the type that the app is given for the home page, as the storefront it names */
    private function titlesTheAppIsGiven(string $type, string $country): array
    {
        $home = $this->getJson('/api/v1/theme/home', ['X-Storefront' => $country])->assertOk();
        $section = collect($home->json('sections'))->firstWhere('type', $type);

        return array_column($section['cards'] ?? [], 'title');
    }

    /** @return list<string> the titles of the cards of the first section of the type that the sections endpoint of the app answers for the home page, as the storefront it names */
    private function titlesTheSectionsEndpointGives(string $type, string $country): array
    {
        $sections = $this->getJson('/api/v1/theme/sections?page=home', ['X-Storefront' => $country])->assertOk();

        return array_column(collect($sections->json('sections'))->firstWhere('type', $type)['cards'] ?? [], 'title');
    }

    private function webPageOf(string $country): string
    {
        $this->serveRequestFromStorefront(country: $country);

        return view('theme-sections.home')->render();
    }

    /** @param list<string> $offered */
    private function assertPageDrawsOnly(array $offered, string $page, string $country): void
    {
        foreach (array_keys(self::BANNERS) as $title) {
            in_array($title, $offered, true)
                ? $this->assertStringContainsString($title, $page, "{$country} is not shown the banner [{$title}].")
                : $this->assertStringNotContainsString($title, $page, "{$country} is shown the banner [{$title}], which points at a product it may not sell.");
        }
    }

    // ---- the banners created in Promotion -> Banners ---------------------------------------------------------------------------------------

    /** @param list<string> $offered */
    #[DataProvider('bannersOfEachStorefront')]
    public function test_the_dashboard_banners_a_storefront_is_given_are_those_of_its_own_products_and_those_of_no_product(string $country, array $offered): void
    {
        $this->givePublishedBannerTheme();

        $this->assertSame($offered, $this->titlesTheAppIsGiven(type: 'store_banner', country: $country));
    }

    /** @param list<string> $offered */
    #[DataProvider('bannersOfEachStorefront')]
    public function test_the_banner_section_of_the_web_page_draws_the_banners_of_the_storefront_and_no_other(string $country, array $offered): void
    {
        $this->givePublishedBannerTheme(sections: ['store_banner']);

        $this->assertPageDrawsOnly(offered: $offered, page: $this->webPageOf(country: $country), country: $country);
    }

    /** @param list<string> $offered */
    #[DataProvider('bannersOfEachStorefront')]
    public function test_the_resolver_hands_the_banners_the_storefront_is_offered_to_every_surface_in_the_order_of_their_priority(string $country, array $offered): void
    {
        $this->serveRequestFromStorefront(country: $country);

        $cards = app(SectionDataResolver::class)->dashboardBanners('Main Banner', 6);

        $this->assertSame($offered, array_column($cards, 'title'));
        $this->assertSame(array_map(fn (string $title): int => $this->bannerId(title: $title), $offered), array_column($cards, 'banner_id'));
    }

    public function test_the_limit_of_the_section_counts_the_banners_the_storefront_is_offered_and_not_the_ones_it_is_not(): void
    {
        $this->givePublishedBannerTheme(limit: 2);

        $this->assertSame(['Emirati serum offer', 'Category offer'], $this->titlesTheAppIsGiven(type: 'store_banner', country: 'AE'));
        $this->assertSame(['Saudi serum offer', 'Category offer'], $this->titlesTheAppIsGiven(type: 'store_banner', country: 'SA'));
        $this->assertSame(['Category offer', 'Brand offer'], $this->titlesTheAppIsGiven(type: 'store_banner', country: 'QA'));
    }

    // ---- the tiles that are linked to a banner ---------------------------------------------------------------------------------------------

    /** @param list<string> $offered */
    #[DataProvider('tileSectionsOfEachStorefront')]
    public function test_the_tiles_of_a_section_that_are_linked_to_banners_are_delivered_to_the_storefronts_that_may_sell_their_product_only(string $type, string $country, array $offered): void
    {
        $this->givePublishedBannerTheme(sections: [$type]);

        $this->assertSame($offered, $this->titlesTheAppIsGiven(type: $type, country: $country));
    }

    /** @param list<string> $offered */
    #[DataProvider('tileSectionsOfEachStorefront')]
    public function test_the_tiles_of_the_web_page_that_are_linked_to_banners_are_drawn_to_the_storefronts_that_may_sell_their_product_only(string $type, string $country, array $offered): void
    {
        $this->givePublishedBannerTheme(sections: [$type]);

        $this->assertPageDrawsOnly(offered: $offered, page: $this->webPageOf(country: $country), country: $country);
    }

    /** @param list<string> $offered */
    #[DataProvider('bannersOfEachStorefront')]
    public function test_the_sections_endpoint_of_the_app_gives_the_same_cards_for_the_dashboard_banners_and_for_the_linked_tiles(string $country, array $offered): void
    {
        $this->givePublishedBannerTheme();

        $this->assertSame($offered, $this->titlesTheSectionsEndpointGives(type: 'store_banner', country: $country));
        $this->assertSame($offered, $this->titlesTheSectionsEndpointGives(type: 'banner_mosaic', country: $country));
    }

    // ---- what is not narrowed --------------------------------------------------------------------------------------------------------------

    public function test_while_storefronts_are_off_every_banner_is_delivered_to_every_storefront_as_it_always_was(): void
    {
        $this->givePublishedBannerTheme();
        config(['gcc.storefronts.enabled' => false]);

        foreach (['SA', 'AE', 'QA'] as $country) {
            $this->assertSame(array_keys(self::BANNERS), $this->titlesTheAppIsGiven(type: 'store_banner', country: $country), $country);
            $this->assertSame(array_keys(self::BANNERS), $this->titlesTheAppIsGiven(type: 'banner_mosaic', country: $country), $country);
        }
    }

    public function test_which_folder_the_dashboard_banners_come_from_is_decided_before_the_storefront_narrows_them(): void
    {
        $this->giveARetiredBannerInAnotherFolder();
        DB::table('banners')->whereNotIn('title', ['Emirati serum offer', self::RETIRED])->delete();
        $this->givePublishedBannerTheme(sections: ['store_banner']);

        $this->assertSame(['Emirati serum offer'], $this->titlesTheAppIsGiven(type: 'store_banner', country: 'AE'));
        $this->assertSame([], $this->titlesTheAppIsGiven(type: 'store_banner', country: 'KW'));
    }

    public function test_the_banners_of_a_folder_the_theme_left_behind_are_still_delivered_while_the_active_folder_holds_none(): void
    {
        DB::table('banners')->update(['theme' => 'aster']);
        $this->givePublishedBannerTheme(sections: ['store_banner']);

        $this->assertSame(['Emirati serum offer', ...self::BANNERS_OF_NO_PRODUCT], $this->titlesTheAppIsGiven(type: 'store_banner', country: 'AE'));
        $this->assertSame(self::BANNERS_OF_NO_PRODUCT, $this->titlesTheAppIsGiven(type: 'store_banner', country: 'QA'));
    }

    private function giveARetiredBannerInAnotherFolder(): void
    {
        DB::table('banners')->insert([
            'banner_type' => 'Main Banner', 'theme' => 'aster', 'published' => 1, 'priority' => 9, 'title' => self::RETIRED,
            'resource_type' => null, 'resource_id' => null, 'url' => '', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_admin_panel_is_given_every_banner_because_it_works_across_every_country(): void
    {
        $this->serveAsHttpRequest(uri: '/admin/banners');
        $this->app->forgetInstance(StorefrontContext::class);

        $this->assertSame(array_keys(self::BANNERS), array_column(app(SectionDataResolver::class)->dashboardBanners('Main Banner', 6), 'title'));
    }

    public function test_a_banner_whose_product_is_gone_is_delivered_as_the_other_banner_lists_do_because_no_storefront_owns_it(): void
    {
        $this->givePublishedBannerTheme();
        DB::table('products')->where('id', 2)->delete();

        $this->assertSame(['Emirati serum offer', ...self::BANNERS_OF_NO_PRODUCT], $this->titlesTheAppIsGiven(type: 'store_banner', country: 'AE'));
        $this->assertSame(['Emirati serum offer', 'Saudi serum offer', ...self::BANNERS_OF_NO_PRODUCT], $this->titlesTheAppIsGiven(type: 'store_banner', country: 'SA'));
    }

    public function test_the_default_storefront_is_delivered_what_it_is_delivered_with_storefronts_off_while_no_banner_points_at_another_countrys_product(): void
    {
        $this->givePublishedBannerTheme();
        DB::table('banners')->whereIn('title', ['Emirati serum offer', 'Kuwaiti serum offer'])->delete();

        $withStorefrontsOn = $this->homeOfTheDefaultStorefront();
        config(['gcc.storefronts.enabled' => false]);
        $withStorefrontsOff = $this->homeOfTheDefaultStorefront();

        $this->assertSame($withStorefrontsOff, $withStorefrontsOn);
        $this->assertSame(['Saudi serum offer', ...self::BANNERS_OF_NO_PRODUCT], array_column(collect($withStorefrontsOn['sections'])->firstWhere('type', 'store_banner')['cards'], 'title'));
        $this->assertSame(['Saudi serum offer', ...self::BANNERS_OF_NO_PRODUCT], array_column(collect($withStorefrontsOn['sections'])->firstWhere('type', 'banner_mosaic')['cards'], 'title'));
    }

    /** @return array<string, mixed> everything the app is given for the home page, as the default storefront, from a cache that holds nothing: the key of the default storefront is the same with storefronts off */
    private function homeOfTheDefaultStorefront(): array
    {
        Cache::flush();

        return $this->getJson('/api/v1/theme/home', ['X-Storefront' => 'SA'])->assertOk()->json();
    }

    // ---- what the narrowing costs, and what it does when it cannot be read -----------------------------------------------------------------

    /** @return list<string> the text of every statement the callback ran, without the quotes each engine puts around a name and without the questions it asks the schema */
    private function statementsOf(callable $callback): array
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $callback();
        $connection->disableQueryLog();

        $statements = array_map(static fn (string $statement): string => str_replace(['"', '`'], '', $statement), array_column($connection->getQueryLog(), 'query'));

        return array_values(array_filter($statements, static fn (string $statement): bool => preg_match('/pragma|information_schema|sqlite_master/', $statement) !== 1));
    }

    /**
     * @param list<string> $statements
     * @return list<string> those of them that read the banners table
     */
    private function readsOfTheBanners(array $statements): array
    {
        return array_values(array_filter($statements, static fn (string $statement): bool => str_contains($statement, 'from banners')));
    }

    public function test_the_banners_of_a_section_are_read_with_the_one_statement_they_were_always_read_with_while_storefronts_are_off(): void
    {
        config(['gcc.storefronts.enabled' => false]);
        $this->app->forgetInstance(StorefrontContext::class);

        $statements = $this->statementsOf(fn () => app(SectionDataResolver::class)->dashboardBanners('Main Banner', 6));

        $this->assertSame(
            ['select * from banners where published = ? and banner_type = ? and theme = ? order by priority asc, id asc limit 6'],
            $this->readsOfTheBanners($statements),
            'the switched-off storefront reads its banners once, as it did before any of this was written',
        );
        $this->assertCount(2, $statements, "the banner read and the one that loads their images, and nothing else:\n" . implode("\n", $statements));
    }

    public function test_the_storefront_narrows_the_banners_in_the_read_that_fetches_them_and_not_by_reading_every_banner_of_the_shop(): void
    {
        $this->serveRequestFromStorefront(country: 'KW');
        app(SectionDataResolver::class)->dashboardBanners('Main Banner', 6);

        $statements = $this->statementsOf(fn () => app(SectionDataResolver::class)->dashboardBanners('Main Banner', 6));

        $this->assertCount(1, $this->readsOfTheBanners($statements), "the banners are read once, not once to narrow them and once to fetch them:\n" . implode("\n", $statements));
        $this->assertStringContainsString('from products', $this->readsOfTheBanners($statements)[0], 'the storefront narrows the read itself, so the limit counts the banners it may show');
        $this->assertCount(2, $statements, "the banner read and the one that loads their images, and nothing else:\n" . implode("\n", $statements));
    }

    public function test_a_section_draws_nothing_rather_than_another_countrys_banner_when_the_read_that_narrows_it_cannot_be_answered(): void
    {
        $this->givePublishedBannerTheme();
        $offered = ['Kuwaiti serum offer', ...self::BANNERS_OF_NO_PRODUCT];
        $this->assertSame($offered, $this->titlesTheAppIsGiven(type: 'store_banner', country: 'KW'));
        $this->assertSame($offered, $this->titlesTheAppIsGiven(type: 'banner_mosaic', country: 'KW'));

        Schema::drop('products');
        Cache::flush();

        $this->assertSame([], $this->titlesTheAppIsGiven(type: 'store_banner', country: 'KW'), 'the banners created in the dashboard');
        $this->assertSame([], $this->titlesTheAppIsGiven(type: 'banner_mosaic', country: 'KW'), 'the tiles linked to a banner');
    }
}
