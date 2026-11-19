<?php

namespace Tests\Feature\Storefront;

use App\Exceptions\StorefrontNotSellableException;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontCurrency;
use App\Services\Storefront\StorefrontResolver;
use App\Services\Storefront\StorefrontSellingGate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\TestCase;

/**
 * GCC-05: a storefront other than the default one trades in its own currency. The platform here runs the multi-currency model with the Saudi
 * riyal as its default, which is the case where an amount of another storefront would be converted by the exchange rate if nothing stopped it.
 * Each storefront must show its own amounts as they are stored, in its own symbol and decimals, and must never change what another one reads.
 */
class StorefrontCurrencyTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use BuildsStorefrontLegalDocuments;

    private const SAUDI_RIYAL_ID = '2';

    /** @var array<string, array{id: int, symbol: string, rate: float}> */
    private const CURRENCIES = [
        'USD' => ['id' => 1, 'symbol' => '$', 'rate' => 1.0],
        'SAR' => ['id' => 2, 'symbol' => 'SAR', 'rate' => 3.75],
        'AED' => ['id' => 3, 'symbol' => 'AED', 'rate' => 3.6725],
        'KWD' => ['id' => 4, 'symbol' => 'KD', 'rate' => 0.307],
        'QAR' => ['id' => 5, 'symbol' => 'QR', 'rate' => 3.64],
        'BHD' => ['id' => 6, 'symbol' => 'BD', 'rate' => 0.376],
        'OMR' => ['id' => 7, 'symbol' => 'RO', 'rate' => 0.385],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontLegalDocumentsTable();
        $this->buildMoneyTables();

        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.currency.enabled' => true,
            'gcc.storefronts.countries.AE.active' => true,
            'gcc.storefronts.countries.KW.active' => true,
            'gcc.storefronts.countries.QA.active' => true,
            'gcc.storefronts.countries.BH.active' => true,
            'gcc.storefronts.countries.OM.active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('currencies');
        Schema::dropIfExists('business_settings');

        parent::tearDown();
    }

    private function buildMoneyTables(): void
    {
        Schema::dropIfExists('currencies');
        Schema::dropIfExists('business_settings');

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('symbol')->nullable();
            $table->string('code')->nullable();
            $table->decimal('exchange_rate', 20, 8)->default(1);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        foreach (self::CURRENCIES as $code => $currency) {
            DB::table('currencies')->insert(['id' => $currency['id'], 'name' => $code, 'symbol' => $currency['symbol'], 'code' => $code, 'exchange_rate' => $currency['rate'], 'status' => 1]);
        }

        DB::table('business_settings')->insert([
            ['type' => 'currency_model', 'value' => 'multi_currency'],
            ['type' => 'system_default_currency', 'value' => self::SAUDI_RIYAL_ID],
            ['type' => 'decimal_point_settings', 'value' => '2'],
            ['type' => 'currency_symbol_position', 'value' => 'left'],
        ]);
    }

    private function inStorefront(string $country, callable $callback): mixed
    {
        return StorefrontResolver::fromConfig()->context(explicitChoice: $country)->serve($callback);
    }

    private function removeCurrency(string $code, bool $deleteRow = true): void
    {
        $deleteRow
            ? DB::table('currencies')->where('code', $code)->delete()
            : DB::table('currencies')->where('code', $code)->update(['status' => 0]);
        Cache::forget('active_currency_list');
    }

    /** @return array<string, array{0: string, 1: string, 2: int, 3: string}> */
    public static function storefrontsWithTheirOwnCurrency(): array
    {
        return [
            'United Arab Emirates' => ['AE', 'AED', 3, 'AED12.50'],
            'Kuwait' => ['KW', 'KWD', 4, 'KD12.500'],
            'Qatar' => ['QA', 'QAR', 5, 'QR12.50'],
            'Bahrain' => ['BH', 'BHD', 6, 'BD12.500'],
            'Oman' => ['OM', 'OMR', 7, 'RO12.500'],
        ];
    }

    public function test_the_service_is_one_instance_per_application(): void
    {
        $this->assertSame(app(StorefrontCurrency::class), app(StorefrontCurrency::class));
    }

    public function test_the_default_storefront_reads_the_platform_settings_while_the_switch_is_on(): void
    {
        $settings = $this->inStorefront('SA', fn (): array => [
            getWebConfig('system_default_currency'),
            getWebConfig('currency_model'),
            getWebConfig('decimal_point_settings'),
            webCurrencyConverter(1),
        ]);

        $this->assertSame([self::SAUDI_RIYAL_ID, 'multi_currency', '2', 'SAR3.75'], $settings);
    }

    public function test_every_storefront_reads_the_platform_settings_while_the_switch_is_off(): void
    {
        config(['gcc.storefronts.currency.enabled' => false]);

        $settings = $this->inStorefront('KW', fn (): array => [
            getWebConfig('system_default_currency'),
            getWebConfig('currency_model'),
            getWebConfig('decimal_point_settings'),
        ]);

        $this->assertSame([self::SAUDI_RIYAL_ID, 'multi_currency', '2'], $settings);
    }

    public function test_the_switch_does_nothing_while_storefronts_are_off(): void
    {
        config(['gcc.storefronts.enabled' => false]);

        $this->assertNull(app(StorefrontCurrency::class)->override(setting: 'currency_model'));
        $this->assertFalse(app(StorefrontCurrency::class)->isEnabled());
        $this->assertSame('multi_currency', getWebConfig('currency_model'));
    }

    #[DataProvider('storefrontsWithTheirOwnCurrency')]
    public function test_a_storefront_answers_the_three_money_settings_for_its_own_currency(string $country, string $code, int $currencyId, string $shown): void
    {
        $decimals = in_array($code, ['KWD', 'BHD', 'OMR'], true) ? '3' : '2';

        $settings = $this->inStorefront($country, fn (): array => [
            getWebConfig('system_default_currency'),
            getWebConfig('currency_model'),
            getWebConfig('decimal_point_settings'),
        ]);

        $this->assertSame([(string) $currencyId, 'single_currency', $decimals], $settings);
    }

    #[DataProvider('storefrontsWithTheirOwnCurrency')]
    public function test_a_storefront_shows_its_amounts_as_stored_in_its_own_symbol_and_decimals(string $country, string $code, int $currencyId, string $shown): void
    {
        $this->assertSame($shown, $this->inStorefront($country, fn () => webCurrencyConverter(12.5)));
    }

    public function test_no_exchange_rate_is_applied_to_the_amount_of_a_storefront(): void
    {
        // Ten dinars in the catalog of Kuwait are ten dinars on the page, not what ten riyals are worth: the platform's rates are not used.
        $this->assertSame('KD10.000', $this->inStorefront('KW', fn () => webCurrencyConverter(10)));
        $this->assertSame('SAR37.50', $this->inStorefront('SA', fn () => webCurrencyConverter(10)));
    }

    public function test_the_decimals_of_a_dinar_and_a_rial_are_three_and_round_at_the_third(): void
    {
        $this->assertSame('KD2.346', $this->inStorefront('KW', fn () => webCurrencyConverter(2.3456)));
        $this->assertSame('AED2.35', $this->inStorefront('AE', fn () => webCurrencyConverter(2.3456)));
    }

    public function test_a_session_that_moves_between_storefronts_never_keeps_the_symbol_of_the_previous_one(): void
    {
        $shown = [];

        foreach (['SA', 'KW', 'AE', 'OM', 'SA', 'BH', 'QA', 'KW'] as $country) {
            $shown[] = $this->inStorefront($country, fn () => webCurrencyConverter(1));
        }

        $this->assertSame(['SAR3.75', 'KD1.000', 'AED1.00', 'RO1.000', 'SAR3.75', 'BD1.000', 'QR1.00', 'KD1.000'], $shown);
    }

    public function test_a_storefront_leaves_the_platform_settings_and_their_cache_as_they_were(): void
    {
        $this->inStorefront('KW', fn () => [getWebConfig('system_default_currency'), getWebConfig('currency_model'), getWebConfig('decimal_point_settings')]);

        $this->assertSame(self::SAUDI_RIYAL_ID, getWebConfig('system_default_currency'));
        $this->assertSame('multi_currency', getWebConfig('currency_model'));
        $this->assertSame('2', getWebConfig('decimal_point_settings'));
        $this->assertSame('multi_currency', DB::table('business_settings')->where('type', 'currency_model')->value('value'));
        $this->assertSame(self::SAUDI_RIYAL_ID, DB::table('business_settings')->where('type', 'system_default_currency')->value('value'));
    }

    /** @return array{code: mixed, symbol: mixed, rate: mixed} the money the converters that do not reload it read out of the session */
    private function moneyKeptInTheSession(): array
    {
        return ['code' => session('currency_code'), 'symbol' => session('currency_symbol'), 'rate' => session('currency_exchange_rate')];
    }

    public function test_a_market_leaves_the_money_the_converters_keep_in_the_session_as_it_was(): void
    {
        loadCurrency();
        $platformMoney = $this->moneyKeptInTheSession();

        app(StorefrontCurrency::class)->within(country: 'KW', callback: fn (): string => setCurrencySymbol(amount: 1, currencyCode: getCurrencyCode()));

        $this->assertSame($platformMoney, $this->moneyKeptInTheSession());
        $this->assertSame('SAR', getCurrencyCode());
        $this->assertSame(1 / 3.75, localToDefaultCurrency(amount: 1), 'a converter that reads the rate out of the session without reloading it answers the platform rate');
    }

    public function test_a_market_whose_work_throws_puts_back_the_market_and_the_money_of_the_one_before_it(): void
    {
        loadCurrency();
        $platformMoney = $this->moneyKeptInTheSession();

        $this->assertThrows(
            fn (): mixed => app(StorefrontCurrency::class)->within(country: 'KW', callback: function (): never {
                setCurrencySymbol(amount: 1, currencyCode: getCurrencyCode());

                throw new RuntimeException('one row of the page failed');
            }),
            RuntimeException::class,
        );

        $this->assertSame(self::SAUDI_RIYAL_ID, getWebConfig('system_default_currency'));
        $this->assertSame($platformMoney, $this->moneyKeptInTheSession());
    }

    public function test_a_setting_that_is_not_about_money_is_never_overridden(): void
    {
        $this->assertSame('left', $this->inStorefront('KW', fn () => getWebConfig('currency_symbol_position')));
        $this->assertNull(app(StorefrontCurrency::class)->override(setting: 'currency_symbol_position'));
    }

    public function test_a_storefront_without_its_currency_keeps_the_platform_settings_and_may_not_sell(): void
    {
        $this->removeCurrency('KWD');
        $this->publishRequiredLegalDocuments(country: 'KW');

        $settings = $this->inStorefront('KW', fn (): array => [getWebConfig('system_default_currency'), getWebConfig('currency_model')]);

        $this->assertSame([self::SAUDI_RIYAL_ID, 'multi_currency'], $settings);
        $this->assertFalse(app(StorefrontCurrency::class)->isReady(country: 'KW', currencyCode: 'KWD'));
        $this->expectException(StorefrontNotSellableException::class);
        app(StorefrontSellingGate::class)->assertMaySell(StorefrontResolver::fromConfig()->context(explicitChoice: 'KW'));
    }

    public function test_a_storefront_whose_currency_is_switched_off_may_not_sell(): void
    {
        $this->removeCurrency('BHD', deleteRow: false);
        $this->publishRequiredLegalDocuments(country: 'BH');

        $this->assertFalse(app(StorefrontSellingGate::class)->maySell(country: 'BH', currencyCode: 'BHD'));
        $this->assertSame('multi_currency', $this->inStorefront('BH', fn () => getWebConfig('currency_model')));
    }

    public function test_a_storefront_with_its_currency_and_approved_texts_may_sell(): void
    {
        $this->publishRequiredLegalDocuments(country: 'KW');

        $this->assertTrue(app(StorefrontSellingGate::class)->maySell(country: 'KW', currencyCode: 'KWD'));
    }

    public function test_the_currency_alone_does_not_open_a_storefront_whose_legal_texts_are_not_approved(): void
    {
        $this->assertFalse(app(StorefrontSellingGate::class)->maySell(country: 'KW', currencyCode: 'KWD'));
    }

    public function test_the_default_storefront_may_sell_without_a_currency_of_its_own(): void
    {
        $this->removeCurrency('SAR');

        $this->assertTrue(app(StorefrontCurrency::class)->isReady(country: 'SA', currencyCode: 'SAR'));
        $this->assertTrue(app(StorefrontSellingGate::class)->maySell(country: 'SA', currencyCode: 'SAR'));
    }

    public function test_the_storefront_list_says_a_storefront_without_its_currency_cannot_sell(): void
    {
        $this->publishRequiredLegalDocuments(country: 'KW');
        $this->publishRequiredLegalDocuments(country: 'OM');
        $this->removeCurrency('OMR');

        $this->getJson('/api/v1/storefronts')
            ->assertOk()
            ->assertJsonFragment(['country' => 'KW', 'path' => 'kw', 'currency' => 'KWD', 'is_default' => false, 'sellable' => true])
            ->assertJsonFragment(['country' => 'OM', 'path' => 'om', 'currency' => 'OMR', 'is_default' => false, 'sellable' => false]);
    }

    public function test_the_decimals_of_an_unlisted_currency_are_two(): void
    {
        $this->assertSame(2, app(StorefrontCurrency::class)->decimalsOf('xyz'));
        $this->assertSame(3, app(StorefrontCurrency::class)->decimalsOf('kwd'));
    }

    public function test_a_storefront_page_that_no_middleware_bound_reads_the_currency_of_the_storefront_its_products_come_from(): void
    {
        // The catalog narrows such a request by what it asks for (path, header or query), so the currency must follow the same rule: a Kuwaiti
        // product is never shown with the Saudi currency because a route forgot the storefront middleware.
        $this->serveAsHttpRequest('/products?storefront=KW');
        $this->assertSame(['single_currency', '3'], [getWebConfig('currency_model'), getWebConfig('decimal_point_settings')]);

        $this->serveAsHttpRequest('/products', ['X-Storefront' => 'AE']);
        $this->assertSame(['single_currency', '2'], [getWebConfig('currency_model'), getWebConfig('decimal_point_settings')]);

        $this->serveAsHttpRequest('/oman/products');
        $this->assertSame('multi_currency', getWebConfig('currency_model'));

        $this->serveAsHttpRequest('/om/products');
        $this->assertSame(['single_currency', '3'], [getWebConfig('currency_model'), getWebConfig('decimal_point_settings')]);

        $this->serveAsHttpRequest('/products');
        $this->assertSame(['multi_currency', '2'], [getWebConfig('currency_model'), getWebConfig('decimal_point_settings')]);
    }

    public function test_a_panel_request_reads_the_platform_settings_whatever_storefront_it_names(): void
    {
        $this->serveAsHttpRequest('/admin/products/list?storefront=KW');
        $this->assertSame('multi_currency', getWebConfig('currency_model'));

        $this->serveAsHttpRequest('/api/v3/seller/products/list', ['X-Storefront' => 'KW']);
        $this->assertSame('multi_currency', getWebConfig('currency_model'));
    }

    public function test_a_context_that_was_never_bound_is_the_default_storefront(): void
    {
        // A queue worker or a console command has no request: it reads the platform settings until a job binds the storefront it works for.
        $this->assertFalse(app()->bound(StorefrontContext::class));
        $this->assertSame('multi_currency', getWebConfig('currency_model'));
    }

    public function test_the_readiness_command_lists_each_storefront_and_succeeds_when_those_switched_on_have_their_currency(): void
    {
        Schema::dropIfExists('shipping_types');

        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain('Storefront currencies: on')
            ->expectsOutputToContain('default storefront')
            ->expectsOutputToContain('Ready: every storefront that is switched on has the currency it trades in.')
            ->assertExitCode(0);
    }

    public function test_the_readiness_command_fails_and_names_the_missing_currency(): void
    {
        $this->removeCurrency('QAR');
        $this->removeCurrency('OMR', deleteRow: false);

        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain('missing: add the currency in the admin panel')
            ->expectsOutputToContain('inactive: switch the currency on in the admin panel')
            ->expectsOutputToContain('Not ready')
            ->assertExitCode(1);
    }

    public function test_the_readiness_command_ignores_a_storefront_that_is_not_switched_on(): void
    {
        config(['gcc.storefronts.countries.QA.active' => false]);
        Schema::dropIfExists('shipping_types');
        $this->removeCurrency('QAR');

        $this->artisan('gcc:storefront:currency-readiness')->assertExitCode(0);
    }
}
