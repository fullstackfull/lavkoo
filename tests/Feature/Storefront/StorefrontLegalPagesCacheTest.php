<?php

namespace Tests\Feature\Storefront;

use App\Models\BusinessPage;
use App\Models\StorefrontLegalDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\Support\BuildsStorefrontLegalPages;
use Tests\TestCase;

/**
 * The list of business pages the apps and the page footers read is cached per storefront and per language, because each storefront's list
 * carries that storefront's own texts (I2-04). The default storefront keeps the key it has always had, and a change of the pages or of the
 * legal texts clears every storefront's list without touching the default one's.
 */
class StorefrontLegalPagesCacheTest extends TestCase
{
    use BuildsStorefrontLegalPages;

    private const PLATFORM_KEY = 'cache_for_business_page_list';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildBusinessPageTables();
        $this->buildStorefrontLegalDocumentsTable();
        $this->prepareBusinessPageRendering();
        $this->insertInstalledBusinessPages();
        $this->activateStorefronts('KW', 'AE');
        $this->publishStorefrontTexts(country: 'KW');
        $this->publishStorefrontTexts(country: 'AE');
    }

    /** Every list a visitor of these storefronts can have been given. */
    private function warmEveryList(): void
    {
        $this->listBusinessPagesAs(storefront: 'SA');
        foreach (['KW', 'AE'] as $storefront) {
            foreach (['en', 'ar'] as $language) {
                $this->listBusinessPagesAs(storefront: $storefront, language: $language);
            }
        }
    }

    /** @return list<string> */
    private function storefrontKeys(): array
    {
        return ['storefront:KW:' . self::PLATFORM_KEY . ':en', 'storefront:KW:' . self::PLATFORM_KEY . ':ar', 'storefront:AE:' . self::PLATFORM_KEY . ':en', 'storefront:AE:' . self::PLATFORM_KEY . ':ar'];
    }

    private function assertWarm(array $keys): void
    {
        foreach ($keys as $key) {
            $this->assertTrue(Cache::has($key), "{$key} is cached");
        }
    }

    private function assertCold(array $keys): void
    {
        foreach ($keys as $key) {
            $this->assertFalse(Cache::has($key), "{$key} is not cached");
        }
    }

    private function descriptionIn(string $key, string $slug): ?string
    {
        return Cache::get($key)->firstWhere('slug', $slug)?->description;
    }

    public function test_each_storefront_and_each_language_has_a_list_of_its_own(): void
    {
        $this->warmEveryList();

        $this->assertWarm($this->storefrontKeys());
        $this->assertSame('<p>KW terms text in en</p>', $this->descriptionIn(key: 'storefront:KW:' . self::PLATFORM_KEY . ':en', slug: 'terms-and-conditions'));
        $this->assertSame('<p>KW terms text in ar</p>', $this->descriptionIn(key: 'storefront:KW:' . self::PLATFORM_KEY . ':ar', slug: 'terms-and-conditions'));
        $this->assertSame('<p>AE terms text in en</p>', $this->descriptionIn(key: 'storefront:AE:' . self::PLATFORM_KEY . ':en', slug: 'terms-and-conditions'));
    }

    public function test_the_default_storefront_keeps_the_key_it_has_always_had_with_the_pages_it_has_always_had(): void
    {
        $this->listBusinessPagesAs(storefront: 'SA');

        $this->assertSame('<p>Saudi terms-and-conditions text</p>', $this->descriptionIn(key: self::PLATFORM_KEY, slug: 'terms-and-conditions'));
        $this->assertCount(7, Cache::get(self::PLATFORM_KEY));
        $this->assertCold($this->storefrontKeys());
    }

    public function test_a_storefront_never_fills_or_reads_the_list_of_the_default_storefront(): void
    {
        $this->listBusinessPagesAs(storefront: 'KW');
        $this->assertFalse(Cache::has(self::PLATFORM_KEY));

        $this->listBusinessPagesAs(storefront: 'SA');
        $this->listBusinessPagesAs(storefront: 'KW');

        $this->assertSame('<p>Saudi terms-and-conditions text</p>', $this->descriptionIn(key: self::PLATFORM_KEY, slug: 'terms-and-conditions'));
        $this->assertSame('<p>KW terms text in en</p>', $this->descriptionIn(key: 'storefront:KW:' . self::PLATFORM_KEY . ':en', slug: 'terms-and-conditions'));
    }

    public function test_storefronts_that_are_off_keep_the_one_list_they_had(): void
    {
        config(['gcc.storefronts.enabled' => false]);
        Cache::flush();

        $this->listBusinessPagesAs(storefront: 'KW');

        $this->assertSame('<p>Saudi terms-and-conditions text</p>', $this->descriptionIn(key: self::PLATFORM_KEY, slug: 'terms-and-conditions'));
        $this->assertCold($this->storefrontKeys());
    }

    public function test_a_language_the_storefront_has_no_texts_in_has_one_list_of_its_own_whatever_the_app_asks_for(): void
    {
        $this->listBusinessPagesAs(storefront: 'KW', language: 'in');
        $this->listBusinessPagesAs(storefront: 'KW', language: 'hi');

        $this->assertWarm(['storefront:KW:' . self::PLATFORM_KEY . ':none']);
        $this->assertCold(['storefront:KW:' . self::PLATFORM_KEY . ':in', 'storefront:KW:' . self::PLATFORM_KEY . ':hi', 'storefront:KW:' . self::PLATFORM_KEY . ':en']);
        $this->assertNull($this->descriptionIn(key: 'storefront:KW:' . self::PLATFORM_KEY . ':none', slug: 'terms-and-conditions'));
    }

    public function test_a_change_of_a_business_page_clears_the_lists_of_every_storefront(): void
    {
        $this->warmEveryList();

        BusinessPage::where('slug', 'about-us')->firstOrFail()->update(['title' => 'About the shop']);

        $this->assertCold([self::PLATFORM_KEY, ...$this->storefrontKeys()]);
    }

    public function test_a_legal_text_that_is_saved_clears_the_lists_of_every_storefront_but_not_the_default_one(): void
    {
        $this->warmEveryList();

        StorefrontLegalDocument::where(['country_code' => 'KW', 'document_type' => 'terms', 'locale' => 'en'])->firstOrFail()->forceFill(['revoked_at' => now()])->save();

        $this->assertCold($this->storefrontKeys());
        $this->assertWarm([self::PLATFORM_KEY]);
    }

    public function test_a_legal_text_that_is_created_or_deleted_clears_the_lists_of_every_storefront(): void
    {
        $this->warmEveryList();
        $created = StorefrontLegalDocument::forceCreate([
            'country_code' => 'KW', 'document_type' => 'terms', 'locale' => 'en', 'version' => 2, 'status' => StorefrontLegalDocument::STATUS_DRAFT,
            'body' => '<p>KW terms, second version</p>', 'body_hash' => hash('sha256', 'second'),
        ]);
        $this->assertCold($this->storefrontKeys());

        $this->warmEveryList();
        $created->delete();

        $this->assertCold($this->storefrontKeys());
        $this->assertWarm([self::PLATFORM_KEY]);
    }

    public function test_the_lists_are_cleared_by_name_while_storefronts_are_on_and_left_alone_while_they_are_off(): void
    {
        $this->warmEveryList();
        config(['gcc.storefronts.enabled' => false]);

        cacheRemoveByType(type: 'business_pages');

        $this->assertCold([self::PLATFORM_KEY]);
        $this->assertWarm($this->storefrontKeys());

        config(['gcc.storefronts.enabled' => true]);
        cacheRemoveByType(type: 'business_pages');

        $this->assertCold($this->storefrontKeys());
    }

    /** The tests of time stand still at a moment of their own: the texts of setUp took effect a day before it. */
    private function standStillAt(): CarbonImmutable
    {
        $now = CarbonImmutable::now()->startOfSecond();
        $this->travelTo($now);

        return $now;
    }

    public function test_the_list_is_kept_for_three_hours_when_no_text_is_scheduled(): void
    {
        $start = $this->standStillAt();
        $this->listBusinessPagesAs(storefront: 'KW');
        $key = 'storefront:KW:' . self::PLATFORM_KEY . ':en';

        $this->travelTo($start->addHours(3)->subMinute());
        $this->assertTrue(Cache::has($key));

        $this->travelTo($start->addHours(3)->addMinute());
        $this->assertFalse(Cache::has($key));
    }

    public function test_the_list_is_not_kept_past_the_moment_a_scheduled_text_takes_effect(): void
    {
        $start = $this->standStillAt();
        $this->publishStorefrontText(country: 'KW', type: 'terms', locale: 'en', overrides: [
            'version' => 2, 'effective_from' => $start->addMinutes(30), 'body' => '<p>KW terms, second version</p>', 'body_hash' => hash('sha256', 'second'),
        ]);
        $key = 'storefront:KW:' . self::PLATFORM_KEY . ':en';

        $this->assertSame('<p>KW terms text in en</p>', $this->descriptionOf(response: $this->listBusinessPagesAs(storefront: 'KW'), slug: 'terms-and-conditions'));

        $this->travelTo($start->addMinutes(29));
        $this->assertTrue(Cache::has($key));
        $this->assertSame('<p>KW terms text in en</p>', $this->descriptionOf(response: $this->listBusinessPagesAs(storefront: 'KW'), slug: 'terms-and-conditions'));

        $this->travelTo($start->addMinutes(31));
        $this->assertFalse(Cache::has($key));
        $this->assertSame('<p>KW terms, second version</p>', $this->descriptionOf(response: $this->listBusinessPagesAs(storefront: 'KW'), slug: 'terms-and-conditions'));
    }

    public function test_a_text_scheduled_in_another_storefront_does_not_shorten_the_list(): void
    {
        $start = $this->standStillAt();
        $this->publishStorefrontText(country: 'AE', type: 'terms', locale: 'en', overrides: ['version' => 2, 'effective_from' => $start->addMinutes(10)]);
        $this->listBusinessPagesAs(storefront: 'KW');

        $this->travelTo($start->addHours(2));

        $this->assertTrue(Cache::has('storefront:KW:' . self::PLATFORM_KEY . ':en'));
    }
}
