<?php

namespace Tests\Feature\SalesChannels;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Support\ChannelAccountMarketStop;
use App\Services\SalesChannels\Support\ChannelHttp;
use App\Services\SalesChannels\Support\ChannelOperations;
use App\Services\SalesChannels\Support\ChannelSettings;
use App\Services\Storefront\StorefrontLifecycle;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

/**
 * Stopping a market stops its marketplace accounts (H2-12), and a noon account's country is one noon serves (H2-07).
 *
 * The locks a channel call passes were global (`SALES_CHANNELS_ENABLED`) and per account (the account's own five switches). Neither is
 * what an operator reaches for when ONE market has to stop: a country could be paused -- or never opened for sale at all -- and its
 * accounts went on publishing prices, pushing stock and confirming shipments on Amazon, Trendyol and noon. A pause that leaves three
 * marketplaces selling is not a pause.
 *
 * The market of an account is read from its own vendor settings (`ChannelAccountCountry`), and whether that market may sell is the SAME
 * answer the website's checkout asks (`StorefrontSellingGate`), so a market cannot be shut for a customer and open for a marketplace.
 *
 * An account whose market cannot be determined is NOT stopped, which is the opposite of the usual direction here and is deliberate: a
 * market nobody has identified has not been paused either, and stopping such an account would take every Turkish Trendyol account and
 * every unlabelled account off the air the moment the first country is paused -- a platform-wide outage caused by a per-market action.
 *
 * Every URL here is a made-up .example host: no test can reach a real channel (stray requests are refused).
 */
class ChannelMarketStopTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private const READ = 'trendyol.orders_list';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();

        config([
            'sales_channels.enabled' => true,
            'sales_channels.external_writes_enabled' => true,
            'gcc.storefronts.default_country' => 'SA',
            // Set here and never in `config/gcc.php`: which markets are active is the owner's G0 decision and never a test's.
            'gcc.storefronts.countries' => [
                'SA' => ['path' => 'sa', 'currency' => 'SAR', 'active' => true],
                'KW' => ['path' => 'kw', 'currency' => 'KWD', 'active' => true],
            ],
        ]);

        if (!\Illuminate\Support\Facades\Schema::hasTable('audit_logs')) {
            (require database_path('migrations/2026_12_29_100000_create_audit_logs_and_name_the_market_each_line_concerns.php'))->up();
            \App\Services\AuditLogger::forget();
        }
    }

    private function account(string $storefrontCode = 'SA'): SalesChannelAccount
    {
        return SalesChannelAccount::create([
            'channel' => 'trendyol', 'name' => 'Test', 'enabled' => true, 'write_enabled' => true, 'dry_run' => false,
            'settings' => ['storefront_code' => $storefrontCode],
            'capabilities' => ['connection_test', 'read_only_sync', 'order_import'],
        ]);
    }

    private function read(SalesChannelAccount $account): void
    {
        app(ChannelHttp::class)->send($account, self::READ, 'GET', 'https://channel.example/orders', json: null, query: ['page' => 0]);
    }

    private function pause(string $market): void
    {
        app(StorefrontLifecycle::class)->pause(
            country: $market,
            mode: StorefrontLifecycle::BROWSE_ONLY,
            by: 'The head of the operations team',
            reason: 'Decision of the operations meeting',
        );
        $this->beforeApplicationDestroyed(static fn () => DB::table('business_settings')->where('type', StorefrontLifecycle::settingName(country: $market))->delete());
    }

    public function test_with_storefronts_off_no_account_is_ever_stopped_by_a_market(): void
    {
        config(['gcc.storefronts.enabled' => false]);
        $this->pause('SA');
        $account = $this->account();

        $this->assertNull(app(ChannelAccountMarketStop::class)->reasonFor($account));
        $this->assertFalse(app(ChannelAccountMarketStop::class)->isStopped($account));
    }

    public function test_pausing_a_market_stops_the_calls_of_its_channel_accounts(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $this->pause('SA');
        $account = $this->account('SA');

        Http::fake(['*' => Http::response(['content' => []], 200)]);

        try {
            $this->read($account);
            $this->fail('A paused market must make no channel calls.');
        } catch (ChannelUnavailableException $refused) {
            $this->assertSame(ChannelUnavailableException::MARKET_STOPPED, $refused->reason);
            $this->assertStringContainsString('SA', $refused->getMessage());
        }

        Http::assertNothingSent();
    }

    /** The twin: the same account, the same call, with the market selling. */
    public function test_the_same_account_calls_out_while_its_market_is_selling(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $account = $this->account('SA');
        Http::fake(['*' => Http::response(['content' => []], 200)]);

        $this->read($account);

        Http::assertSentCount(1);
    }

    /**
     * An account of a market that may not sell for ANY reason is stopped, not only a paused one.
     *
     * Kuwait here has not been opened for sale at all, which is the state every market but the default one is in today. It is the same
     * question -- may this market sell -- asked of the same service the checkout asks.
     */
    public function test_an_account_of_a_market_that_has_not_been_opened_makes_no_calls(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $account = $this->account('KW');

        $this->assertTrue(app(ChannelAccountMarketStop::class)->isStopped($account));
        $this->assertStringContainsString('KW', (string) app(ChannelAccountMarketStop::class)->reasonFor($account));
    }

    /** An account whose settings name no market the platform has a storefront for is NOT stopped by a per-market action. */
    public function test_an_account_of_no_known_market_is_not_stopped(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $this->pause('SA');
        // An empty Trendyol storefront code is the Turkish marketplace, which is not a market of this platform.
        $account = $this->account('');

        $this->assertNull(app(ChannelAccountMarketStop::class)->marketOf($account));
        $this->assertNull(app(ChannelAccountMarketStop::class)->reasonFor($account));
    }

    // ---- H2-07: the markets each channel serves, as data ---------------------------------------------------------

    public function test_the_markets_each_channel_serves_are_read_from_the_configuration(): void
    {
        $this->assertSame(['SA', 'AE', 'KW'], ChannelSettings::marketsOf('trendyol'));
        $this->assertSame(['SA', 'AE', 'EG'], ChannelSettings::marketsOf('noon'));
        $this->assertSame(['SA', 'AE'], ChannelSettings::marketsOf('amazon'));

        config(['sales_channels.markets.trendyol.serves' => ['SA', 'AE', 'KW', 'QA']]);

        $this->assertSame(['SA', 'AE', 'KW', 'QA'], ChannelSettings::marketsOf('trendyol'), 'opening a market is a list to edit, not a constant to change');
    }

    /** And nobody has checked any of those lists against a vendor document, which the data says rather than implies. */
    public function test_no_channels_market_list_claims_to_have_been_verified(): void
    {
        foreach (['trendyol', 'noon', 'amazon'] as $channel) {
            $this->assertFalse(ChannelSettings::marketsAreVerified($channel), "{$channel}'s market list must not claim a verification nobody did");
            $this->assertNotSame('', (string) config('sales_channels.markets.' . $channel . '.source'), 'and it says where it came from');
        }
    }

    public function test_a_noon_account_may_not_name_a_country_noon_does_not_serve(): void
    {
        $this->expectExceptionMessage('The noon country must be one of: sa, ae, eg.');

        ChannelSettings::validate('noon', ['country_code' => 'kw']);
    }

    public function test_a_noon_account_keeps_the_country_it_has_always_defaulted_to(): void
    {
        $this->assertSame('sa', ChannelSettings::validate('noon', [])['country_code']);
        $this->assertSame('ae', ChannelSettings::validate('noon', ['country_code' => ' AE '])['country_code'], 'noon writes a country in lower case');
    }

    public function test_an_empty_noon_country_is_refused_rather_than_stored(): void
    {
        $this->expectExceptionMessage('The noon country must be one of');

        ChannelSettings::validate('noon', ['country_code' => '']);
    }
}
