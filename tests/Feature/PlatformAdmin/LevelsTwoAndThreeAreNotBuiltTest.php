<?php

namespace Tests\Feature\PlatformAdmin;

use App\Exceptions\PlatformAdminGrantRefusedException;
use App\Models\PlatformAdminGrant;
use App\Services\PlatformAdmin\PlatformAdminGrantService;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Levels 2 and 3 of GCC-20 -- the write actions, their dual approval and the store-status switch -- are NOT built, and each action class needs the
 * owner's separate authorization before one is (charter s.5, L-15). This file is what makes waiting safe: it pins the three ways the API is closed
 * today, so that the day one of them is opened it has to be opened on purpose.
 *
 *  1. **Only level 1 can be granted.** The console refuses any other level, and so does the service it calls, whoever calls it.
 *  2. **A grant row carrying a level this build does not implement opens nothing more.** A row written straight into the database -- a restored dump,
 *     an administrator with a SQL client -- cannot acquire what the console refuses to give, because no route and no answer of this API reads the
 *     level as a permission. `GET /me` still reports what the row says, which is the truth the shell is entitled to; it simply buys nothing. The day
 *     a route begins to read `level >= 2`, this test fails and the question "who may hold level 2, and how is the row made" has to be answered first.
 *  3. **A write route added later must carry the step-up.** Nine routes change anything today and every one of them changes a session, a sign-in or
 *     this phone -- never business data. A tenth that changes business data without a fresh second factor would be a level 2 or 3 action let through
 *     on an unlocked phone, so it is refused here rather than noticed in review.
 *
 * None of this decides anything the owner is owed: it holds the door shut and names who opens it.
 */
class LevelsTwoAndThreeAreNotBuiltTest extends PlatformAdminTestCase
{
    /**
     * The routes that may change something without a fresh second factor, and what each changes. Every one is the session, the sign-in or the phone
     * this session is alerted on. A route that changes BUSINESS DATA does not belong on this list at any level.
     */
    private const SESSION_AND_DEVICE_WRITES = [
        'platform_admin.auth.login',
        'platform_admin.auth.mfa',
        'platform_admin.auth.step_up',
        'platform_admin.auth.logout',
        'platform_admin.sessions.end_others',
        'platform_admin.session.revoke',
        'platform_admin.devices.register',
        'platform_admin.device.forget',
    ];

    /** The reads that are of ONE market and refuse "every market at once", so the walk below asks each for a market it may see. */
    private const ONE_MARKET_ROUTES = ['platform_admin.reports.view', 'platform_admin.exports.orders', 'platform_admin.orders.view'];

    /**
     * The reads whose answer is about the ACCOUNT that asked rather than about the platform: who they are, their open sessions, their own lines of the
     * trail, their own phones. Two different admins can never answer those byte for byte, so they are left out of the comparison and named here.
     *
     * The default is to be COMPARED: a read added later is compared unless somebody puts it on this list on purpose, which is the way round that makes
     * the comparison hold as the API grows.
     */
    private const ACCOUNT_SHAPED_READS = [
        'platform_admin.me.view',
        'platform_admin.sessions.list',
        'platform_admin.audit_logs.view',
        'platform_admin.devices.list',
    ];

    public function test_only_the_one_level_that_exists_can_be_granted_whoever_asks_for_another(): void
    {
        $service = app(PlatformAdminGrantService::class);
        $admin = $this->makeAdmin();

        foreach ([0, 2, 3, 4, 99, -1] as $level) {
            try {
                $service->grant(adminId: $admin['id'], countries: ['KW'], level: $level);
                $this->fail("level {$level} was granted, and only level 1 exists");
            } catch (PlatformAdminGrantRefusedException $refused) {
                $this->assertStringContainsString('Only level 1 (monitor) exists', $refused->getMessage(), "level {$level}");
            }
        }

        $this->assertSame(0, DB::table('platform_admin_grants')->count(), 'a refused level wrote no grant');
        $this->assertSame(1, PlatformAdminGrant::LEVEL_MONITOR);
        $this->assertSame(
            ['LEVEL_MONITOR'],
            array_values(array_filter(array_keys((new \ReflectionClass(PlatformAdminGrant::class))->getConstants()), static fn (string $name): bool => str_starts_with($name, 'LEVEL_'))),
            'there is one level constant, so nobody reads this class and believes a second level is implemented',
        );
    }

    #[DataProvider('levelsNobodyImplemented')]
    public function test_a_grant_row_carrying_a_level_this_build_does_not_implement_answers_exactly_what_level_one_answers(int $level): void
    {
        $atLevelOne = $this->mintToken($this->atLevel(level: PlatformAdminGrant::LEVEL_MONITOR));
        $outOfBand = $this->mintToken($this->atLevel(level: $level));

        // `GET /me` is honest about the row: the shell is told the level it holds. That is read FIRST, because the walk below would otherwise have
        // signed both sessions out before it was asked.
        $mine = $this->getJson(self::PREFIX . '/me', $this->bearer($outOfBand))->assertOk();
        $ordinary = $this->getJson(self::PREFIX . '/me', $this->bearer($atLevelOne))->assertOk();
        $this->assertSame($level, $mine->json('data.level'));
        $this->assertSame(PlatformAdminGrant::LEVEL_MONITOR, $ordinary->json('data.level'));

        // And `me` says nothing ELSE about the level. It is the one answer a shell would read a permission out of, so it is compared member by
        // member with the three that name the account taken off both sides -- not left out of the comparison, which is how a first draft of this test
        // let a `may_act` flag derived from the level through without failing.
        $this->assertSame(
            $this->withoutWhoAsked($ordinary->json('data')),
            $this->withoutWhoAsked($mine->json('data')),
            "level {$level} changes what GET /me says beyond the level itself, so a shell could read a permission out of a row the console refuses to make",
        );

        // And it buys nothing: every READ of the API answers the two tokens the same, byte for byte. The reads are the whole of what a level could
        // widen today, because the only routes that write are the session's own, which the test below pins.
        $before = $this->ownTables();
        $read = 0;
        foreach ($this->platformRoutes() as $route) {
            if (array_values(array_diff($route->methods(), ['HEAD'])) !== ['GET'] || in_array($route->getName(), self::ACCOUNT_SHAPED_READS, true)) {
                continue;
            }
            [$method, $uri] = $this->requestTo($route);
            $bare = in_array($route->getName(), self::ONE_MARKET_ROUTES, true) ? $uri . '?market=KW' : $uri;

            $this->assertSame(
                $this->saying($this->json($method, $bare, [], $this->bearer($atLevelOne)), path: $bare),
                $this->saying($this->json($method, $bare, [], $this->bearer($outOfBand)), path: $bare),
                "level {$level} changes what {$method} {$uri} answers, so a grant row written out of band is worth more than the console would give",
            );
            $read++;
        }

        $this->assertGreaterThan(5, $read, 'the walk read the API rather than skipping it');
        $this->assertNotSame($before, $this->ownTables(), 'the walk did happen: the reads were audited');
    }

    /** @return array<string, array{0: int}> */
    public static function levelsNobodyImplemented(): array
    {
        return ['level 2, review and assign' => [2], 'level 3, the acting level' => [3], 'a level out of the air' => [99]];
    }

    public function test_a_level_below_the_one_that_exists_is_a_withdrawn_grant_and_opens_nothing_at_all(): void
    {
        $token = $this->mintToken($this->atLevel(level: 0));

        $this->assertEveryRouteRefuses($this->bearer($token), why: 'a grant at a level that does not exist is withdrawn', query: ['market' => 'KW']);
    }

    public function test_every_route_that_changes_anything_changes_a_session_or_this_phone_and_never_business_data(): void
    {
        $writing = array_values(array_map(
            static fn (RoutingRoute $route): string => (string) $route->getName(),
            array_filter($this->platformRoutes(), static fn (RoutingRoute $route): bool => array_values(array_diff($route->methods(), ['HEAD'])) !== ['GET']),
        ));
        sort($writing);
        $declared = self::SESSION_AND_DEVICE_WRITES;
        sort($declared);

        $this->assertSame($declared, $writing, 'a route of this API that writes something else is a level 2 or 3 action, which no owner has authorized');
    }

    public function test_a_write_route_that_is_not_one_of_those_has_to_prove_the_second_factor_again(): void
    {
        $missing = [];

        foreach ($this->platformRoutes() as $route) {
            $writes = array_values(array_diff($route->methods(), ['HEAD'])) !== ['GET'];
            $stepsUp = in_array('platform_admin_step_up', $route->gatherMiddleware(), true);

            if ($writes && !in_array($route->getName(), self::SESSION_AND_DEVICE_WRITES, true) && !$stepsUp) {
                $missing[] = $route->methods()[0] . ' ' . $route->uri();
            }
        }

        $this->assertSame([], $missing, 'these change something that is not a session and ask for no fresh second factor: ' . implode(', ', $missing));
    }

    /**
     * One answer of `GET /me` without the three members that name the account that asked. Everything else -- the markets, the capabilities, the second
     * factor, the contract and anything added later -- must be the same for two admins whose grants differ only in a level nothing implements.
     *
     * @param array<string, mixed> $profile
     *
     * @return array<string, mixed>
     */
    private function withoutWhoAsked(array $profile): array
    {
        unset($profile['admin_id'], $profile['name'], $profile['level'], $profile['token_expires_at']);

        return $profile;
    }

    /**
     * An admin of Kuwait holding every capability, whose grant row carries the level given -- written straight into the table, the way a restored
     * dump or a SQL client would write it, because the console refuses every level but one and this is the row it refuses to make.
     *
     * @return array<string, mixed>
     */
    private function atLevel(int $level): array
    {
        return $this->platformAdmin(
            countries: ['KW'],
            grantOverrides: ['capabilities' => json_encode(PlatformAdminGrant::CAPABILITIES), 'level' => $level],
        );
    }

    public function test_the_step_up_middleware_is_on_a_route_so_the_ratchet_above_is_not_vacuous(): void
    {
        $carrying = array_values(array_filter(
            $this->platformRoutes(),
            static fn (RoutingRoute $route): bool => in_array('platform_admin_step_up', $route->gatherMiddleware(), true),
        ));

        $this->assertNotEmpty($carrying, 'if nothing carries the step-up the ratchet proves nothing: it would hold for a reason that is not the rule');
        $this->assertSame(['platform_admin.exports.orders'], array_map(static fn (RoutingRoute $route): string => (string) $route->getName(), $carrying));
    }
}
