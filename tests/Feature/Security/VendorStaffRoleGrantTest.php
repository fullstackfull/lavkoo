<?php

namespace Tests\Feature\Security;

use App\Models\SellerRole;
use App\Models\SellerStaff;
use App\Services\Marketplace\SellerPermissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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
    private int $financeColleagueId;
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
        $this->financeColleagueId = DB::table('seller_staff')->insertGetId([
            'seller_id' => self::SELLER, 'seller_role_id' => $this->financeRoleId, 'name' => 'accountant', 'email' => 'accountant@alpha.test', 'password' => 'accountants-hash',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_staff_member_who_manages_staff_writes_a_role_that_holds_no_more_than_their_own(): void
    {
        $this->signInAsStaff($this->managerId)->post('/vendor/business-settings/staff/roles', ['name' => 'Self made', 'permissions' => $this->everyPermission()])->assertRedirect();

        $this->assertSame(['staff.manage'], $this->permissionsOfRoleNamed('Self made'));
    }

    public function test_a_staff_member_who_manages_staff_rewrites_a_role_without_giving_it_more_than_their_own_or_taking_from_it_what_they_do_not_hold(): void
    {
        $this->signInAsStaff($this->managerId)->put('/vendor/business-settings/staff/roles/' . $this->financeRoleId, [
            'name' => 'Finance', 'permissions' => $this->everyPermission(), 'status' => 'active',
        ])->assertRedirect();

        $this->assertEqualsCanonicalizing(['payouts.request', 'staff.manage'], $this->permissionsOfRoleNamed('Finance'));
    }

    public function test_a_staff_member_who_manages_staff_saves_a_richer_role_without_its_holders_losing_what_they_hold(): void
    {
        $this->signInAsStaff($this->managerId)->put('/vendor/business-settings/staff/roles/' . $this->financeRoleId, [
            'name' => 'Finance (renamed)', 'permissions' => ['payouts.request'], 'status' => 'active',
        ])->assertRedirect();

        $this->assertSame(['payouts.request'], $this->permissionsOfRoleNamed('Finance (renamed)'));
        $this->assertSame(['payouts.request'], $this->permissionsOf(staffId: $this->financeColleagueId));
    }

    public function test_a_staff_member_who_manages_staff_can_still_take_a_permission_they_hold_out_of_a_role(): void
    {
        $roleId = $this->insertSellerRole(name: 'Mixed', permissions: ['staff.manage', 'payouts.request']);

        $this->signInAsStaff($this->managerId)->put('/vendor/business-settings/staff/roles/' . $roleId, ['name' => 'Mixed', 'permissions' => [], 'status' => 'active'])->assertRedirect();

        $this->assertSame(['payouts.request'], $this->permissionsOfRoleNamed('Mixed'));
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

    public function test_a_staff_member_who_manages_staff_can_still_deactivate_or_rename_a_colleague_who_holds_a_richer_role(): void
    {
        $this->signInAsStaff($this->managerId)->put('/vendor/business-settings/staff/' . $this->financeColleagueId, [
            'name' => 'Renamed accountant', 'seller_role_id' => $this->financeRoleId, 'status' => 'inactive',
        ])->assertRedirect();

        $this->assertSame(['success'], $this->toastTypes());
        $accountant = SellerStaff::find($this->financeColleagueId);
        $this->assertSame(['Renamed accountant', 'inactive', $this->financeRoleId], [$accountant->name, $accountant->status, (int) $accountant->seller_role_id]);
    }

    public function test_a_staff_member_who_manages_staff_cannot_set_a_new_password_for_a_colleague_who_holds_a_richer_role(): void
    {
        $this->signInAsStaff($this->managerId)->put('/vendor/business-settings/staff/' . $this->financeColleagueId, [
            'name' => 'accountant', 'seller_role_id' => $this->financeRoleId, 'status' => 'active', 'password' => self::PASSWORD,
        ])->assertRedirect();

        $this->assertSame(['error'], $this->toastTypes());
        $this->assertSame('accountants-hash', SellerStaff::find($this->financeColleagueId)->password);
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

    public function test_the_owner_still_takes_every_permission_out_of_a_role_and_sets_a_colleagues_password(): void
    {
        $this->signInAs(self::SELLER);

        $this->put('/vendor/business-settings/staff/roles/' . $this->financeRoleId, ['name' => 'Finance', 'permissions' => [], 'status' => 'active'])->assertRedirect();
        $this->put('/vendor/business-settings/staff/' . $this->financeColleagueId, [
            'name' => 'accountant', 'seller_role_id' => $this->everythingRoleId, 'status' => 'active', 'password' => self::PASSWORD,
        ])->assertRedirect();

        $this->assertSame([], $this->permissionsOfRoleNamed('Finance'));
        $this->assertTrue(Hash::check(self::PASSWORD, SellerStaff::find($this->financeColleagueId)->password));
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
