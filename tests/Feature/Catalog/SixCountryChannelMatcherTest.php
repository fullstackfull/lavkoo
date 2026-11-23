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
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\Support\BuildsCountryMatrix;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

/**
 * D1 across the six countries: the channel product matcher proposes a listing the products of the country its account sells in. For every ordered
 * pair of countries the same SKU and barcode exist in both and the matcher, told one of them, proposes that one only. An account whose country is
 * known (a Trendyol storefront, a noon country, a Saudi or UAE Amazon marketplace) is narrowed to it; an account whose country is not known is not,
 * and a code two countries share stays a conflict. With the switch off no account is narrowed.
 */
class SixCountryChannelMatcherTest extends TestCase
{
    use BuildsCodeScopeCatalog, BuildsCountryMatrix, BuildsSalesChannelSchema;

    private ListingSyncService $listings;

    /** @var array<string, int> the product of every country that holds the code all six share */
    private array $shared = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSalesChannelSchema();
        $this->addStorefrontCountryColumn();
        $this->listings = app(ListingSyncService::class);
        $this->insertHoldersOfEveryPair(['added_by' => 'admin', 'product_type' => 'physical', 'variation' => '[]']);
        foreach ($this->matrixCountries() as $country) {
            $this->shared[$country] = (int) DB::table('products')->insertGetId([
                'name' => "Shared box {$country}", 'code' => 'SHARED-CODE', 'barcode' => '6290000000000', 'added_by' => 'admin', 'product_type' => 'physical', 'variation' => '[]', 'storefront_country' => $country,
            ]);
        }
    }

    protected function tearDown(): void
    {
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    /** @param list<array{product_id: int, variant_key: string, reason: string}> $candidates @return list<int> */
    private function idsOf(array $candidates): array
    {
        return array_map(static fn (array $candidate): int => $candidate['product_id'], $candidates);
    }

    /** @param array<string, mixed> $settings */
    private function account(string $channel, array $settings, ?array $marketplaceIds = null): SalesChannelAccount
    {
        return SalesChannelAccount::create(['channel' => $channel, 'name' => ucfirst($channel) . ' ' . count(SalesChannelAccount::all()), 'enabled' => true, 'settings' => $settings] + ($marketplaceIds === null ? [] : ['marketplace_ids' => $marketplaceIds]));
    }

    private function sync(SalesChannelAccount $account): SalesChannelProductMapping
    {
        $this->listings->sync($account, [new RemoteListing('L' . $account->id, 'SHARED-CODE', 'c', '6290000000000', null, 'A listing', null, 'onSale', Money::of('99'), 'SAR', 5, CarbonImmutable::parse('2026-11-10'), [])]);

        return SalesChannelProductMapping::where('account_id', $account->id)->firstOrFail();
    }

    // ---- the matcher, told a country --------------------------------------------------------------------------------------------

    public function test_with_the_switch_on_the_matcher_proposes_the_product_of_the_country_it_is_told_and_no_other(): void
    {
        $this->scopeCodesByCountry();
        $matcher = app(ProductMatcher::class);
        $failures = [];
        foreach ($this->orderedPairs() as [$from, $to]) {
            $code = $this->codeOfPair($from, $to);
            $barcode = $this->barcodeOfPair($from, $to);
            $inFrom = (int) DB::table('products')->where('code', $code)->where('storefront_country', $from)->value('id');
            $inTo = DB::table('products')->insertGetId(['name' => "Copy in {$to}", 'code' => $code, 'barcode' => $barcode, 'added_by' => 'admin', 'product_type' => 'physical', 'variation' => '[]', 'storefront_country' => $to]);

            $this->matrixCase($failures, true, $this->idsOf($matcher->candidates($code, $barcode, $from)) === [$inFrom], "the code of {$from}/{$to}, told {$from}: only the product of {$from}");
            $this->matrixCase($failures, true, $this->idsOf($matcher->candidates($code, $barcode, $to)) === [(int) $inTo], "the code of {$from}/{$to}, told {$to}: only the product of {$to}");
            $this->matrixCase($failures, true, $this->idsOf($matcher->candidates($code, null, null)) === [$inFrom, (int) $inTo], "the code of {$from}/{$to}, told no country: both, an ambiguity");
            $this->matrixCase($failures, true, $this->idsOf($matcher->candidates(null, $barcode, $to)) === [(int) $inTo], "the barcode of {$from}/{$to}, told {$to}: only the product of {$to}");
            foreach (array_diff($this->matrixCountries(), [$from, $to]) as $other) {
                $this->matrixCase($failures, true, $matcher->candidates($code, $barcode, $other) === [], "the code of {$from}/{$to}, told {$other}, which holds none: nothing");
            }
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_with_the_switch_off_the_matcher_ignores_the_country_it_is_told(): void
    {
        $matcher = app(ProductMatcher::class);
        $failures = [];
        foreach ($this->orderedPairs() as [$from, $to]) {
            $this->matrixCase($failures, true, count($matcher->candidates($this->codeOfPair($from, $to), null, $to)) === 1, "the code of {$from}/{$to}, told {$to}: the one product that holds it, wherever it is");
        }
        $this->matrixCase($failures, true, count($matcher->candidates('SHARED-CODE', null, 'KW')) === count($this->matrixCountries()), 'the code all countries hold, told KW: every country');

        $this->assertMatrixHolds($failures);
    }

    // ---- the account of a channel -----------------------------------------------------------------------------------------------

    public function test_with_the_switch_on_a_listing_of_an_account_of_a_known_country_is_proposed_that_countrys_product(): void
    {
        $this->scopeCodesByCountry();
        $accounts = [];
        foreach ($this->matrixCountries() as $country) {
            $accounts["a noon account of {$country}"] = [$country, $this->account('noon', ['country_code' => strtolower($country)])];
        }
        foreach (['SA', 'AE', 'KW'] as $country) {
            $accounts["a Trendyol account of {$country}"] = [$country, $this->account('trendyol', ['storefront_code' => $country])];
        }
        $accounts['an Amazon account of the Saudi marketplace'] = ['SA', $this->account('amazon', ['region' => 'eu', 'marketplace_ids' => ['A17E79C6D8DWNP']], ['A17E79C6D8DWNP'])];
        $accounts['an Amazon account of the UAE marketplace'] = ['AE', $this->account('amazon', ['region' => 'eu', 'marketplace_ids' => ['A2VIGQ35RCS4UG']], ['A2VIGQ35RCS4UG'])];
        $failures = [];
        foreach ($accounts as $name => [$country, $account]) {
            $this->matrixCase($failures, true, app(ChannelAccountCountry::class)->of($account) === $country, "{$name}: its country is {$country}");
            $row = $this->sync($account);
            $this->matrixCase($failures, true, $row->status === SalesChannelProductMapping::SUGGESTED && $row->suggested_product_id === $this->shared[$country], "{$name}: the listing is proposed the product of {$country}, not a conflict among six");
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_with_the_switch_on_a_listing_of_an_account_of_no_known_country_is_a_conflict_among_the_six(): void
    {
        $this->scopeCodesByCountry();
        $accounts = [
            'a Trendyol account whose storefront is not set' => $this->account('trendyol', ['storefront_code' => '']),
            'an Amazon account of the Saudi and the UAE marketplaces' => $this->account('amazon', ['region' => 'eu', 'marketplace_ids' => ['A17E79C6D8DWNP', 'A2VIGQ35RCS4UG']], ['A17E79C6D8DWNP', 'A2VIGQ35RCS4UG']),
            'an Amazon account of a marketplace the list does not know' => $this->account('amazon', ['region' => 'eu', 'marketplace_ids' => ['A000NOTAMARKET']], ['A000NOTAMARKET']),
        ];
        $failures = [];
        foreach ($accounts as $name => $account) {
            $this->matrixCase($failures, true, app(ChannelAccountCountry::class)->of($account) === null, "{$name}: no country");
            $row = $this->sync($account);
            $this->matrixCase($failures, true, $row->status === SalesChannelProductMapping::CONFLICT && $row->product_id === null && $row->suggested_product_id === null, "{$name}: a conflict, nothing linked on a guess");
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_with_the_switch_on_an_account_of_a_country_outside_the_six_is_proposed_none_of_them(): void
    {
        $this->scopeCodesByCountry();
        $accounts = [
            'a noon account of Egypt' => $this->account('noon', ['country_code' => 'eg']),
            'an Amazon account of the Egyptian marketplace' => $this->account('amazon', ['region' => 'eu', 'marketplace_ids' => ['ARBP9OOSHTCHU']], ['ARBP9OOSHTCHU']),
            'a Trendyol account of the Turkish storefront' => $this->account('trendyol', ['storefront_code' => 'TR']),
        ];
        $failures = [];
        foreach ($accounts as $name => $account) {
            $row = $this->sync($account);
            $this->matrixCase($failures, true, $row->status === SalesChannelProductMapping::UNMAPPED && $row->product_id === null && $row->suggested_product_id === null, "{$name}: none of the six is proposed");
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_with_the_switch_off_no_account_is_narrowed(): void
    {
        $accounts = [
            'a noon account of KW' => $this->account('noon', ['country_code' => 'kw']),
            'a Trendyol account of SA' => $this->account('trendyol', ['storefront_code' => 'SA']),
            'an Amazon account of the Saudi marketplace' => $this->account('amazon', ['region' => 'eu', 'marketplace_ids' => ['A17E79C6D8DWNP']], ['A17E79C6D8DWNP']),
        ];
        $failures = [];
        foreach ($accounts as $name => $account) {
            $row = $this->sync($account);
            $this->matrixCase($failures, true, $row->status === SalesChannelProductMapping::CONFLICT, "{$name}: a conflict among the six, as today");
        }

        $this->assertMatrixHolds($failures);
    }
}
