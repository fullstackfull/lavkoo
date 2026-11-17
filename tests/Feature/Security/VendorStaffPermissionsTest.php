<?php

namespace Tests\Feature\Security;

use App\Services\Marketplace\SellerPermissionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A staff member signs in to the vendor panel AS the owner (the staff login stamps seller_staff_id on the session), so every handler that
 * scopes on auth('seller') acts on the owner's account for them, and SellerStaffAccessMiddleware is the only thing that decides what a staff
 * member may do. It allowed the `profile` and `shop` areas to every active staff row, however little their role grants: a staff member with
 * no role at all could reset the owner's password, and so take the account, and close the shop or change its details. The seller app's API
 * refuses the same operations: `seller_owner` for the account, `seller_can:shop_settings.manage` for the shop.
 *
 * Reading the profile and the shop page stays open to every staff member (it is how they see which shop they work for); changing the owner's
 * profile or password takes the account holder, and changing the shop takes shop_settings.manage.
 *
 * Those handlers ignore the {id} of their address, so what it says must not matter either: the money rule of the middleware used to read the whole
 * path, and a role that only requests payouts (or holds every permission) passed the profile and shop writes by putting `payout` in the id.
 */
class VendorStaffPermissionsTest extends VendorPanelTestCase
{
    private const STAFF_WITHOUT_ROLE = 5;
    private const STAFF_WHO_MANAGES_PRODUCTS = 6;
    private const STAFF_WHO_MANAGES_THE_SHOP = 7;
    private const STAFF_WITH_EVERY_PERMISSION = 8;
    private const STAFF_WHO_ONLY_REQUESTS_PAYOUTS = 9;

    private const NEW_PASSWORD = 'Staff-Chosen-9!x';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::table(table: 'shops', callback: static function (Blueprint $table): void {
            $table->string('tax_identification_number')->nullable();
            $table->dateTime('tin_expire_date')->nullable();
            $table->string('tin_certificate')->nullable();
            $table->string('tin_certificate_storage_type')->nullable();
        });
        $this->buildStaffTables();

        $this->insertRole(roleId: 1, permissions: ['products.view', 'products.manage']);
        $this->insertRole(roleId: 2, permissions: ['shop_settings.manage']);
        $this->insertRole(roleId: 3, permissions: app(SellerPermissionService::class)->allKeys());
        $this->insertRole(roleId: 4, permissions: ['payouts.request']);
        $this->insertStaff(staffId: self::STAFF_WITHOUT_ROLE, roleId: null);
        $this->insertStaff(staffId: self::STAFF_WHO_MANAGES_PRODUCTS, roleId: 1);
        $this->insertStaff(staffId: self::STAFF_WHO_MANAGES_THE_SHOP, roleId: 2);
        $this->insertStaff(staffId: self::STAFF_WITH_EVERY_PERMISSION, roleId: 3);
        $this->insertStaff(staffId: self::STAFF_WHO_ONLY_REQUESTS_PAYOUTS, roleId: 4);
    }

    /** @return array<string, array{int}> every kind of staff member, including the one whose role grants every permission there is */
    public static function everyStaffMember(): array
    {
        return [
            'staff with no role' => [self::STAFF_WITHOUT_ROLE],
            'staff who manage products' => [self::STAFF_WHO_MANAGES_PRODUCTS],
            'staff who manage the shop' => [self::STAFF_WHO_MANAGES_THE_SHOP],
            'staff with every permission' => [self::STAFF_WITH_EVERY_PERMISSION],
            'staff who only request payouts' => [self::STAFF_WHO_ONLY_REQUESTS_PAYOUTS],
        ];
    }

    /** @return array<string, array{int}> the staff whose role does not grant shop_settings.manage */
    public static function staffWithoutShopSettings(): array
    {
        return [
            'staff with no role' => [self::STAFF_WITHOUT_ROLE],
            'staff who manage products' => [self::STAFF_WHO_MANAGES_PRODUCTS],
            'staff who only request payouts' => [self::STAFF_WHO_ONLY_REQUESTS_PAYOUTS],
        ];
    }

    /** @return array<string, array{int}> the staff whose role grants shop_settings.manage */
    public static function staffWithShopSettings(): array
    {
        return [
            'staff who manage the shop' => [self::STAFF_WHO_MANAGES_THE_SHOP],
            'staff with every permission' => [self::STAFF_WITH_EVERY_PERMISSION],
        ];
    }

    /**
     * @return array<string, array{string}> what the {id} of profile/update/{id} and shop/update/{id} can say: those handlers ignore it and act on
     *                                       the signed-in seller, so no word in it may change which rule the middleware applies
     */
    public static function idsOfTheProfileAndShopForms(): array
    {
        return [
            'a plain id' => ['1'],
            'payout' => ['payout'],
            'withdraw' => ['withdraw'],
            'payment-information' => ['payment-information'],
            'bank-info' => ['bank-info'],
            'money-withdraw' => ['money-withdraw'],
        ];
    }

    // ---- the owner's own account ----

    #[DataProvider('everyStaffMember')]
    public function test_a_staff_member_cannot_change_the_owners_password_whatever_their_role(int $staffId): void
    {
        $before = $this->ownerPasswordHash();

        $this->signInAsStaff($staffId)->patchJson('/vendor/profile/update/' . self::SELLER, $this->passwordForm())->assertForbidden();

        $this->assertSame($before, $this->ownerPasswordHash());
        $this->assertTrue(Hash::check(self::OWN_PASSWORD, $this->ownerPasswordHash()), 'the owner can still sign in with their own password');
    }

    #[DataProvider('everyStaffMember')]
    public function test_a_staff_member_cannot_change_the_owners_profile_whatever_their_role(int $staffId): void
    {
        $this->signInAsStaff($staffId)->postJson('/vendor/profile/update/' . self::SELLER, $this->profileForm())->assertForbidden();

        $this->assertSame(['Alpha', 'Pharmacy', '050000001'], $this->ownerProfile());
    }

    public function test_the_owner_can_still_change_their_own_password(): void
    {
        $this->signInAs(self::SELLER)->patchJson('/vendor/profile/update/' . self::SELLER, $this->passwordForm())
            ->assertOk()->assertJsonStructure(['message']);

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $this->ownerPasswordHash()));
    }

    public function test_the_owner_can_still_change_their_own_profile(): void
    {
        $this->signInAs(self::SELLER)->postJson('/vendor/profile/update/' . self::SELLER, $this->profileForm())->assertOk()->assertJsonStructure(['message']);

        $this->assertSame(['Renamed', 'Owner', '0509998888'], $this->ownerProfile());
    }

    // ---- the shop ----

    #[DataProvider('staffWithoutShopSettings')]
    public function test_a_staff_member_without_shop_settings_cannot_change_the_shop(int $staffId): void
    {
        $before = $this->shopAndSettings();

        $this->signInAsStaff($staffId);
        $this->post('/vendor/shop/close-shop-temporary', ['id' => self::SELLERS_SHOP, 'status' => 0])->assertForbidden();
        $this->post('/vendor/shop/add-vacation', $this->vacationForm())->assertForbidden();
        $this->post('/vendor/shop/update/' . self::SELLERS_SHOP, $this->shopForm())->assertForbidden();
        $this->post('/vendor/shop/update-other-settings', $this->otherSettingsForm())->assertForbidden();

        $this->assertSame($before, $this->shopAndSettings());
    }

    #[DataProvider('staffWithShopSettings')]
    public function test_a_staff_member_with_shop_settings_can_still_change_the_shop(int $staffId): void
    {
        $this->signInAsStaff($staffId);

        $this->post('/vendor/shop/close-shop-temporary', ['id' => self::SELLERS_SHOP, 'status' => 0])->assertRedirect();
        $this->post('/vendor/shop/add-vacation', $this->vacationForm())->assertRedirect();
        $this->post('/vendor/shop/update/' . self::SELLERS_SHOP, $this->shopForm())->assertRedirect(route('vendor.shop.index'));
        $this->post('/vendor/shop/update-other-settings', $this->otherSettingsForm())->assertRedirect();

        $this->assertShopWasChanged();
    }

    public function test_the_owner_can_still_change_the_shop(): void
    {
        $this->signInAs(self::SELLER);

        $this->post('/vendor/shop/close-shop-temporary', ['id' => self::SELLERS_SHOP, 'status' => 0])->assertRedirect();
        $this->post('/vendor/shop/add-vacation', $this->vacationForm())->assertRedirect();
        $this->post('/vendor/shop/update/' . self::SELLERS_SHOP, $this->shopForm())->assertRedirect(route('vendor.shop.index'));
        $this->post('/vendor/shop/update-other-settings', $this->otherSettingsForm())->assertRedirect();

        $this->assertShopWasChanged();
    }

    // ---- a word in the id of the profile and shop forms ----

    #[DataProvider('idsOfTheProfileAndShopForms')]
    public function test_a_staff_member_who_only_requests_payouts_cannot_change_the_owners_account_whatever_the_id_says(string $id): void
    {
        $before = $this->ownerPasswordHash();

        $this->signInAsStaff(self::STAFF_WHO_ONLY_REQUESTS_PAYOUTS);
        $this->patchJson('/vendor/profile/update/' . $id, $this->passwordForm())->assertForbidden();
        $this->postJson('/vendor/profile/update/' . $id, $this->profileForm())->assertForbidden();

        $this->assertSame($before, $this->ownerPasswordHash());
        $this->assertSame(['Alpha', 'Pharmacy', '050000001'], $this->ownerProfile());
    }

    #[DataProvider('idsOfTheProfileAndShopForms')]
    public function test_a_staff_member_with_every_permission_cannot_change_the_owners_account_whatever_the_id_says(string $id): void
    {
        $before = $this->ownerPasswordHash();

        $this->signInAsStaff(self::STAFF_WITH_EVERY_PERMISSION);
        $this->patchJson('/vendor/profile/update/' . $id, $this->passwordForm())->assertForbidden();
        $this->postJson('/vendor/profile/update/' . $id, $this->profileForm())->assertForbidden();

        $this->assertSame($before, $this->ownerPasswordHash());
        $this->assertSame(['Alpha', 'Pharmacy', '050000001'], $this->ownerProfile());
    }

    #[DataProvider('idsOfTheProfileAndShopForms')]
    public function test_a_staff_member_who_only_requests_payouts_cannot_change_the_shop_whatever_the_id_says(string $id): void
    {
        $before = $this->shopAndSettings();

        $this->signInAsStaff(self::STAFF_WHO_ONLY_REQUESTS_PAYOUTS)->post('/vendor/shop/update/' . $id, $this->shopForm())->assertForbidden();

        $this->assertSame($before, $this->shopAndSettings());
    }

    #[DataProvider('idsOfTheProfileAndShopForms')]
    public function test_the_owner_still_changes_their_account_whatever_the_id_says(string $id): void
    {
        $this->signInAs(self::SELLER);

        $this->patchJson('/vendor/profile/update/' . $id, $this->passwordForm())->assertOk();
        $this->postJson('/vendor/profile/update/' . $id, $this->profileForm())->assertOk();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $this->ownerPasswordHash()));
        $this->assertSame(['Renamed', 'Owner', '0509998888'], $this->ownerProfile());
    }

    #[DataProvider('idsOfTheProfileAndShopForms')]
    public function test_a_staff_member_with_shop_settings_still_changes_the_shop_whatever_the_id_says(string $id): void
    {
        $this->signInAsStaff(self::STAFF_WHO_MANAGES_THE_SHOP)->post('/vendor/shop/update/' . $id, $this->shopForm())->assertRedirect(route('vendor.shop.index'));

        $this->assertSame('Alpha Renamed', $this->shopAndSettings()['shop']['name']);
    }

    // ---- what stays open to every staff member ----

    #[DataProvider('everyStaffMember')]
    public function test_every_staff_member_can_still_read_the_shop_and_the_profile(int $staffId): void
    {
        $this->signInAsStaff($staffId);

        $this->get('/vendor/shop/update/' . self::SELLERS_SHOP)->assertOk()->assertSee('Alpha Pharmacy');
        $this->get('/vendor/profile/update/' . self::SELLER)->assertOk()->assertSee('Alpha');
    }

    public function test_a_staff_member_is_still_refused_what_their_role_does_not_grant(): void
    {
        $this->signInAsStaff(self::STAFF_WITHOUT_ROLE)->get('/vendor/products/list/all')->assertForbidden();
    }

    /** @param list<string> $permissions */
    private function insertRole(int $roleId, array $permissions): void
    {
        DB::table('seller_roles')->insert([
            'id' => $roleId, 'seller_id' => self::SELLER, 'name' => 'Role ' . $roleId, 'permissions' => json_encode($permissions), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertStaff(int $staffId, ?int $roleId): void
    {
        DB::table('seller_staff')->insert([
            'id' => $staffId, 'seller_id' => self::SELLER, 'seller_role_id' => $roleId, 'name' => 'Staff ' . $staffId, 'email' => 'staff' . $staffId . '@alpha.test',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function ownerPasswordHash(): string
    {
        return (string) DB::table('sellers')->where('id', self::SELLER)->value('password');
    }

    /** @return list<string> the first name, last name and phone of the owner */
    private function ownerProfile(): array
    {
        $owner = DB::table('sellers')->where('id', self::SELLER)->first();

        return [$owner->f_name, $owner->l_name, $owner->phone];
    }

    /** @return array<string, mixed> the shop's row and the seller's own settings the shop pages write, without the timestamps */
    private function shopAndSettings(): array
    {
        return [
            'shop' => Arr::except((array) DB::table('shops')->where('id', self::SELLERS_SHOP)->first(), ['updated_at']),
            'stock_limit' => (int) DB::table('sellers')->where('id', self::SELLER)->value('stock_limit'),
        ];
    }

    private function assertShopWasChanged(): void
    {
        $changed = $this->shopAndSettings();

        $this->assertSame(1, (int) $changed['shop']['temporary_close'], 'the shop is closed for customers');
        $this->assertSame([1, 'Back soon'], [(int) $changed['shop']['vacation_status'], $changed['shop']['vacation_note']]);
        $this->assertSame(['Alpha Renamed', 'TIN-1'], [$changed['shop']['name'], $changed['shop']['tax_identification_number']]);
        $this->assertSame(12, $changed['stock_limit']);
    }

    /** @return array<string, string> a valid password form: the panel posts the new password twice */
    private function passwordForm(): array
    {
        return ['password' => self::NEW_PASSWORD, 'confirm_password' => self::NEW_PASSWORD];
    }

    /** @return array<string, string> a valid profile form */
    private function profileForm(): array
    {
        return ['f_name' => 'Renamed', 'l_name' => 'Owner', 'phone' => '0509998888'];
    }

    /** @return array<string, string> a valid shop form: the vendor panel always posts these four */
    private function shopForm(): array
    {
        return ['name' => 'Alpha Renamed', 'company_phone' => '0559998888', 'country_code' => '+966', 'address' => 'New Address 9, Riyadh'];
    }

    /** @return array<string, string|int> the vacation form with the switch on */
    private function vacationForm(): array
    {
        return [
            'id' => self::SELLERS_SHOP, 'vacation_status' => 1, 'vacation_duration_type' => 'custom',
            'vacation_start_date' => '2030-01-10 00:00:00', 'vacation_end_date' => '2030-01-20 00:00:00', 'vacation_note' => 'Back soon',
        ];
    }

    /** @return array<string, string|int> the order setup form: a stock limit and a tax number */
    private function otherSettingsForm(): array
    {
        return ['stock_limit' => 12, 'tax_identification_number' => 'TIN-1'];
    }
}
