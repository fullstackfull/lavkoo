<?php

namespace Tests\Feature\Security;

use App\Models\SellerRole;
use App\Models\SellerStaff;
use App\Services\Marketplace\SellerPermissionService;
use Illuminate\Support\Facades\DB;
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
        $rewritten = $this->sellerApiAsStaff($this->managerToken, 'PUT', 'seller-center/security/roles/' . $this->financeRoleId, ['name' => 'Finance', 'permissions' => $this->everyPermission(), 'status' => 'active']);

        $this->assertSame(201, $created->getStatusCode());
        $this->assertSame(200, $rewritten->getStatusCode());
        $this->assertSame(['staff.manage'], $this->permissionsOfRoleNamed('Self made'));
        $this->assertSame(['staff.manage'], $this->permissionsOfRoleNamed('Finance'));
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
