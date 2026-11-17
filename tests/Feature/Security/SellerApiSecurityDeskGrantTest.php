<?php

namespace Tests\Feature\Security;

use App\Models\SellerRole;
use App\Models\SellerStaff;
use App\Services\Marketplace\SellerPermissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSellerApiStaff;
use Tests\TestCase;

/**
 * The seller app's security desk writes roles and staff for the shop. Its write handlers could not run at all (the controller used a class it did
 * not import, so every write answered 500 to the owner and to staff alike), and the two that hand a role to somebody did not tell the service who was
 * writing, so once they ran a staff member who only manages staff could have put themselves, or anybody, in the role that holds every permission
 * (payouts.request, shop_settings.manage): the panel's staff pages were closed that way in the previous round of this pull request.
 *
 * The manager holds only staff.manage. The shop has a role of the owner's that grants payouts.request and one that grants every permission.
 */
class SellerApiSecurityDeskGrantTest extends TestCase
{
    use BuildsSellerApiStaff;

    private const PASSWORD = 'Staff-Chosen-9!x';

    private string $managerToken;
    private int $managerId;
    private int $colleagueId;
    private int $financeColleagueId;
    private int $financeRoleId;
    private int $everythingRoleId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSellerApiWorld();
        $this->createSellerApiStaffTables();
        ['id' => $this->managerId, 'token' => $this->managerToken] = $this->insertSellerApiStaffMember(name: 'manager', permissions: ['staff.manage']);
        $this->colleagueId = $this->insertSellerApiStaffMember(name: 'colleague', permissions: null)['id'];
        $this->financeRoleId = $this->insertSellerApiRole(name: 'Finance', permissions: ['payouts.request']);
        $this->everythingRoleId = $this->insertSellerApiRole(name: 'Everything', permissions: $this->everyPermission());
        $this->financeColleagueId = DB::table('seller_staff')->insertGetId([
            'seller_id' => self::SELLER, 'seller_role_id' => $this->financeRoleId, 'name' => 'accountant', 'email' => 'accountant@alpha.test', 'password' => 'accountants-hash',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownSellerApiWorld();

        parent::tearDown();
    }

    // ---- a staff member who manages staff ----

    public function test_a_staff_member_who_manages_staff_writes_a_role_that_holds_no_more_than_their_own(): void
    {
        $created = $this->sellerApiAsStaff($this->managerToken, 'POST', 'seller-center/security/roles', ['name' => 'Self made', 'permissions' => $this->everyPermission()]);

        $this->assertSame(201, $created->getStatusCode());
        $this->assertSame(['staff.manage'], $this->permissionsOfRoleNamed('Self made'));
    }

    public function test_a_staff_member_who_manages_staff_rewrites_a_role_without_giving_it_more_than_their_own_or_taking_from_it_what_they_do_not_hold(): void
    {
        $rewritten = $this->sellerApiAsStaff($this->managerToken, 'PUT', 'seller-center/security/roles/' . $this->financeRoleId, ['name' => 'Finance', 'permissions' => $this->everyPermission(), 'status' => 'active']);

        $this->assertSame(200, $rewritten->getStatusCode());
        $this->assertEqualsCanonicalizing(['payouts.request', 'staff.manage'], $this->permissionsOfRoleNamed('Finance'));
    }

    public function test_a_staff_member_who_manages_staff_saves_a_richer_role_without_its_holders_losing_what_they_hold(): void
    {
        $saved = $this->sellerApiAsStaff($this->managerToken, 'PUT', 'seller-center/security/roles/' . $this->financeRoleId, ['name' => 'Finance (renamed)', 'permissions' => ['payouts.request'], 'status' => 'active']);

        $this->assertSame(200, $saved->getStatusCode());
        $this->assertSame(['payouts.request'], $this->permissionsOfRoleNamed('Finance (renamed)'));
        $this->assertSame(['payouts.request'], $this->permissionsOf(staffId: $this->financeColleagueId));
    }

    public function test_a_staff_member_who_manages_staff_can_still_deactivate_or_rename_a_colleague_who_holds_a_richer_role(): void
    {
        $response = $this->sellerApiAsStaff($this->managerToken, 'PUT', 'seller-center/security/staff/' . $this->financeColleagueId, [
            'name' => 'Renamed accountant', 'seller_role_id' => $this->financeRoleId, 'status' => 'inactive',
        ]);

        $accountant = SellerStaff::find($this->financeColleagueId);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['Renamed accountant', 'inactive', $this->financeRoleId], [$accountant->name, $accountant->status, (int) $accountant->seller_role_id]);
    }

    public function test_a_staff_member_who_manages_staff_cannot_switch_on_a_colleague_who_holds_a_richer_role(): void
    {
        $this->theOwnerSwitchesTheAccountantOff();

        $response = $this->sellerApiAsStaff($this->managerToken, 'PUT', 'seller-center/security/staff/' . $this->financeColleagueId, $this->staffForm(roleId: $this->financeRoleId));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('seller_role_id', $response->json('errors.0.code'));
        $this->assertSame('inactive', SellerStaff::find($this->financeColleagueId)->status);
    }

    public function test_saving_a_colleague_who_is_switched_off_without_a_status_leaves_them_switched_off(): void
    {
        $this->theOwnerSwitchesTheAccountantOff();

        $response = $this->sellerApiAsStaff($this->managerToken, 'PUT', 'seller-center/security/staff/' . $this->financeColleagueId, ['name' => 'accountant (renamed)', 'seller_role_id' => $this->financeRoleId]);

        $accountant = SellerStaff::find($this->financeColleagueId);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['accountant (renamed)', 'inactive'], [$accountant->name, $accountant->status]);
    }

    public function test_saving_a_colleague_who_is_active_without_a_status_leaves_them_active(): void
    {
        $response = $this->sellerApiAsStaff($this->managerToken, 'PUT', 'seller-center/security/staff/' . $this->financeColleagueId, ['name' => 'accountant (renamed)', 'seller_role_id' => $this->financeRoleId]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('active', SellerStaff::find($this->financeColleagueId)->status);
    }

    /** @return iterable<string, array{0: string}> */
    public static function colleaguesTheManagerMaySwitchOn(): iterable
    {
        yield 'a colleague with no role' => ['no_role'];
        yield 'a colleague whose role grants no more than the manager holds' => ['role_within_the_managers'];
    }

    #[DataProvider('colleaguesTheManagerMaySwitchOn')]
    public function test_a_staff_member_who_manages_staff_can_still_switch_on_a_colleague_who_holds_no_more_than_they_do(string $colleague): void
    {
        $roleId = $colleague === 'no_role' ? null : $this->insertSellerApiRole(name: 'Team leads', permissions: ['staff.manage']);
        $colleagueId = DB::table('seller_staff')->insertGetId([
            'seller_id' => self::SELLER, 'seller_role_id' => $roleId, 'name' => 'former colleague', 'email' => 'former@alpha.test', 'password' => 'former-hash',
            'status' => 'inactive', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->sellerApiAsStaff($this->managerToken, 'PUT', 'seller-center/security/staff/' . $colleagueId, ['name' => 'former colleague', 'seller_role_id' => $roleId, 'status' => 'active']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('active', SellerStaff::find($colleagueId)->status);
    }

    public function test_a_staff_member_who_manages_staff_cannot_set_a_new_password_for_a_colleague_who_holds_a_richer_role(): void
    {
        $response = $this->sellerApiAsStaff($this->managerToken, 'PUT', 'seller-center/security/staff/' . $this->financeColleagueId, [
            'name' => 'accountant', 'seller_role_id' => $this->financeRoleId, 'status' => 'active', 'password' => self::PASSWORD,
        ]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('accountants-hash', SellerStaff::find($this->financeColleagueId)->password);
    }

    public function test_a_staff_member_who_manages_staff_cannot_put_themselves_in_a_role_that_holds_more_than_their_own(): void
    {
        $response = $this->sellerApiAsStaff($this->managerToken, 'PUT', 'seller-center/security/staff/' . $this->managerId, $this->staffForm(roleId: $this->everythingRoleId));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['staff.manage'], $this->permissionsOf(staffId: $this->managerId));
    }

    public function test_a_staff_member_who_manages_staff_cannot_give_somebody_else_a_role_that_holds_more_than_their_own(): void
    {
        $response = $this->sellerApiAsStaff($this->managerToken, 'PUT', 'seller-center/security/staff/' . $this->colleagueId, $this->staffForm(roleId: $this->financeRoleId));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('seller_role_id', $response->json('errors.0.code'));
        $this->assertNull(SellerStaff::find($this->colleagueId)->seller_role_id);
    }

    public function test_a_staff_member_who_manages_staff_cannot_add_somebody_in_a_role_that_holds_more_than_their_own(): void
    {
        $staffBefore = SellerStaff::count();

        $response = $this->sellerApiAsStaff($this->managerToken, 'POST', 'seller-center/security/staff', $this->newStaffForm(roleId: $this->everythingRoleId));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame($staffBefore, SellerStaff::count());
    }

    public function test_a_staff_member_who_manages_staff_still_hands_out_what_their_own_role_grants(): void
    {
        $role = $this->sellerApiAsStaff($this->managerToken, 'POST', 'seller-center/security/roles', ['name' => 'Team leads', 'permissions' => ['staff.manage']]);
        $roleId = (int) $role->json('id');
        $given = $this->sellerApiAsStaff($this->managerToken, 'PUT', 'seller-center/security/staff/' . $this->colleagueId, $this->staffForm(roleId: $roleId));
        $added = $this->sellerApiAsStaff($this->managerToken, 'POST', 'seller-center/security/staff', $this->newStaffForm(roleId: $roleId));

        $this->assertSame([201, 200, 201], [$role->getStatusCode(), $given->getStatusCode(), $added->getStatusCode()]);
        $this->assertSame($roleId, (int) SellerStaff::find($this->colleagueId)->seller_role_id);
        $this->assertSame($roleId, (int) SellerStaff::where('email', 'newcomer@alpha.test')->value('seller_role_id'));
    }

    // ---- the owner ----

    public function test_the_owner_still_hands_out_every_permission(): void
    {
        $role = $this->sellerApi('POST', 'seller-center/security/roles', ['name' => 'Self made', 'permissions' => $this->everyPermission()]);
        $given = $this->sellerApi('PUT', 'seller-center/security/staff/' . $this->colleagueId, $this->staffForm(roleId: (int) $role->json('id')));
        $added = $this->sellerApi('POST', 'seller-center/security/staff', $this->newStaffForm(roleId: $this->everythingRoleId));

        $this->assertSame([201, 200, 201], [$role->getStatusCode(), $given->getStatusCode(), $added->getStatusCode()]);
        $this->assertEqualsCanonicalizing($this->everyPermission(), $this->permissionsOfRoleNamed('Self made'));
        $this->assertEqualsCanonicalizing($this->everyPermission(), $this->permissionsOf(staffId: $this->colleagueId));
        $this->assertSame($this->everythingRoleId, (int) SellerStaff::where('email', 'newcomer@alpha.test')->value('seller_role_id'));
    }

    public function test_the_owner_still_switches_a_colleague_on_whatever_role_they_hold_but_only_when_the_status_says_so(): void
    {
        $this->theOwnerSwitchesTheAccountantOff();

        $renamed = $this->sellerApi('PUT', 'seller-center/security/staff/' . $this->financeColleagueId, ['name' => 'accountant', 'seller_role_id' => $this->financeRoleId]);
        $statusAfterTheRename = SellerStaff::find($this->financeColleagueId)->status;
        $switchedOn = $this->sellerApi('PUT', 'seller-center/security/staff/' . $this->financeColleagueId, $this->staffForm(roleId: $this->financeRoleId));

        $this->assertSame([200, 200], [$renamed->getStatusCode(), $switchedOn->getStatusCode()]);
        $this->assertSame(['inactive', 'active'], [$statusAfterTheRename, SellerStaff::find($this->financeColleagueId)->status]);
    }

    public function test_the_owner_still_takes_every_permission_out_of_a_role_and_sets_a_colleagues_password(): void
    {
        $rewritten = $this->sellerApi('PUT', 'seller-center/security/roles/' . $this->financeRoleId, ['name' => 'Finance', 'permissions' => [], 'status' => 'active']);
        $updated = $this->sellerApi('PUT', 'seller-center/security/staff/' . $this->financeColleagueId, [
            'name' => 'accountant', 'seller_role_id' => $this->everythingRoleId, 'status' => 'active', 'password' => self::PASSWORD,
        ]);

        $this->assertSame([200, 200], [$rewritten->getStatusCode(), $updated->getStatusCode()]);
        $this->assertSame([], $this->permissionsOfRoleNamed('Finance'));
        $this->assertTrue(Hash::check(self::PASSWORD, SellerStaff::find($this->financeColleagueId)->password));
    }

    public function test_the_owner_changes_and_removes_roles_and_staff_through_the_other_write_routes_of_the_desk(): void
    {
        $roleId = (int) $this->sellerApi('POST', 'seller-center/security/roles', ['name' => 'Temporary', 'permissions' => ['orders.view']])->json('id');
        $staffId = (int) $this->sellerApi('POST', 'seller-center/security/staff', $this->newStaffForm(roleId: $roleId))->json('id');

        $roleUpdated = $this->sellerApi('PUT', 'seller-center/security/roles/' . $roleId, ['name' => 'Renamed', 'permissions' => ['orders.view', 'orders.manage'], 'status' => 'active']);
        $staffUpdated = $this->sellerApi('PUT', 'seller-center/security/staff/' . $staffId, ['name' => 'Newcomer', 'seller_role_id' => $roleId, 'status' => 'inactive']);
        $signedOut = $this->sellerApi('POST', 'seller-center/security/staff/' . $staffId . '/sign-out');
        $staffRemoved = $this->sellerApi('DELETE', 'seller-center/security/staff/' . $staffId);
        $roleRemoved = $this->sellerApi('DELETE', 'seller-center/security/roles/' . $roleId);

        $this->assertSame([200, 200, 200, 200, 200], [$roleUpdated->getStatusCode(), $staffUpdated->getStatusCode(), $signedOut->getStatusCode(), $staffRemoved->getStatusCode(), $roleRemoved->getStatusCode()]);
        $this->assertNull(SellerStaff::find($staffId));
        $this->assertNull(SellerRole::find($roleId));
    }

    // ---- helpers ----

    private function theOwnerSwitchesTheAccountantOff(): void
    {
        $response = $this->sellerApi('PUT', 'seller-center/security/staff/' . $this->financeColleagueId, ['name' => 'accountant', 'seller_role_id' => $this->financeRoleId, 'status' => 'inactive']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('inactive', SellerStaff::find($this->financeColleagueId)->status);
    }

    /** @return list<string> */
    private function everyPermission(): array
    {
        return app(SellerPermissionService::class)->allKeys();
    }

    /** @return list<string> what the staff member's role grants right now */
    private function permissionsOf(int $staffId): array
    {
        return app(SellerPermissionService::class)->permissionsOf(SellerStaff::with('role')->findOrFail($staffId));
    }

    /** @return list<string> */
    private function permissionsOfRoleNamed(string $name): array
    {
        return SellerRole::where('name', $name)->firstOrFail()->permissions;
    }

    /** @return array<string, mixed> the whole member, since the update is a full replacement */
    private function staffForm(int $roleId): array
    {
        return ['name' => 'Staff', 'seller_role_id' => $roleId, 'status' => 'active'];
    }

    /** @return array<string, mixed> */
    private function newStaffForm(int $roleId): array
    {
        return ['name' => 'Newcomer', 'email' => 'newcomer@alpha.test', 'password' => self::PASSWORD, 'seller_role_id' => $roleId];
    }
}
