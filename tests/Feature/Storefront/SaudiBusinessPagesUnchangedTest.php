<?php

namespace Tests\Feature\Storefront;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsStorefrontLegalPages;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The default (Saudi) storefront keeps serving the business pages the platform has always served, with storefronts off and with them on
 * (I2-04). The answers below were recorded from the code as it was before any storefront legal text was served: the web page, the page
 * list of the apps, and the answer for a page that is missing or unpublished. Another country's approved texts exist in these tests and
 * must change none of them.
 */
class SaudiBusinessPagesUnchangedTest extends TestCase
{
    use BuildsStorefrontLegalPages;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildBusinessPageTables();
        $this->buildStorefrontLegalDocumentsTable();
        $this->prepareBusinessPageRendering();
        $this->insertInstalledBusinessPages();
        $this->insertBusinessPage(slug: 'delivery-notes', title: 'Delivery Notes', description: '<p>Notes</p>', defaultStatus: 0);
        $this->insertBusinessPage(slug: 'draft-page', title: 'Draft Page', description: '<p>Draft</p>', status: 0, defaultStatus: 0);
        $this->publishRequiredLegalDocuments(country: 'KW');
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function serve(bool $storefrontsOn): void
    {
        config(['gcc.storefronts.enabled' => $storefrontsOn]);
        Cache::flush();
    }

    private function withQuery(string $uri, string ...$parameters): string
    {
        $query = implode('&', array_filter($parameters));

        return $query === '' ? $uri : "{$uri}?{$query}";
    }

    /** The recorded timestamp, as the API writes a stored one. */
    private function recordedTimestamp(): string
    {
        return Carbon::parse('2026-11-01 10:00:00')->toJSON();
    }

    /** @return array<string, mixed> a page as GET /api/v1/business-pages has always listed it */
    private function listedPage(int $id, string $title, string $slug, int $defaultStatus, ?string $description = null): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'slug' => $slug,
            'description' => $description ?? "<p>Saudi {$slug} text</p>",
            'status' => 1,
            'default_status' => $defaultStatus,
            'created_at' => $this->recordedTimestamp(),
            'updated_at' => $this->recordedTimestamp(),
            'banner_full_url' => ['key' => 'business-pages', 'path' => url('/assets/front-end/img/placeholder/business-page.png'), 'status' => 200],
            'banner' => null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function listedInstalledPages(): array
    {
        return [
            $this->listedPage(id: 1, title: 'About Us', slug: 'about-us', defaultStatus: 1),
            $this->listedPage(id: 2, title: 'Terms And Conditions', slug: 'terms-and-conditions', defaultStatus: 1),
            $this->listedPage(id: 3, title: 'Privacy Policy', slug: 'privacy-policy', defaultStatus: 1),
            $this->listedPage(id: 4, title: 'Refund Policy', slug: 'refund-policy', defaultStatus: 1),
            $this->listedPage(id: 5, title: 'Return Policy', slug: 'return-policy', defaultStatus: 1),
            $this->listedPage(id: 6, title: 'Cancellation Policy', slug: 'cancellation-policy', defaultStatus: 1),
            $this->listedPage(id: 7, title: 'Shipping Policy', slug: 'shipping-policy', defaultStatus: 1),
        ];
    }

    private function listedCustomPage(): array
    {
        return $this->listedPage(id: 8, title: 'Delivery Notes', slug: 'delivery-notes', defaultStatus: 0, description: '<p>Notes</p>');
    }

    #[DataProvider('requestsOfTheDefaultStorefront')]
    public function test_the_page_list_of_the_apps_is_what_it_has_always_been(bool $storefrontsOn, string $query, array $headers): void
    {
        $this->serve($storefrontsOn);
        $this->serveAsHttpRequest(uri: $this->withQuery('/api/v1/business-pages', $query), headers: $headers);

        $this->getJson($this->withQuery('/api/v1/business-pages', $query), $headers)
            ->assertOk()
            ->assertExactJson([...$this->listedInstalledPages(), $this->listedCustomPage()]);
    }

    #[DataProvider('requestsOfTheDefaultStorefront')]
    public function test_the_default_and_the_custom_page_lists_are_what_they_have_always_been(bool $storefrontsOn, string $query, array $headers): void
    {
        $this->serve($storefrontsOn);
        $this->serveAsHttpRequest(uri: $this->withQuery('/api/v1/business-pages', $query), headers: $headers);

        $this->getJson($this->withQuery('/api/v1/business-pages', 'type=default', $query), $headers)
            ->assertOk()
            ->assertExactJson($this->listedInstalledPages());
        $this->getJson($this->withQuery('/api/v1/business-pages', 'type=pages', $query), $headers)
            ->assertOk()
            ->assertExactJson([$this->listedCustomPage()]);
    }

    #[DataProvider('requestsOfTheDefaultStorefront')]
    public function test_a_business_page_is_rendered_as_it_has_always_been(bool $storefrontsOn, string $query, array $headers): void
    {
        $this->serve($storefrontsOn);

        $response = $this->get($this->withQuery('/business-page/terms-and-conditions', $query), $headers)->assertOk();

        $this->assertSame(
            '<!doctype html> <html lang="en" dir="ltr"> <head><title>Terms And Conditions</title></head> <body> <div class="container for-container">'
            . ' <div class="business-pages-banner-section mt-4 " data-bg-img="' . url('/assets/front-end/img/placeholder/business-page.png') . '">'
            . ' <div class="container"> <h1 class="text-center text-capitalize font-semi-bold fs-24">Terms And Conditions</h1> </div> </div>'
            . ' <div class="card my-4"> <div class="card-body"> <div class="for-padding"> <p>Saudi terms-and-conditions text</p> </div> </div> </div>'
            . ' </div> </body> </html>',
            preg_replace('/\s+/', ' ', trim($response->getContent())),
        );
    }

    #[DataProvider('requestsOfTheDefaultStorefront')]
    public function test_every_installed_page_shows_its_own_saudi_text(bool $storefrontsOn, string $query, array $headers): void
    {
        $this->serve($storefrontsOn);

        foreach ([...$this->listedInstalledPages(), $this->listedCustomPage()] as $page) {
            $this->get($this->withQuery("/business-page/{$page['slug']}", $query), $headers)
                ->assertOk()
                ->assertViewIs(VIEW_FILE_NAMES['business_page'])
                ->assertViewHas('businessPage', fn ($businessPage) => $businessPage->title === $page['title'] && $businessPage->description === $page['description'])
                ->assertSee($page['description'], escape: false);
        }
    }

    #[DataProvider('requestsOfTheDefaultStorefront')]
    public function test_an_unpublished_page_is_answered_as_it_has_always_been(bool $storefrontsOn, string $query, array $headers): void
    {
        $this->serve($storefrontsOn);

        $this->get($this->withQuery('/business-page/draft-page', $query), $headers)
            ->assertRedirect(route('home'))
            ->assertSessionHas('toastr::messages', [['type' => 'error', 'title' => null, 'message' => 'Page not found', 'options' => []]]);
    }

    #[DataProvider('requestsOfTheDefaultStorefront')]
    public function test_an_unknown_page_is_answered_as_it_has_always_been(bool $storefrontsOn, string $query, array $headers): void
    {
        $this->serve($storefrontsOn);

        $this->get($this->withQuery('/business-page/no-such-page', $query), $headers)
            ->assertRedirect(route('home'))
            ->assertSessionHas('toastr::messages', [['type' => 'error', 'title' => null, 'message' => 'Page not found', 'options' => []]]);
    }
}
