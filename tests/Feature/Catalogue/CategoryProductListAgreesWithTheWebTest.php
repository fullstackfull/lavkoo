<?php

namespace Tests\Feature\Catalogue;

use App\Utils\CategoryManager;
use App\Utils\ProductManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsProductCatalog;
use Tests\TestCase;

/**
 * B-13: the app's category product list selects the same products the website's category page does.
 *
 * The website selects by the three category id columns (`ProductManager::whereInCategory`). The app's list selected by a SUBSTRING match
 * over `category_ids` -- `like '%"5"%'` -- which is a JSON text column holding the same three ids as a DERIVED copy:
 * `ProductService::getCategoriesArray()` builds it from those very columns. So the columns are the authoritative side and the JSON is the
 * duplicate, and the pattern depends on how whoever wrote the row quoted the ids.
 *
 * A row whose ids were written as NUMBERS rather than as strings therefore matched nothing, so such a product was absent from its own
 * category in the app and present in it on the website -- two lists disagreeing about what is in a category, which is what the project's
 * "web and API logic stay in sync" rule exists to prevent. Every test here compares the two readers against each other rather than against
 * a hand-written list, because agreeing is the requirement.
 */
class CategoryProductListAgreesWithTheWebTest extends TestCase
{
    use BuildsProductCatalog;

    private const TOP = 10;

    private const SUB = 11;

    private const SUB_SUB = 12;

    private const OTHER_TOP = 20;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCatalogTables();
        DB::table('categories')->insert([
            ['id' => self::TOP, 'name' => 'Medicines', 'slug' => 'medicines', 'parent_id' => 0, 'position' => 0],
            ['id' => self::SUB, 'name' => 'Pain relief', 'slug' => 'pain-relief', 'parent_id' => self::TOP, 'position' => 1],
            ['id' => self::SUB_SUB, 'name' => 'Tablets', 'slug' => 'tablets', 'parent_id' => self::SUB, 'position' => 2],
            ['id' => self::OTHER_TOP, 'name' => 'Vitamins', 'slug' => 'vitamins', 'parent_id' => 0, 'position' => 0],
        ]);
    }

    public function test_the_app_list_and_the_website_agree_on_a_top_a_sub_and_a_sub_sub_category(): void
    {
        $this->insertProduct(id: 1, name: 'Paracetamol', category: self::TOP, subCategory: self::SUB, subSubCategory: self::SUB_SUB);
        $this->insertProduct(id: 2, name: 'Ibuprofen', category: self::TOP, subCategory: self::SUB, subSubCategory: null);
        $this->insertProduct(id: 3, name: 'Vitamin C', category: self::OTHER_TOP, subCategory: null, subSubCategory: null);

        foreach ([self::TOP, self::SUB, self::SUB_SUB, self::OTHER_TOP] as $category) {
            $this->assertSame(
                $this->websiteSelects($category),
                $this->appListSelects($category),
                "the app's list and the website's page disagree about category {$category}",
            );
        }
    }

    /** The row shape that used to be invisible: ids written as numbers, which the substring pattern never matched. */
    public function test_a_product_whose_stored_category_ids_are_numbers_is_still_in_its_category(): void
    {
        $this->insertProduct(id: 1, name: 'Paracetamol', category: self::TOP, subCategory: self::SUB, subSubCategory: null);
        DB::table('products')->where('id', 1)->update([
            'category_ids' => json_encode([['id' => self::TOP, 'position' => 1], ['id' => self::SUB, 'position' => 2]]),
        ]);

        $this->assertSame([1], $this->appListSelects(self::TOP), 'a product whose ids are stored as numbers is missing from its category');
        $this->assertSame($this->websiteSelects(self::TOP), $this->appListSelects(self::TOP));
    }

    /** And the row shape with no derived copy at all, which a direct insert or a migration leaves behind. */
    public function test_a_product_with_no_stored_category_ids_is_still_in_its_category(): void
    {
        $this->insertProduct(id: 1, name: 'Paracetamol', category: self::TOP, subCategory: self::SUB, subSubCategory: null, extra: ['category_ids' => null]);

        $this->assertSame([1], $this->appListSelects(self::TOP));
    }

    public function test_a_product_of_another_category_is_not_listed(): void
    {
        $this->insertProduct(id: 1, name: 'Paracetamol', category: self::TOP, subCategory: self::SUB, subSubCategory: null);
        $this->insertProduct(id: 2, name: 'Vitamin C', category: self::OTHER_TOP, subCategory: null, subSubCategory: null);

        $this->assertSame([1], $this->appListSelects(self::TOP));
        $this->assertSame([2], $this->appListSelects(self::OTHER_TOP));
    }

    /** A category whose id is a prefix of another's must not borrow its products: the whole reason a substring match is the wrong tool. */
    public function test_a_category_whose_id_is_a_prefix_of_another_does_not_borrow_its_products(): void
    {
        DB::table('categories')->insert([['id' => 1, 'name' => 'First', 'slug' => 'first', 'parent_id' => 0, 'position' => 0]]);
        DB::table('categories')->insert([['id' => 101, 'name' => 'Hundred and one', 'slug' => 'hundred-and-one', 'parent_id' => 0, 'position' => 0]]);
        $this->insertProduct(id: 1, name: 'In one', category: 1, subCategory: null, subSubCategory: null);
        $this->insertProduct(id: 2, name: 'In a hundred and one', category: 101, subCategory: null, subSubCategory: null);

        $this->assertSame([1], $this->appListSelects(1), 'category 1 was given category 101\'s product');
        $this->assertSame([2], $this->appListSelects(101));
    }

    /** An inactive product is still left out: the reader that was replaced was one condition among several. */
    public function test_the_list_still_leaves_out_a_product_a_customer_may_not_see(): void
    {
        $this->insertProduct(id: 1, name: 'Paracetamol', category: self::TOP, subCategory: self::SUB, subSubCategory: null);
        $this->insertProduct(id: 2, name: 'Withdrawn', status: 0, category: self::TOP, subCategory: self::SUB, subSubCategory: null);

        $this->assertSame([1], $this->appListSelects(self::TOP));
    }

    /** @return list<int> what the app's category product list answers, through the real reader the API controller calls */
    private function appListSelects(int $category): array
    {
        return $this->idsOf(CategoryManager::products($category, Request::create('/api/v1/categories/products/' . $category, 'GET'), 'all'));
    }

    /** @return list<int> what the website's category page selects, through its own reader */
    private function websiteSelects(int $category): array
    {
        return $this->idsOf(
            \App\Models\Product::active()->tap(fn ($query) => ProductManager::whereInCategory($query, $category))->get(),
        );
    }
}
