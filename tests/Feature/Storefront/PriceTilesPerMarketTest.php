<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontCurrency;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * F-10: the shop-by-price tiles are priced in the storefront's own currency, with that currency's decimals.
 *
 * Two things were wrong, and only one of them was a formatting problem.
 *
 * **The bounds were integers.** `(int) ($band['min'] ?? 0)` -- and three of the six currencies are thousandths (KWD, BHD, OMR), so a band of
 * half a dinar truncated to zero: the tile read "0+" and its link filtered the listing at nothing. The label and the query now use the same
 * typed value, and a whole number is unchanged, because 100.0 builds the same URL and formats the same way 100 did.
 *
 * **The currency and the decimals** come from `webCurrencyConverter`, which reads the settings in force -- and a storefront that trades in
 * its own currency answers its own for exactly those settings (`StorefrontCurrency`), so the tile follows without a change of its own. That
 * is what is asserted here, because "it follows by construction" is a hypothesis until a test renders it.
 *
 * **What is NOT fixed, and is an owner decision rather than a coding one:** a band is two plain numbers with no currency on them
 * (`SectionRegistry`'s `price_band` schema), so one band typed once means a different real amount in every market -- 100 shown as 100 dinars
 * on one storefront and as 375 riyals on another, from the same stored number. That is the same missing minor-unit/currency field recorded
 * against fixed-amount deals (B-16) and the account-deletion threshold, and it is not solvable inside a theme section.
 */
class PriceTilesPerMarketTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        View::getFinder()->prependLocation(base_path('tests/Support/views'));
        $this->buildStorefrontCatalogTables();
        $this->buildTheCurrencies();
        $this->registerTheListingRoute();
        StorefrontCatalog::flush();
    }

    protected function tearDown(): void
    {
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    /** The first test of the switch: with storefront currencies off a tile reads exactly as it does today. */
    public function test_while_storefront_currencies_are_off_a_tile_is_priced_as_it_always_was(): void
    {
        $html = $this->renderTiles(band: ['min' => 100, 'max' => 200]);

        $this->assertStringContainsString('375.00SAR - 750.00SAR', $html, 'the platform conversion, with the platform decimals');
    }

    public function test_a_kuwaiti_tile_is_priced_in_dinars_with_three_decimals(): void
    {
        $this->switchStorefrontCurrenciesOn();
        $this->serveMarket('KW');

        $html = $this->renderTiles(band: ['min' => 100, 'max' => 200]);

        $this->assertStringContainsString('100.000KD - 200.000KD', $html, 'the dinar, as stored, with its three decimals');
        $this->assertStringNotContainsString('SAR', $html);
    }

    /** The bound that used to be truncated: half a dinar is half a dinar, in the label and in the link. */
    public function test_a_band_of_less_than_one_unit_keeps_its_decimals(): void
    {
        $this->switchStorefrontCurrenciesOn();
        $this->serveMarket('KW');

        $html = $this->renderTiles(band: ['min' => 0.5, 'max' => 2.75]);

        $this->assertStringContainsString('0.500KD - 2.750KD', $html, 'and not "0.000KD - 2.000KD"');
        $this->assertStringContainsString('min_price=0.5', $html, 'the link filters at what the tile says');
        $this->assertStringContainsString('max_price=2.75', $html);
    }

    /** A whole-number band is byte for byte what it was: the same label and the same link. */
    public function test_a_whole_number_band_builds_the_link_it_always_did(): void
    {
        $html = $this->renderTiles(band: ['min' => 100, 'max' => 200]);

        $this->assertStringContainsString('min_price=100', $html);
        $this->assertStringContainsString('max_price=200', $html);
        $this->assertStringNotContainsString('min_price=100.0', $html);
    }

    /** A band with no ceiling reads as "from", in the storefront's own money. */
    public function test_a_band_with_no_ceiling_reads_as_from(): void
    {
        $this->switchStorefrontCurrenciesOn();
        $this->serveMarket('KW');

        $html = $this->renderTiles(band: ['min' => 1.25, 'max' => 0]);

        $this->assertStringContainsString('1.250KD+', $html);
        $this->assertStringNotContainsString('max_price', $html);
    }

    /** A label an operator typed is theirs, whatever the currency: the range is only built when they left it empty. */
    public function test_a_label_an_operator_typed_is_shown_as_typed(): void
    {
        $this->switchStorefrontCurrenciesOn();
        $this->serveMarket('KW');

        $html = $this->renderTiles(band: ['min' => 0.5, 'max' => 2, 'label' => 'Under three dinars']);

        $this->assertStringContainsString('Under three dinars', $html);
    }

    private function renderTiles(array $band): string
    {
        return view('theme-sections.types.price_tiles', [
            's' => ['title' => 'Shop by price', 'eyebrow' => '', 'columns' => 4],
            'blocks' => [['type' => 'price_band', 'settings' => $band]],
            '__section' => ['blocks' => [['type' => 'price_band', 'settings' => $band]]],
        ])->render();
    }

    private function switchStorefrontCurrenciesOn(): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.currency.enabled' => true,
            'gcc.storefronts.countries.KW.active' => true,
        ]);
        StorefrontCatalog::flush();
        app()->forgetInstance(StorefrontCurrency::class);
    }

    private function serveMarket(string $country): void
    {
        app()->instance(StorefrontContext::class, StorefrontResolver::fromConfig()->context(explicitChoice: $country));
        session()->forget(['currency_code', 'currency_symbol', 'currency_model', 'decimal_point_settings', 'usd_to_default_currency']);
    }

    /** The riyal as the platform's base at 3.75, the dinar as a market's own currency: one band, two ways of printing it. */
    private function buildTheCurrencies(): void
    {
        Schema::dropIfExists('currencies');
        Schema::create('currencies', static function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('symbol');
            $table->string('code');
            $table->double('exchange_rate');
            $table->boolean('status')->default(true);
        });
        DB::table('currencies')->insert([
            ['id' => 1, 'name' => 'Saudi riyal', 'symbol' => 'SAR', 'code' => 'SAR', 'exchange_rate' => 3.75],
            ['id' => 2, 'name' => 'Kuwaiti dinar', 'symbol' => 'KD', 'code' => 'KWD', 'exchange_rate' => 0.307],
        ]);
        foreach ([
            'system_default_currency' => 1,
            'currency_model' => 'multi_currency',
            'decimal_point_settings' => 2,
        ] as $type => $value) {
            DB::table('business_settings')->updateOrInsert(['type' => $type], ['value' => json_encode($value)]);
        }
    }

    /** The listing the tiles link to. Its real route lives in the web group, which this test does not load. */
    private function registerTheListingRoute(): void
    {
        if (!Route::has('products')) {
            Route::get('products', static fn (): string => 'ok')->name('products');
        }
    }
}
