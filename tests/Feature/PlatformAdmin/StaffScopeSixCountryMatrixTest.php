<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\Admin;
use App\Models\PlatformAdminGrant;
use App\Services\PlatformAdmin\PlatformEffectiveMarkets;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsPlatformStaffWorld;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\Support\SeedsPlatformMarketData;

/**
 * The six countries of the platform, a grant and a staff scope at a time (GCC-20 with GCC-14c): what an admin reaches through the platform admin API, for every grant that can
 * be given (the platform owner's, each country alone, every pair, one triple, all six, none) against every staff scope that can be given (no row, each country alone, every pair,
 * one triple, all six, a value that is no country, the master admin), with the staff switch on and off.
 *
 * The world is built from config('gcc.storefronts.countries'): every country has its own numbers (its position in the configuration plus one of everything: products at zero stock,
 * orders waiting, and so on), so that a market that is shown to somebody who may not see it cannot hide behind another's figures; four orders belong to no market. What each
 * admin should see is worked out here from the two lists, as plain set logic and from the description of the world, and never by asking the code under test: the markets are
 * the markets of the grant that the staff scope leaves, in the order of the configuration, and the bucket of orders without a market is for an admin whose grant names every market
 * and whom no staff scope restricts.
 */
class StaffScopeSixCountryMatrixTest extends PlatformAdminTestCase
{
    use BuildsPlatformStaffWorld;
    use BuildsStorefrontLegalDocuments;
    use SeedsPlatformMarketData;

    private const STATUSES = ['pending', 'confirmed', 'processing', 'out_for_delivery', 'delivered', 'returned', 'failed', 'canceled'];

    private const NO_SALES = ['by_currency' => null, 'reason' => 'order_currency_not_recorded'];

    /** The markets whose storefront is active; Saudi Arabia is the default storefront, which is grandfathered through the legal gate, so only the others raise `legal_not_ready`. */
    private const ACTIVE = ['SA', 'AE', 'QA', 'OM'];

    private const GRANDFATHERED = 'SA';

    private const ZERO_STOCK_WARNING = 2;

    private const ZERO_STOCK_CRITICAL = 5;

    private const PENDING_WARNING = 3;

    private const PENDING_CRITICAL = 5;

    private const SEVERITY_RANK = ['info' => 1, 'warning' => 2, 'critical' => 3];

    /** @var list<string> */
    private array $countries;

    /** @var array<int, int> the live product of each country, by the position of the country, that the orders and the refund requests are about */
    private array $healthyProduct = [];

    private int $employeeId;

    private int $grantId;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-17 12:00:00');
        $this->countries = $this->configuredCountries();
        $this->buildStorefrontLegalDocumentsTable();
        $this->buildStaffCountryTable();
        DB::table('business_settings')->insert(['type' => 'stock_limit', 'value' => '5']);
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.platform_admin.alerts' => [
                'zero_stock_warning_count' => self::ZERO_STOCK_WARNING,
                'zero_stock_critical_count' => self::ZERO_STOCK_CRITICAL,
                'pending_age_hours' => 24,
                'pending_warning_count' => self::PENDING_WARNING,
                'pending_critical_count' => self::PENDING_CRITICAL,
            ],
        ]);
        foreach ($this->countries as $country) {
            config(["gcc.storefronts.countries.{$country}.active" => in_array($country, self::ACTIVE, true)]);
        }

        $this->buildTheWorld();
        $employee = $this->employeeWithGrant();
        $this->employeeId = $employee['id'];
        $this->grantId = $employee['grant_id'];
        $this->token = $this->mintToken($employee);
    }

    // ---- the world ---------------------------------------------------------------------------------------------------------------------

    /**
     * Country number i (counting from zero in the order of the configuration), with n countries: i + 1 live products at zero stock, n - i at low stock, one that is well stocked;
     * i + 1 orders that have been pending for days and one that was delivered, all of the well stocked product; n - i refund requests about it, the first of them pending. Then four orders
     * that belong to no market (no line, a product that is gone, lines of two countries, a product of a country that is not configured), a refund request about the product that is
     * gone and a product at zero stock in the country that is not configured.
     */
    private function buildTheWorld(): void
    {
        $count = count($this->countries);
        $productId = 1000;
        foreach ($this->countries as $index => $country) {
            for ($zero = 0; $zero <= $index; $zero++) {
                $this->product(id: ++$productId, country: $country, stock: 0);
            }
            for ($low = 0; $low < $count - $index; $low++) {
                $this->product(id: ++$productId, country: $country, stock: 1 + $low % 5);
            }
            $this->healthyProduct[$index] = ++$productId;
            $this->product(id: $productId, country: $country, stock: 50);
        }
        $this->product(id: 9000, country: 'XX', stock: 0);

        $orderId = 0;
        foreach ($this->countries as $index => $country) {
            for ($waiting = 0; $waiting <= $index; $waiting++) {
                $this->order(id: ++$orderId, status: 'pending', at: $this->pendingAt($index), products: [$this->healthyProduct[$index]]);
            }
            $this->order(id: ++$orderId, status: 'delivered', at: '2026-11-01 10:00:00', products: [$this->healthyProduct[$index]]);
            for ($refund = 0; $refund < $count - $index; $refund++) {
                $this->refund(status: $refund === 0 ? 'pending' : 'approved', at: '2026-11-10 10:00:00', product: $this->healthyProduct[$index]);
            }
        }

        $this->order(id: 901, status: 'pending', at: '2026-11-05 10:00:00', products: []);
        $this->order(id: 902, status: 'pending', at: '2026-11-05 11:00:00', products: [9999]);
        $this->order(id: 903, status: 'delivered', at: '2026-11-06 10:00:00', products: [$this->healthyProduct[0], $this->healthyProduct[2]]);
        $this->order(id: 904, status: 'pending', at: '2026-11-05 12:00:00', products: [9000]);
        $this->refund(status: 'pending', at: '2026-11-10 10:00:00', product: 9999);
    }

    private function pendingAt(int $index): string
    {
        return sprintf('2026-11-%02d 10:00:00', 8 + $index);
    }

    /** @return array<string, mixed> what the overview says of country number $index */
    private function expectedBlock(int $index): array
    {
        $country = $this->countries[$index];
        $counts = array_count_values(array_column($this->alertsOfCountry(index: $index), 0));

        return [
            'market' => $country,
            'storefront_currency' => config("gcc.storefronts.countries.{$country}.currency"),
            'active' => in_array($country, self::ACTIVE, true),
            'orders' => ['total' => $index + 2, 'by_status' => $this->statuses(['pending' => $index + 1, 'delivered' => 1]), 'latest_at' => $this->zulu($this->pendingAt($index))],
            'sales' => self::NO_SALES,
            'pending_fulfilment' => ['count' => $index + 1],
            'returns' => ['refund_requests' => count($this->countries) - $index, 'pending' => 1],
            'stock' => $this->expectedStock(zero: $index + 1, low: count($this->countries) - $index),
            'alerts' => ['critical' => $counts['critical'] ?? 0, 'warning' => $counts['warning'] ?? 0, 'info' => $counts['info'] ?? 0],
        ];
    }

    /** @return array<string, mixed> */
    private function expectedUnattributed(): array
    {
        return [
            'orders' => ['total' => 4, 'by_status' => $this->statuses(['pending' => 3, 'delivered' => 1]), 'latest_at' => $this->zulu('2026-11-06 10:00:00')],
            'sales' => self::NO_SALES,
            'pending_fulfilment' => ['count' => 3],
            'returns' => ['refund_requests' => 1, 'pending' => 1],
            'stock' => $this->expectedStock(zero: 1, low: 0),
            'reason' => 'no_single_market',
        ];
    }

    /**
     * The stock half of a block. Nothing of this test's world is past zero, and it builds neither inventory table, so the oversell is zero and the
     * held stock and the pools are NO figure rather than a zero -- which is what the API answers on an installation that records neither.
     *
     * @return array<string, mixed>
     */
    private function expectedStock(int $zero, int $low): array
    {
        return ['zero' => $zero, 'low' => $low, 'oversold' => 0, 'oversold_units' => 0, 'reserved' => null, 'allocation' => null];
    }

    /** @return list<array{0: string, 1: string}> the severity and the code of every alert country number $index raises, from the thresholds the test sets */
    private function alertsOfCountry(int $index): array
    {
        $country = $this->countries[$index];
        $zero = $index + 1;
        $stuck = $index + 1;
        $alerts = [];

        if (in_array($country, self::ACTIVE, true) && $country !== self::GRANDFATHERED) {
            $alerts[] = ['critical', 'legal_not_ready'];
        }
        if ($zero >= self::ZERO_STOCK_WARNING) {
            $alerts[] = [$zero >= self::ZERO_STOCK_CRITICAL ? 'critical' : 'warning', 'stock_zero'];
        }
        $alerts[] = [match (true) {
            $stuck >= self::PENDING_CRITICAL => 'critical',
            $stuck >= self::PENDING_WARNING => 'warning',
            default => 'info',
        }, 'orders_pending_too_long'];

        return $alerts;
    }

    /**
     * @param list<string> $markets
     *
     * @return list<array{0: string, 1: string, 2: string}> severity, market and code of the alerts of these markets, worst first, then by market in the order of the configuration, then by code
     */
    private function expectedAlerts(array $markets): array
    {
        $alerts = [];
        foreach ($this->countries as $index => $country) {
            if (in_array($country, $markets, true)) {
                foreach ($this->alertsOfCountry(index: $index) as [$severity, $code]) {
                    $alerts[] = [$severity, $country, $code, $index];
                }
            }
        }
        usort($alerts, static fn (array $left, array $right): int => [self::SEVERITY_RANK[$right[0]], $left[3], $left[2]] <=> [self::SEVERITY_RANK[$left[0]], $right[3], $right[2]]);

        return array_map(static fn (array $alert): array => array_slice($alert, 0, 3), $alerts);
    }

    /**
     * @param array<string, int> $counts
     *
     * @return array<string, int>
     */
    private function statuses(array $counts): array
    {
        return array_merge(array_fill_keys(self::STATUSES, 0), $counts);
    }

    private function zulu(string $time): string
    {
        return Carbon::parse($time)->utc()->toIso8601ZuluString();
    }

    // ---- what is given: every grant, every staff scope ---------------------------------------------------------------------------------

    /** @return array<string, list<string>|null> the grants by name: null is every market, the platform owner's */
    private function grants(): array
    {
        $grants = ['the platform owner' => null];
        foreach ($this->countries as $country) {
            $grants[$country] = [$country];
        }
        foreach ($this->countries as $position => $first) {
            foreach (array_slice($this->countries, $position + 1) as $second) {
                $grants["{$first}+{$second}"] = [$first, $second];
            }
        }
        $grants[implode('+', array_slice($this->countries, 0, 3))] = array_slice($this->countries, 0, 3);
        $grants['all six'] = $this->countries;
        $grants['no country'] = [];

        return $grants;
    }

    /** @return array<string, array{0: list<string>|null, 1: int}> every staff scope by name: the rows an admin holds and their role (1 is the master admin) */
    private function staffScopes(): array
    {
        $scopes = ['no row' => [null, 2]];
        foreach ($this->countries as $country) {
            $scopes[$country] = [[$country], 2];
        }
        foreach ($this->countries as $position => $first) {
            foreach (array_slice($this->countries, $position + 1) as $second) {
                $scopes["{$first}+{$second}"] = [[$first, $second], 2];
            }
        }
        $scopes[implode('+', array_slice($this->countries, 0, 3))] = [array_slice($this->countries, 0, 3), 2];
        $scopes['all six'] = [$this->countries, 2];
        $scopes['a value that is no country'] = [['zz'], 2];
        $scopes['a country in lower case'] = [['kw'], 2];
        $scopes['a country and a value that is none'] = [['KW', 'zz'], 2];
        $scopes['an empty value'] = [[''], 2];
        $scopes['the master admin with rows'] = [['KW'], 1];
        $scopes['the master admin with none'] = [null, 1];

        return $scopes;
    }

    /** The grants and staff scopes a test that asks the heavy answers goes through: the ones where the two lists overlap in every way. */
    private function representativeGrants(): array
    {
        return array_intersect_key($this->grants(), array_flip(['the platform owner', 'SA', 'AE+KW', 'SA+AE+KW', 'all six', 'no country']));
    }

    private function representativeStaffScopes(): array
    {
        return array_intersect_key($this->staffScopes(), array_flip(['no row', 'SA', 'OM', 'AE+KW', 'BH+OM', 'SA+AE+KW', 'all six', 'a value that is no country', 'the master admin with rows']));
    }

    /**
     * @param list<string>|null $grant
     * @param list<string>|null $staffRows
     */
    private function arrange(?array $grant, ?array $staffRows, int $role, bool $switchOn): void
    {
        DB::table('platform_admin_grants')->where('id', $this->grantId)->update(['countries' => $grant === null ? null : json_encode($grant)]);
        $this->setRole(adminId: $this->employeeId, role: $role);
        $this->setStaffCountries(adminId: $this->employeeId, countries: $staffRows);
        $this->switchStaffScopes(on: $switchOn);
    }

    // ---- what each of them should reach, as set logic ---------------------------------------------------------------------------------

    /**
     * @param list<string>|null $grant
     * @param list<string>|null $staffRows
     *
     * @return list<string> the markets of the grant that the staff scope leaves, in the order of the configuration
     */
    private function expectedMarkets(?array $grant, ?array $staffRows, int $role, bool $switchOn): array
    {
        $granted = array_values(array_filter($this->countries, static fn (string $country): bool => $grant === null || in_array($country, $grant, true)));

        if (!$this->isRestrictedByStaffScope(staffRows: $staffRows, role: $role, switchOn: $switchOn)) {
            return $granted;
        }

        return array_values(array_filter($granted, static fn (string $country): bool => in_array($country, $staffRows, true)));
    }

    /** Whether the staff scope restricts the admin: the switch is on, they are not the master admin, and they hold a row (whatever it says). */
    private function isRestrictedByStaffScope(?array $staffRows, int $role, bool $switchOn): bool
    {
        return $switchOn && $role !== 1 && $staffRows !== null;
    }

    private function expectedScope(?array $grant, ?array $staffRows, int $role, bool $switchOn): string
    {
        return $grant === null && !$this->isRestrictedByStaffScope(staffRows: $staffRows, role: $role, switchOn: $switchOn) ? 'all' : 'restricted';
    }

    // ---- the tests -------------------------------------------------------------------------------------------------------------------------

    public function test_the_service_gives_the_markets_of_the_grant_that_the_staff_scope_leaves_for_every_grant_and_every_staff_scope(): void
    {
        $service = app(PlatformEffectiveMarkets::class);
        $checked = 0;

        foreach ([true, false] as $switchOn) {
            foreach ($this->grants() as $grantName => $grant) {
                foreach ($this->staffScopes() as $scopeName => [$rows, $role]) {
                    $this->arrange(grant: $grant, staffRows: $rows, role: $role, switchOn: $switchOn);
                    $reach = $service->of(grant: PlatformAdminGrant::query()->findOrFail($this->grantId), admin: Admin::query()->findOrFail($this->employeeId));
                    $label = "grant {$grantName}, staff scope {$scopeName}, switch " . ($switchOn ? 'on' : 'off');

                    $this->assertSame($this->expectedMarkets(grant: $grant, staffRows: $rows, role: $role, switchOn: $switchOn), $reach->markets, $label);
                    $this->assertSame($this->expectedMarkets(grant: $grant, staffRows: null, role: $role, switchOn: false), $reach->granted, "{$label}: what the grant names");
                    $this->assertSame($this->isRestrictedByStaffScope(staffRows: $rows, role: $role, switchOn: $switchOn), $reach->restrictedByStaffScope(), "{$label}: restricted");
                    $this->assertSame($reach->markets !== $reach->granted, $reach->narrowsTheGrant(), "{$label}: narrows");
                    $checked++;
                }
            }
        }

        $this->assertSame(2 * 25 * 30, $checked, 'every grant against every staff scope, with the switch on and off');
    }

    public function test_me_and_the_markets_show_the_effective_markets_for_every_grant_and_every_staff_scope_with_the_switch_on(): void
    {
        $checked = 0;

        foreach ($this->grants() as $grantName => $grant) {
            foreach ($this->staffScopes() as $scopeName => [$rows, $role]) {
                $this->arrange(grant: $grant, staffRows: $rows, role: $role, switchOn: true);
                $expected = $this->expectedMarkets(grant: $grant, staffRows: $rows, role: $role, switchOn: true);
                $scope = $this->expectedScope(grant: $grant, staffRows: $rows, role: $role, switchOn: true);
                $label = "grant {$grantName}, staff scope {$scopeName}";

                $me = $this->getJson(self::PREFIX . '/me', $this->bearer($this->token));
                $this->assertSame(200, $me->getStatusCode(), "{$label}: signed in whatever the lists say, and an empty list is no failure");
                $this->assertSame([$expected, $scope], [$me->json('data.markets'), $me->json('data.scope')], "{$label}: me");

                $markets = $this->getJson(self::PREFIX . '/markets', $this->bearer($this->token));
                $this->assertSame([$scope, $expected], [$markets->json('data.scope'), array_column($markets->json('data.markets'), 'code')], "{$label}: markets");
                $checked++;
            }
        }

        $this->assertSame(25 * 30, $checked);
    }

    public function test_the_overview_and_the_alerts_hold_the_effective_markets_and_the_bucket_only_for_the_unrestricted_owner(): void
    {
        $checked = 0;

        foreach ($this->representativeGrants() as $grantName => $grant) {
            foreach ($this->representativeStaffScopes() as $scopeName => [$rows, $role]) {
                $this->arrange(grant: $grant, staffRows: $rows, role: $role, switchOn: true);
                $expected = $this->expectedMarkets(grant: $grant, staffRows: $rows, role: $role, switchOn: true);
                $scope = $this->expectedScope(grant: $grant, staffRows: $rows, role: $role, switchOn: true);
                $label = "grant {$grantName}, staff scope {$scopeName}";

                $this->assertReadersAnswer(label: $label, expectedMarkets: $expected, expectedScope: $scope);
                $checked++;
            }
        }

        $this->assertSame(6 * 9, $checked);
    }

    public function test_with_the_staff_switch_off_every_answer_is_the_grants_whatever_rows_there_are(): void
    {
        $checked = 0;
        $scopes = array_intersect_key($this->staffScopes(), array_flip(['no row', 'SA', 'AE+KW', 'all six', 'a value that is no country', 'the master admin with rows']));

        foreach ($this->grants() as $grantName => $grant) {
            foreach ($scopes as $scopeName => [$rows, $role]) {
                $this->arrange(grant: $grant, staffRows: $rows, role: $role, switchOn: false);
                $expected = $this->expectedMarkets(grant: $grant, staffRows: null, role: $role, switchOn: false);
                $scope = $grant === null ? 'all' : 'restricted';
                $label = "grant {$grantName}, staff scope {$scopeName}, switch off";

                $me = $this->getJson(self::PREFIX . '/me', $this->bearer($this->token));
                $this->assertSame([$expected, $scope], [$me->json('data.markets'), $me->json('data.scope')], "{$label}: me");
                $markets = $this->getJson(self::PREFIX . '/markets', $this->bearer($this->token));
                $this->assertSame([$scope, $expected], [$markets->json('data.scope'), array_column($markets->json('data.markets'), 'code')], "{$label}: markets");
                $checked++;
            }
        }
        foreach ($this->representativeGrants() as $grantName => $grant) {
            foreach ($this->representativeStaffScopes() as $scopeName => [$rows, $role]) {
                $this->arrange(grant: $grant, staffRows: $rows, role: $role, switchOn: false);

                $this->assertReadersAnswer(label: "grant {$grantName}, staff scope {$scopeName}, switch off", expectedMarkets: $this->expectedMarkets(grant: $grant, staffRows: null, role: $role, switchOn: false), expectedScope: $grant === null ? 'all' : 'restricted');
                $checked++;
            }
        }

        $this->assertSame(25 * 6 + 6 * 9, $checked);
    }

    public function test_turning_the_staff_switch_off_restores_exactly_the_answers_the_grant_alone_gives(): void
    {
        $compared = 0;

        foreach ($this->representativeGrants() as $grantName => $grant) {
            foreach (array_diff_key($this->representativeStaffScopes(), ['no row' => 1]) as $scopeName => [$rows, $role]) {
                $this->arrange(grant: $grant, staffRows: null, role: $role, switchOn: false);
                $alone = $this->answersOfEveryReader();

                $this->arrange(grant: $grant, staffRows: $rows, role: $role, switchOn: false);
                $this->assertSame($alone, $this->answersOfEveryReader(), "grant {$grantName}, staff scope {$scopeName}: the rows change nothing while the switch is off");

                $this->arrange(grant: $grant, staffRows: $rows, role: $role, switchOn: true);
                $this->arrange(grant: $grant, staffRows: $rows, role: $role, switchOn: false);
                $this->assertSame($alone, $this->answersOfEveryReader(), "grant {$grantName}, staff scope {$scopeName}: off again after it was on");
                $compared++;
            }
        }

        $this->assertSame(6 * 8, $compared);
    }

    public function test_an_audit_trail_is_read_whole_by_the_owner_the_staff_scope_does_not_restrict_and_own_lines_only_by_everybody_else(): void
    {
        $other = $this->platformAdmin(countries: ['AE']);
        $this->line(adminId: $other['id'], action: 'overview.view');
        $this->line(adminId: null, action: 'grant.created', context: ['operator' => ['user' => 'ops', 'host' => 'server-1']]);
        $this->line(adminId: $this->employeeId, action: 'alerts.view');
        $checked = 0;

        foreach (array_intersect_key($this->grants(), array_flip(['the platform owner', 'KW', 'SA+AE+KW'])) as $grantName => $grant) {
            foreach (array_intersect_key($this->staffScopes(), array_flip(['no row', 'KW', 'all six', 'a value that is no country', 'the master admin with rows'])) as $scopeName => [$rows, $role]) {
                foreach ([true, false] as $switchOn) {
                    $this->arrange(grant: $grant, staffRows: $rows, role: $role, switchOn: $switchOn);
                    $everything = $this->expectedScope(grant: $grant, staffRows: $rows, role: $role, switchOn: $switchOn) === 'all';
                    $label = "grant {$grantName}, staff scope {$scopeName}, switch " . ($switchOn ? 'on' : 'off');

                    $response = $this->getJson(self::PREFIX . '/audit-logs?per_page=100', $this->bearer($this->token));
                    $admins = array_values(array_unique(array_column($response->json('data'), 'admin_id')));
                    sort($admins);

                    $this->assertSame($everything ? [null, $this->employeeId, $other['id']] : [$this->employeeId], $admins, "{$label}: whose lines");
                    $this->assertSame($everything, str_contains($response->getContent(), 'server-1'), "{$label}: the operator of a console line");
                    $this->assertSame($everything ? 'all' : 'restricted', $response->json('meta.scope'), $label);
                    $checked++;
                }
            }
        }

        $this->assertSame(3 * 5 * 2, $checked);
    }

    // ---- what the readers answer -----------------------------------------------------------------------------------------------------------

    /**
     * Asks the overview and the alerts for all of the admin's markets and for each of the six one at a time, and says each answer is what the world and the markets say.
     *
     * @param list<string> $expectedMarkets
     */
    private function assertReadersAnswer(string $label, array $expectedMarkets, string $expectedScope): void
    {
        $indices = array_keys(array_filter($this->countries, static fn (string $country): bool => in_array($country, $expectedMarkets, true)));

        $overview = $this->getJson(self::PREFIX . '/overview', $this->bearer($this->token));
        $this->assertSame(200, $overview->getStatusCode(), "{$label}: overview");
        $this->assertSame($expectedScope, $overview->json('data.scope'), "{$label}: overview scope");
        $this->assertSame('all', $overview->json('data.market'), $label);
        $this->assertSame(array_map(fn (int $index): array => $this->expectedBlock(index: $index), $indices), $overview->json('data.markets'), "{$label}: the markets of the overview");
        $this->assertSame($expectedScope === 'all' ? $this->expectedUnattributed() : null, $overview->json('data.unattributed'), "{$label}: the bucket of orders without a market");

        $alerts = $this->getJson(self::PREFIX . '/alerts', $this->bearer($this->token));
        $this->assertSame(200, $alerts->getStatusCode(), "{$label}: alerts");
        $this->assertSame($this->expectedAlerts(markets: $expectedMarkets), array_map(static fn (array $alert): array => [$alert['severity'], $alert['market'], $alert['code']], $alerts->json('data')), "{$label}: the alerts");
        $this->assertSame([$expectedScope, 'all', count($this->expectedAlerts(markets: $expectedMarkets))], [$alerts->json('meta.scope'), $alerts->json('meta.market'), $alerts->json('meta.total')], "{$label}: alerts meta");

        foreach ($this->countries as $index => $country) {
            $reaches = in_array($country, $expectedMarkets, true);
            $single = $this->getJson(self::PREFIX . '/overview?market=' . $country, $this->bearer($this->token));
            $alone = $this->getJson(self::PREFIX . '/alerts?market=' . strtolower($country), $this->bearer($this->token));

            if ($reaches) {
                $this->assertSame(200, $single->getStatusCode(), "{$label}: overview of {$country}");
                $this->assertSame([$this->expectedBlock(index: $index)], $single->json('data.markets'), "{$label}: overview of {$country}");
                $this->assertNull($single->json('data.unattributed'), "{$label}: a single market never carries the bucket");
                $this->assertSame($this->expectedAlerts(markets: [$country]), array_map(static fn (array $alert): array => [$alert['severity'], $alert['market'], $alert['code']], $alone->json('data')), "{$label}: alerts of {$country}");
            } else {
                $refusal = ['errors' => [['code' => 'market_not_allowed', 'message' => 'This account may not see that market.']]];
                $this->assertSame([403, $refusal], [$single->getStatusCode(), $single->json()], "{$label}: overview of {$country} is refused");
                $this->assertSame([403, $refusal], [$alone->getStatusCode(), $alone->json()], "{$label}: alerts of {$country} are refused");
            }
        }
    }

    /** @return array<string, string> the body of every reader but the trail, for the admin as they are now */
    private function answersOfEveryReader(): array
    {
        $answers = [];
        foreach (['/me', '/markets', '/overview', '/alerts', '/overview?market=KW', '/alerts?market=SA', '/overview?market=all'] as $path) {
            $response = $this->getJson(self::PREFIX . $path, $this->bearer($this->token));
            $answers[$path] = $response->getStatusCode() . ' ' . $response->getContent();
        }

        return $answers;
    }

    /** @param array<string, mixed>|null $context */
    private function line(?int $adminId, string $action, ?array $context = null): void
    {
        DB::table('platform_admin_audit_logs')->insert([
            'admin_id' => $adminId, 'action' => $action, 'market' => null, 'token_id' => null, 'ip' => '203.0.113.7', 'user_agent' => 'TestAgent/1.0',
            'context' => $context === null ? null : json_encode($context), 'created_at' => now(),
        ]);
    }
}
