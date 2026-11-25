<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\PlatformAdminGrant;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsPlatformAdminWorld;
use Tests\TestCase;

/**
 * What a grant says about what it includes and when it ends, read from the row the way a request reads it.
 */
class GrantModelTest extends TestCase
{
    use BuildsPlatformAdminWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildPlatformAdminWorld();
        DB::table('admins')->insert(['id' => 7, 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'x']);
    }

    public function test_the_capabilities_that_exist_are_these_six_in_this_order(): void
    {
        $this->assertSame(['overview', 'markets', 'alerts', 'audit', 'sessions', 'health'], PlatformAdminGrant::CAPABILITIES);
    }

    /**
     * Which of them a grant that names none does NOT get.
     *
     * The first five were there when the first grant was made, so naming none meant all of them and still does. `health` was added afterwards,
     * and giving it to every existing grant the moment the code shipped would have widened what an operator may read without anybody deciding.
     */
    public function test_a_capability_added_after_the_first_grant_is_not_given_by_a_grant_that_names_none(): void
    {
        $this->assertSame(['health'], PlatformAdminGrant::NOT_GIVEN_BY_DEFAULT);
        $this->assertSame([], array_diff(PlatformAdminGrant::NOT_GIVEN_BY_DEFAULT, PlatformAdminGrant::CAPABILITIES), 'a capability that is not given by default has to be a capability');

        $namesNone = new PlatformAdminGrant();

        $this->assertSame(['overview', 'markets', 'alerts', 'audit', 'sessions'], $namesNone->capabilityList());
        $this->assertFalse($namesNone->allows(capability: 'health'));
        $this->assertTrue($namesNone->allows(capability: 'overview'));
    }

    /** @return array<string, array{0: ?string, 1: list<string>}> */
    public static function storedCapabilities(): array
    {
        $all = ['overview', 'markets', 'alerts', 'audit', 'sessions'];

        return [
            'SQL NULL is every capability that exists' => [null, $all],
            'a list is those capabilities' => ['["alerts","overview"]', ['overview', 'alerts']],
            'in the order the list has them: the order of the capabilities' => ['["sessions","audit","markets"]', ['markets', 'audit', 'sessions']],
            'an empty list is none' => ['[]', []],
            'the JSON text null is none, not every one' => ['null', []],
            'a name that is not a capability is dropped' => ['["overview","payments"]', ['overview']],
            'only names that are not capabilities is none' => ['["payments"]', []],
            'a repeated name is one' => ['["alerts","alerts"]', ['alerts']],
            'names in another case are not capabilities' => ['["Overview"]', []],
            'damaged JSON is none' => ['["overview"', []],
            'an object is none' => ['{"overview":true}', []],
            'an object whose values are capabilities is none' => ['{"first":"overview"}', []],
            'a bare string is none' => ['"overview"', []],
            'non-string entries are dropped' => ['["markets",7,null,true]', ['markets']],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('storedCapabilities')]
    public function test_only_sql_null_means_every_capability(?string $stored, array $expected): void
    {
        if ($stored !== null) {
            json_decode($stored);
            if (json_last_error() !== JSON_ERROR_NONE && DB::getDriverName() !== 'sqlite') {
                $this->markTestSkipped('A JSON column on this engine refuses damaged text, so it cannot be stored at all.');
            }
        }
        $this->grant(['capabilities' => $stored]);

        $grant = PlatformAdminGrant::query()->firstOrFail();

        $this->assertSame($expected, $grant->capabilityList());
        foreach (PlatformAdminGrant::CAPABILITIES as $capability) {
            $this->assertSame(in_array($capability, $expected, true), $grant->allows(capability: $capability), $capability);
        }
        $this->assertFalse($grant->allows(capability: 'payments'), 'a part that does not exist is never allowed');
    }

    public function test_a_grant_has_no_end_until_it_is_given_one_and_ends_at_the_very_moment_it_names(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $this->grant(['expires_at' => null]);
        $grant = PlatformAdminGrant::query()->firstOrFail();
        $this->assertFalse($grant->hasExpired());
        $this->assertTrue($grant->isActive());

        foreach ([['2026-11-17 10:00:01', false], ['2026-11-17 10:00:00', true], ['2026-11-17 09:59:59', true]] as [$ends, $expired]) {
            DB::table('platform_admin_grants')->update(['expires_at' => $ends]);
            $grant = PlatformAdminGrant::query()->firstOrFail();

            $this->assertSame($expired, $grant->hasExpired(), $ends);
            $this->assertSame(!$expired, $grant->isActive(), $ends);
        }
    }

    public function test_an_expired_grant_is_withdrawn_by_time_and_a_revoked_one_is_withdrawn_by_the_operator_and_either_is_not_active(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $this->grant(['expires_at' => now()->subDay()]);
        $grant = PlatformAdminGrant::query()->firstOrFail();

        $this->assertTrue($grant->hasExpired());
        $this->assertFalse($grant->isWithdrawn(), 'time ended it, nobody withdrew it');

        DB::table('platform_admin_grants')->update(['revoked_at' => now(), 'expires_at' => now()->addDay()]);
        $grant = PlatformAdminGrant::query()->firstOrFail();
        $this->assertTrue($grant->isWithdrawn());
        $this->assertFalse($grant->hasExpired());
        $this->assertFalse($grant->isActive());

        DB::table('platform_admin_grants')->update(['revoked_at' => null, 'level' => 0]);
        $this->assertTrue(PlatformAdminGrant::query()->firstOrFail()->isWithdrawn(), 'a level that does not exist is withdrawn too');
        $this->assertFalse(PlatformAdminGrant::query()->firstOrFail()->isActive());
    }

    public function test_the_end_of_a_grant_is_read_as_a_moment(): void
    {
        $this->grant(['expires_at' => '2027-03-31 23:59:59']);

        $this->assertSame('2027-03-31 23:59:59', PlatformAdminGrant::query()->firstOrFail()->expires_at->format('Y-m-d H:i:s'));
    }

    /** @param array<string, mixed> $overrides */
    private function grant(array $overrides): void
    {
        DB::table('platform_admin_grants')->insert(array_merge([
            'admin_id' => 7, 'admin_fingerprint' => $this->adminFingerprint(adminId: 7), 'level' => 1, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }
}
