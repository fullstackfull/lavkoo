<?php

namespace Tests\Feature\Security;

use App\Models\SellerRole;
use App\Models\SellerStaff;
use App\Services\Marketplace\SellerPermissionService;
use Illuminate\Support\Facades\DB;

/**
 * The panel handed SellerTeamService no principal ("the panel is the owner's own door, so nothing is withheld"), but the staff middleware lets any
 * staff member with staff.manage through that door: they wrote a role holding every permission and put themselves in it, and so held payouts.request
 * (bank and payment information, withdrawals) and shop_settings.manage, which their own role never granted. The seller app's API narrows a role to
 * what its writer holds; the service now does the same for the panel, for writing a role and for giving a role to somebody.
 *
 * The manager holds only staff.manage. The shop has a role of the owner's that grants payouts.request and one that grants every permission.
 */
class VendorStaffRoleGrantTest extends VendorPanelTestCase
{
    private const PASSWORD = 'Staff-Chosen-9!x';

    private int $managerId;
    private int $colleagueId;
    private int $financeRoleId;
    private int $everythingRoleId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStaffTables();
        $this->managerId = $this->insertStaffMember(name: 'manager', permissions: ['staff.manage']);
        $this->colleagueId = $this->insertStaffMember(name: 'colleague', permissions: null);
        $this->financeRoleId = $this->insertSellerRole(name: 'Finance', permissions: ['payouts.request']);
        $this->everythingRoleId = $this->insertSellerRole(name: 'Everything', permissions: $this->everyPermission());
    }

    public function test_a_staff_member_who_manages_staff_writes_a_role_that_holds_no_more_than_their_own(): void
    {
        $this->signInAsStaff($this->managerId)->post('/vendor/business-settings/staff/roles', ['name' => 'Self made', 'permissions' => $this->everyPermission()])->assertRedirect();

        $this->assertSame(['staff.manage'], $this->permissionsOfRoleNamed('Self made'));
    }

    public function test_a_staff_member_who_manages_staff_rewrites_a_role_to_hold_no_more_than_their_own(): void
    {
        $this->signInAsStaff($this->managerId)->put('/vendor/business-settings/staff/roles/' . $this->financeRoleId, [
            'name' => 'Finance', 'permissions' => $this->everyPermission(), 'status' => 'active',
        ])->assertRedirect();

        $this->assertSame(['staff.manage'], $this->permissionsOfRoleNamed('Finance'));
    }

    public function test_a_staff_member_who_manages_staff_cannot_put_themselves_in_a_role_that_holds_more_than_their_own(): void
    {
        $this->signInAsStaff($this->managerId)->put('/vendor/business-settings/staff/' . $this->managerId, $this->staffForm(roleId: $this->everythingRoleId))->assertRedirect();

        $this->assertSame(['error'], $this->toastTypes());
        $this->assertSame(['staff.manage'], $this->permissionsOf(staffId: $this->managerId));
    }

    public function test_a_staff_member_who_manages_staff_cannot_give_somebody_else_a_role_that_holds_more_than_their_own(): void
    {
        $this->signInAsStaff($this->managerId)->put('/vendor/business-settings/staff/' . $this->colleagueId, $this->staffForm(roleId: $this->financeRoleId))->assertRedirect();

        $this->assertSame(['error'], $this->toastTypes());
        $this->assertNull(SellerStaff::find($this->colleagueId)->seller_role_id);
    }

    public function test_a_staff_member_who_manages_staff_cannot_add_somebody_in_a_role_that_holds_more_than_their_own(): void
    {
        $staffBefore = SellerStaff::count();

        $this->signInAsStaff($this->managerId)->post('/vendor/business-settings/staff', $this->newStaffForm(roleId: $this->everythingRoleId))->assertRedirect();

        $this->assertSame(['error'], $this->toastTypes());
        $this->assertSame($staffBefore, SellerStaff::count());
    }

    public function test_the_self_granted_role_of_the_review_gives_nothing_that_the_staff_member_did_not_hold(): void
    {
        $this->signInAsStaff($this->managerId);
        $this->post('/vendor/business-settings/staff/roles', ['name' => 'Self made', 'permissions' => $this->everyPermission()])->assertRedirect();
        $this->put('/vendor/business-settings/staff/' . $this->managerId, $this->staffForm(roleId: (int)SellerRole::where('name', 'Self made')->value('id')))->assertRedirect();

        $this->assertSame(['staff.manage'], $this->permissionsOf(staffId: $this->managerId));
        $this->get('/vendor/business-settings/withdraw/index')->assertForbidden();
        $this->post('/vendor/shop/update/' . self::SELLERS_SHOP, ['name' => 'Taken', 'company_phone' => '0559998888', 'country_code' => '+966', 'address' => 'Anywhere'])->assertForbidden();
        $this->assertSame('Alpha Pharmacy', DB::table('shops')->where('id', self::SELLERS_SHOP)->value('name'));
    }

    public function test_a_staff_member_who_manages_staff_still_hands_out_what_their_own_role_grants(): void
    {
        $this->signInAsStaff($this->managerId);

        $this->post('/vendor/business-settings/staff/roles', ['name' => 'Team leads', 'permissions' => ['staff.manage']])->assertRedirect();
        $roleId = (int)SellerRole::where('name', 'Team leads')->value('id');
        $this->put('/vendor/business-settings/staff/' . $this->colleagueId, $this->staffForm(roleId: $roleId))->assertRedirect();
        $this->post('/vendor/business-settings/staff', $this->newStaffForm(roleId: $roleId))->assertRedirect();

        $this->assertSame(['staff.manage'], $this->permissionsOfRoleNamed('Team leads'));
        $this->assertSame($roleId, (int)SellerStaff::find($this->colleagueId)->seller_role_id);
        $this->assertSame($roleId, (int)SellerStaff::where('email', 'newcomer@alpha.test')->value('seller_role_id'));
    }

    public function test_the_owner_still_hands_out_every_permission(): void
    {
        $this->signInAs(self::SELLER);

        $this->post('/vendor/business-settings/staff/roles', ['name' => 'Self made', 'permissions' => $this->everyPermission()])->assertRedirect();
        $roleId = (int)SellerRole::where('name', 'Self made')->value('id');
        $this->put('/vendor/business-settings/staff/' . $this->colleagueId, $this->staffForm(roleId: $roleId))->assertRedirect();
        $this->post('/vendor/business-settings/staff', $this->newStaffForm(roleId: $this->everythingRoleId))->assertRedirect();

        $this->assertEqualsCanonicalizing($this->everyPermission(), $this->permissionsOfRoleNamed('Self made'));
        $this->assertEqualsCanonicalizing($this->everyPermission(), $this->permissionsOf(staffId: $this->colleagueId));
        $this->assertSame($this->everythingRoleId, (int)SellerStaff::where('email', 'newcomer@alpha.test')->value('seller_role_id'));
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

    /** @return array<string, mixed> what the staff form puts a member in: the whole member, since the update is a full replacement */
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
