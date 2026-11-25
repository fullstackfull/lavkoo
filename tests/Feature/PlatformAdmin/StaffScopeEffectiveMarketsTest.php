<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\Admin;
use App\Models\PlatformAdminGrant;
use App\Services\PlatformAdmin\PlatformEffectiveMarkets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsPlatformStaffWorld;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\Support\SeedsPlatformMarketData;

/**
 * The platform admin API (GCC-20) and the staff country scopes (GCC-14c) together: the markets an admin reaches are the markets of their grant that the staff scope of the
 * admin panel also leaves them, whatever answer of the API is asked (the decision recorded with the staff scopes: a platform admin's grant is intersected with the staff scope).
 *
 * The world is the one the platform tests use (products, orders, refund requests of Saudi Arabia, the Emirates and Kuwait, and four orders that belong to no market). What
 * each admin should see is worked out here from the two lists, as plain set logic, and never by asking the code under test.
 */
class StaffScopeEffectiveMarketsTest extends PlatformAdminTestCase
{
    use BuildsPlatformStaffWorld;
    use BuildsStorefrontLegalDocuments;
    use SeedsPlatformMarketData;

    private const ALL_SIX = ['SA', 'AE', 'KW', 'QA', 'BH', 'OM'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMarketScenario();
        $this->buildStorefrontLegalDocumentsTable();
        $this->buildStaffCountryTable();
        $this->switchStaffScopes(on: true);
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.countries.AE.active' => true,
            'gcc.storefronts.countries.KW.active' => true,
            'gcc.platform_admin.alerts' => ['zero_stock_warning_count' => 2, 'zero_stock_critical_count' => 3, 'pending_age_hours' => 24, 'pending_warning_count' => 2, 'pending_critical_count' => 3],
        ]);
    }

    // ---- the rule ----------------------------------------------------------------------------------------------------------------------

    public function test_an_employee_with_the_platform_owners_grant_is_narrowed_to_the_countries_of_their_staff_scope(): void
    {
        $token = $this->mintToken($this->employeeWithGrant(grantCountries: null, staffCountries: ['KW', 'SA']));

        $me = $this->me($token);

        $me->assertOk()->assertJsonPath('data.markets', ['SA', 'KW'])->assertJsonPath('data.scope', 'restricted');
        $this->assertSame(['SA', 'KW'], array_column($this->getJson(self::PREFIX . '/markets', $this->bearer($token))->json('data.markets'), 'code'));
    }

    public function test_the_stricter_of_the_two_lists_wins_and_the_markets_come_in_the_order_of_the_configuration(): void
    {
        $token = $this->mintToken($this->employeeWithGrant(grantCountries: ['OM', 'KW', 'SA'], staffCountries: ['QA', 'KW', 'SA']));

        $this->me($token)->assertOk()->assertJsonPath('data.markets', ['SA', 'KW']);
    }

    public function test_a_grant_that_is_narrower_than_the_staff_scope_stays_what_the_grant_says(): void
    {
        $token = $this->mintToken($this->employeeWithGrant(grantCountries: ['KW'], staffCountries: ['SA', 'KW', 'OM']));

        $this->me($token)->assertOk()->assertJsonPath('data.markets', ['KW'])->assertJsonPath('data.scope', 'restricted');
    }

    public function test_an_employee_whose_staff_countries_the_grant_does_not_name_reaches_no_market_and_can_still_sign_in(): void
    {
        $employee = $this->employeeWithGrant(grantCountries: ['KW'], staffCountries: ['SA']);

        $signIn = $this->postJson(self::PREFIX . '/auth/login', ['email' => $employee['email'], 'password' => $employee['password'], 'device_name' => 'Pixel']);

        $signIn->assertOk()->assertJsonPath('data.me.markets', [])->assertJsonPath('data.me.scope', 'restricted');
        $token = (string) $signIn->json('data.token');
        $this->me($token)->assertOk()->assertJsonPath('data.markets', []);
        $this->getJson(self::PREFIX . '/markets', $this->bearer($token))->assertOk()->assertJsonPath('data.markets', [])->assertJsonPath('data.scope', 'restricted');
        $overview = $this->getJson(self::PREFIX . '/overview', $this->bearer($token));
        $overview->assertOk()->assertJsonPath('data.markets', [])->assertJsonPath('data.unattributed', null)->assertJsonPath('data.scope', 'restricted');
        $alerts = $this->getJson(self::PREFIX . '/alerts', $this->bearer($token));
        $alerts->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
    }

    public function test_an_employee_restricted_to_nothing_by_damaged_values_reaches_no_market(): void
    {
        foreach ([['zz'], ['kw'], ['']] as $damaged) {
            $token = $this->mintToken($this->employeeWithGrant(grantCountries: null, staffCountries: $damaged));

            $this->me($token)->assertOk()->assertJsonPath('data.markets', [])->assertJsonPath('data.scope', 'restricted');
        }
    }

    public function test_a_value_that_is_no_country_grants_nothing_beside_one_that_is(): void
    {
        $token = $this->mintToken($this->employeeWithGrant(grantCountries: null, staffCountries: ['KW', 'zz']));

        $this->me($token)->assertOk()->assertJsonPath('data.markets', ['KW']);
    }

    public function test_a_country_taken_out_of_the_configuration_stops_granting_at_the_next_request(): void
    {
        $token = $this->mintToken($this->employeeWithGrant(grantCountries: null, staffCountries: ['KW', 'OM']));
        $this->me($token)->assertJsonPath('data.markets', ['KW', 'OM']);

        $countries = config('gcc.storefronts.countries');
        unset($countries['OM']);
        config(['gcc.storefronts.countries' => $countries]);

        $this->me($token)->assertJsonPath('data.markets', ['KW']);
    }

    // ---- who is not narrowed ---------------------------------------------------------------------------------------------------------------

    public function test_the_master_admin_is_never_narrowed_whatever_rows_it_has(): void
    {
        $master = $this->platformAdmin();
        $this->assignStaffCountries(adminId: $master['id'], countries: ['KW']);

        $this->me($this->mintToken($master))->assertOk()->assertJsonPath('data.markets', self::ALL_SIX)->assertJsonPath('data.scope', 'all');
    }

    public function test_an_employee_with_no_row_is_not_narrowed(): void
    {
        $this->me($this->mintToken($this->employeeWithGrant(grantCountries: null, staffCountries: null)))->assertOk()->assertJsonPath('data.markets', self::ALL_SIX)->assertJsonPath('data.scope', 'all');
    }

    public function test_with_the_staff_switch_off_the_grant_alone_decides(): void
    {
        $this->switchStaffScopes(on: false);
        $owner = $this->mintToken($this->employeeWithGrant(grantCountries: null, staffCountries: ['KW']));
        $viewer = $this->mintToken($this->employeeWithGrant(grantCountries: ['SA', 'AE'], staffCountries: ['KW']));

        $this->me($owner)->assertOk()->assertJsonPath('data.markets', self::ALL_SIX)->assertJsonPath('data.scope', 'all');
        $this->assertNotNull($this->getJson(self::PREFIX . '/overview', $this->bearer($owner))->json('data.unattributed'));
        $this->me($viewer)->assertOk()->assertJsonPath('data.markets', ['SA', 'AE']);
    }

    public function test_with_the_switch_on_but_the_table_missing_nothing_is_narrowed_and_nothing_fails(): void
    {
        $owner = $this->mintToken($this->employeeWithGrant(grantCountries: null, staffCountries: ['KW']));
        $this->me($owner)->assertJsonPath('data.markets', ['KW']);

        Schema::drop('admin_country_scopes');

        $this->me($owner)->assertOk()->assertJsonPath('data.markets', self::ALL_SIX)->assertJsonPath('data.scope', 'all');
        $this->getJson(self::PREFIX . '/overview', $this->bearer($owner))->assertOk();
    }

    public function test_with_the_switch_on_but_the_products_country_column_missing_nothing_is_narrowed_and_nothing_fails(): void
    {
        $owner = $this->mintToken($this->employeeWithGrant(grantCountries: null, staffCountries: ['KW']));
        $this->me($owner)->assertJsonPath('data.markets', ['KW']);

        Schema::table('products', fn ($table) => $table->dropColumn('storefront_country'));

        $this->me($owner)->assertOk()->assertJsonPath('data.markets', self::ALL_SIX)->assertJsonPath('data.scope', 'all');
    }

    // ---- a change of the scope applies at the next request -----------------------------------------------------------------------------

    public function test_a_scope_given_changed_or_taken_back_after_the_sign_in_applies_to_the_next_request_with_the_same_token(): void
    {
        $employee = $this->employeeWithGrant(grantCountries: null, staffCountries: null);
        $token = $this->mintToken($employee);
        $this->me($token)->assertJsonPath('data.markets', self::ALL_SIX)->assertJsonPath('data.scope', 'all');

        $this->setStaffCountries(adminId: $employee['id'], countries: ['KW']);
        $this->me($token)->assertJsonPath('data.markets', ['KW'])->assertJsonPath('data.scope', 'restricted');
        $this->getJson(self::PREFIX . '/overview?market=SA', $this->bearer($token))->assertStatus(403);

        $this->setStaffCountries(adminId: $employee['id'], countries: ['SA', 'QA']);
        $this->me($token)->assertJsonPath('data.markets', ['SA', 'QA']);
        $this->getJson(self::PREFIX . '/overview?market=SA', $this->bearer($token))->assertOk();
        $this->getJson(self::PREFIX . '/overview?market=KW', $this->bearer($token))->assertStatus(403);

        $this->setStaffCountries(adminId: $employee['id'], countries: null);
        $this->me($token)->assertJsonPath('data.markets', self::ALL_SIX)->assertJsonPath('data.scope', 'all');
        $this->getJson(self::PREFIX . '/overview?market=KW', $this->bearer($token))->assertOk();
    }

    public function test_an_admin_made_the_master_admin_after_the_sign_in_is_not_narrowed_at_the_next_request(): void
    {
        $employee = $this->employeeWithGrant(grantCountries: null, staffCountries: ['KW']);
        $token = $this->mintToken($employee);
        $this->me($token)->assertJsonPath('data.markets', ['KW']);

        $this->setRole(adminId: $employee['id'], role: 1);

        $this->me($token)->assertJsonPath('data.markets', self::ALL_SIX)->assertJsonPath('data.scope', 'all');
    }

    public function test_turning_the_staff_switch_off_between_two_requests_restores_the_grants_answers_at_once(): void
    {
        $token = $this->mintToken($this->employeeWithGrant(grantCountries: null, staffCountries: ['KW']));
        $narrowed = $this->getJson(self::PREFIX . '/overview', $this->bearer($token))->json('data.markets');
        $this->assertSame(['KW'], array_column($narrowed, 'market'));

        $this->switchStaffScopes(on: false);
        $this->assertSame(self::ALL_SIX, array_column($this->getJson(self::PREFIX . '/overview', $this->bearer($token))->json('data.markets'), 'market'));

        $this->switchStaffScopes(on: true);
        $this->assertSame(['KW'], array_column($this->getJson(self::PREFIX . '/overview', $this->bearer($token))->json('data.markets'), 'market'));
    }

    public function test_a_session_is_not_ended_and_nothing_is_written_to_the_staff_scope_by_a_narrowing(): void
    {
        $employee = $this->employeeWithGrant(grantCountries: null, staffCountries: null);
        $token = $this->mintToken($employee);
        $this->me($token)->assertOk();
        $scopesBefore = DB::table('admin_country_scopes')->get()->toJson();

        $this->setStaffCountries(adminId: $employee['id'], countries: ['KW']);
        $scopesAfterAssign = DB::table('admin_country_scopes')->get()->toJson();
        $this->me($token)->assertOk();
        $this->getJson(self::PREFIX . '/overview', $this->bearer($token))->assertOk();

        $this->assertSame($scopesAfterAssign, DB::table('admin_country_scopes')->get()->toJson(), 'the platform API only reads the staff scope');
        $this->assertNotSame($scopesBefore, $scopesAfterAssign);
        $this->assertNull(DB::table('platform_admin_tokens')->where('admin_id', $employee['id'])->value('revoked_at'), 'a narrowed scope ends no session');
    }

    // ---- what the app sees ---------------------------------------------------------------------------------------------------------------------

    public function test_the_sign_in_shows_the_markets_the_admin_reaches(): void
    {
        $employee = $this->employeeWithGrant(grantCountries: null, staffCountries: ['AE', 'BH']);

        $signIn = $this->postJson(self::PREFIX . '/auth/login', ['email' => $employee['email'], 'password' => $employee['password'], 'device_name' => 'Pixel']);

        $signIn->assertOk();
        $this->assertSame(['AE', 'BH'], $signIn->json('data.me.markets'));
        $this->assertSame('restricted', $signIn->json('data.me.scope'));
        $this->assertSame(['admin_id', 'name', 'level', 'scope', 'markets', 'token_expires_at'], array_keys($signIn->json('data.me')), 'the six members it has always had and nothing more');
    }

    public function test_the_shape_of_no_answer_changes_for_anybody(): void
    {
        $narrowed = $this->mintToken($this->employeeWithGrant(grantCountries: null, staffCountries: ['KW']));
        $plain = $this->mintToken($this->platformAdmin());

        foreach ([$narrowed, $plain] as $token) {
            $me = $this->me($token)->json('data');
            $this->assertSame(['admin_id', 'name', 'level', 'scope', 'markets', 'token_expires_at', 'capabilities', 'grant_expires_at', 'mfa'], array_keys($me));
            $this->assertSame(['enrolled', 'verified', 'method'], array_keys($me['mfa']));
            $this->assertSame(['scope', 'generated_at', 'markets'], array_keys($this->getJson(self::PREFIX . '/markets', $this->bearer($token))->json('data')));
            // `settlements` is one key, added by E-17: what the platform settled in the window, per company and per
            // currency. It sits beside `markets` rather than inside one because a settlement has no market.
            $this->assertSame(['scope', 'market', 'generated_at', 'window', 'freshness', 'attribution', 'settlements', 'markets', 'unattributed'], array_keys($this->getJson(self::PREFIX . '/overview', $this->bearer($token))->json('data')));
            $this->assertSame(['scope', 'market', 'severity', 'generated_at', 'page', 'per_page', 'total', 'has_more'], array_keys($this->getJson(self::PREFIX . '/alerts', $this->bearer($token))->json('meta')));
            $this->assertSame(['scope', 'page', 'per_page', 'has_more'], array_keys($this->getJson(self::PREFIX . '/audit-logs', $this->bearer($token))->json('meta')));
        }
    }

    public function test_the_overview_of_a_narrowed_employee_holds_their_markets_and_no_bucket_of_orders_without_one(): void
    {
        $token = $this->mintToken($this->employeeWithGrant(grantCountries: null, staffCountries: ['KW', 'AE']));

        $response = $this->getJson(self::PREFIX . '/overview', $this->bearer($token));

        $response->assertOk()->assertJsonPath('data.scope', 'restricted')->assertJsonPath('data.market', 'all')->assertJsonPath('data.unattributed', null);
        $this->assertSame(['AE', 'KW'], array_column($response->json('data.markets'), 'market'));
        $this->assertSame([1, 3], array_column(array_column($response->json('data.markets'), 'orders'), 'total'));
        $this->assertStringNotContainsString('"SA"', $response->getContent());
    }

    public function test_the_alerts_of_a_narrowed_employee_are_those_of_their_markets_only(): void
    {
        $kuwait = $this->mintToken($this->employeeWithGrant(grantCountries: null, staffCountries: ['KW']));
        $others = $this->mintToken($this->employeeWithGrant(grantCountries: null, staffCountries: ['SA', 'AE']));

        $this->assertSame([['critical', 'KW', 'legal_not_ready'], ['warning', 'KW', 'stock_zero'], ['info', 'KW', 'orders_pending_too_long']], $this->alertsOf($kuwait));
        $this->assertSame([['critical', 'AE', 'legal_not_ready'], ['info', 'SA', 'orders_pending_too_long']], $this->alertsOf($others));
        $this->assertSame('restricted', $this->getJson(self::PREFIX . '/alerts', $this->bearer($kuwait))->json('meta.scope'));
    }

    public function test_the_bucket_of_orders_without_a_market_is_for_the_platform_owner_who_is_not_narrowed_only(): void
    {
        $everyCountry = $this->mintToken($this->employeeWithGrant(grantCountries: null, staffCountries: self::ALL_SIX));
        $noRow = $this->mintToken($this->employeeWithGrant(grantCountries: null, staffCountries: null));
        $master = $this->mintToken($this->platformAdmin());

        $narrowed = $this->getJson(self::PREFIX . '/overview', $this->bearer($everyCountry));
        $this->assertSame(self::ALL_SIX, array_column($narrowed->json('data.markets'), 'market'), 'all six countries, and still narrowed: it is a restricted employee');
        $this->assertNull($narrowed->json('data.unattributed'));
        $this->assertSame('restricted', $narrowed->json('data.scope'));
        foreach ([$noRow, $master] as $owner) {
            $response = $this->getJson(self::PREFIX . '/overview', $this->bearer($owner));
            $this->assertSame(4, $response->json('data.unattributed.orders.total'));
            $this->assertSame('all', $response->json('data.scope'));
        }
    }

    // ---- the market a request names ------------------------------------------------------------------------------------------------------------

    public function test_a_market_the_staff_scope_forbids_is_refused_exactly_like_one_the_grant_does_not_name(): void
    {
        $byGrant = $this->mintToken($this->employeeWithGrant(grantCountries: ['KW'], staffCountries: null));
        $byStaffScope = $this->mintToken($this->employeeWithGrant(grantCountries: null, staffCountries: ['KW']));
        $answers = [];
        foreach ([$byGrant, $byStaffScope] as $token) {
            foreach (['/overview', '/alerts'] as $path) {
                $response = $this->getJson(self::PREFIX . $path . '?market=SA', $this->bearer($token));
                $response->assertStatus(403)->assertExactJson(['errors' => [['code' => 'market_not_allowed', 'message' => 'This account may not see that market.']]]);
                $answers[$path][] = [$response->getContent(), $response->headers->get('Cache-Control'), $response->headers->get('Vary'), $response->headers->get('Content-Type')];
            }
        }

        $this->assertSame($answers['/overview'][0], $answers['/overview'][1]);
        $this->assertSame($answers['/alerts'][0], $answers['/alerts'][1]);
    }

    public function test_the_trail_of_a_refused_market_is_the_same_whichever_list_refused_it(): void
    {
        $byGrant = $this->employeeWithGrant(grantCountries: ['KW'], staffCountries: null);
        $byStaffScope = $this->employeeWithGrant(grantCountries: null, staffCountries: ['KW']);
        $this->getJson(self::PREFIX . '/overview?market=SA', $this->bearer($this->mintToken($byGrant)))->assertStatus(403);
        $this->getJson(self::PREFIX . '/overview?market=SA', $this->bearer($this->mintToken($byStaffScope)))->assertStatus(403);

        $lines = DB::table('platform_admin_audit_logs')->orderBy('id')->get();

        $this->assertCount(2, $lines, 'one line for each request and no line about the staff scope');
        $this->assertSame(['overview.view', 'SA', $this->sortedByKey(['status' => 403, 'params' => ['market' => 'SA']])], [$lines[0]->action, $lines[0]->market, $this->contextOf($lines[0])]);
        $this->assertSame([$lines[0]->action, $lines[0]->market, $this->contextOf($lines[0])], [$lines[1]->action, $lines[1]->market, $this->contextOf($lines[1])]);
    }

    /** @return array<string, array{0: string, 1: int, 2: list<string>}> the query, the status, and the markets of the answer when it is a 200 */
    public static function spellingsOfTheMarket(): array
    {
        return [
            'the forbidden one' => ['?market=SA', 403, []],
            'in lower case' => ['?market=sa', 403, []],
            'in mixed case' => ['?market=Sa', 403, []],
            'with spaces around it' => ['?market=%20SA%20', 403, []],
            'with a tab' => ['?market=%09SA', 403, []],
            'with a line feed' => ['?market=SA%0A', 403, []],
            'the allowed one' => ['?market=KW', 200, ['KW']],
            'the allowed one in lower case' => ['?market=kw', 200, ['KW']],
            'the allowed one with spaces' => ['?market=%20kw%20', 200, ['KW']],
            'all' => ['?market=all', 200, ['KW']],
            'all in capitals' => ['?market=ALL', 200, ['KW']],
            'all with spaces' => ['?market=%20all%20', 200, ['KW']],
            'an empty market' => ['?market=', 200, ['KW']],
            'no market' => ['', 200, ['KW']],
            'the name of the parameter in another case' => ['?Market=SA', 200, ['KW']],
            'a list of the forbidden one' => ['?market[]=SA', 422, []],
            'a list of both' => ['?market[]=KW&market[]=SA', 422, []],
            'a list in the other order' => ['?market[]=SA&market[]=KW', 422, []],
            'a keyed list' => ['?market[a]=SA', 422, []],
            'two codes in one value' => ['?market=KW,SA', 422, []],
            'two codes in the other order' => ['?market=SA,KW', 422, []],
            'the forbidden one twice, the last wins' => ['?market=KW&market=SA', 403, []],
            'the allowed one last' => ['?market=SA&market=KW', 200, ['KW']],
            'the name of a country' => ['?market=Saudi', 422, []],
            'three letters' => ['?market=SAU', 422, []],
        ];
    }

    /**
     * @param list<string> $expectedMarkets
     */
    #[DataProvider('spellingsOfTheMarket')]
    public function test_no_spelling_of_the_market_reaches_one_the_staff_scope_forbids(string $query, int $status, array $expectedMarkets): void
    {
        $token = $this->mintToken($this->employeeWithGrant(grantCountries: null, staffCountries: ['KW']));

        $overview = $this->getJson(self::PREFIX . '/overview' . $query, $this->bearer($token));
        $alerts = $this->getJson(self::PREFIX . '/alerts' . $query, $this->bearer($token));

        $this->assertSame([$status, $status], [$overview->getStatusCode(), $alerts->getStatusCode()]);
        if ($status === 200) {
            $this->assertSame($expectedMarkets, array_column($overview->json('data.markets'), 'market'));
            $this->assertSame([], array_values(array_diff(array_column($alerts->json('data'), 'market'), $expectedMarkets)));
            $this->assertNull($overview->json('data.unattributed'));
            $this->assertStringNotContainsString('"SA"', $overview->getContent());
        }
    }

    public function test_nothing_but_the_query_string_names_a_market(): void
    {
        $token = $this->mintToken($this->employeeWithGrant(grantCountries: null, staffCountries: ['KW']));

        $withBody = $this->call('GET', self::PREFIX . '/overview', [], [], [], $this->transformHeadersToServerVars($this->bearer($token) + ['Content-Type' => 'application/json']), json_encode(['market' => 'SA']));
        $withHeaders = $this->getJson(self::PREFIX . '/overview', $this->bearer($token) + ['X-Storefront' => 'SA', 'X-Country' => 'SA', 'Accept-Language' => 'ar-SA']);

        foreach ([$withBody, $withHeaders] as $response) {
            $response->assertOk();
            $this->assertSame(['KW'], array_column($response->json('data.markets'), 'market'));
        }
    }

    // ---- what the staff scope does not touch ------------------------------------------------------------------------------------------------

    public function test_the_audit_trail_of_a_narrowed_owner_is_their_own_lines_without_the_operator(): void
    {
        $narrowed = $this->employeeWithGrant(grantCountries: null, staffCountries: ['KW']);
        $other = $this->platformAdmin(countries: ['AE']);
        $master = $this->platformAdmin();
        $this->line(adminId: $other['id'], action: 'overview.view', context: ['status' => 200]);
        $this->line(adminId: $narrowed['id'], action: 'grant.created', context: ['operator' => ['user' => 'ops', 'host' => 'server-1'], 'level' => 1]);
        $this->line(adminId: null, action: 'grant.revoked', context: ['operator' => ['user' => 'ops', 'host' => 'server-1']]);
        $this->line(adminId: $narrowed['id'], action: 'alerts.view', context: ['status' => 200]);

        $mine = $this->getJson(self::PREFIX . '/audit-logs', $this->bearer($this->mintToken($narrowed)));

        $mine->assertOk()->assertJsonPath('meta.scope', 'restricted');
        $this->assertSame(['alerts.view', 'grant.created'], array_column($mine->json('data'), 'action'));
        $this->assertSame([$narrowed['id']], array_unique(array_column($mine->json('data'), 'admin_id')));
        $this->assertStringNotContainsString('operator', $mine->getContent());
        $this->assertStringNotContainsString('server-1', $mine->getContent());

        $everything = $this->getJson(self::PREFIX . '/audit-logs?per_page=100', $this->bearer($this->mintToken($master)));
        $this->assertSame('all', $everything->json('meta.scope'));
        $this->assertStringContainsString('server-1', $everything->getContent());
        $this->assertContains($other['id'], array_column($everything->json('data'), 'admin_id'));
    }

    public function test_the_parts_the_grant_includes_and_the_day_it_ends_are_what_they_were(): void
    {
        $employee = $this->employeeWithGrant(grantCountries: null, staffCountries: ['KW'], grantOverrides: ['capabilities' => json_encode(['overview']), 'expires_at' => now()->addDays(10)]);
        $token = $this->mintToken($employee);

        $this->me($token)->assertOk()->assertJsonPath('data.capabilities', ['overview'])->assertJsonPath('data.level', 1)->assertJsonPath('data.markets', ['KW']);
        $this->assertNotNull($this->me($token)->json('data.grant_expires_at'));
        $this->getJson(self::PREFIX . '/overview', $this->bearer($token))->assertOk();
        $this->getJson(self::PREFIX . '/alerts', $this->bearer($token))->assertStatus(403)->assertJsonPath('errors.0.code', 'capability_not_allowed')->assertJsonPath('errors.0.capability', 'alerts');

        DB::table('platform_admin_grants')->where('id', $employee['grant_id'])->update(['expires_at' => now()->subMinute()]);

        $this->me($token)->assertStatus(401)->assertExactJson($this->uniformRefusal());
    }

    public function test_the_service_says_what_the_grant_names_what_is_left_and_whether_the_staff_scope_restricted_the_admin(): void
    {
        $reach = app(PlatformEffectiveMarkets::class);
        $grant = fn (?array $countries): PlatformAdminGrant => PlatformAdminGrant::query()->findOrFail($this->employeeWithGrant(grantCountries: $countries)['grant_id']);
        $admin = fn (PlatformAdminGrant $grant): Admin => Admin::query()->findOrFail($grant->admin_id);

        $named = $grant(['AE', 'KW', 'QA']);
        $this->assignStaffCountries(adminId: $named->admin_id, countries: ['KW', 'QA', 'OM']);
        $narrowed = $reach->of(grant: $named, admin: $admin($named));
        $this->assertSame([['AE', 'KW', 'QA'], ['KW', 'QA'], ['KW', 'OM', 'QA']], [$narrowed->granted, $narrowed->markets, $narrowed->staffCountries]);
        $this->assertTrue($narrowed->narrowsTheGrant());
        $this->assertTrue($narrowed->restrictedByStaffScope());

        $wider = $grant(['KW']);
        $this->assignStaffCountries(adminId: $wider->admin_id, countries: ['KW', 'QA']);
        $notNarrowed = $reach->of(grant: $wider, admin: $admin($wider));
        $this->assertSame(['KW'], $notNarrowed->markets);
        $this->assertFalse($notNarrowed->narrowsTheGrant(), 'the staff scope took nothing away');
        $this->assertTrue($notNarrowed->restrictedByStaffScope(), 'but the admin is restricted in the admin panel, and so is no platform owner');

        $free = $grant(null);
        $unrestricted = $reach->of(grant: $free, admin: $admin($free));
        $this->assertSame([self::ALL_SIX, self::ALL_SIX, null], [$unrestricted->granted, $unrestricted->markets, $unrestricted->staffCountries]);
        $this->assertFalse($unrestricted->restrictedByStaffScope());
    }

    private function me(string $token): TestResponse
    {
        return $this->getJson(self::PREFIX . '/me', $this->bearer($token));
    }

    /** @return list<array{0: string, 1: string, 2: string}> the severity, the market and the code of every alert, in the order the API gives them */
    private function alertsOf(string $token): array
    {
        return array_map(static fn (array $alert): array => [$alert['severity'], $alert['market'], $alert['code']], $this->getJson(self::PREFIX . '/alerts', $this->bearer($token))->assertOk()->json('data'));
    }

    /** @param array<string, mixed> $context */
    private function line(?int $adminId, string $action, array $context): void
    {
        DB::table('platform_admin_audit_logs')->insert([
            'admin_id' => $adminId, 'action' => $action, 'market' => null, 'token_id' => null, 'ip' => '203.0.113.7', 'user_agent' => 'TestAgent/1.0',
            'context' => json_encode($context), 'created_at' => now(),
        ]);
    }
}
