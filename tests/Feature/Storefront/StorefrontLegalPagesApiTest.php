<?php

namespace Tests\Feature\Storefront;

use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsStorefrontLegalPages;
use Tests\TestCase;

/**
 * GET /api/v1/business-pages answers the apps with the texts of the storefront they name in the X-Storefront header, in the language they
 * name in the lang header (I2-04). A legal page the storefront has no text for in that language is left out of the list: no other country's
 * text and no Saudi text stand in for it. Only the body of a legal page changes; every other page, and the shape of the list, stay as they are.
 */
class StorefrontLegalPagesApiTest extends TestCase
{
    use BuildsStorefrontLegalPages;

    private const LEGAL_SLUGS = ['terms-and-conditions', 'privacy-policy', 'refund-policy', 'return-policy', 'cancellation-policy', 'shipping-policy'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildBusinessPageTables();
        $this->buildStorefrontLegalDocumentsTable();
        $this->prepareBusinessPageRendering();
        $this->insertInstalledBusinessPages();
        $this->insertBusinessPage(slug: 'delivery-notes', title: 'Delivery Notes', description: '<p>Saudi delivery notes</p>', defaultStatus: 0);
        $this->activateStorefronts('KW', 'AE');
    }

    /** @return list<string> */
    private function slugsOf(TestResponse $response): array
    {
        return array_column($response->json(), 'slug');
    }

    public function test_the_list_of_a_storefront_carries_the_current_text_of_that_storefront(): void
    {
        $this->publishStorefrontTexts(country: 'KW');

        $list = $this->listBusinessPagesAs(storefront: 'KW');

        foreach (config('gcc.storefronts.legal.page_slugs') as $type => $slug) {
            $this->assertSame("<p>KW {$type} text in en</p>", $this->descriptionOf(response: $list, slug: $slug), $slug);
        }
        $this->assertStringNotContainsString('Saudi terms', $list->getContent());
    }

    public function test_only_the_body_of_a_legal_page_differs_from_the_list_of_the_default_storefront(): void
    {
        $this->publishStorefrontTexts(country: 'KW');

        $saudi = $this->listBusinessPagesAs(storefront: 'SA')->json();
        $kuwait = $this->listBusinessPagesAs(storefront: 'KW')->json();

        $this->assertCount(count($saudi), $kuwait);
        foreach ($saudi as $position => $saudiPage) {
            $expected = in_array($saudiPage['slug'], self::LEGAL_SLUGS, true) ? array_replace($saudiPage, ['description' => $kuwait[$position]['description']]) : $saudiPage;
            $this->assertSame($expected, $kuwait[$position], $saudiPage['slug']);
        }
    }

    public function test_a_page_that_stands_for_no_legal_document_is_the_same_in_every_storefront(): void
    {
        $this->publishStorefrontTexts(country: 'KW');

        $list = $this->listBusinessPagesAs(storefront: 'KW');

        $this->assertSame('<p>Saudi about-us text</p>', $this->descriptionOf(response: $list, slug: 'about-us'));
        $this->assertSame('<p>Saudi delivery notes</p>', $this->descriptionOf(response: $list, slug: 'delivery-notes'));
    }

    public function test_the_list_follows_the_language_the_app_asks_for(): void
    {
        $this->publishStorefrontTexts(country: 'KW');
        $this->storeOffersEnglishAndArabic();

        foreach (['ar' => 'ar', 'sa' => 'ar', 'en' => 'en', 'us' => 'en'] as $asked => $served) {
            $this->assertSame("<p>KW terms text in {$served}</p>", $this->descriptionOf(response: $this->listBusinessPagesAs(storefront: 'KW', language: $asked), slug: 'terms-and-conditions'), $asked);
        }
    }

    public function test_the_language_the_app_asks_for_is_served_when_the_store_has_no_language_of_that_name(): void
    {
        $this->publishStorefrontTexts(country: 'KW');

        $this->assertSame('<p>KW terms text in ar</p>', $this->descriptionOf(response: $this->listBusinessPagesAs(storefront: 'KW', language: 'ar'), slug: 'terms-and-conditions'));
    }

    public function test_the_type_filters_of_the_list_still_apply(): void
    {
        $this->publishStorefrontTexts(country: 'KW');

        $this->assertSame(['about-us', ...self::LEGAL_SLUGS], $this->slugsOf(response: $this->listBusinessPagesAs(storefront: 'KW', query: 'type=default')));
        $this->assertSame(['delivery-notes'], $this->slugsOf(response: $this->listBusinessPagesAs(storefront: 'KW', query: 'type=pages')));
    }

    public function test_a_storefront_with_no_text_lists_no_legal_page_and_never_the_saudi_text(): void
    {
        $list = $this->listBusinessPagesAs(storefront: 'KW');

        $this->assertSame(['about-us', 'delivery-notes'], $this->slugsOf(response: $list));
        $this->assertStringNotContainsString('Saudi terms', $list->getContent());
    }

    public function test_a_storefront_with_no_text_in_the_apps_language_lists_no_legal_page_not_the_text_of_another_language(): void
    {
        $this->publishStorefrontTexts(country: 'KW', locales: ['en']);

        $list = $this->listBusinessPagesAs(storefront: 'KW', language: 'ar');

        $this->assertSame(['about-us', 'delivery-notes'], $this->slugsOf(response: $list));
        $this->assertStringNotContainsString('text in en', $list->getContent());
    }

    public function test_a_legal_page_the_storefront_has_no_text_for_is_left_out_and_the_others_stay(): void
    {
        $this->publishStorefrontTexts(country: 'KW', withheldTypes: ['refund']);

        $this->assertNotContains('refund-policy', $this->slugsOf(response: $this->listBusinessPagesAs(storefront: 'KW')));
        $this->assertContains('shipping-policy', $this->slugsOf(response: $this->listBusinessPagesAs(storefront: 'KW')));
    }

    public function test_the_texts_of_another_storefront_are_never_listed(): void
    {
        $this->publishStorefrontTexts(country: 'AE');

        $list = $this->listBusinessPagesAs(storefront: 'KW');

        $this->assertSame(['about-us', 'delivery-notes'], $this->slugsOf(response: $list));
        $this->assertStringNotContainsString('AE ', $list->getContent());
    }

    public function test_the_missing_table_of_legal_texts_lists_no_legal_page_and_no_error(): void
    {
        Schema::dropIfExists('storefront_legal_documents');

        $this->assertSame(['about-us', 'delivery-notes'], $this->slugsOf(response: $this->listBusinessPagesAs(storefront: 'KW')));
    }

    #[DataProvider('unfitCurrentTexts')]
    public function test_a_current_text_that_is_unfit_is_not_listed_and_no_older_text_takes_its_place(string $defect): void
    {
        $this->publishStorefrontTexts(country: 'KW');
        $body = $this->storefrontTextBody(country: 'KW', type: 'terms', locale: 'en');
        $this->publishStorefrontText(country: 'KW', type: 'terms', locale: 'en', overrides: ['version' => 2, 'effective_from' => now()->subHour()] + $this->overridesOf(defect: $defect, body: $body));

        $this->assertNotContains('terms-and-conditions', $this->slugsOf(response: $this->listBusinessPagesAs(storefront: 'KW')));
    }

    #[DataProvider('textsThatAreNotCurrent')]
    public function test_a_row_that_is_not_current_neither_replaces_nor_hides_the_current_text(string $defect): void
    {
        $this->publishStorefrontTexts(country: 'KW');
        $body = $this->storefrontTextBody(country: 'KW', type: 'terms', locale: 'en');
        $this->publishStorefrontText(country: 'KW', type: 'terms', locale: 'en', overrides: ['version' => 2] + $this->overridesOf(defect: $defect, body: $body));

        $this->assertSame('<p>KW terms text in en</p>', $this->descriptionOf(response: $this->listBusinessPagesAs(storefront: 'KW'), slug: 'terms-and-conditions'));
    }

    #[DataProvider('unfitCurrentTexts')]
    public function test_the_only_text_of_a_storefront_is_not_listed_when_it_is_unfit(string $defect): void
    {
        $this->publishStorefrontTexts(country: 'KW', withheldTypes: ['terms']);
        $body = $this->storefrontTextBody(country: 'KW', type: 'terms', locale: 'en');
        $this->publishStorefrontText(country: 'KW', type: 'terms', locale: 'en', overrides: $this->overridesOf(defect: $defect, body: $body));

        $this->assertNotContains('terms-and-conditions', $this->slugsOf(response: $this->listBusinessPagesAs(storefront: 'KW')));
    }
}
