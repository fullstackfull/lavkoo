<?php

namespace Tests\Feature\Storefront;

use App\Http\Middleware\NoIndexUnlaunchedStorefront;
use App\Models\Currency;
use App\Models\Product;
use App\Services\Seo\StorefrontSeo;
use App\Services\Seo\StorefrontSitemap;
use App\Services\Seo\StructuredDataBuilder;
use App\Services\Storefront\StorefrontCurrency;
use App\Services\Storefront\StorefrontResolver;
use App\Services\Storefront\StorefrontUrl;
use App\Utils\BrandManager;
use App\Utils\Convert;
use App\Utils\Helpers;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsStorefrontCatalog;
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
 * configured, every number, tag and file is what it is today, compared field by field against the same snapshot. The snapshot holds
 * this feature's headline output — the rendered `seo.product-supplement` partial, through the real composer — because a proof that
 * excuses the page itself proves nothing, and it is taken under both currency models, since the model an operator picked decides
 * whether the money helpers convert a stored amount or show it as it is.
 */
class StorefrontMoneyAndSeoTest extends TestCase
{
    use BuildsStorefrontSeoWorld;
    use BuildsStorefrontLegalDocuments;
    /** For serveAsHttpRequest only: the configuration endpoint is reached before any storefront middleware binds a context. */
    use BuildsStorefrontCatalog;

    private const SAUDI_PRICE = '100.00';

    private const KUWAITI_PRICE = '12.345';

    /** A third market's price above the price filter's 500 step, so Kuwait's own bound and the whole catalogue's round to different numbers. */
    private const QATARI_PRICE_ABOVE_THE_STEP = '600.00';

    /** The theme headers that print the currency switcher, which is where a visitor's pick is shown back to them. */
    private const CURRENCY_SWITCHER_HEADERS = [
        'resources/themes/default/layouts/front-end/partials/_header.blade.php',
        'resources/themes/default/auction/layouts/partials/_header.blade.php',
    ];

    /**
     * The currencies the switcher's own dropdown offers in this suite's world. It is part of the rendered region because the region is
     * the page's, `@if` and all, rather than the one line that names the pick; nothing in this feature decides the list.
     */
    private const SWITCHER_DROPDOWN_CURRENCIES = 'USD SAR AED KWD QAR BHD OMR';

    /** A key no storefront scopes, for the vacuity check: what one market writes under it, another reads. */
    private const UNSCOPED_PROBE_KEY = 'gcc_test_unscoped_cache_probe';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontSeoWorld();
        $this->buildStorefrontLegalDocumentsTable();

        // The theme's own views are only registered for an HTTP request (ThemeServiceProvider), so a test that renders one says so.
        View::addLocation(resource_path('themes/' . theme_root_path()));

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

    #[DataProvider('currencyModels')]
    public function test_with_the_markets_switched_off_every_number_tag_and_file_is_what_it_is_today(string $currencyModel): void
    {
        config(['gcc.storefronts.enabled' => false, 'gcc.storefronts.currency.enabled' => false]);
        $this->setBusinessSettings(['currency_model' => $currencyModel]);

        $snapshot = $this->saudiSnapshot();

        // The platform's own settings, untouched: the riyal, two decimals, the platform's own currency model.
        $this->assertSame('SAR', $snapshot['money']['currency']);
        $this->assertSame(2, $snapshot['money']['decimals']);

        // The rendered page is in the snapshot, so it has to be there to compare: the real partial, through the real composer, with
        // the bare address, no storefront alternate, and the offer this page has always published — the amount as it is stored, in
        // the platform's default currency, under either currency model. What the page PRINTS under the multi-currency model is a
        // different number, and that disagreement is older than the markets:
        // {@see test_the_published_offer_still_disagrees_with_the_printed_price_under_the_multi_currency_model}.
        $this->assertStringContainsString('<link rel="canonical" href="https://lavkoo.test/product/saudi-serum">', $snapshot['rendered_supplement']);
        $this->assertStringNotContainsString('/sa/', $snapshot['rendered_supplement']);
        $this->assertStringContainsString('"price":"100"', $snapshot['rendered_supplement']);
        $this->assertStringContainsString('"priceCurrency":"SAR"', $snapshot['rendered_supplement']);
        $this->assertSame(['price' => self::SAUDI_PRICE, 'currency' => 'SAR', 'decimals' => 2], $snapshot['offer']);
        $this->assertSame('100', $snapshot['schema_offer']['price']);
        $this->assertSame('SAR', $snapshot['schema_offer']['priceCurrency']);

        // The price filter the listing renders, in both session states, pinned absolutely rather than compared with the other state:
        // these are live Saudi fields, and a sign appearing in the first state is exactly the drift this proof exists to catch. The
        // ceiling is the Saudi catalogue's own under either currency model.
        $this->assertStringContainsString('placeholder="0"', $snapshot['rendered_price_filter']['cold'], 'a first request carries no sign');
        $this->assertStringContainsString('placeholder="500"', $snapshot['rendered_price_filter']['cold']);
        $this->assertStringNotContainsString('SAR', $snapshot['rendered_price_filter']['cold']);
        $this->assertStringContainsString('placeholder="SAR0"', $snapshot['rendered_price_filter']['warm'], 'once the session is warm');
        $this->assertStringContainsString('placeholder="SAR500"', $snapshot['rendered_price_filter']['warm']);

        // The currency switcher each theme header prints, pinned absolutely for the same reason: these too are live Saudi fields, and
        // they still show the visitor their own pick, which is the whole of what the header has ever said about money. The whole
        // guarded region is rendered, so the operator's currency model decides whether it is there at all -- under the single-currency
        // model the header offers no switcher, and that is as true today as it was before the markets existed.
        $this->assertSame($currencyModel === 'multi_currency' ? [
            self::CURRENCY_SWITCHER_HEADERS[0] => '<span>SAR SAR</span> ' . self::SWITCHER_DROPDOWN_CURRENCIES,
            self::CURRENCY_SWITCHER_HEADERS[1] => 'SAR SAR ' . self::SWITCHER_DROPDOWN_CURRENCIES,
        ] : [
            self::CURRENCY_SWITCHER_HEADERS[0] => '',
            self::CURRENCY_SWITCHER_HEADERS[1] => '',
        ], array_map(
            static fn (string $switcher): string => trim(preg_replace('/\s+/', ' ', strip_tags($switcher, '<span>'))),
            $snapshot['rendered_currency_switchers'],
        ));

        // No storefront address, no storefront alternates, no robots header, and the one sitemap the panel writes today.
        $this->assertSame('https://lavkoo.test/product/saudi-serum', $snapshot['canonical']);
        $this->assertSame(['en' => 'https://lavkoo.test/product/saudi-serum?language=en', 'ar' => 'https://lavkoo.test/product/saudi-serum?language=ar'], $snapshot['alternates']);
        $this->assertNull($snapshot['robots_header']);
        $this->assertSame([], $snapshot['storefront_sitemaps']);
        $this->assertSame([], $snapshot['api_money']);

        // Every product the platform has, in the one platform file, as before the markets existed.
        $this->assertSame(['saudi-serum', 'kuwaiti-serum', 'kuwaiti-balm'], $snapshot['platform_sitemap_slugs']);
    }

    /**
     * A dormant installation is a Saudi one: it holds no other market's products, because no other market exists on it. So this compares
     * the two states on that catalogue, and every field has to match — no field is excused.
     */
    #[DataProvider('currencyModels')]
    public function test_with_the_markets_on_and_only_saudi_arabia_configured_nothing_changes(string $currencyModel): void
    {
        $this->keepOnlySaudiCatalogue();
        $this->setBusinessSettings(['currency_model' => $currencyModel]);

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

        $this->assertSame($before, $this->saudiSnapshot());
    }

    /**
     * Characterisation, not an endorsement, and the reason the offer above is dormant rather than corrected.
     *
     * Under the multi-currency model the money helpers treat a stored amount as a base-currency amount and convert it for display, so
     * a product stored at 100 PRINTS as SAR375.00 on the live Saudi page while its JSON-LD offer says 100 SAR. The page and the
     * crawler have disagreed like that since before the markets existed, and correcting it would move the published price of a live
     * page — which is the one thing a dormant slice may not do, and a decision for whoever owns what Google is shown. This pins what
     * ships today so that the change is visible when somebody makes it, as
     * {@see test_a_percentage_discount_is_rounded_to_whole_percent_everywhere_today} pins the rounding beside it.
     */
    public function test_the_published_offer_still_disagrees_with_the_printed_price_under_the_multi_currency_model(): void
    {
        config(['gcc.storefronts.enabled' => false, 'gcc.storefronts.currency.enabled' => false]);
        $this->setBusinessSettings(['currency_model' => 'multi_currency']);

        $snapshot = $this->saudiSnapshot();

        $this->assertSame('SAR375.00', $snapshot['money']['formatted'], 'what the page prints');
        $this->assertStringContainsString('"price":"100"', $snapshot['rendered_supplement'], 'what the crawler is told');
        $this->assertStringContainsString('"priceCurrency":"SAR"', $snapshot['rendered_supplement']);

        // A market that trades in its own currency has no such gap: there the model is the single-currency one, so the number the page
        // prints and the number the offer states are the same number.
        $this->openSixMarkets();
        $this->inStorefront('KW', function (): void {
            $this->assertSame('KD12.345', app(StorefrontCurrency::class)->money(amount: self::KUWAITI_PRICE)['formatted']);
            $this->assertStringContainsString('"price":"12.345"', $this->renderedSupplement(productId: 2));
        });
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

            // The listing and the cards print a discounted unit price through getProductPriceByType, which reaches the same helper.
            $discounted = ['unit_price' => self::KUWAITI_PRICE, 'discount' => 1.25, 'discount_type' => 'flat'];
            $this->assertSame(
                app(StorefrontCurrency::class)->money(amount: 11.095)['formatted'],
                (string) getProductPriceByType(product: $discounted, type: 'discounted_unit_price', result: 'string'),
            );
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

        // The price filter reads the pick straight out of the session where no storefront currency is in force, so it is the surface
        // the stale pick would reach first.
        session()->put('currency_symbol', 'SAR');
        $filter = $this->inStorefrontKeepingTheVisitorsPick('KW', fn (): string => view('web-views.products.partials._filter-product-price')->render());

        $this->assertStringContainsString('placeholder="KD0"', $filter);
        $this->assertStringNotContainsString('SAR', $filter);

        // The other surface a stale pick could reach is the header's currency switcher, and the reason it cannot is structural rather
        // than a matter of what the switcher prints: the override that puts a storefront currency in force forces `currency_model` to
        // `single_currency`, and both headers wrap the switcher in `@if($currency_model == 'multi_currency')`. The two states are
        // mutually exclusive, so a market trading in its own money offers no switcher at all.
        //
        // This renders the whole guarded region with the operator's model set to multi_currency -- the only setting under which the
        // switcher could appear -- and with the entire stale pick in the session. It goes red the moment either half of that structure
        // changes: if the override stops forcing the model, or if a header's guard is removed, the region renders and the session's
        // 'SAR SAR' is back on a page of dinars.
        $this->setBusinessSettings(['currency_model' => 'multi_currency']);
        session()->put('currency_code', 'SAR');
        session()->put('currency_symbol', 'SAR');

        $switchers = $this->inStorefrontKeepingTheVisitorsPick('KW', fn (): array => $this->renderedCurrencySwitchers());

        foreach ($switchers as $header => $switcher) {
            $this->assertSame('', $switcher, $header . ' offers no currency switcher while this market trades in its own money');
        }

        $this->assertSame(
            'single_currency',
            $this->inStorefrontKeepingTheVisitorsPick('KW', static fn (): string => (string) getWebConfig(name: 'currency_model')),
            'the model the headers read is forced by the storefront currency, which is what empties the region above',
        );
    }

    /**
     * **The currency a payment is taken in, on a market that trades in its own money.** Four places on the money paths read the
     * visitor's session pick: `Customer\PaymentController` lines 417, 486 and 634 (`$request['current_currency_code'] ??
     * session('currency_code')`) and `OrderEditManager` line 829. Every one of them sits inside
     * `if (getWebConfig(name: 'currency_model') == 'multi_currency')`, and `StorefrontCurrency::override()` forces that setting to
     * `single_currency` whenever a storefront currency is in force — so on such a market the branch is not taken at all, and the `else`
     * branch reads `getWebConfig('system_default_currency')`, which the same override points at the market's own currency.
     *
     * This measures both settings in the real market context, through the same `getWebConfig()` calls those branches make, with the
     * operator's model set to `multi_currency` (the only setting under which the session pick could be read) and the whole stale pick
     * in the session. It goes red the moment the override stops forcing the model — which is the one change that would make those four
     * session reads live on a money path — so it is the guard that keeps them unreachable rather than an assumption that they are.
     *
     * Driving the controller end to end (a cart, a customer, a gateway) is the remaining confirmation and is not done here.
     */
    public function test_a_payment_on_a_market_with_its_own_money_cannot_be_taken_in_a_pick_left_on_another(): void
    {
        $this->openSixMarkets();
        $this->setBusinessSettings(['currency_model' => 'multi_currency']);

        $kuwait = $this->inStorefrontKeepingTheVisitorsPick('KW', function (): array {
            session()->put('currency_code', 'SAR');
            session()->put('currency_symbol', 'SAR');
            session()->put('currency_exchange_rate', 3.75);

            return [
                'model' => (string) getWebConfig(name: 'currency_model'),
                'currency' => (string) Currency::find(getWebConfig(name: 'system_default_currency'))?->code,
                'pick' => (string) session('currency_code'),
            ];
        });

        $this->assertSame('single_currency', $kuwait['model'], 'the branch that reads the session pick is not entered');
        $this->assertSame('KWD', $kuwait['currency'], 'the branch that is entered reads this market own money');
        $this->assertSame('SAR', $kuwait['pick'], 'the stale pick really was in the session, and really was not read');

        // And the default storefront is untouched: the operator's own currency model stands, and so does the platform's own currency.
        $saudi = $this->inStorefrontKeepingTheVisitorsPick('SA', static fn (): array => [
            'model' => (string) getWebConfig(name: 'currency_model'),
            'currency' => (string) Currency::find(getWebConfig(name: 'system_default_currency'))?->code,
        ]);

        $this->assertSame('multi_currency', $saudi['model']);
        $this->assertSame('SAR', $saudi['currency']);
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

    /**
     * The configuration endpoint the app reads carries no storefront middleware, so it answers from the storefront header alone. The
     * decimals it states must still be the chosen market's currency's, which is the whole of B-03's second criterion for the app.
     */
    public function test_the_configuration_an_app_reads_states_the_chosen_markets_decimals(): void
    {
        $this->openSixMarkets();

        $this->serveAsHttpRequest(uri: 'api/v1/config', headers: ['X-Storefront' => 'KW']);
        app()->forgetInstance(StorefrontCurrency::class);

        $this->assertSame(3, (int) getWebConfig('decimal_point_settings'), 'the key the endpoint answers with');
        $this->assertSame('KWD', app(StorefrontCurrency::class)->apiMoney()['storefront_money']['currency']);
        $this->assertSame(3, app(StorefrontCurrency::class)->apiMoney()['storefront_money']['decimals']);

        $this->serveAsHttpRequest(uri: 'api/v1/config', headers: ['X-Storefront' => 'SA']);
        app()->forgetInstance(StorefrontCurrency::class);
        clearWebConfigCacheKeys();

        $this->assertSame(2, (int) getWebConfig('decimal_point_settings'));
        $this->assertSame([], app(StorefrontCurrency::class)->apiMoney(), 'the default storefront adds nothing to the answer');
    }

    /* ------------------------------------------------------------------------------- B-14: search and filters per storefront */

    public function test_the_filter_facets_and_the_price_bound_read_only_this_markets_products_and_money(): void
    {
        $this->openSixMarkets();

        // A third market's product above the filter's 500 step. Without it the Kuwaiti catalogue and the whole catalogue round to the
        // same bound, and every assertion below about the bound passes just as well when the helper reads every market's products.
        $this->addProduct(id: 4, name: 'Qatari serum', slug: 'qatari-serum', price: self::QATARI_PRICE_ABOVE_THE_STEP, country: 'QA');

        [$kuwaitiFacet, $kuwaitiBound, $kuwaitiFloor, $kuwaitiSign] = $this->inStorefront('KW', fn (): array => [
            BrandManager::getActiveBrandWithCountingAndPriorityWiseSorting()->first()->brand_products_count,
            getProductMaxUnitPriceRange(type: 'web'),
            getProductMinUnitPriceRange(type: 'web'),
            getCurrencySymbol(type: 'web'),
        ]);
        // The Saudi floor is read without the web conversion, so the only thing that can move it is the catalogue the helper reads.
        [$saudiFacet, $saudiFloor, $saudiSign] = $this->inStorefront('SA', fn (): array => [
            BrandManager::getActiveBrandWithCountingAndPriorityWiseSorting()->first()->brand_products_count,
            getProductMinUnitPriceRange(),
            getCurrencySymbol(type: 'web'),
        ]);

        $this->assertSame(2, $kuwaitiFacet, 'the Kuwaiti brand facet counts the two Kuwaiti products and neither Saudi one');
        $this->assertSame(1, $saudiFacet);

        // Each bound is its own market's catalogue rounded by the range step, and each is a different number from the one the whole
        // catalogue gives: Kuwait's maximum of 12.345 dinars rounds to 500 where the catalogue's 600 rounds to 1000, and the Saudi floor
        // of 100 riyals holds its step where the catalogue's cheapest 8.750 falls under ten and gives 0. Between them the two cover both
        // helpers, and each turns red on its own if the helper behind it stops narrowing to the market in force.
        $this->assertSame(500, $kuwaitiBound);
        $this->assertSame(100, $saudiFloor);
        $this->assertSame(0, $kuwaitiFloor, 'Kuwait keeps a floor of zero of its own, its cheapest product being under ten dinars');
        $this->assertSame('KD', $kuwaitiSign);
        $this->assertSame('SAR', $saudiSign);

        // The field a visitor actually reads, not only the helper behind it: the Kuwaiti filter is signed in dinars on a first request
        // as well as a later one, and the maximum it offers is Kuwait's own 500 and not the catalogue's 1000. That number is written out
        // rather than interpolated from the helper, so the rendered field is read against Kuwait's catalogue instead of following the
        // helper wherever it goes.
        $kuwaitiFilter = $this->inStorefront('KW', fn (): array => $this->renderedPriceFilter());
        foreach (['cold', 'warm'] as $state) {
            $this->assertStringContainsString('placeholder="KD0"', $kuwaitiFilter[$state], $state);
            $this->assertStringContainsString('placeholder="KD500"', $kuwaitiFilter[$state], $state);
        }

        $this->assertRenderedMinimumCarriesTheSignAndNotTheFloor();
    }

    /**
     * **The rendered price filter carries the SIGN and the CEILING. It does not carry the floor, and no assertion against it can prove
     * one.** The partial prints `placeholder="{{ $priceFilterSign }}{{ '0' }}"` -- a template literal -- and never calls the minimum
     * helper at all; the slider beside it is a literal `data-min-value="0"` too. The floor is therefore proved at the surface where it
     * IS observable, which is the helper, and this is what keeps that claim honest: it is the assertion that goes red the day the field
     * starts carrying the floor, so the record cannot quietly fall out of date.
     *
     * **Why the field was not wired to the helper instead.** Doing so would change what a Saudi shopper sees today -- measured on this
     * head, the Saudi floor is 350 converted (100 as stored) while the field offers 0 -- and it would leave the placeholder disagreeing
     * with the slider's own hardcoded minimum unless that moved too. That is a behaviour change to a live Saudi surface that nothing in
     * the plan asks for, against a rule that says to inject logic and not alter the design. The floor already has a reader that a wrong
     * value would reach: `api/v1/config` publishes it as `product_min_unit_price_range`, straight from this helper.
     *
     * The Saudi market is the one to measure it on, because its floor is a non-zero number there: `KD0` on the Kuwaiti field could be
     * the literal zero or a floor that happens to be zero, and would prove nothing either way.
     */
    private function assertRenderedMinimumCarriesTheSignAndNotTheFloor(): void
    {
        [$floorAsStored, $floorConverted, $filter] = $this->inStorefront('SA', fn (): array => [
            getProductMinUnitPriceRange(),
            getProductMinUnitPriceRange(type: 'web'),
            $this->renderedPriceFilter()['warm'],
        ]);

        $this->assertSame(100, $floorAsStored, 'the Saudi floor as stored, which the field would print if it carried the floor');
        $this->assertSame(350, $floorConverted, 'and converted for the web, which it would print under the web conversion');

        $this->assertStringContainsString('placeholder="SAR0"', $filter, 'the minimum is the sign and the template\'s own literal zero');
        $this->assertStringNotContainsString('placeholder="SAR' . $floorAsStored . '"', $filter);
        $this->assertStringNotContainsString('placeholder="SAR' . $floorConverted . '"', $filter);
        $this->assertStringNotContainsString(
            'getProductMinUnitPriceRange',
            file_get_contents(resource_path('themes/default/web-views/products/partials/_filter-product-price.blade.php')),
            'if the field ever calls the minimum helper, the floor becomes a rendered bound and this test must be rewritten to prove it',
        );
    }

    /**
     * A bound typed into the price filter is compared against the catalogue as typed on a market that trades in its own currency,
     * because the catalogue's prices are in that currency and nothing is converted. A pick a visitor left in the session on another
     * market must not put a rate between the two.
     */
    public function test_a_price_filter_bound_typed_in_this_markets_money_is_compared_as_typed(): void
    {
        $this->openSixMarkets();

        $this->inStorefront('KW', function (): void {
            session()->put('currency_code', 'SAR');
            session()->put('currency_exchange_rate', 3.75);

            $this->assertSame(10.0, Convert::usdPaymentModule(10, session('currency_code')), 'the web list filter');
            $this->assertSame(10.0, Helpers::convert_currency_to_usd(10), 'the search filter');
        });
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

    /**
     * A page every market has of its own — a landing page — is carried by every live market, so its hreflang set names them all, in
     * every published language.
     */
    public function test_a_landing_pages_hreflang_set_names_every_live_market(): void
    {
        $this->openSixMarkets();

        $seo = app(StorefrontSeo::class);
        $alternates = $this->inStorefront('KW', fn (): array => $seo->alternates(
            url: route('home'),
            languageCodes: ['en', 'ar'],
            carriedBy: $seo->indexableCountries(),
        ));

        $this->assertSame([
            'en-SA' => 'https://lavkoo.test/?language=en',
            'ar-SA' => 'https://lavkoo.test/?language=ar',
            'en-AE' => 'https://lavkoo.test/ae?language=en',
            'ar-AE' => 'https://lavkoo.test/ae?language=ar',
            'en-KW' => 'https://lavkoo.test/kw?language=en',
            'ar-KW' => 'https://lavkoo.test/kw?language=ar',
        ], $alternates);
    }

    /**
     * Which markets may be indexed and which markets carry a page are two different questions, and a product page must answer the
     * second. Every product belongs to one country, so the Saudi storefront serves no page at all for a Kuwaiti product — its own
     * controller takes the not-found branch — and an `en-SA` alternate, or an `x-default` built from one, would point a crawler at a
     * redirect. The alternates of a product page name its own market, in every published language, and nobody else's.
     */
    public function test_a_product_pages_hreflang_set_names_only_the_markets_whose_catalogue_holds_it(): void
    {
        $this->openSixMarkets();

        $seo = app(StorefrontSeo::class);

        // The gate still says all three markets may be indexed; the catalogue says which one holds the product.
        $this->assertSame(['SA', 'AE', 'KW'], $this->inStorefront('KW', fn (): array => $seo->indexableCountries()));
        $this->assertSame(['KW'], $this->inStorefront('KW', fn (): array => $seo->countriesCarrying(product: Product::query()->find(2))));
        $this->assertSame(['SA'], $this->inStorefront('KW', fn (): array => $seo->countriesCarrying(product: Product::query()->find(1))));

        $kuwaiti = $this->inStorefront('KW', fn (): array => $seo->alternates(
            url: route('product', ['slug' => 'kuwaiti-serum']),
            languageCodes: ['en', 'ar'],
            carriedBy: $seo->countriesCarrying(product: Product::query()->find(2)),
        ));

        $this->assertSame([
            'en' => 'https://lavkoo.test/kw/product/kuwaiti-serum?language=en',
            'ar' => 'https://lavkoo.test/kw/product/kuwaiti-serum?language=ar',
        ], $kuwaiti);

        // Symmetrically for the Saudi page, which keeps the bare address its own market has always had.
        $saudi = $this->inStorefront('SA', fn (): array => $seo->alternates(
            url: route('product', ['slug' => 'saudi-serum']),
            languageCodes: ['en', 'ar'],
            carriedBy: $seo->countriesCarrying(product: Product::query()->find(1)),
        ));

        $this->assertSame([
            'en' => 'https://lavkoo.test/product/saudi-serum?language=en',
            'ar' => 'https://lavkoo.test/product/saudi-serum?language=ar',
        ], $saudi);
    }

    /** The market the page declares is a market whose own shop would show the product: one source, read through the narrowed catalogue. */
    public function test_a_market_the_page_declares_can_itself_show_the_product(): void
    {
        $this->openSixMarkets();

        $seo = app(StorefrontSeo::class);

        foreach ([1 => 'saudi-serum', 2 => 'kuwaiti-serum'] as $productId => $slug) {
            foreach ($seo->countriesCarrying(product: Product::query()->find($productId)) as $country) {
                $this->assertSame(
                    $slug,
                    $this->inStorefront($country, fn (): ?string => Product::active()->where('slug', $slug)->value('slug')),
                    "{$country} declares {$slug}, so its own catalogue has to hold it",
                );
            }
        }

        // And a market the page does not declare cannot: this is the redirect the old alternates pointed at.
        $this->assertNull($this->inStorefront('SA', fn (): ?string => Product::active()->where('slug', 'kuwaiti-serum')->value('slug')));
        $this->assertNull($this->inStorefront('KW', fn (): ?string => Product::active()->where('slug', 'saudi-serum')->value('slug')));
    }

    /** A product of a market a crawler may not be told about declares nothing at all, so no hreflang and no x-default is published for it. */
    public function test_a_product_of_a_market_that_is_not_live_declares_no_market(): void
    {
        $this->openSixMarkets();
        $this->addProduct(id: 4, name: 'Qatari balm', slug: 'qatari-balm', price: '9.00', country: 'QA');

        $seo = app(StorefrontSeo::class);

        $this->assertSame([], $this->inStorefront('QA', fn (): array => $seo->countriesCarrying(product: Product::query()->find(4))));
        $this->assertSame([], $this->inStorefront('QA', fn (): array => $seo->alternates(
            url: route('product', ['slug' => 'qatari-balm']),
            languageCodes: ['en', 'ar'],
            carriedBy: $seo->countriesCarrying(product: Product::query()->find(4)),
        )));
    }

    /**
     * A market that is switched off answers nothing under its path, so it is never given an address of its own: naming `/kw/...` while
     * Kuwait is dark would publish a link to a page that does not exist. It takes the platform address, which is what a country this
     * installation does not serve has always taken.
     */
    public function test_a_market_that_is_switched_off_is_never_given_an_address_of_its_own(): void
    {
        $this->openSixMarkets();
        $url = route('product', ['slug' => 'kuwaiti-serum']);

        $this->assertSame('https://lavkoo.test/kw/product/kuwaiti-serum', app(StorefrontUrl::class)->of(url: $url, country: 'KW'));

        config(['gcc.storefronts.countries.KW.active' => false]);

        $this->assertSame($url, app(StorefrontUrl::class)->of(url: $url, country: 'KW'));
        $this->assertSame($url, app(StorefrontUrl::class)->of(url: $url, country: 'ZZ'), 'a country this installation does not serve, for comparison');
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

        $html = $this->inStorefront('KW', fn (): string => $this->renderedSupplement(productId: 2));

        $this->assertStringContainsString('<link rel="canonical" href="https://lavkoo.test/kw/product/kuwaiti-serum">', $html);
        $this->assertStringContainsString('hreflang="en" href="https://lavkoo.test/kw/product/kuwaiti-serum?language=en"', $html);
        $this->assertStringContainsString('hreflang="ar" href="https://lavkoo.test/kw/product/kuwaiti-serum?language=ar"', $html);

        // Every address the page publishes is its own market's, including the fallback a crawler uses for an unmatched language. No
        // Saudi address appears: the Saudi shop holds no Kuwaiti product and would redirect away from this page.
        $this->assertStringContainsString('hreflang="x-default" href="https://lavkoo.test/kw/product/kuwaiti-serum?language=en"', $html);
        $this->assertStringNotContainsString('-SA"', $html);
        $this->assertStringNotContainsString('https://lavkoo.test/product/', $html);

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
        // The landing page is carried by every live market, and it has no path of its own, so its language alternate keeps the
        // separator rather than hanging off the host.
        $this->assertStringContainsString('hreflang="en-SA" href="https://lavkoo.test/?language=en"', $kuwait);
        $this->assertStringNotContainsString('saudi-serum', $kuwait);
        $this->assertStringNotContainsString('<loc>https://lavkoo.test/product/', $kuwait);

        // A product entry declares only the market whose catalogue holds it, the fallback for an unmatched language included: the
        // whole file published `en-SA`, `ar-SA` and an `x-default` at a bare address for every Kuwaiti product before this.
        $this->assertStringContainsString(
            '<loc>https://lavkoo.test/kw/product/kuwaiti-serum</loc>' . "\n"
            . '    <xhtml:link rel="alternate" hreflang="en" href="https://lavkoo.test/kw/product/kuwaiti-serum?language=en"/>' . "\n"
            . '    <xhtml:link rel="alternate" hreflang="ar" href="https://lavkoo.test/kw/product/kuwaiti-serum?language=ar"/>' . "\n"
            . '    <xhtml:link rel="alternate" hreflang="x-default" href="https://lavkoo.test/kw/product/kuwaiti-serum?language=en"/>',
            $kuwait,
        );
        $this->assertStringNotContainsString('href="https://lavkoo.test/product/kuwaiti-serum', $kuwait);

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

    /** The other path that renders a fixed amount off a price: the discount typed on the product itself. */
    public function test_a_fixed_amount_product_discount_states_its_unit_in_this_markets_money(): void
    {
        $this->openSixMarkets();

        $product = ['unit_price' => self::KUWAITI_PRICE, 'discount' => 1.25, 'discount_type' => 'flat'];

        $this->assertSame('KD1.250', $this->inStorefront('KW', fn (): string => (string) getProductPriceByType(product: $product, type: 'discount', result: 'string')));
        $this->assertStringStartsWith('SAR', $this->inStorefront('SA', fn (): string => (string) getProductPriceByType(product: $product, type: 'discount', result: 'string')));
    }

    /**
     * Characterisation, not an endorsement. getProductPriceByType() rounds a percentage with a variable it never assigns
     * ($decimalPointSettings), so every share is rounded to whole percent on every market: twelve and a half per cent reads as
     * thirteen. It is the same on the live Saudi storefront today, so correcting it would change that page and belongs to whoever
     * owns that decision, not here. This pins what it does so the change is visible when somebody makes it.
     */
    public function test_a_percentage_discount_is_rounded_to_whole_percent_everywhere_today(): void
    {
        $this->openSixMarkets();

        $product = ['unit_price' => self::KUWAITI_PRICE, 'discount' => 12.5, 'discount_type' => 'percent'];

        $this->assertSame('13%', $this->inStorefront('KW', fn (): string => (string) getProductPriceByType(product: $product, type: 'discount', result: 'string')));
        $this->assertSame('13%', $this->inStorefront('SA', fn (): string => (string) getProductPriceByType(product: $product, type: 'discount', result: 'string')));
    }

    /** A discount stated as a share of the price is not money, so it carries no currency on any market. */
    public function test_a_percentage_discount_states_a_share_and_not_a_currency(): void
    {
        $this->openSixMarkets();

        $product = ['unit_price' => self::KUWAITI_PRICE, 'discount' => 10, 'discount_type' => 'percent'];

        $this->assertSame('10%', $this->inStorefront('KW', fn (): string => (string) getProductPriceByType(product: $product, type: 'discount', result: 'string')));
    }

    /* ------------------------------------------------------------------ B-14/B-16: one market never reads another's cache */

    /**
     * The price filter's bound and the brand facet are cached, so the key they are cached under decides whether a market can be served
     * another market's catalogue.
     *
     * Warmed for real as one market and read for real as the other, in ONE store with no flush between the two legs, both ways round.
     * A test that clears the cache before each leg proves only that the two markets compute different numbers; it never puts one
     * market's entry in front of the other, which is the whole property. Each leg therefore carries a vacuity check: the warming
     * market's entry is still in the store when the asking market reads past it, and a value under an unscoped key — one nothing
     * scopes — is read straight through from one market to the other, so a pass cannot come from a store that was empty anyway.
     *
     * The Saudi storefront's key is the plain key, by design, so the two markets' keys are the plain one and `storefront:KW:...`.
     */
    public function test_a_catalogue_answer_warmed_in_one_market_is_never_served_to_another(): void
    {
        $this->openSixMarkets();

        $answers = ['SA' => ['price_floor' => 350, 'brand_facet' => 1], 'KW' => ['price_floor' => 0, 'brand_facet' => 2]];

        foreach ([['KW', 'SA'], ['SA', 'KW']] as [$warms, $asks]) {
            Cache::flush();

            $warmKey = $this->priceBoundCacheKeyOf($warms);
            $this->assertNotSame($warmKey, $this->priceBoundCacheKeyOf($asks), "{$warms} and {$asks} have to write the price bound under different keys");
            $this->assertSame(CACHE_FOR_PRODUCTS_MIN_UNIT_PRICE, $this->priceBoundCacheKeyOf('SA'), 'the Saudi storefront keeps the plain key');

            $this->assertSame($answers[$warms], $this->catalogueAnswers($warms), "{$warms} warms the cache");
            $this->servingStorefront($warms, static fn (): bool => Cache::put(self::UNSCOPED_PROBE_KEY, 'warmed-by-' . $warms, CACHE_FOR_3_HOURS));

            // No flush here, on purpose: this is the whole test.
            $this->assertSame($answers[$asks], $this->catalogueAnswers($asks), "{$asks} reads its own catalogue after {$warms} warmed the cache");

            $this->assertTrue(Cache::has($warmKey), "the {$warms} entry is still in the store, so {$asks} read past it rather than finding nothing");
            $this->assertSame(
                'warmed-by-' . $warms,
                $this->servingStorefront($asks, static fn (): ?string => Cache::get(self::UNSCOPED_PROBE_KEY)),
                "the vacuity check: an unscoped key written by {$warms} does reach {$asks}, so this is one store across both legs",
            );
        }
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
        $this->assertSame($before['alternates'], $after['alternates']);
        $this->assertSame($before['offer'], $after['offer']);
        $this->assertSame($before['schema_offer'], $after['schema_offer']);
        // The whole page a crawler reads, and the whole price filter a visitor reads, byte for byte, with all six markets live.
        $this->assertSame($before['rendered_supplement'], $after['rendered_supplement']);
        $this->assertSame($before['rendered_price_filter'], $after['rendered_price_filter']);
        $this->assertSame($before['rendered_currency_switchers'], $after['rendered_currency_switchers']);
        $this->assertSame($before['robots_header'], $after['robots_header']);
        $this->assertSame($before['price_bound'], $after['price_bound']);
        $this->assertSame($before['sign'], $after['sign']);
        $this->assertSame($before['clearance_discount'], $after['clearance_discount']);
        $this->assertSame($before['filter_bound_conversion'], $after['filter_bound_conversion']);

        // One Saudi number moves, and not because of money: with the markets live the Saudi catalogue no longer holds the cheaper
        // Kuwaiti product, so the cheapest Saudi price sets the price filter's floor. That is the catalogue narrowing this branch
        // already carried, in riyals both times, and it is why the dormancy proof above compares a Saudi-only catalogue.
        $this->assertSame(30, $before['price_floor']);
        $this->assertSame(350, $after['price_floor']);

        // The Saudi sitemap keeps the Saudi product and gains no other market's.
        $this->assertSame(['saudi-serum'], $after['platform_sitemap_slugs']);

        // The page keeps the language-only set and the bare addresses it has today, because Saudi Arabia is the only market whose
        // catalogue holds this product. A market alternate appears where a page really does exist in several markets — a landing
        // page: {@see test_a_landing_pages_hreflang_set_names_every_live_market}.
        $this->assertSame(
            ['en' => 'https://lavkoo.test/product/saudi-serum?language=en', 'ar' => 'https://lavkoo.test/product/saudi-serum?language=ar'],
            $after['alternates'],
        );
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
        return $this->inStorefront('SA', function (): array {
            $seo = app(StorefrontSeo::class);
            $offer = $seo->offer(amount: self::SAUDI_PRICE);

            return [
                'money' => app(StorefrontCurrency::class)->money(amount: self::SAUDI_PRICE),
                'api_money' => app(StorefrontCurrency::class)->apiMoney(amount: self::SAUDI_PRICE),
                // The feature's headline output: the partial the product page includes, rendered through the real composer, which is where
                // the canonical, the hreflang set and the JSON-LD offer a crawler actually reads are decided. A dormancy proof that
                // compares the services but not the page excuses the one field a reader would check first.
                'rendered_supplement' => $this->renderedSupplement(),
                // The other surface this feature renders: the price filter of the listing, whose two placeholders carry the sign and the
                // catalogue's ceiling. Nothing in the suite rendered it, which is how a sign reached a live Saudi field unseen.
                'rendered_price_filter' => $this->renderedPriceFilter(),
                // The third surface this feature renders: the header's currency switcher, which states the money the rest of the page is
                // priced in and so must not move while no storefront trades in its own currency.
                'rendered_currency_switchers' => $this->renderedCurrencySwitchers(),
                'offer' => $offer,
                'schema_offer' => app(StructuredDataBuilder::class)->product([
                    'name' => 'Saudi serum',
                    'price' => $offer['price'],
                    'currency' => $offer['currency'],
                    'price_decimals' => $offer['decimals'],
                ])['offers'],
                'canonical' => $seo->canonical(route('product', ['slug' => 'saudi-serum'])),
                'alternates' => $seo->alternates(
                    url: route('product', ['slug' => 'saudi-serum']),
                    languageCodes: ['en', 'ar'],
                    carriedBy: $seo->countriesCarrying(product: Product::query()->find(1)),
                ),
                'indexable' => $seo->isIndexable(),
                'robots_header' => $this->robotsHeader(),
                'price_bound' => getProductMaxUnitPriceRange(type: 'web'),
                'price_floor' => getProductMinUnitPriceRange(type: 'web'),
                'filter_bound_conversion' => Convert::usdPaymentModule(10, (string) session('currency_code')),
                'sign' => getCurrencySymbol(type: 'web'),
                'clearance_discount' => (string) getProductPriceByType(
                    product: ['unit_price' => self::SAUDI_PRICE, 'discount' => 0, 'discount_type' => 'flat', 'clearance_sale' => ['discount_type' => 'flat', 'discount_amount' => 2.5]],
                    type: 'discount',
                    result: 'string',
                ),
                'storefront_sitemaps' => array_keys(app(StorefrontSitemap::class)->files(at: CarbonImmutable::parse('2026-11-21T09:00:00+00:00'), stamp: 'now')),
                'platform_sitemap_slugs' => app(StorefrontSitemap::class)->defaultStorefrontSlugs(),
            ];
        });
    }

    /** The key the price filter's lower bound is cached under for one market. */
    private function priceBoundCacheKeyOf(string $country): string
    {
        return $this->servingStorefront($country, static fn (): string => storefrontCacheKey(key: CACHE_FOR_PRODUCTS_MIN_UNIT_PRICE));
    }

    /**
     * The two cached catalogue answers this slice reads, for the market serving the request.
     *
     * @return array{price_floor: int, brand_facet: int}
     */
    private function catalogueAnswers(string $country): array
    {
        return $this->servingStorefront($country, static fn (): array => [
            'price_floor' => getProductMinUnitPriceRange(type: 'web'),
            'brand_facet' => (int) BrandManager::getActiveBrandWithCountingAndPriorityWiseSorting()->first()->brand_products_count,
        ]);
    }

    /**
     * Serves the callback as a customer of the market does and touches the cache, so a leg can read whatever another leg warmed.
     * {@see inStorefront()} flushes, which is right for every test that compares two markets' numbers and wrong for the one that
     * compares their cache keys.
     */
    private function servingStorefront(string $country, callable $callback): mixed
    {
        return StorefrontResolver::fromConfig()->context(explicitChoice: $country)->serve($callback);
    }

    /**
     * The price filter the product listing, the shop page and the flash-deal page all include, rendered from the live theme, in the
     * two session states a visitor can be in.
     *
     * The sign beside its bounds is read straight out of the visitor's session where no storefront currency is in force, and the
     * session only holds one once a money helper has called loadCurrency(). So the field prints no sign at all on a first request
     * and the platform's sign afterwards, and a dormancy claim that holds in one state and not the other is not a dormancy claim.
     *
     * @return array{cold: string, warm: string}
     */
    private function renderedPriceFilter(): array
    {
        session()->forget(['system_default_currency_info', 'currency_code', 'currency_symbol', 'currency_exchange_rate', 'usd', 'default']);
        $cold = view('web-views.products.partials._filter-product-price')->render();

        loadCurrency();

        return ['cold' => $cold, 'warm' => view('web-views.products.partials._filter-product-price')->render()];
    }

    /**
     * The currency switcher each theme header prints — **its whole guarded region, verbatim**: the header's own
     * `@php($currency_model = getWebConfig(...))`, its own `@if($currency_model == 'multi_currency')`, everything down to the matching
     * `@endif`, with the file's own `@use` lines in front. The rest of a header needs a whole request's worth of shared view data.
     *
     * Nothing is reconstructed and no line is lifted out. An earlier version of this helper grepped the one line that mentions
     * `currency_symbol` and rendered that alone, which **dropped the two lines of guard above it** — and a review then read "SAR SAR"
     * off a Kuwaiti storefront from a string this helper had built. The page never emits it: the override that puts a storefront
     * currency in force forces `currency_model` to `single_currency`, so the `@if` is false and the region renders nothing at all.
     * Measured over all 16 cells of markets off/on x SA/KW x both currency models, on both headers.
     *
     * @return array<string, string>
     */
    private function renderedCurrencySwitchers(): array
    {
        $switchers = [];

        foreach (self::CURRENCY_SWITCHER_HEADERS as $header) {
            $lines = explode("\n", file_get_contents(base_path($header)));
            $switchers[$header] = trim(Blade::render(implode("\n", [
                ...preg_grep('/^@use\(/', $lines),
                ...$this->guardedCurrencyRegionOf($header, $lines),
            ])));
        }

        return $switchers;
    }

    /**
     * The lines of one header from its currency-model assignment down to the matching `@endif`, inclusive.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private function guardedCurrencyRegionOf(string $header, array $lines): array
    {
        $start = null;
        foreach ($lines as $index => $line) {
            if (str_contains($line, '@php($currency_model')) {
                $start = $index;

                continue;
            }

            if ($start !== null && trim($line) === '@endif') {
                $region = array_slice($lines, $start, $index - $start + 1);

                $this->assertCount(1, preg_grep('/@if\(\$currency_model/', $region), $header . ' guards its switcher on the currency model');
                $this->assertCount(1, preg_grep('/currency_symbol/', $region), $header . ' prints its currency switcher inside that guard');

                return $region;
            }
        }

        $this->fail($header . ' has no guarded currency region');
    }

    /** The partial the product page includes, rendered through the real composer for the Saudi product. */
    private function renderedSupplement(int $productId = 1): string
    {
        return view('seo.product-supplement', ['productDetails' => Product::query()->find($productId)])->render();
    }

    /**
     * The two currency models an operator can pick. The money helpers convert a stored amount under the first and show it as it is
     * stored under the second, so a dormancy claim that holds under one and not the other is not a dormancy claim.
     *
     * @return array<string, array{0: string}>
     */
    public static function currencyModels(): array
    {
        return [
            'the platform converts a stored amount' => ['multi_currency'],
            'the platform shows an amount as stored' => ['single_currency'],
        ];
    }

    /** The catalogue of an installation that serves Saudi Arabia only, which is what a dormant installation is. */
    private function keepOnlySaudiCatalogue(): void
    {
        DB::table('products')->where('storefront_country', '!=', 'SA')->delete();
        Cache::flush();
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

    /**
     * Serves a market without clearing the visitor's currency pick, which {@see inStorefront()} forgets. The one test that asks what a
     * stale pick does to a page needs the pick to still be there.
     */
    private function inStorefrontKeepingTheVisitorsPick(string $country, callable $callback): mixed
    {
        app()->forgetInstance(StorefrontCurrency::class);
        Cache::flush();

        return StorefrontResolver::fromConfig()->context(explicitChoice: $country)->serve($callback);
    }

    /** Every test that reads a storefront's own money needs a fresh currency service, because it remembers the market in force. */
    private function inStorefront(string $country, callable $callback): mixed
    {
        session()->forget(['system_default_currency_info', 'currency_code', 'currency_symbol', 'currency_exchange_rate', 'usd', 'default']);

        return $this->inStorefrontKeepingTheVisitorsPick($country, $callback);
    }
}
