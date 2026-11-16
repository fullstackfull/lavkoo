<?php

namespace Tests\Feature\Security;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Finder\Finder;
use Tests\Support\BuildsSellerApiWorld;
use Tests\TestCase;

/**
 * translate() answers a key that no language file of the current locale defines with the key's own words and writes the key into that
 * locale's tracked new-messages.php, on the live server, by the first request that hits it. The seller APIs refuse a row that is not the
 * seller's with such a message, so every locale must define every one of them: an Arabic seller is refused in Arabic, and a refusal
 * rewrites no file under resources/lang.
 */
class SellerRefusalMessagesTest extends TestCase
{
    use BuildsSellerApiWorld;

    /** Refusals the seller APIs answer with that are not named in a recordNotFound() call. */
    private const OTHER_REFUSALS = ['order_not_found', 'Order_not_found', 'delivery_man_not_found', 'data_not_found', 'not_found'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSellerApiWorld();
    }

    protected function tearDown(): void
    {
        $this->tearDownSellerApiWorld();

        parent::tearDown();
    }

    /** @return iterable<string, array{0: string}> */
    public static function locales(): iterable
    {
        foreach (glob(dirname(__DIR__, 3) . '/resources/lang/*', GLOB_ONLYDIR) ?: [] as $directory) {
            yield basename($directory) => [basename($directory)];
        }
    }

    #[DataProvider('locales')]
    public function test_every_locale_defines_every_refusal_the_seller_apis_answer_with(string $locale): void
    {
        $messages = [...include base_path("resources/lang/{$locale}/messages.php"), ...include base_path("resources/lang/{$locale}/new-messages.php")];

        foreach ($this->refusalKeys() as $key) {
            $this->assertArrayHasKey($key, $messages, "resources/lang/{$locale} does not define {$key}");
        }
    }

    public function test_the_refusals_named_in_record_not_found_calls_are_found_in_the_code(): void
    {
        $named = $this->namedInRecordNotFoundCalls();

        foreach (['product_not_found', 'review_not_found', 'payment_method_not_found', 'restock_request_not_found', 'category_shipping_cost_not_found', 'clearance_product_not_found'] as $key) {
            $this->assertContains($key, $named);
        }
    }

    public function test_an_arabic_seller_refused_a_review_that_is_not_theirs_is_answered_in_arabic_and_no_language_file_is_written(): void
    {
        $this->useAnArabicStore();
        $before = $this->languageFileDigests();

        $response = $this->withHeader('lang', 'ar')->sellerApi('GET', 'shop-product-reviews-status?id=999&status=1');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('review', $response->json('errors.0.code'));
        $this->assertSame($this->definedIn(locale: 'sa', key: 'review_not_found'), $response->json('errors.0.message'));
        $this->assertNotSame('Review not found', $response->json('errors.0.message'));
        $this->assertSame($before, $this->languageFileDigests());
    }

    public function test_an_arabic_seller_refused_a_product_that_is_not_theirs_is_answered_in_arabic_and_no_language_file_is_written(): void
    {
        $this->useAnArabicStore();
        $before = $this->languageFileDigests();

        $response = $this->withHeader('lang', 'ar')->sellerApi('GET', 'products/edit/999');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame($this->definedIn(locale: 'sa', key: 'product_not_found'), $response->json('errors.0.message'));
        $this->assertSame($before, $this->languageFileDigests());
    }

    // ---- helpers ----

    /** @return list<string> */
    private function refusalKeys(): array
    {
        return array_values(array_unique([...self::OTHER_REFUSALS, ...$this->namedInRecordNotFoundCalls()]));
    }

    /** @return list<string> */
    private function namedInRecordNotFoundCalls(): array
    {
        $keys = [];
        foreach ((new Finder())->files()->in(app_path())->name('*.php')->contains('recordNotFound(') as $file) {
            preg_match_all("/recordNotFound\(\s*code:\s*'[^']+',\s*message:\s*'([^']+)'/", $file->getContents(), $matches);
            $keys = [...$keys, ...$matches[1]];
        }

        return array_values(array_unique($keys));
    }

    private function useAnArabicStore(): void
    {
        DB::table('business_settings')->insert(['type' => 'language', 'value' => json_encode([
            ['id' => 1, 'name' => 'english', 'direction' => 'ltr', 'code' => 'en', 'status' => 1, 'default' => true],
            ['id' => 2, 'name' => 'Arabic', 'direction' => 'rtl', 'code' => 'sa', 'status' => 1, 'default' => false],
        ])]);
        Cache::flush();
        $this->createSellerApiTable('products', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('added_by')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('reviews', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    /** translate() reads messages.php first and new-messages.php for what that does not define. */
    private function definedIn(string $locale, string $key): string
    {
        return [...include base_path("resources/lang/{$locale}/new-messages.php"), ...include base_path("resources/lang/{$locale}/messages.php")][$key];
    }

    /** @return array<string, string> */
    private function languageFileDigests(): array
    {
        $digests = [];
        foreach (glob(base_path('resources/lang/*/*.php')) ?: [] as $file) {
            $digests[$file] = md5_file($file);
        }

        return $digests;
    }
}
