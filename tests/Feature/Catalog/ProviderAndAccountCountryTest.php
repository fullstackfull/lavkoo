<?php

namespace Tests\Feature\Catalog;

use App\Enums\Fulfillment\ProviderCode;
use App\Enums\SalesChannels\SalesChannel;
use App\Models\SalesChannelAccount;
use App\Services\Fulfillment\ProviderStorefrontCountry;
use App\Services\SalesChannels\Support\ChannelAccountCountry;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\TestCase;

/**
 * B-05, the half the earlier slices left open: a provider connection and a marketplace account are narrowed by **their own** country, not by the
 * default storefront's and not by nothing at all.
 *
 * A warehouse holds the boxes of one country, and an Amazon account sells in one marketplace. Before this, every provider's items were matched
 * against the default storefront's products whichever country the connection served, and an account of a marketplace outside the Gulf was read
 * as "country unknown" and matched against every country's products.
 */
class ProviderAndAccountCountryTest extends TestCase
{
    use BuildsCodeScopeCatalog;

    private ProviderStorefrontCountry $providerCountry;

    private ChannelAccountCountry $accountCountry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCodeScopeProducts();
        $this->buildIntegrationSettingsTable();
        $this->providerCountry = app(ProviderStorefrontCountry::class);
        $this->accountCountry = app(ChannelAccountCountry::class);
    }

    protected function tearDown(): void
    {
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    // ---- the provider connection -------------------------------------------------------------------------------------------------

    public function test_nothing_is_narrowed_while_the_switch_is_off(): void
    {
        $this->connectionSettings(provider: 'm5azn', settings: ['storefront_country' => 'KW']);

        $this->assertNull($this->providerCountry->of('m5azn'));
        $this->assertFalse($this->providerCountry->servesNoCatalogueOfOurs('m5azn'));
    }

    public function test_a_connection_that_names_no_country_serves_the_default_storefronts(): void
    {
        $this->scopeCodesByCountry();

        $this->assertSame('SA', $this->providerCountry->of(ProviderCode::TorodPoint->value));
    }

    public function test_a_connection_is_narrowed_to_the_country_it_names(): void
    {
        $this->scopeCodesByCountry();
        $this->connectionSettings(provider: 'torod_point', settings: ['storefront_country' => 'KW']);

        $this->assertSame('KW', $this->providerCountry->of('torod_point'));
        $this->assertFalse($this->providerCountry->servesNoCatalogueOfOurs('torod_point'));
    }

    public function test_the_providers_own_country_setting_is_read_when_it_has_no_storefront_country_of_its_own(): void
    {
        $this->scopeCodesByCountry();
        $this->connectionSettings(provider: 'm5azn', settings: ['country_code' => 'ae']);

        $this->assertSame('AE', $this->providerCountry->of('m5azn'), 'M5AZN already names the country it sells in');
    }

    public function test_a_connection_that_names_a_country_we_have_no_storefront_for_matches_nothing(): void
    {
        $this->scopeCodesByCountry();
        $this->connectionSettings(provider: 'm5azn', settings: ['storefront_country' => 'EG']);

        $this->assertTrue($this->providerCountry->servesNoCatalogueOfOurs('m5azn'));
    }

    public function test_an_unknown_provider_falls_back_to_the_default_storefronts_country(): void
    {
        $this->scopeCodesByCountry();

        $this->assertSame('SA', $this->providerCountry->of('a_provider_that_is_not_connected'));
        $this->assertSame('SA', $this->providerCountry->of(null));
    }

    public function test_a_missing_integration_settings_table_is_the_default_storefronts_country(): void
    {
        $this->scopeCodesByCountry();
        Schema::dropIfExists('integration_settings');

        $this->assertSame('SA', $this->providerCountry->of('m5azn'));
        $this->assertFalse($this->providerCountry->servesNoCatalogueOfOurs('m5azn'));
    }

    // ---- the marketplace account -------------------------------------------------------------------------------------------------

    public function test_an_amazon_account_of_a_gulf_marketplace_sells_in_that_marketplaces_country(): void
    {
        $this->scopeCodesByCountry();

        $this->assertSame('AE', $this->accountCountry->of($this->amazonAccount(['A2VIGQ35RCS4UG'])));
        $this->assertFalse($this->accountCountry->sellsWhereWeHaveNoCatalogue($this->amazonAccount(['A2VIGQ35RCS4UG'])));
    }

    public function test_an_amazon_account_of_a_marketplace_outside_the_gulf_has_no_catalogue_of_ours(): void
    {
        $this->scopeCodesByCountry();
        foreach (['ARBP9OOSHTCHU' => 'Egypt', 'A33AVAJ2PDY3EV' => 'Turkey', 'ATVPDKIKX0DER' => 'the United States'] as $marketplace => $where) {
            $account = $this->amazonAccount([$marketplace]);

            $this->assertNull($this->accountCountry->of($account), $where);
            $this->assertTrue($this->accountCountry->sellsWhereWeHaveNoCatalogue($account), $where . ' sells where we have no storefront');
        }
    }

    public function test_an_amazon_account_of_two_countries_marketplaces_is_not_narrowed_and_is_not_cut_off(): void
    {
        $this->scopeCodesByCountry();
        $account = $this->amazonAccount(['A17E79C6D8DWNP', 'A2VIGQ35RCS4UG']);

        $this->assertNull($this->accountCountry->of($account));
        $this->assertFalse($this->accountCountry->sellsWhereWeHaveNoCatalogue($account), 'two of our own countries is an ambiguity, not a foreign market');
    }

    public function test_an_amazon_account_of_an_unknown_marketplace_is_not_narrowed_and_is_not_cut_off(): void
    {
        $this->scopeCodesByCountry();
        $account = $this->amazonAccount(['A000NOTAMARKET']);

        $this->assertNull($this->accountCountry->of($account));
        $this->assertFalse($this->accountCountry->sellsWhereWeHaveNoCatalogue($account), 'a marketplace we cannot read is not a country we can refuse');
    }

    public function test_an_amazon_account_with_no_marketplace_is_not_narrowed_and_is_not_cut_off(): void
    {
        $this->scopeCodesByCountry();
        $account = $this->amazonAccount([]);

        $this->assertNull($this->accountCountry->of($account));
        $this->assertFalse($this->accountCountry->sellsWhereWeHaveNoCatalogue($account));
    }

    public function test_a_trendyol_storefront_outside_our_countries_has_no_catalogue_of_ours(): void
    {
        $this->scopeCodesByCountry();
        $turkish = $this->channelAccount(SalesChannel::Trendyol->value, ['storefront_code' => 'TR']);
        $kuwaiti = $this->channelAccount(SalesChannel::Trendyol->value, ['storefront_code' => 'KW']);

        $this->assertTrue($this->accountCountry->sellsWhereWeHaveNoCatalogue($turkish));
        $this->assertSame('KW', $this->accountCountry->of($kuwaiti));
        $this->assertFalse($this->accountCountry->sellsWhereWeHaveNoCatalogue($kuwaiti));
    }

    public function test_an_account_whose_setting_is_empty_is_not_narrowed_and_is_not_cut_off(): void
    {
        $this->scopeCodesByCountry();
        $account = $this->channelAccount(SalesChannel::Trendyol->value, ['storefront_code' => '']);

        $this->assertNull($this->accountCountry->of($account));
        $this->assertFalse($this->accountCountry->sellsWhereWeHaveNoCatalogue($account));
    }

    public function test_no_account_is_cut_off_while_the_switch_is_off(): void
    {
        foreach ([$this->amazonAccount(['ARBP9OOSHTCHU']), $this->channelAccount(SalesChannel::Trendyol->value, ['storefront_code' => 'TR'])] as $account) {
            $this->assertFalse($this->accountCountry->sellsWhereWeHaveNoCatalogue($account), 'nothing about the country is read while the switch is off');
        }
    }

    // ---- helpers -----------------------------------------------------------------------------------------------------------------

    /** @param list<string> $marketplaces */
    private function amazonAccount(array $marketplaces): SalesChannelAccount
    {
        return $this->channelAccount(SalesChannel::Amazon->value, ['marketplace_ids' => $marketplaces]);
    }

    /** @param array<string, mixed> $settings */
    private function channelAccount(string $channel, array $settings): SalesChannelAccount
    {
        return (new SalesChannelAccount())->forceFill(['id' => 1, 'channel' => $channel, 'settings' => $settings]);
    }

    /** @param array<string, mixed> $settings */
    private function connectionSettings(string $provider, array $settings): void
    {
        DB::table('integration_settings')->insert(['provider' => $provider, 'enabled' => 1, 'settings' => json_encode($settings)]);
    }

    private function buildIntegrationSettingsTable(): void
    {
        $this->refuseToDropTablesOutsideATestDatabase();
        Schema::dropIfExists('integration_settings');
        Schema::create('integration_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->index();
            $table->boolean('enabled')->default(false);
            $table->text('settings')->nullable();
            $table->timestamps();
        });
    }
}
