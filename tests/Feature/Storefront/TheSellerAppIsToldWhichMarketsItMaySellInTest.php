<?php

namespace Tests\Feature\Storefront;

use App\Services\Marketplace\SellerMarketScope;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsSellerMarketWorld;
use Tests\Support\GrantsSellerMarkets;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * `GET api/v3/seller/seller-center/markets`: the markets the signed-in seller may put a product in (B-09).
 *
 * The seller app has no market picker, and the reason is in the card: nothing told it what the choice was. The grant lives in
 * `seller_market_scopes`, no route reads it, and the only way a seller learnt their grant was a product create being refused. This is the
 * read side. It writes nothing, and where a seller product is saved is unchanged -- `SellerAppProductMarketTest` owns that half.
 *
 * Driven through the real route with a real bearer token, so `seller_api_auth` resolves the principal and `seller_market` resolves the
 * grant from the rows and binds the working market. Reading the service by hand could not tell a bound route from an unbound one, and this
 * endpoint's whole answer is about what the bind produced.
 */
class TheSellerAppIsToldWhichMarketsItMaySellInTest extends TestCase
{
    use BuildsSellerMarketWorld;
    use DatabaseTransactions;
    use GrantsSellerMarkets;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildSellerMarketWorld();
        $this->createTheMarketGrantTable();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();
        $this->dropTheMarketGrantTable();
        $this->tearDownSellerMarketWorld();

        parent::tearDown();
    }

    // ---- the platform of today: the grants are off --------------------------------------------------------------------

    /**
     * **Nothing is switched on, which is every installation today.** A new seller product takes the column's own default and no other
     * market is reachable, so the honest answer is the ONE default market, with `enabled` false so an app can leave the picker out. An
     * empty list would be an app with nothing to show; every configured market would be an offer the platform cannot keep.
     */
    public function test_with_the_market_grants_off_the_answer_is_the_one_default_market(): void
    {
        $answer = $this->theMarkets();

        $answer->assertOk();
        $this->assertFalse($answer->json('enabled'), 'the app is told the picker is not needed');
        $this->assertSame('SA', $answer->json('default_market'));
        $this->assertNull($answer->json('working_market'));
        $this->assertSame(['SA'], $this->countriesOf($answer));
        $this->assertTrue($answer->json('may_create'));
        $this->assertNull($answer->json('refusal'));
        $this->assertNull($answer->json('refusal_message'));
    }

    /** A grant ROW with the switch off is not read at all, so a row written early changes nothing until the switch moves. */
    public function test_a_grant_row_written_while_the_switch_is_off_is_not_offered(): void
    {
        $this->grantMarket(sellerId: self::SELLER, market: 'AE');

        $this->assertSame(['SA'], $this->countriesOf($this->theMarkets()));
    }

    /** And a database that never ran the grant migration answers the same, rather than failing on a table that is not there. */
    public function test_a_platform_whose_grant_table_does_not_exist_answers_the_default_market(): void
    {
        config([SellerMarketScope::SWITCH => true]);
        $this->dropOnlyTheMarketGrantTable();

        $answer = $this->theMarkets();

        $answer->assertOk();
        $this->assertFalse($answer->json('enabled'));
        $this->assertSame(['SA'], $this->countriesOf($answer));
    }

    // ---- the grants in force ------------------------------------------------------------------------------------------

    public function test_a_seller_granted_one_market_is_offered_that_market_and_works_in_it(): void
    {
        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'AE');

        $answer = $this->theMarkets();

        $this->assertTrue($answer->json('enabled'));
        $this->assertSame(['AE'], $this->countriesOf($answer));
        $this->assertSame('AE', $answer->json('working_market'), 'one granted market is the market the request works in');
        $this->assertTrue($answer->json('may_create'));
    }

    /**
     * Two markets and nothing named: there is no one market to save a product in, so `may_create` is false and the refusal is the SAME one
     * the product create gives -- which is the point of composing this out of `SellerMarketScope` rather than restating it.
     */
    public function test_a_seller_granted_two_markets_is_offered_both_and_asked_which_one(): void
    {
        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'SA');
        $this->grantMarket(sellerId: self::SELLER, market: 'AE');

        $answer = $this->theMarkets();

        $this->assertSame(['AE', 'SA'], $this->countriesOf($answer));
        $this->assertNull($answer->json('working_market'), 'a grant of two with none named works in no one market');
        $this->assertFalse($answer->json('may_create'));
        $this->assertSame('name_the_market_this_product_belongs_to_your_account_works_in_more_than_one', $answer->json('refusal'));
        $this->assertNotSame('', (string) $answer->json('refusal_message'), 'a refusal that surfaces as a bare key is a code shown to a seller');
    }

    /** The market the app is working in is the one it named, and naming one of the two settles the refusal. */
    public function test_naming_a_market_of_the_grant_makes_it_the_working_market(): void
    {
        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'SA');
        $this->grantMarket(sellerId: self::SELLER, market: 'AE');

        $answer = $this->theMarkets(market: 'AE');

        $this->assertSame('AE', $answer->json('working_market'));
        $this->assertTrue($answer->json('may_create'));
        $this->assertSame(['AE', 'SA'], $this->countriesOf($answer), 'what the seller MAY sell in does not shrink to what they are working in');
    }

    /**
     * **The header widens nothing, and this endpoint is not the exception.** A request naming a market the rows do not hold is refused by
     * `EnforceSellerMarketScope` before the controller runs, with its own code and no data -- so a caller cannot read a market list by
     * asking for a market they were not granted. Measured here rather than assumed, because a reader of this endpoint would reasonably
     * expect it to answer the grant whatever market was named.
     */
    public function test_naming_a_market_outside_the_grant_is_refused_before_the_endpoint_runs(): void
    {
        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'AE');

        $refused = $this->theMarkets(market: self::UNGRANTED_MARKET);

        $refused->assertStatus(403);
        $this->assertSame('market_scope', $refused->json('errors.0.code'));
        $this->assertNull($refused->json('markets'), 'the refusal carries no market list at all');

        // The unrestricted twin: the same call naming a market the rows DO hold is answered, so the 403 is the grant and not the address.
        $this->theMarkets(market: 'AE')->assertOk();
    }

    /** No row is no limit, the rule the scope applies everywhere: an ungranted shop is offered every market the platform is configured for. */
    public function test_a_seller_with_no_grant_row_is_offered_every_configured_market(): void
    {
        $this->switchMarketScopesOn();

        $this->assertSame(
            $this->everyConfiguredMarket(),
            $this->countriesOf($this->theMarkets()),
            'a shop with no row is unrestricted, not restricted to nothing',
        );
    }

    /**
     * A grant whose rows name nothing the platform serves reaches no market, and that is answered as an empty list WITH the refusal that
     * says so -- never as a quiet fall back to the default market, which is a market the seller does not hold.
     */
    public function test_a_grant_of_nothing_the_platform_serves_is_offered_no_market_at_all(): void
    {
        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'ZZ');

        $answer = $this->theMarkets();

        $this->assertSame([], $this->countriesOf($answer));
        $this->assertFalse($answer->json('may_create'));
        $this->assertSame('your_account_does_not_work_in_any_market', $answer->json('refusal'));
    }

    // ---- what each offered market says ------------------------------------------------------------------------------

    /**
     * A seller prepares a catalogue before a storefront opens, so a market that is not serving visitors is OFFERED and said to be
     * inactive rather than left out -- the same reading `StaffCountryScope::configuredCountries()` applies to an employee's markets.
     */
    public function test_each_offered_market_says_its_currency_whether_it_is_active_and_whether_it_is_the_default(): void
    {
        $this->switchMarketScopesOn();
        config(['gcc.storefronts.countries.AE.active' => false]);
        $this->grantMarket(sellerId: self::SELLER, market: 'SA');
        $this->grantMarket(sellerId: self::SELLER, market: 'AE');

        $rows = $this->theMarkets()->json('markets');
        usort($rows, static fn (array $left, array $right): int => strcmp((string) $left['country'], (string) $right['country']));

        $this->assertSame([
            ['country' => 'AE', 'currency' => 'AED', 'active' => false, 'is_default' => false],
            ['country' => 'SA', 'currency' => 'SAR', 'active' => true, 'is_default' => true],
        ], $rows);
    }

    // ---- the endpoint is the seller's own, and nobody else's --------------------------------------------------------

    /** The unrestricted twin of every refusal above: with no token the address is not a way to read anybody's grant. */
    public function test_without_a_seller_token_the_endpoint_answers_nothing(): void
    {
        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'AE');

        $this->flushHeaders();
        $answered = $this->withHeaders(['Accept' => 'application/json'])->json('GET', '/api/v3/seller/seller-center/markets');

        $this->assertNotSame(200, $answered->status(), 'an unauthenticated caller is not told which markets a shop holds');
    }

    // ---- what the tests build and read -----------------------------------------------------------------------------

    private function theMarkets(?string $market = null): TestResponse
    {
        $headers = ['Authorization' => 'Bearer ' . self::SELLER_TOKEN, 'Accept' => 'application/json']
            + ($market === null ? [] : [StorefrontResolver::HEADER => $market]);

        // `withHeaders()` merges into the headers of the test, so a market named by one call would still be sent by the next.
        $this->flushHeaders();

        return $this->withHeaders($headers)->json('GET', '/api/v3/seller/seller-center/markets');
    }

    /** @return list<string> */
    private function countriesOf(TestResponse $answer): array
    {
        $countries = array_map(static fn (array $row): string => (string) $row['country'], (array) $answer->json('markets'));
        sort($countries);

        return $countries;
    }

    /** @return list<string> */
    private function everyConfiguredMarket(): array
    {
        $markets = StorefrontResolver::fromConfig()->configuredCountries();
        sort($markets);

        return $markets;
    }
}
