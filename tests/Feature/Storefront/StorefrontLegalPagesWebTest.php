<?php

namespace Tests\Feature\Storefront;

use App\Models\BusinessPage;
use App\Services\Storefront\StorefrontLegalPages;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsStorefrontLegalPages;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * On the website a legal page of a storefront other than the default one shows that storefront's current effective text in the language
 * the visitor reads the site in, or nothing (I2-04). Nothing is the answer a page that does not exist gets: no other country's text and
 * no Saudi text stand in for a missing one, and a text that is not approved, revoked, or not yet effective is never shown.
 */
class StorefrontLegalPagesWebTest extends TestCase
{
    use BuildsStorefrontLegalPages;
    use RestoresLanguageFiles;

    private const SAUDI_TERMS = '<p>Saudi terms-and-conditions text</p>';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildBusinessPageTables();
        $this->buildStorefrontLegalDocumentsTable();
        $this->prepareBusinessPageRendering();
        $this->insertInstalledBusinessPages();
        $this->insertBusinessPage(slug: 'delivery-notes', title: 'Delivery Notes', description: '<p>Saudi delivery notes</p>', defaultStatus: 0);
        $this->activateStorefronts('KW', 'AE');
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** The page as a visitor of the storefront reads it, in the language whose code the site keeps in the session ('sa' is Arabic). */
    private function visit(string $slug, string $storefront = 'KW', string $language = 'en'): TestResponse
    {
        return $this->withSession(['local' => $language])->get("/business-page/{$slug}?storefront={$storefront}");
    }

    /** The answer a page that does not exist gets: back to the home page, with an error message in the visitor's language. */
    private function assertShowsNothing(TestResponse $response): void
    {
        $response->assertRedirect(route('home'))
            ->assertSessionHas('toastr::messages', fn (array $messages) => $messages[0]['type'] === 'error');
    }

    /** The Kuwaiti text of the terms in English, with what makes it unfit or not current; the other texts of the storefront are as they should be. */
    private function publishKuwaitTerms(string $defect, array $overrides = []): void
    {
        $body = $this->storefrontTextBody(country: 'KW', type: 'terms', locale: 'en');

        $this->publishStorefrontText(country: 'KW', type: 'terms', locale: 'en', overrides: $overrides + $this->overridesOf(defect: $defect, body: $body));
    }

    public function test_the_page_of_a_storefront_shows_the_current_text_of_that_storefront(): void
    {
        $this->publishStorefrontTexts(country: 'KW');

        $this->visit(slug: 'terms-and-conditions')
            ->assertOk()
            ->assertViewIs(VIEW_FILE_NAMES['business_page'])
            ->assertViewHas('businessPage', fn ($page) => $page->title === 'Terms And Conditions' && $page->description === '<p>KW terms text in en</p>')
            ->assertSee('<p>KW terms text in en</p>', escape: false)
            ->assertDontSee(self::SAUDI_TERMS, escape: false);
    }

    public function test_every_legal_page_shows_the_text_of_its_own_document_type(): void
    {
        $this->publishStorefrontTexts(country: 'KW');

        foreach (config('gcc.storefronts.legal.page_slugs') as $type => $slug) {
            $this->visit(slug: $slug)
                ->assertOk()
                ->assertSee("<p>KW {$type} text in en</p>", escape: false);
        }
    }

    public function test_the_text_follows_the_language_the_visitor_reads_the_site_in(): void
    {
        $this->publishStorefrontTexts(country: 'KW');

        $this->visit(slug: 'privacy-policy', language: 'sa')->assertOk()->assertSee('<p>KW privacy text in ar</p>', escape: false)->assertDontSee('in en');
        $this->visit(slug: 'privacy-policy', language: 'ar')->assertOk()->assertSee('<p>KW privacy text in ar</p>', escape: false);
        $this->visit(slug: 'privacy-policy', language: 'en')->assertOk()->assertSee('<p>KW privacy text in en</p>', escape: false)->assertDontSee('in ar');
    }

    public function test_the_storefront_can_be_named_in_the_header_too(): void
    {
        $this->publishStorefrontTexts(country: 'KW');

        $this->withSession(['local' => 'en'])->get('/business-page/refund-policy', ['X-Storefront' => 'KW'])
            ->assertOk()
            ->assertSee('<p>KW refund text in en</p>', escape: false);
    }

    public function test_the_page_of_a_storefront_differs_from_the_saudi_page_only_in_its_text(): void
    {
        $this->publishStorefrontTexts(country: 'KW');

        $saudi = $this->get('/business-page/terms-and-conditions')->assertOk()->getContent();
        $kuwait = $this->visit(slug: 'terms-and-conditions')->assertOk()->getContent();

        $this->assertSame($saudi, str_replace('<p>KW terms text in en</p>', self::SAUDI_TERMS, $kuwait));
    }

    public function test_a_storefront_with_no_text_shows_no_page_and_never_the_saudi_text(): void
    {
        foreach (config('gcc.storefronts.legal.page_slugs') as $slug) {
            $this->visit(slug: $slug)->assertRedirect(route('home'));
        }
    }

    public function test_a_storefront_with_no_text_in_the_visitors_language_shows_no_page_not_the_text_of_another_language(): void
    {
        $this->publishStorefrontTexts(country: 'KW', locales: ['en']);

        $response = $this->visit(slug: 'terms-and-conditions', language: 'sa');

        $this->assertShowsNothing(response: $response);
        $response->assertDontSee('<p>KW terms text in en</p>', escape: false);
    }

    public function test_a_language_the_storefront_does_not_serve_shows_no_page(): void
    {
        $this->publishStorefrontTexts(country: 'KW');

        $this->assertShowsNothing(response: $this->visit(slug: 'terms-and-conditions', language: 'in'));
    }

    public function test_the_texts_of_another_storefront_are_never_shown(): void
    {
        $this->publishStorefrontTexts(country: 'AE');

        $this->assertShowsNothing(response: $this->visit(slug: 'terms-and-conditions', storefront: 'KW'));
    }

    public function test_the_missing_table_of_legal_texts_shows_no_page_and_no_error(): void
    {
        Schema::dropIfExists('storefront_legal_documents');

        $this->assertShowsNothing(response: $this->visit(slug: 'terms-and-conditions'));
    }

    #[DataProvider('unfitCurrentTexts')]
    public function test_the_only_text_of_a_storefront_is_not_shown_when_it_is_unfit(string $defect): void
    {
        $this->publishStorefrontTexts(country: 'KW', withheldTypes: ['terms']);
        $this->publishKuwaitTerms(defect: $defect);

        $this->assertShowsNothing(response: $this->visit(slug: 'terms-and-conditions'));
    }

    #[DataProvider('textsThatAreNotCurrent')]
    public function test_a_row_that_is_not_current_is_never_shown(string $defect): void
    {
        $this->publishStorefrontTexts(country: 'KW', withheldTypes: ['terms']);
        $this->publishKuwaitTerms(defect: $defect);

        $this->assertShowsNothing(response: $this->visit(slug: 'terms-and-conditions'));
    }

    #[DataProvider('unfitCurrentTexts')]
    public function test_an_older_text_does_not_take_the_place_of_a_current_text_that_is_unfit(string $defect): void
    {
        $this->publishStorefrontTexts(country: 'KW');
        $this->publishKuwaitTerms(defect: $defect, overrides: ['version' => 2, 'effective_from' => now()->subHour()]);

        $this->assertShowsNothing(response: $this->visit(slug: 'terms-and-conditions'));
    }

    #[DataProvider('textsThatAreNotCurrent')]
    public function test_a_row_that_is_not_current_does_not_hide_the_current_text(string $defect): void
    {
        $this->publishStorefrontTexts(country: 'KW');
        $this->publishKuwaitTerms(defect: $defect, overrides: ['version' => 2]);

        $this->visit(slug: 'terms-and-conditions')->assertOk()->assertSee('<p>KW terms text in en</p>', escape: false);
    }

    public function test_a_newer_text_that_is_effective_replaces_the_older_one(): void
    {
        $this->publishStorefrontTexts(country: 'KW');
        DB::table('storefront_legal_documents')->where(['country_code' => 'KW', 'document_type' => 'terms', 'locale' => 'en'])->update(['effective_from' => now()->subDays(3)]);
        $this->publishStorefrontText(country: 'KW', type: 'terms', locale: 'en', overrides: ['version' => 2, 'body' => '<p>KW terms, second version</p>', 'body_hash' => hash('sha256', 'second')]);

        $this->visit(slug: 'terms-and-conditions')->assertOk()->assertSee('<p>KW terms, second version</p>', escape: false)->assertDontSee('<p>KW terms text in en</p>', escape: false);
    }

    public function test_a_page_that_stands_for_no_legal_document_is_the_same_in_every_storefront(): void
    {
        $this->publishStorefrontTexts(country: 'KW');

        $this->visit(slug: 'about-us')->assertOk()->assertSee('<p>Saudi about-us text</p>', escape: false);
        $this->visit(slug: 'delivery-notes')->assertOk()->assertSee('<p>Saudi delivery notes</p>', escape: false);
    }

    public function test_the_platforms_publish_switch_still_decides_whether_a_legal_page_exists(): void
    {
        $this->publishStorefrontTexts(country: 'KW');
        DB::table('business_pages')->where('slug', 'terms-and-conditions')->update(['status' => 0]);

        $this->assertShowsNothing(response: $this->visit(slug: 'terms-and-conditions'));
    }

    public function test_the_page_shown_is_a_copy_that_a_save_could_never_write_back(): void
    {
        $this->publishStorefrontTexts(country: 'KW');
        $this->serveAsHttpRequest(uri: '/business-page/terms-and-conditions?storefront=KW');
        $platformPage = BusinessPage::where('slug', 'terms-and-conditions')->firstOrFail();

        $shown = app(StorefrontLegalPages::class)->shownInStorefront(page: $platformPage);

        $this->assertNotSame($platformPage, $shown);
        $this->assertSame('<p>KW terms text in en</p>', $shown->description);
        $this->assertSame(self::SAUDI_TERMS, $platformPage->description);
        $this->assertFalse($shown->isDirty());
        $this->assertFalse($platformPage->isDirty());
    }

    public function test_serving_a_storefront_leaves_the_platforms_page_and_the_saudi_answer_as_they_were(): void
    {
        $this->publishStorefrontTexts(country: 'KW');

        $this->visit(slug: 'terms-and-conditions')->assertSee('<p>KW terms text in en</p>', escape: false);

        $this->assertSame(self::SAUDI_TERMS, DB::table('business_pages')->where('slug', 'terms-and-conditions')->value('description'));
        $this->get('/business-page/terms-and-conditions')->assertOk()->assertSee(self::SAUDI_TERMS, escape: false)->assertDontSee('KW terms', escape: false);
    }
}
