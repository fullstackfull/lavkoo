<?php

namespace Tests\Feature\Storefront;

use App\Traits\CacheManagerTrait;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsStorefrontLegalPages;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The agreement a customer accepts at checkout and at registration links to the legal pages. Those links go through the page slugs, and a
 * page opened in a new tab is served by the default storefront unless the link says which storefront it belongs to, so the link of any
 * other storefront names it (I2-04). The link of the default storefront is the link it has always been.
 */
class StorefrontLegalLinksTest extends TestCase
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
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** What the storefront layout hands every view as $web_config['business_pages']: the cached list of the storefront being served. */
    private function businessPagesOfTheStorefront(): object
    {
        return (new class {
            use CacheManagerTrait;
        })->cacheBusinessPagesTable();
    }

    /**
     * The links the real checkout summary and registration form render. Each agreement is cut out of the template as it is written, so
     * what is rendered is the markup of the page, not a copy of it.
     *
     * @return array<string, list<string>> the hrefs of each agreement, by the template it is in
     */
    private function agreementLinks(): array
    {
        $templates = [
            'checkout summary, aside' => ['web-views/partials/_order-summary.blade.php', 0],
            'checkout summary, mobile bar' => ['web-views/partials/_order-summary.blade.php', 1],
            'registration' => ['web-views/customer-views/auth/register.blade.php', 0],
        ];

        $links = [];
        foreach ($templates as $name => [$template, $position]) {
            $source = (string) file_get_contents(resource_path("themes/default/{$template}"));
            preg_match_all('~<label[^>]*>(?:(?!</label>).)*terms-and-conditions.*?</label>~s', $source, $agreements);
            $rendered = Blade::render($agreements[0][$position], ['web_config' => ['business_pages' => $this->businessPagesOfTheStorefront()]]);
            preg_match_all('~href="([^"]+)"~', $rendered, $hrefs);
            $links[$name] = $hrefs[1];
        }

        return $links;
    }

    /**
     * @param list<string> $policies the pages the checkout agreement links to after the terms, which the registration form does not have
     * @return array<string, list<string>> the links each agreement is expected to carry, by the template it is in
     */
    private function expectedAgreementLinks(string $query, array $policies = ['privacy-policy', 'refund-policy']): array
    {
        $link = fn (string $slug) => url("/business-page/{$slug}") . $query;
        $checkout = array_map($link, ['terms-and-conditions', ...$policies]);

        return ['checkout summary, aside' => $checkout, 'checkout summary, mobile bar' => $checkout, 'registration' => [$link('terms-and-conditions')]];
    }

    #[DataProvider('requestsOfTheDefaultStorefront')]
    public function test_the_links_of_the_default_storefront_are_the_links_they_have_always_been(bool $storefrontsOn, string $query, array $headers): void
    {
        config(['gcc.storefronts.enabled' => $storefrontsOn]);
        $this->serveAsHttpRequest(uri: "/checkout-payment?{$query}", headers: $headers);

        $this->assertSame(route('business-page.view', ['slug' => 'terms-and-conditions']), businessPageUrl(slug: 'terms-and-conditions'));
        $this->assertSame(url('/business-page/about-us'), businessPageUrl(slug: 'about-us'));
        $this->assertSame($this->expectedAgreementLinks(query: ''), $this->agreementLinks());
    }

    public function test_the_link_of_a_storefront_names_the_storefront_and_opens_its_own_text(): void
    {
        $this->activateStorefronts('KW');
        $this->publishStorefrontTexts(country: 'KW');
        $this->serveAsHttpRequest(uri: '/checkout-payment?storefront=KW');

        $link = businessPageUrl(slug: 'terms-and-conditions');

        $this->assertSame(url('/business-page/terms-and-conditions') . '?storefront=KW', $link);
        $this->withSession(['local' => 'en'])->get($link)->assertOk()->assertSee('<p>KW terms text in en</p>', escape: false)->assertDontSee('Saudi', escape: false);
    }

    public function test_the_link_names_the_storefront_the_app_chose_in_the_header(): void
    {
        $this->activateStorefronts('KW');
        $this->serveAsHttpRequest(uri: '/checkout-payment', headers: ['X-Storefront' => 'KW']);

        $this->assertSame(url('/business-page/privacy-policy') . '?storefront=KW', businessPageUrl(slug: 'privacy-policy'));
    }

    public function test_every_agreement_of_a_storefront_links_to_that_storefronts_pages(): void
    {
        $this->activateStorefronts('KW');
        $this->publishStorefrontTexts(country: 'KW');
        $this->serveAsHttpRequest(uri: '/checkout-payment?storefront=KW');

        $this->assertSame($this->expectedAgreementLinks(query: '?storefront=KW'), $this->agreementLinks());
    }

    public function test_a_checkout_agreement_links_only_to_the_policies_the_storefront_has_a_text_for(): void
    {
        $this->activateStorefronts('KW');
        $this->publishStorefrontTexts(country: 'KW', withheldTypes: ['privacy']);
        $this->serveAsHttpRequest(uri: '/checkout-payment?storefront=KW');

        $this->assertSame($this->expectedAgreementLinks(query: '?storefront=KW', policies: ['refund-policy']), $this->agreementLinks());
    }
}
