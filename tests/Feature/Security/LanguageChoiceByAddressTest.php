<?php

namespace Tests\Feature\Security;

use App\Models\DealOfTheDay;
use App\Models\FlashDeal;
use App\Models\Translation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Modules\Blog\app\Models\Blog;
use Modules\Blog\app\Models\BlogCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\TestCase;

/**
 * The panels and the seller APIs show a title as it was typed, every other page in the shopper's language. The decision is made on path
 * segments, so an address that merely contains /vendor, /seller or /admin (a product called seller-bag, a store installed in /vendor-store,
 * a host called vendor.example.com) is a storefront page like any other.
 */
class LanguageChoiceByAddressTest extends TestCase
{
    use BuildsFulfillmentSchema;

    private const SHOPPER_LANGUAGE = 'ar';

    private const APP_LANGUAGE = 'en';

    private const INSTALLED_IN_VENDOR_STORE = ['SCRIPT_NAME' => '/vendor-store/index.php', 'SCRIPT_FILENAME' => '/var/www/html/vendor-store/index.php', 'PHP_SELF' => '/vendor-store/index.php'];

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

    /** Pages every shopper sees, including those whose address only contains the words the panels are recognised by. */
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
            'a blog post named after a vendor' => ['http://localhost/blog/vendor-news', []],
            'a host that starts with vendor' => ['http://vendor.example.com/', []],
            'a store installed in /vendor-store' => ['http://localhost/vendor-store/', self::INSTALLED_IN_VENDOR_STORE],
        ];
    }

    public static function panelPages(): array
    {
        return [
            'the admin panel' => ['http://localhost/admin/deal/flash', []],
            'the vendor panel' => ['http://localhost/vendor/deal/list', []],
            'the seller area' => ['http://localhost/seller/deals', []],
            'the seller API v2' => ['http://localhost/api/v2/seller/products/list', []],
            'the seller API v3' => ['http://localhost/api/v3/seller/products/list', []],
            'the admin panel of a store installed in /vendor-store' => ['http://localhost/vendor-store/admin/deal/flash', self::INSTALLED_IN_VENDOR_STORE],
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
}
