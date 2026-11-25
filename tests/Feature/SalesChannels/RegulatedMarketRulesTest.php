<?php

namespace Tests\Feature\SalesChannels;

use App\Models\ChannelListingDraft;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Listings\ListingDraftService;
use App\Services\SalesChannels\Listings\RegulatedProductGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\Support\CreatesChannelRecords;
use Tests\TestCase;

/**
 * What a regulator closes is a country's decision, not a marketplace's (H2-09).
 *
 * The pharmacy gate held the owner's rules globally and per channel, because what Amazon closes and what Trendyol closes are
 * different lists. It had no country dimension at all -- so the Saudi FDA's list would have been applied to a listing on noon in
 * the Emirates, and an Emirati rule could not be written down anywhere.
 *
 * The market of a listing is the country the ACCOUNT sells in, read by `ChannelAccountCountry` from the account's own settings,
 * because a regulator cares where a thing is sold. An account that names no country -- every account of this installation today
 * -- is judged by the global and per-channel rules exactly as before, and the gate still fails closed for it.
 *
 * It ships with NO market entries: which products are regulated in each of the six markets, and what clearance a listing needs
 * there, is a legal question that nothing in this repository decides. This is the seam those answers are written into, and the
 * first case here is that with the seam empty nothing has moved.
 */
class RegulatedMarketRulesTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;
    use CreatesChannelRecords;

    /** The product both markets' accounts list, named so that a `Vitamin` rule names it. */
    private const PRODUCT_NAME = 'Vitamin C 500mg';

    private SalesChannelAccount $saudi;

    private SalesChannelAccount $kuwaiti;

    private int $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        config(['sales_channels.enabled' => true]);

        $this->saudi = $this->accountOfTheMarket('sa', 'partner-sa');
        $this->kuwaiti = $this->accountOfTheMarket('kw', 'partner-kw');
        $this->product = $this->product(self::PRODUCT_NAME, 'VIT-500');
        DB::table('products')->where('id', $this->product)->update(['category_ids' => json_encode([['id' => '7', 'position' => 1]])]);
    }

    public function test_with_no_market_entries_at_all_nothing_has_moved(): void
    {
        $this->globalRules(mode: 'strict', rules: ['name_patterns' => ['Vitamin']]);

        foreach ([$this->saudi, $this->kuwaiti] as $account) {
            $this->assertSame(
                RegulatedProductGate::NEEDS_CLEARANCE,
                $this->refusalFor($account),
                'the global rule reaches every market, as it did before markets existed',
            );
        }
    }

    public function test_a_market_can_regulate_what_the_platform_does_not(): void
    {
        $this->globalRules(mode: 'off');
        $this->marketRules('KW', ['mode' => 'strict', 'name_patterns' => ['Vitamin']]);

        $this->assertSame(RegulatedProductGate::NEEDS_CLEARANCE, $this->refusalFor($this->kuwaiti), 'the Kuwaiti account is held to Kuwait\'s rule');
        $this->assertNull($this->refusalFor($this->saudi), 'and the Saudi account is not: the unrestricted twin, in the same process, on the same product');
    }

    public function test_a_market_rule_is_added_to_the_global_one_and_never_replaces_it(): void
    {
        $this->globalRules(mode: 'strict', rules: ['brand_ids' => ['77']]);
        $this->marketRules('KW', ['name_patterns' => ['Vitamin']]);
        DB::table('products')->where('id', $this->product)->update(['brand_id' => 77]);

        $this->assertSame(RegulatedProductGate::NEEDS_CLEARANCE, $this->refusalFor($this->saudi), 'the global brand rule still names it in Saudi Arabia');
        $this->assertSame(RegulatedProductGate::NEEDS_CLEARANCE, $this->refusalFor($this->kuwaiti), 'and in Kuwait, where the market adds a name rule beside it');
    }

    public function test_a_markets_mode_outranks_a_channels_because_a_regulator_outranks_a_marketplace(): void
    {
        $this->globalRules(mode: 'strict', rules: ['name_patterns' => ['Vitamin'], 'channels' => ['noon' => ['mode' => 'off']]]);

        $this->assertNull($this->refusalFor($this->kuwaiti), 'the channel has switched itself off');

        $this->marketRules('KW', ['mode' => 'strict']);

        $this->assertSame(
            RegulatedProductGate::NEEDS_CLEARANCE,
            $this->refusalFor($this->kuwaiti),
            'and the market switches it back on, because a marketplace policy cannot open what a country closed',
        );
        $this->assertNull($this->refusalFor($this->saudi), 'while the market that said nothing keeps the channel\'s own answer');
    }

    public function test_a_market_may_switch_one_channel_off_without_touching_the_others(): void
    {
        $this->globalRules(mode: 'strict', rules: ['name_patterns' => ['Vitamin']]);
        $this->marketRules('KW', ['channels' => ['noon' => ['mode' => 'off']]]);

        $this->assertNull($this->refusalFor($this->kuwaiti), 'the most specific setting is the (market, channel) one');
        $this->assertSame(RegulatedProductGate::NEEDS_CLEARANCE, $this->refusalFor($this->saudi), 'the other market is untouched');
    }

    public function test_an_account_that_names_no_country_is_judged_by_the_global_rules(): void
    {
        $this->globalRules(mode: 'strict', rules: ['name_patterns' => ['Vitamin']]);
        $this->marketRules('KW', ['mode' => 'off']);
        $nameless = $this->accountOfTheMarket('', 'partner-none');

        $this->assertSame(
            RegulatedProductGate::NEEDS_CLEARANCE,
            $this->refusalFor($nameless),
            'no country named is not a market with no rules: it is the platform\'s own rules, which is what every account of this installation has',
        );
        $this->assertNull($this->refusalFor($this->kuwaiti), 'while the market that switched itself off is off');
    }

    public function test_the_market_the_gate_judges_by_is_the_one_the_account_sells_in(): void
    {
        $this->assertSame('KW', app(RegulatedProductGate::class)->marketOf($this->draftFor($this->kuwaiti)));
        $this->assertSame('SA', app(RegulatedProductGate::class)->marketOf($this->draftFor($this->saudi)));
    }

    public function test_the_mode_of_a_market_that_said_nothing_is_the_mode_it_always_was(): void
    {
        $this->globalRules(mode: 'strict');

        foreach ([null, 'KW', 'SA'] as $market) {
            $this->assertSame(RegulatedProductGate::MODE_STRICT, app(RegulatedProductGate::class)->modeFor('noon', $market), (string) $market);
        }
    }

    /** A noon account of one country, which is how noon says which country an account sells in. */
    private function accountOfTheMarket(string $country, string $partnerCode): SalesChannelAccount
    {
        $account = $this->noonAccount(partnerCode: $partnerCode);
        $account->forceFill(['settings' => ['warehouse_code' => 'WH-1', 'country_code' => $country]])->save();

        return $account->fresh();
    }

    private function draftFor(SalesChannelAccount $account): ChannelListingDraft
    {
        return app(ListingDraftService::class)->draftFor($account, $this->product, productType: 'health_personal_care');
    }

    private function refusalFor(SalesChannelAccount $account): ?string
    {
        return app(RegulatedProductGate::class)->refusalFor($this->draftFor($account)->fresh());
    }

    /** @param array<string, mixed> $rules */
    private function globalRules(string $mode, array $rules = []): void
    {
        config(['sales_channels.listing.regulated' => $rules + [
            'mode' => $mode, 'category_ids' => [], 'name_patterns' => [], 'brand_ids' => [], 'channels' => [], 'markets' => [],
        ]]);
    }

    /** @param array<string, mixed> $rules */
    private function marketRules(string $market, array $rules): void
    {
        config(['sales_channels.listing.regulated.markets.' . $market => $rules]);
    }
}
