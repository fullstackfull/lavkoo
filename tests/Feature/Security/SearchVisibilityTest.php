<?php

namespace Tests\Feature\Security;

use App\Services\Search\ProductSearchService;
use App\Utils\ProductManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsProductCatalog;
use Tests\TestCase;

/**
 * A search answers only with products a customer may see. The translated-name searches and the category filter used to add products
 * the active scope had already left out, so a draft, disabled or unapproved product could be found by name or by its category, and
 * the word-by-word hint of an empty search used to tell which words a product that is not for sale contains.
 */
class SearchVisibilityTest extends TestCase
{
    use BuildsProductCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->buildCatalogTables();
        $this->registerMySqlLocate();
        $this->seedCatalog();
    }

    /**
     * The categories 20 > 21 > 12 hold products that match a search in category 12 only through their sub sub category: their main
     * and sub category are others. Product 6 is visible, 7 is disabled, 8 is visible but named otherwise, 9 is a draft and 10 belongs
     * to a seller who is still pending.
     */
    private function seedDeepCategoryProducts(): void
    {
        DB::table('categories')->insert([
            ['id' => self::OTHER_CATEGORY, 'name' => 'Chairs', 'parent_id' => 0, 'position' => 0],
            ['id' => self::OTHER_SUB_CATEGORY, 'name' => 'Office chairs', 'parent_id' => self::OTHER_CATEGORY, 'position' => 1],
            ['id' => self::SUB_SUB_CATEGORY, 'name' => 'Desk chairs', 'parent_id' => self::OTHER_SUB_CATEGORY, 'position' => 2],
        ]);
        $insertDeepProduct = fn (int $id, string $name, int $status = 1, int $requestStatus = 1, ?int $userId = null, string $addedBy = 'admin') => $this->insertProduct(
            id: $id, name: $name, status: $status, requestStatus: $requestStatus, userId: $userId, addedBy: $addedBy,
            category: self::OTHER_CATEGORY, subCategory: self::OTHER_SUB_CATEGORY, subSubCategory: self::SUB_SUB_CATEGORY,
        );
        $insertDeepProduct(id: 6, name: 'Alpha deep lamp');
        $insertDeepProduct(id: 7, name: 'Alpha hidden deep lamp', status: 0);
        $insertDeepProduct(id: 8, name: 'Zeta deep chair');
        $insertDeepProduct(id: 9, name: 'Alpha draft deep lamp', requestStatus: 0);
        $insertDeepProduct(id: 10, name: 'Alpha pending seller deep lamp', userId: 9, addedBy: 'seller');
    }

    public static function nameSearches(): array
    {
        return [
            'translated name search for the API' => [fn (string $name) => ProductManager::translated_product_search(base64_encode($name))['products']],
            'translated name search for the web' => [fn (string $name) => ProductManager::getTranslatedProductSearchForWeb($name)['products']],
        ];
    }

    public static function categorySearches(): array
    {
        return [
            'API search' => [fn (string $name, int $category) => ProductManager::search_products(Request::create('/'), $name, $category)['products']],
            'web search' => [fn (string $name, int $category) => ProductManager::getSearchProductsForWeb($name, $category)['products']],
            'translated API search' => [fn (string $name, int $category) => ProductManager::translated_product_search(base64_encode($name), $category)['products']],
        ];
    }

    public function test_the_web_product_list_filtered_by_a_search_category_shows_only_visible_products(): void
    {
        $request = Request::create('/products', 'GET', [
            'data_from' => 'search',
            'category_ids' => [self::CATEGORY],
            'search_category_value' => self::SUB_CATEGORY,
        ]);

        $listed = ProductManager::getProductListData(request: $request);

        $this->assertSame([1, 3], $this->idsOf($listed));
    }

    #[DataProvider('nameSearches')]
    public function test_a_translated_name_search_finds_only_products_a_customer_may_see(Closure $search): void
    {
        $this->assertSame([1], $this->idsOf($search('Alpha')));
    }

    #[DataProvider('categorySearches')]
    public function test_a_search_inside_a_sub_category_finds_only_visible_products_that_match_the_name(Closure $search): void
    {
        $this->assertSame([1], $this->idsOf($search('Alpha', self::SUB_CATEGORY)));
    }

    #[DataProvider('categorySearches')]
    public function test_a_search_inside_a_main_category_finds_only_visible_products_that_match_the_name(Closure $search): void
    {
        $this->assertSame([1], $this->idsOf($search('Alpha', self::CATEGORY)));
    }

    #[DataProvider('categorySearches')]
    public function test_a_search_inside_a_sub_sub_category_finds_only_visible_products_that_match_the_name(Closure $search): void
    {
        $this->seedDeepCategoryProducts();

        $this->assertSame([6], $this->idsOf($search('Alpha', self::SUB_SUB_CATEGORY)));
    }

    public function test_the_web_product_list_filtered_by_a_sub_sub_category_shows_only_visible_products(): void
    {
        $this->seedDeepCategoryProducts();
        $request = Request::create('/products', 'GET', [
            'data_from' => 'search',
            'category_ids' => [self::OTHER_CATEGORY],
            'search_category_value' => self::SUB_SUB_CATEGORY,
        ]);

        $listed = ProductManager::getProductListData(request: $request);

        $this->assertSame([6, 8], $this->idsOf($listed));
    }

    public function test_the_translated_web_search_inside_a_category_finds_only_visible_products_that_match_the_name(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite has no JSON containment of an object in a list, so the category list of a product can never match there; the MySQL and MariaDB jobs run this test.');
        }
        $this->seedDeepCategoryProducts();
        $search = fn (int $category) => $this->idsOf(ProductManager::getTranslatedProductSearchForWeb('Alpha', (string) $category)['products']);

        $this->assertSame([1], $search(self::SUB_CATEGORY));
        $this->assertSame([1], $search(self::CATEGORY));
        $this->assertSame([6], $search(self::SUB_SUB_CATEGORY));
        $this->assertSame([], $search(99));
    }

    public function test_a_word_found_in_a_product_a_customer_may_see_is_not_reported_as_unmatched(): void
    {
        $this->indexEveryProduct();
        $search = app(ProductSearchService::class);

        $this->assertSame([], $search->unmatchedTerms('alpha lamp zeta chair'));
        $this->assertSame(['nonexistentwordxyz'], $search->unmatchedTerms('alpha nonexistentwordxyz'));
    }

    public function test_a_word_found_only_in_a_product_a_customer_may_not_see_is_reported_as_unmatched(): void
    {
        DB::table('products')->where('id', 2)->update(['details' => '<p>Unreleased crimsonwidget edition</p>']);
        $this->indexEveryProduct();

        $unmatched = app(ProductSearchService::class)->unmatchedTerms('hidden draft pending crimsonwidget');

        $this->assertSame(['hidden', 'draft', 'pending', 'crimsonwidget'], $unmatched);
    }
}
