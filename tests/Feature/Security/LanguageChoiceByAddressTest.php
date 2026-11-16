<?php

namespace Tests\Feature\Security;

use App\Models\Attribute;
use App\Models\Brand;
use App\Models\BusinessPage;
use App\Models\Category;
use App\Models\DealOfTheDay;
use App\Models\FlashDeal;
use App\Models\Product;
use App\Models\Translation;
use App\Utils\Helpers;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Blog\app\Models\Blog;
use Modules\Blog\app\Models\BlogCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\TestCase;

/**
 * The address of a request decides which language a page is shown in: the panels and the seller APIs show the text as it was typed, the
 * APIs use the language the app asked for, every other page the shopper's own. The decision is made on path segments, so an address that
 * merely contains /api, /vendor, /seller or /admin (a product called api-gadget, a store installed in /vendor-store, a host called
 * api.example.com) is a storefront page like any other.
 */
class LanguageChoiceByAddressTest extends TestCase
{
    use BuildsFulfillmentSchema;

    private const SHOPPER_LANGUAGE = 'ar';

    private const APP_LANGUAGE = 'en';

    private const INSTALLED_IN_VENDOR_STORE = ['SCRIPT_NAME' => '/vendor-store/index.php', 'SCRIPT_FILENAME' => '/var/www/html/vendor-store/index.php', 'PHP_SELF' => '/vendor-store/index.php'];

    private const INSTALLED_IN_API_SHOP = ['SCRIPT_NAME' => '/api-shop/index.php', 'SCRIPT_FILENAME' => '/var/www/html/api-shop/index.php', 'PHP_SELF' => '/api-shop/index.php'];

    private const MODELS_THAT_LOAD_TRANSLATIONS = [Product::class, Category::class, Brand::class, Attribute::class, FlashDeal::class, DealOfTheDay::class, Blog::class, BlogCategory::class];

    protected function setUp(): void
    {
        parent::setUp();

        App::setLocale(self::APP_LANGUAGE);
        session()->put('local', self::SHOPPER_LANGUAGE);
    }

    private function visit(string $url, array $server = []): void
    {
        app()->instance('request', Request::create($url, 'GET', [], [], [], $server));
    }

    /** Pages every shopper sees, including those whose address only contains the words the panels and the APIs are recognised by. */
    public static function storefrontPages(): array
    {
        return [
            'the home page' => ['http://localhost/', []],
            'a product page' => ['http://localhost/product/gadget', []],
            'the shop page of a vendor' => ['http://localhost/vendor-shop/acme', []],
            'the vendor list' => ['http://localhost/vendors', []],
            'a seller profile' => ['http://localhost/seller-profile/3', []],
            'an address that only looks like the admin one' => ['http://localhost/administrators', []],
            'a product named after a seller' => ['http://localhost/product/seller-bag', []],
            'a product named after the API' => ['http://localhost/product/api-gadget', []],
            'a blog post named after a vendor' => ['http://localhost/blog/vendor-news', []],
            'a blog post named after the API' => ['http://localhost/blog/api-news', []],
            'a host that starts with api' => ['http://api.example.com/', []],
            'a host that starts with vendor' => ['http://vendor.example.com/', []],
            'a store installed in /vendor-store' => ['http://localhost/vendor-store/', self::INSTALLED_IN_VENDOR_STORE],
            'a store installed in /api-shop' => ['http://localhost/api-shop/', self::INSTALLED_IN_API_SHOP],
            'the public vendor registration page' => ['http://localhost/vendor/auth/registration/index', []],
            'the public vendor registration form' => ['http://localhost/vendor/auth/registration/add', []],
        ];
    }

    public static function webPanelPages(): array
    {
        return [
            'the admin panel' => ['http://localhost/admin/deal/flash', []],
            'the vendor panel' => ['http://localhost/vendor/deal/list', []],
            'the seller area' => ['http://localhost/seller/deals', []],
            'the admin panel of a store installed in /vendor-store' => ['http://localhost/vendor-store/admin/deal/flash', self::INSTALLED_IN_VENDOR_STORE],
        ];
    }

    public static function sellerApiRequests(): array
    {
        return [
            'the seller API v2' => ['http://localhost/api/v2/seller/products/list', []],
            'the seller API v3' => ['http://localhost/api/v3/seller/products/list', []],
        ];
    }

    public static function panelPages(): array
    {
        return array_merge(self::webPanelPages(), self::sellerApiRequests());
    }

    public static function languageOfTheName(): array
    {
        return [
            'the vendor registration page, in the language of the shopper' => ['http://localhost/vendor/auth/registration/index', [], 'ar-name'],
            'the home page, in the language of the shopper' => ['http://localhost/', [], 'ar-name'],
            'the admin panel, as it was typed' => ['http://localhost/admin/products/list', [], 'raw-name'],
            'the vendor panel, as it was typed' => ['http://localhost/vendor/products/list', [], 'raw-name'],
            'the seller API v3, in the language of the app' => ['http://localhost/api/v3/seller/products/list', [], 'en-name'],
            'the customer API, in the language of the app' => ['http://localhost/api/v1/products/latest', [], 'en-name'],
        ];
    }

    public static function apiRequests(): array
    {
        return [
            'the customer API' => ['http://localhost/api/v1/products/latest', []],
            'the customer API of a seller shop' => ['http://localhost/api/v1/seller/acme/products', []],
            'the seller API v2' => ['http://localhost/api/v2/seller/products/list', []],
            'the seller API v3' => ['http://localhost/api/v3/seller/products/list', []],
            'the API root' => ['http://localhost/api', []],
            'the API of a store installed in /vendor-store' => ['http://localhost/vendor-store/api/v1/products/latest', self::INSTALLED_IN_VENDOR_STORE],
        ];
    }

    public static function customerApiRequests(): array
    {
        return [
            'the customer API' => ['http://localhost/api/v1/products/latest', []],
            'the customer API of a seller shop' => ['http://localhost/api/v1/seller/acme/products', []],
        ];
    }

    #[DataProvider('storefrontPages')]
    #[DataProvider('customerApiRequests')]
    public function test_the_deal_titles_are_translated_for_a_storefront_page_and_for_the_customer_api(string $url, array $server): void
    {
        $this->visit($url, $server);

        $this->assertSame('TRANSLATED', $this->flashDealTitle());
        $this->assertSame('TRANSLATED', $this->dealOfTheDayTitle());
    }

    #[DataProvider('panelPages')]
    public function test_a_panel_shows_a_deal_title_as_it_was_typed(string $url, array $server): void
    {
        $this->visit($url, $server);

        $this->assertSame('RAW', $this->flashDealTitle());
        $this->assertSame('RAW', $this->dealOfTheDayTitle());
    }

    #[DataProvider('storefrontPages')]
    public function test_a_storefront_page_shows_a_blog_post_and_its_category_in_the_shoppers_language(string $url, array $server): void
    {
        $this->createBlogTranslations();
        $this->visit($url, $server);

        $blog = $this->blog();
        $this->assertSame('TRANSLATED-title', $blog->title);
        $this->assertSame('TRANSLATED-description', $blog->description);
        $this->assertSame('TRANSLATED-name', $this->blogCategory()->name);
    }

    #[DataProvider('panelPages')]
    public function test_a_panel_shows_a_blog_post_and_its_category_as_they_were_typed(string $url, array $server): void
    {
        $this->createBlogTranslations();
        $this->visit($url, $server);

        $blog = $this->blog();
        $this->assertSame('RAW-title', $blog->title);
        $this->assertSame('RAW-description', $blog->description);
        $this->assertSame('RAW-name', $this->blogCategory()->name);
    }

    #[DataProvider('storefrontPages')]
    #[DataProvider('customerApiRequests')]
    #[DataProvider('sellerApiRequests')]
    public function test_the_names_of_a_product_a_category_and_a_brand_are_translated_everywhere_but_in_a_web_panel(string $url, array $server): void
    {
        $this->visit($url, $server);

        $this->assertSame('TRANSLATED-name', $this->product()->name);
        $this->assertSame('TRANSLATED-details', $this->product()->details);
        $this->assertSame('TRANSLATED-name', $this->category()->name);
        $this->assertSame('TRANSLATED-name', $this->brand()->name);
    }

    #[DataProvider('webPanelPages')]
    public function test_a_web_panel_shows_the_names_of_a_product_a_category_and_a_brand_as_they_were_typed(string $url, array $server): void
    {
        $this->visit($url, $server);

        $this->assertSame('RAW-name', $this->product()->name);
        $this->assertSame('RAW-details', $this->product()->details);
        $this->assertSame('RAW-name', $this->category()->name);
        $this->assertSame('RAW-name', $this->brand()->name);
    }

    #[DataProvider('languageOfTheName')]
    public function test_a_product_a_category_and_a_brand_are_read_from_the_database_in_the_language_of_the_address(string $url, array $server, string $name): void
    {
        $this->createNamesWithTranslations();
        $this->visit($url, $server);

        $this->assertSame($name, Product::query()->findOrFail(1)->name);
        $this->assertSame($name, Category::query()->findOrFail(1)->name);
        $this->assertSame($name, Brand::query()->findOrFail(1)->name);
    }

    #[DataProvider('storefrontPages')]
    public function test_a_storefront_page_uses_the_shoppers_language_not_the_one_of_the_app(string $url, array $server): void
    {
        $this->visit($url, $server);

        $this->assertSame(self::SHOPPER_LANGUAGE, getDefaultLanguage());
        $this->assertSame(self::SHOPPER_LANGUAGE, Helpers::default_lang());
        foreach (self::MODELS_THAT_LOAD_TRANSLATIONS as $model) {
            $this->assertSame(self::SHOPPER_LANGUAGE, $this->translationLanguageLoadedBy($model), "{$model} loads its translations in the shopper's language");
        }
    }

    #[DataProvider('apiRequests')]
    public function test_an_api_request_uses_the_language_the_app_asked_for(string $url, array $server): void
    {
        $this->visit($url, $server);

        $this->assertSame(self::APP_LANGUAGE, getDefaultLanguage());
        $this->assertSame(self::APP_LANGUAGE, Helpers::default_lang());
        foreach (self::MODELS_THAT_LOAD_TRANSLATIONS as $model) {
            $this->assertSame(self::APP_LANGUAGE, $this->translationLanguageLoadedBy($model), "{$model} loads its translations in the language of the app");
        }
    }

    #[DataProvider('storefrontPages')]
    public function test_a_storefront_page_lists_all_the_tags_of_a_product(string $url, array $server): void
    {
        $this->visit($url, $server);

        $this->assertNull($this->tagsLimit());
    }

    #[DataProvider('apiRequests')]
    public function test_an_api_request_lists_five_tags_of_a_product_at_most(string $url, array $server): void
    {
        $this->visit($url, $server);

        $this->assertSame(5, $this->tagsLimit());
    }

    #[DataProvider('storefrontPages')]
    public function test_a_storefront_page_gets_no_placeholder_for_a_business_page_without_a_banner(string $url, array $server): void
    {
        $this->visit($url, $server);

        $this->assertSame(404, $this->bannerStatusOfABusinessPageWithoutABanner());
    }

    #[DataProvider('apiRequests')]
    public function test_an_api_request_gets_a_placeholder_for_a_business_page_without_a_banner(string $url, array $server): void
    {
        $this->visit($url, $server);

        $this->assertSame(200, $this->bannerStatusOfABusinessPageWithoutABanner());
    }

    /** How many tags of a product a request loads per product: Laravel keeps the limit of a relation as a limit per parent. */
    private function tagsLimit(): ?int
    {
        return (new Product)->tags()->getQuery()->getQuery()->groupLimit['value'] ?? null;
    }

    private function bannerStatusOfABusinessPageWithoutABanner(): int
    {
        $page = new BusinessPage();
        $page->setRelation('banner', null);

        return $page->banner_full_url['status'];
    }

    private function flashDealTitle(): string
    {
        $deal = new FlashDeal(['title' => 'RAW']);
        $deal->setRelation('translations', collect([new Translation(['value' => 'TRANSLATED'])]));

        return $deal->title;
    }

    private function dealOfTheDayTitle(): string
    {
        $deal = new DealOfTheDay(['title' => 'RAW']);
        $deal->setRelation('translations', collect([new Translation(['value' => 'TRANSLATED'])]));

        return $deal->title;
    }

    private function product(): Product
    {
        $product = new Product(['name' => 'RAW-name', 'details' => 'RAW-details']);
        $product->setRelation('translations', collect([new Translation(['value' => 'TRANSLATED-name']), new Translation(['value' => 'TRANSLATED-details'])]));

        return $product;
    }

    private function category(): Category
    {
        $category = new Category(['name' => 'RAW-name']);
        $category->setRelation('translations', collect([new Translation(['value' => 'TRANSLATED-name'])]));

        return $category;
    }

    private function brand(): Brand
    {
        $brand = new Brand(['name' => 'RAW-name']);
        $brand->setRelation('translations', collect([new Translation(['value' => 'TRANSLATED-name'])]));

        return $brand;
    }

    private function blog(): Blog
    {
        $blog = new Blog(['title' => 'RAW-title', 'description' => 'RAW-description']);
        $blog->id = 1;

        return $blog;
    }

    private function blogCategory(): BlogCategory
    {
        $category = new BlogCategory(['name' => 'RAW-name']);
        $category->id = 1;

        return $category;
    }

    /** A product, a category and a brand named "raw-name" as typed, "ar-name" in the language of the shopper and "en-name" in the one of the app. */
    private function createNamesWithTranslations(): void
    {
        $this->buildCoreSchema();
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->integer('status')->default(1);
            $table->timestamps();
        });

        foreach ([Product::class, Category::class, Brand::class] as $model) {
            DB::table((new $model)->getTable())->insert(['id' => 1, 'name' => 'raw-name']);
            foreach ([self::SHOPPER_LANGUAGE, self::APP_LANGUAGE] as $language) {
                DB::table('translations')->insert([
                    'translationable_type' => (new $model)->getMorphClass(),
                    'translationable_id' => 1,
                    'locale' => $language,
                    'key' => 'name',
                    'value' => "{$language}-name",
                ]);
            }
        }
    }

    private function createBlogTranslations(): void
    {
        $this->buildCoreSchema();
        (require base_path('Modules/Blog/database/migrations/2025_01_02_180849_create_blog_translations_table.php'))->up();

        foreach ([[Blog::class, 'title'], [Blog::class, 'description'], [BlogCategory::class, 'name']] as [$model, $key]) {
            DB::table('blog_translations')->insert([
                'translation_type' => (new $model)->translations()->getMorphClass(),
                'translation_id' => 1,
                'key' => $key,
                'value' => "TRANSLATED-{$key}",
                'locale' => self::SHOPPER_LANGUAGE,
                'is_draft' => 0,
            ]);
        }
    }

    /** The language the translate scope of the model asks its translations for, read off the constraint it puts on the relation. */
    private function translationLanguageLoadedBy(string $model): string
    {
        $constraint = $model::query()->applyScopes()->getEagerLoads()['translations'];
        $translations = Relation::noConstraints(fn () => (new $model)->translations());
        $constraint($translations);

        return collect($translations->getQuery()->getQuery()->wheres)->firstWhere('column', 'locale')['value'];
    }
}
