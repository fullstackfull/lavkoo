<?php

namespace Tests\Feature\Catalog;

use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Data\RemoteListing;
use App\Services\SalesChannels\Support\ChannelAccountCountry;
use App\Services\SalesChannels\Sync\ListingSyncService;
use App\Services\SalesChannels\Sync\ProductMatcher;
use App\Services\Storefront\StorefrontCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

/**
 * D1: a channel listing is proposed the products whose SKU or barcode it carries. With the country-scoped codes on, an account that sells in a
 * known country is proposed only that country's products, so the Saudi and the Kuwaiti copy of one box are no longer two candidates. An account
 * whose country is not known is not narrowed: a code shared by two countries stays a conflict and nothing is linked on a guess.
 */
class ChannelProductMatcherCountryTest extends TestCase
{
    use BuildsCodeScopeCatalog, BuildsSalesChannelSchema;

    private ListingSyncService $listings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSalesChannelSchema();
        $this->addStorefrontCountryColumn();
        $this->listings = app(ListingSyncService::class);
        $this->saudi = $this->product(1, 'SA');
        $this->kuwaiti = $this->product(2, 'KW');
    }

    protected function tearDown(): void
    {
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    private int $saudi;

    private int $kuwaiti;

    /** One box on the shelf of two countries: the same SKU and the same EAN. */
    private function product(int $id, string $country): int
    {
        DB::table('products')->insert([
            'id' => $id, 'name' => 'Product ' . $id, 'code' => 'SKU-100', 'barcode' => '6291234567890', 'added_by' => 'admin', 'product_type' => 'physical',
            'variation' => '[]', 'storefront_country' => $country,
        ]);

        return $id;
    }

    /** @param array<string, mixed> $settings */
    private function account(string $channel, array $settings): SalesChannelAccount
    {
        return SalesChannelAccount::create(['channel' => $channel, 'name' => ucfirst($channel) . ' account', 'enabled' => true, 'settings' => $settings]);
    }

    private function listing(string $key = '999'): RemoteListing
    {
        return new RemoteListing($key, 'SKU-100', 'content-1', '6291234567890', null, 'A listing', null, 'onSale', Money::of('99'), 'SAR', 5, CarbonImmutable::parse('2026-11-10'), ['title' => 'A listing']);
    }

    private function row(): SalesChannelProductMapping
    {
        return SalesChannelProductMapping::firstOrFail();
    }

    // ---- the switch off: today's answer ----------------------------------------------------------------------------------------

    public function test_with_the_switch_off_a_code_two_countries_share_is_a_conflict_whatever_the_account(): void
    {
        $this->listings->sync($this->account('trendyol', ['storefront_code' => 'KW']), [$this->listing()]);

        $this->assertSame(SalesChannelProductMapping::CONFLICT, $this->row()->status);
        $this->assertSame('ambiguous', $this->row()->match_method);
        $this->assertNull($this->row()->suggested_product_id);
    }

    public function test_with_the_switch_off_the_matcher_ignores_a_country(): void
    {
        $this->assertCount(2, app(ProductMatcher::class)->candidates('SKU-100', null, 'KW'));
    }

    // ---- the switch on ---------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: array<string, string>, 2: int}> */
    public static function accountsOfKnownCountry(): array
    {
        return [
            'a Trendyol Kuwaiti storefront' => ['trendyol', ['storefront_code' => 'KW'], 2],
            'a Trendyol Saudi storefront' => ['trendyol', ['storefront_code' => 'SA'], 1],
            'a Trendyol storefront in the wrong letter case' => ['trendyol', ['storefront_code' => 'kw'], 2],
            'a noon account of Saudi Arabia' => ['noon', ['country_code' => 'sa'], 1],
        ];
    }

    #[DataProvider('accountsOfKnownCountry')]
    public function test_with_the_switch_on_an_account_of_a_known_country_is_proposed_that_countrys_product_only(string $channel, array $settings, int $expected): void
    {
        $this->scopeCodesByCountry();

        $this->listings->sync($this->account($channel, $settings), [$this->listing()]);

        $this->assertSame(SalesChannelProductMapping::SUGGESTED, $this->row()->status);
        $this->assertSame($expected, $this->row()->suggested_product_id);
        $this->assertSame('product_code', $this->row()->match_method);
    }

    public function test_with_the_switch_on_an_account_that_links_exact_identifiers_links_the_product_of_its_country(): void
    {
        $this->scopeCodesByCountry();

        $stats = $this->listings->sync($this->account('trendyol', ['storefront_code' => 'KW', 'auto_link_exact_barcode' => true]), [$this->listing()]);

        $this->assertSame(1, $stats['linked']);
        $this->assertSame($this->kuwaiti, $this->row()->product_id);
        $this->assertSame(SalesChannelProductMapping::MAPPED, $this->row()->status);
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function accountsOfUnknownCountry(): array
    {
        return [
            'an Amazon account: a region and marketplace ids name no country' => ['amazon', ['region' => 'eu', 'marketplace_ids' => ['A17E79C6D8DWNP']]],
            'the Turkish Trendyol marketplace' => ['trendyol', ['storefront_code' => '']],
            'a noon account of a country that has no storefront here' => ['noon', ['country_code' => 'eg']],
            'a Trendyol storefront that is not configured' => ['trendyol', ['storefront_code' => 'XX']],
        ];
    }

    #[DataProvider('accountsOfUnknownCountry')]
    public function test_with_the_switch_on_an_account_of_no_known_country_keeps_a_shared_code_a_conflict(string $channel, array $settings): void
    {
        $this->scopeCodesByCountry();

        $this->listings->sync($this->account($channel, $settings), [$this->listing()]);

        $this->assertSame(SalesChannelProductMapping::CONFLICT, $this->row()->status);
        $this->assertNull($this->row()->product_id);
    }

    public function test_with_the_switch_on_an_account_is_not_proposed_a_country_that_has_no_such_product(): void
    {
        $this->scopeCodesByCountry();

        $this->listings->sync($this->account('trendyol', ['storefront_code' => 'AE']), [$this->listing()]);

        $this->assertSame(SalesChannelProductMapping::UNMAPPED, $this->row()->status);
        $this->assertNull($this->row()->suggested_product_id);
    }

    public function test_a_missing_column_acts_as_off(): void
    {
        $this->scopeCodesByCountry();
        $this->dropStorefrontCountryColumn();

        $this->listings->sync($this->account('trendyol', ['storefront_code' => 'KW']), [$this->listing()]);

        $this->assertSame(SalesChannelProductMapping::CONFLICT, $this->row()->status);
    }

    public function test_the_variant_sku_of_a_product_is_narrowed_to_the_country_too(): void
    {
        $this->scopeCodesByCountry();
        DB::table('products')->whereIn('id', [1, 2])->update(['code' => 'OTHER', 'barcode' => null, 'variation' => json_encode([['type' => 'Red', 'sku' => 'VAR-1', 'qty' => 5, 'price' => 5]])]);

        $candidates = app(ProductMatcher::class)->candidates('VAR-1', null, 'KW');

        $this->assertSame([[ 'product_id' => 2, 'variant_key' => 'Red', 'reason' => 'variant_sku']], $candidates);
    }

    // ---- the country of an account ---------------------------------------------------------------------------------------------

    public function test_the_country_of_an_account_is_read_from_the_setting_its_channel_keeps_it_in(): void
    {
        $country = app(ChannelAccountCountry::class);

        $this->assertSame('KW', $country->of($this->account('trendyol', ['storefront_code' => 'KW'])));
        $this->assertSame('SA', $country->of($this->account('noon', ['country_code' => 'sa'])));
        $this->assertNull($country->of($this->account('amazon', ['region' => 'eu'])));
        $this->assertNull($country->of($this->account('trendyol', [])));
    }
}
