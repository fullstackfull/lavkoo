<?php

namespace Tests\Feature\Storefront;

use App\Http\Middleware\NoIndexUnlaunchedStorefront;
use App\Services\Seo\StorefrontSeo;
use App\Services\Seo\StorefrontSitemap;
use App\Services\Storefront\StorefrontCurrency;
use App\Services\Storefront\StorefrontResolver;
use App\Utils\BrandManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\Support\BuildsStorefrontSeoWorld;
use Tests\TestCase;

/**
 * One storefront, one answer about its money, and the same answer wherever it is stated (B-03, B-14, B-16, B-17).
 *
 * The price a page prints, the sign beside it, the bound of its price filter, the offer a crawler reads, the entries of its sitemap and
 * the money its app is given all come from {@see StorefrontCurrency::money()}. These tests read each of those surfaces and compare them
 * with each other, so a second calculation appearing anywhere would show up as a disagreement rather than as a plausible number.
 *
 * The first two tests are the dormancy proof: with the markets switched off, and with them switched on while only Saudi Arabia is
 * configured, every number, tag and file is what it is today, compared field by field against the same snapshot.
 */
class StorefrontMoneyAndSeoTest extends TestCase
{
    use BuildsStorefrontSeoWorld;
    use BuildsStorefrontLegalDocuments;

    private const SAUDI_PRICE = '100.00';

    private const KUWAITI_PRICE = '12.345';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontSeoWorld();
        $this->buildStorefrontLegalDocumentsTable();

        $this->addProduct(id: 1, name: 'Saudi serum', slug: 'saudi-serum', price: self::SAUDI_PRICE, country: 'SA');
        $this->addProduct(id: 2, name: 'Kuwaiti serum', slug: 'kuwaiti-serum', price: self::KUWAITI_PRICE, country: 'KW');
        $this->addProduct(id: 3, name: 'Kuwaiti balm', slug: 'kuwaiti-balm', price: '8.750', country: 'KW');
    }

    protected function tearDown(): void
    {
        $this->dropStorefrontSeoWorld();

        parent::tearDown();
    }

    /* ---------------------------------------------------------------------------------------------------------------- dormancy */

    public function test_with_the_markets_switched_off_every_number_tag_and_file_is_what_it_is_today(): void
    {
        config(['gcc.storefronts.enabled' => false, 'gcc.storefronts.currency.enabled' => false]);

        $snapshot = $this->saudiSnapshot();

        // The platform's own settings, untouched: the riyal, two decimals, the platform's multi-currency conversion.
        $this->assertSame('SAR', $snapshot['money']['currency']);
        $this->assertSame(2, $snapshot['money']['decimals']);

        // No storefront address, no storefront alternates, no robots header, and the one sitemap the panel writes today.
        $this->assertSame('https://lavkoo.test/product/saudi-serum', $snapshot['canonical']);
        $this->assertSame(['en' => 'https://lavkoo.test/product/saudi-serum?language=en', 'ar' => 'https://lavkoo.test/product/saudi-serum?language=ar'], $snapshot['alternates']);
        $this->assertNull($snapshot['robots_header']);
        $this->assertSame([], $snapshot['storefront_sitemaps']);
        $this->assertSame([], $snapshot['api_money']);

        // Every product the platform has, in the one platform file, as before the markets existed.
        $this->assertSame(['saudi-serum', 'kuwaiti-serum', 'kuwaiti-balm'], $snapshot['platform_sitemap_slugs']);
    }

    public function test_with_the_markets_on_and_only_saudi_arabia_configured_nothing_changes(): void
    {
        config(['gcc.storefronts.enabled' => false, 'gcc.storefronts.currency.enabled' => false]);
        $before = $this->saudiSnapshot();

        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.currency.enabled' => true,
            'gcc.storefronts.countries.AE.active' => false,
            'gcc.storefronts.countries.KW.active' => false,
            'gcc.storefronts.countries.QA.active' => false,
            'gcc.storefronts.countries.BH.active' => false,
            'gcc.storefronts.countries.OM.active' => false,
        ]);
        Cache::flush();

        $after = $this->saudiSnapshot();

        // One difference is lawful and is not a page: the platform file lists the Saudi storefront's own products once the markets are
        // on, because every other market's products now belong to a market with a sitemap of its own.
        $this->assertSame(['saudi-serum'], $after['platform_sitemap_slugs']);

        unset($before['platform_sitemap_slugs'], $after['platform_sitemap_slugs']);
        $this->assertSame($before, $after);
    }

    /* ----------------------------------------------------------------------------------- B-03: local price and availability */

    public function test_a_kuwaiti_product_is_priced_in_dinars_to_three_decimals_and_a_saudi_one_in_riyals_to_two(): void
    {
        $this->openSixMarkets();

        $kuwait = $this->inStorefront('KW', fn (): array => app(StorefrontCurrency::class)->money(amount: self::KUWAITI_PRICE));
        $saudi = $this->inStorefront('SA', fn (): array => app(StorefrontCurrency::class)->money(amount: self::SAUDI_PRICE));

        $this->assertSame('KWD', $kuwait['currency']);
        $this->assertSame(3, $kuwait['decimals']);
        $this->assertSame('KD', $kuwait['symbol']);
        $this->assertSame('KD12.345', $kuwait['formatted']);
        $this->assertSame(12.345, round((float) $kuwait['amount'], 3));

        $this->assertSame('SAR', $saudi['currency']);
        $this->assertSame(2, $saudi['decimals']);
    }

    /** Whatever a price template reaches for, it is the one service's number: the two cannot drift apart. */
    public function test_the_helper_every_price_template_calls_prints_the_one_services_number(): void
    {
        $this->openSixMarkets();

        $this->inStorefront('KW', function (): void {
            $money = app(StorefrontCurrency::class)->money(amount: self::KUWAITI_PRICE);

            $this->assertSame($money['formatted'], webCurrencyConverter(amount: self::KUWAITI_PRICE));
            $this->assertSame($money['amount'], webCurrencyConverterOnlyDigit(amount: self::KUWAITI_PRICE));
            $this->assertSame($money['currency'], getCurrencyCode(type: 'web'));
            $this->assertSame($money['symbol'], getCurrencySymbol(type: 'web'));
        });
    }

    /**
     * A visitor's currency pick is kept in the session and the session outlives the storefront they picked it on, so the pick must not
     * put a riyal sign on a dinar price.
     */
    public function test_a_currency_a_visitor_picked_on_another_market_never_labels_this_markets_money(): void
    {
        $this->openSixMarkets();

        session()->put('currency_code', 'SAR');
        session()->put('currency_symbol', 'SAR');
        session()->put('currency_exchange_rate', 3.75);

        $kuwait = $this->inStorefront('KW', fn (): array => app(StorefrontCurrency::class)->money(amount: self::KUWAITI_PRICE));

        $this->assertSame('KWD', $kuwait['currency']);
        $this->assertSame('KD', $kuwait['symbol']);
        $this->assertStringNotContainsString('SAR', $kuwait['formatted']);
    }

    public function test_the_money_a_storefront_gives_its_app_is_the_money_its_pages_print(): void
    {
        $this->openSixMarkets();

        $this->inStorefront('KW', function (): void {
            $currency = app(StorefrontCurrency::class);
            $money = $currency->money(amount: self::KUWAITI_PRICE);
            $forTheApp = $currency->apiMoney(amount: self::KUWAITI_PRICE)['storefront_money'];

            $this->assertSame($money['currency'], $forTheApp['currency']);
            $this->assertSame($money['symbol'], $forTheApp['symbol']);
            $this->assertSame($money['decimals'], $forTheApp['decimals']);
            $this->assertSame($money['amount'], $forTheApp['amount']);
            $this->assertSame($money['formatted'], $forTheApp['formatted']);
            $this->assertSame(3, $forTheApp['decimals']);
        });
    }

    /* ------------------------------------------------------------------------------- B-14: search and filters per storefront */

    public function test_the_filter_facets_and_the_price_bound_read_only_this_markets_products_and_money(): void
    {
        $this->openSixMarkets();

        [$kuwaitiFacet, $kuwaitiBound, $kuwaitiSign] = $this->inStorefront('KW', fn (): array => [
            BrandManager::getActiveBrandWithCountingAndPriorityWiseSorting()->first()->brand_products_count,
            getProductMaxUnitPriceRange(type: 'web'),
            getCurrencySymbol(type: 'web'),
        ]);
        [$saudiFacet, $saudiSign] = $this->inStorefront('SA', fn (): array => [
            BrandManager::getActiveBrandWithCountingAndPriorityWiseSorting()->first()->brand_products_count,
            getCurrencySymbol(type: 'web'),
        ]);

        $this->assertSame(2, $kuwaitiFacet, 'the Kuwaiti brand facet counts the two Kuwaiti products and neither Saudi one');
        $this->assertSame(1, $saudiFacet);

        // The bound is the Kuwaiti catalogue's own maximum (12.345 dinars) rounded up by the range step, not the Saudi 100.
        $this->assertSame(500, $kuwaitiBound);
        $this->assertSame('KD', $kuwaitiSign);
        $this->assertSame('SAR', $saudiSign);
    }

    /* ----------------------------------------------------------------------- B-17: canonical, hreflang, offer, sitemap, index */

    public function test_a_kuwaiti_product_pages_canonical_is_its_own_markets_address(): void
    {
        $this->openSixMarkets();

        $canonical = $this->inStorefront('KW', fn (): string => app(StorefrontSeo::class)->canonical(route('product', ['slug' => 'kuwaiti-serum'])));

        $this->assertSame('https://lavkoo.test/kw/product/kuwaiti-serum', $canonical);
        $this->assertSame(
            'https://lavkoo.test/product/saudi-serum',
            $this->inStorefront('SA', fn (): string => app(StorefrontSeo::class)->canonical(route('product', ['slug' => 'saudi-serum']))),
        );
    }

    public function test_the_hreflang_alternates_list_the_storefront_and_the_language_variants_of_the_same_page(): void
    {
        $this->openSixMarkets();

        $alternates = $this->inStorefront('KW', fn (): array => app(StorefrontSeo::class)->alternates(
            url: route('product', ['slug' => 'kuwaiti-serum']),
            languageCodes: ['en', 'ar'],
        ));

        $this->assertSame([
            'en-SA' => 'https://lavkoo.test/product/kuwaiti-serum?language=en',
            'ar-SA' => 'https://lavkoo.test/product/kuwaiti-serum?language=ar',
            'en-AE' => 'https://lavkoo.test/ae/product/kuwaiti-serum?language=en',
            'ar-AE' => 'https://lavkoo.test/ae/product/kuwaiti-serum?language=ar',
            'en-KW' => 'https://lavkoo.test/kw/product/kuwaiti-serum?language=en',
            'ar-KW' => 'https://lavkoo.test/kw/product/kuwaiti-serum?language=ar',
        ], $alternates);
    }

    /** One implementation of the offer, read by the page's structured data and by the app, so neither can state a price the other does not. */
    public function test_one_offer_states_this_markets_price_and_currency_and_the_page_and_the_app_agree_with_it(): void
    {
        $this->openSixMarkets();

        $this->inStorefront('KW', function (): void {
            $offer = app(StorefrontSeo::class)->offer(amount: self::KUWAITI_PRICE);
            $money = app(StorefrontCurrency::class)->money(amount: self::KUWAITI_PRICE);
            $forTheApp = app(StorefrontCurrency::class)->apiMoney(amount: self::KUWAITI_PRICE)['storefront_money'];

            $this->assertSame('KWD', $offer['currency']);
            $this->assertSame(12.345, round((float) $offer['price'], 3));
            $this->assertSame($money['amount'], $offer['price']);
            $this->assertSame($money['currency'], $offer['currency']);
            $this->assertSame($forTheApp['amount'], $offer['price']);
            $this->assertSame($forTheApp['currency'], $offer['currency']);
        });
    }

    /** The tags the product page actually renders, through the real partial and the real composer. */
    public function test_the_rendered_product_page_tags_carry_this_markets_address_alternates_and_offer(): void
    {
        $this->openSixMarkets();

        $html = $this->inStorefront('KW', fn (): string => view('seo.product-supplement', [
            'productDetails' => \App\Models\Product::query()->find(2),
        ])->render());

        $this->assertStringContainsString('<link rel="canonical" href="https://lavkoo.test/kw/product/kuwaiti-serum">', $html);
        $this->assertStringContainsString('hreflang="en-KW" href="https://lavkoo.test/kw/product/kuwaiti-serum?language=en"', $html);
        $this->assertStringContainsString('hreflang="en-SA" href="https://lavkoo.test/product/kuwaiti-serum?language=en"', $html);
        $this->assertStringContainsString('"priceCurrency":"KWD"', $html);
        // Three decimals, not the two a hundredths currency would be rounded to: a dinar price rounded to hundredths is a price
        // nobody can pay, and a crawler told it would advertise a figure the page never printed.
        $this->assertStringContainsString('"price":"12.345"', $html);
        $this->assertStringContainsString('"url":"https://lavkoo.test/kw/product/kuwaiti-serum"', $html);
    }

    public function test_a_markets_sitemap_holds_only_its_own_products_under_its_own_addresses(): void
    {
        $this->openSixMarkets();

        $files = app(StorefrontSitemap::class)->files(at: CarbonImmutable::parse('2026-11-21T09:00:00+00:00'), stamp: 'now');

        $this->assertSame(['sitemap-now-ae.xml', 'sitemap-now-kw.xml'], array_keys($files));

        $kuwait = $files['sitemap-now-kw.xml'];
        $this->assertStringContainsString('<loc>https://lavkoo.test/kw/product/kuwaiti-serum</loc>', $kuwait);
        $this->assertStringContainsString('<loc>https://lavkoo.test/kw/product/kuwaiti-balm</loc>', $kuwait);
        $this->assertStringContainsString('<loc>https://lavkoo.test/kw</loc>', $kuwait);
        $this->assertStringNotContainsString('saudi-serum', $kuwait);
        $this->assertStringNotContainsString('<loc>https://lavkoo.test/product/', $kuwait);

        // The Emirati market is live and has no products, so its file carries its landing page and nobody else's catalogue.
        $this->assertStringNotContainsString('kuwaiti-serum', $files['sitemap-now-ae.xml']);
        $this->assertStringNotContainsString('saudi-serum', $files['sitemap-now-ae.xml']);
    }

    public function test_a_market_that_is_not_live_is_not_indexable_and_keeps_no_sitemap(): void
    {
        $this->openSixMarkets();

        // Qatar is switched on and its currency exists, but its legal texts were never approved, so it is a market being prepared.
        $seo = app(StorefrontSeo::class);

        $this->assertTrue($seo->isIndexable(country: 'KW'));
        $this->assertFalse($seo->isIndexable(country: 'QA'));
        $this->assertFalse($seo->isIndexable(country: 'OM'));

        $files = app(StorefrontSitemap::class)->files(at: CarbonImmutable::now(), stamp: 'now');
        $this->assertArrayNotHasKey('sitemap-now-qa.xml', $files);

        foreach ($files as $xml) {
            $this->assertStringNotContainsString('/qa/', $xml);
        }
    }

    public function test_every_page_of_a_market_that_is_not_live_is_served_noindex(): void
    {
        $this->openSixMarkets();

        $this->assertSame('noindex, nofollow', $this->robotsHeaderOf('QA'));
        $this->assertNull($this->robotsHeaderOf('KW'));
        $this->assertNull($this->robotsHeaderOf('SA'));
    }

    /* ----------------------------------------------------------------------------- B-16: a fixed amount states its unit */

    public function test_a_fixed_amount_clearance_discount_states_its_unit_in_this_markets_money(): void
    {
        $this->openSixMarkets();

        $product = ['unit_price' => self::KUWAITI_PRICE, 'discount' => 0, 'discount_type' => 'flat', 'clearance_sale' => ['discount_type' => 'flat', 'discount_amount' => 2.5]];

        $kuwait = $this->inStorefront('KW', fn (): string => (string) getProductPriceByType(product: $product, type: 'discount', result: 'string'));
        $saudi = $this->inStorefront('SA', fn (): string => (string) getProductPriceByType(product: $product, type: 'discount', result: 'string'));

        $this->assertSame('KD2.500', $kuwait, 'a flat clearance discount on the Kuwaiti market is two and a half dinars, to three decimals');
        $this->assertStringStartsWith('SAR', $saudi);
        $this->assertNotSame($kuwait, $saudi);
    }

    /** A discount stated as a share of the price is not money, so it carries no currency on any market. */
    public function test_a_percentage_discount_states_a_share_and_not_a_currency(): void
    {
        $this->openSixMarkets();

        $product = ['unit_price' => self::KUWAITI_PRICE, 'discount' => 10, 'discount_type' => 'percent'];

        $this->assertSame('10%', $this->inStorefront('KW', fn (): string => (string) getProductPriceByType(product: $product, type: 'discount', result: 'string')));
    }

    /* ----------------------------------------------------------------------------------------------- Saudi protection */

    public function test_with_all_six_markets_live_the_saudi_pages_money_address_and_robots_are_unchanged(): void
    {
        config(['gcc.storefronts.enabled' => false, 'gcc.storefronts.currency.enabled' => false]);
        $before = $this->saudiSnapshot();

        $this->openSixMarkets();
        $after = $this->saudiSnapshot();

        $this->assertSame($before['money'], $after['money']);
        $this->assertSame($before['canonical'], $after['canonical']);
        $this->assertSame($before['robots_header'], $after['robots_header']);
        $this->assertSame($before['price_bound'], $after['price_bound']);
        $this->assertSame($before['sign'], $after['sign']);
        $this->assertSame($before['clearance_discount'], $after['clearance_discount']);

        // The Saudi sitemap keeps the Saudi product and gains no other market's.
        $this->assertSame(['saudi-serum'], $after['platform_sitemap_slugs']);

        // The one tag that changes is the one this feature is for: the page now says which markets carry it, Saudi Arabia among them.
        $this->assertArrayHasKey('en-SA', $after['alternates']);
        $this->assertSame('https://lavkoo.test/product/saudi-serum?language=en', $after['alternates']['en-SA']);
    }

    /* ----------------------------------------------------------------------------------------------------------- helpers */

    /** The six markets on, their currencies in force, and the legal texts of Saudi Arabia, the Emirates and Kuwait approved. */
    private function openSixMarkets(): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.currency.enabled' => true,
            'gcc.storefronts.countries.AE.active' => true,
            'gcc.storefronts.countries.KW.active' => true,
            'gcc.storefronts.countries.QA.active' => true,
            'gcc.storefronts.countries.BH.active' => true,
            'gcc.storefronts.countries.OM.active' => true,
        ]);

        $this->publishRequiredLegalDocuments('AE');
        $this->publishRequiredLegalDocuments('KW');
        Cache::flush();
    }

    /**
     * Everything this feature can state about one Saudi page, in one array, so the dormant state can be compared field by field
     * instead of claim by claim.
     *
     * @return array<string, mixed>
     */
    private function saudiSnapshot(): array
    {
        return $this->inStorefront('SA', fn (): array => [
            'money' => app(StorefrontCurrency::class)->money(amount: self::SAUDI_PRICE),
            'api_money' => app(StorefrontCurrency::class)->apiMoney(amount: self::SAUDI_PRICE),
            'canonical' => app(StorefrontSeo::class)->canonical(route('product', ['slug' => 'saudi-serum'])),
            'alternates' => app(StorefrontSeo::class)->alternates(url: route('product', ['slug' => 'saudi-serum']), languageCodes: ['en', 'ar']),
            'indexable' => app(StorefrontSeo::class)->isIndexable(),
            'robots_header' => $this->robotsHeader(),
            'price_bound' => getProductMaxUnitPriceRange(type: 'web'),
            'sign' => getCurrencySymbol(type: 'web'),
            'clearance_discount' => (string) getProductPriceByType(
                product: ['unit_price' => self::SAUDI_PRICE, 'discount' => 0, 'discount_type' => 'flat', 'clearance_sale' => ['discount_type' => 'flat', 'discount_amount' => 2.5]],
                type: 'discount',
                result: 'string',
            ),
            'storefront_sitemaps' => array_keys(app(StorefrontSitemap::class)->files(at: CarbonImmutable::parse('2026-11-21T09:00:00+00:00'), stamp: 'now')),
            'platform_sitemap_slugs' => app(StorefrontSitemap::class)->defaultStorefrontSlugs(),
        ]);
    }

    /** What the middleware puts on a response served from the market in force. */
    private function robotsHeader(): ?string
    {
        return app(NoIndexUnlaunchedStorefront::class)
            ->handle(Request::create('/'), static fn (): Response => new Response('ok'))
            ->headers->get('X-Robots-Tag');
    }

    private function robotsHeaderOf(string $country): ?string
    {
        return $this->inStorefront($country, fn (): ?string => $this->robotsHeader());
    }

    /** Every test that reads a storefront's own money needs a fresh currency service, because it remembers the market in force. */
    private function inStorefront(string $country, callable $callback): mixed
    {
        app()->forgetInstance(StorefrontCurrency::class);
        session()->forget(['system_default_currency_info', 'currency_code', 'currency_symbol', 'currency_exchange_rate', 'usd', 'default']);
        Cache::flush();

        return StorefrontResolver::fromConfig()->context(explicitChoice: $country)->serve($callback);
    }
}
