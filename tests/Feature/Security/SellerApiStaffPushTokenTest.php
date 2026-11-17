<?php

namespace Tests\Feature\Security;

use App\Services\Marketplace\SellerPermissionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSellerApiStaff;
use Tests\TestCase;

/**
 * A staff member of the seller app signs in with a token of their own, and the shop it opens is the owner's: the phone push token is one column of
 * the owner's row, so the staff member's phone replaced the phone that receives the shop's order and chat alerts (the panel's web token was closed
 * the same way). Registering a phone is the account holder's alone; the shop's staff have no token of their own to store it in.
 */
class SellerApiStaffPushTokenTest extends TestCase
{
    use BuildsSellerApiStaff;

    private const OWNER_PHONE_TOKEN = 'OWNER-PHONE-TOKEN';
    private const STAFF_PHONE_TOKEN = 'STAFF-PHONE-TOKEN';

    /** @var array<string, array{id: int, token: string}> */
    private array $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSellerApiWorld();
        Schema::table('sellers', static function (Blueprint $table): void {
            $table->string('cm_firebase_token')->nullable();
        });
        DB::table('sellers')->where('id', self::SELLER)->update(['cm_firebase_token' => self::OWNER_PHONE_TOKEN]);
        $this->createSellerApiStaffTables();
        $this->staff = [
            'no role' => $this->insertSellerApiStaffMember(name: 'norole', permissions: null),
            'products.view' => $this->insertSellerApiStaffMember(name: 'productsview', permissions: ['products.view']),
            'staff.manage' => $this->insertSellerApiStaffMember(name: 'manager', permissions: ['staff.manage']),
            'every permission' => $this->insertSellerApiStaffMember(name: 'everything', permissions: app(SellerPermissionService::class)->allKeys()),
        ];
    }

    protected function tearDown(): void
    {
        $this->tearDownSellerApiWorld();

        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function everyKindOfStaff(): array
    {
        return [
            'staff with no role' => ['no role'],
            'staff who view products' => ['products.view'],
            'staff who manage staff' => ['staff.manage'],
            'staff with every permission' => ['every permission'],
        ];
    }

    #[DataProvider('everyKindOfStaff')]
    public function test_a_staff_member_cannot_replace_the_owners_phone_push_token(string $staff): void
    {
        $response = $this->sellerApiAsStaff($this->staff[$staff]['token'], 'PUT', 'cm-firebase-token', ['cm_firebase_token' => self::STAFF_PHONE_TOKEN]);

        $response->assertForbidden();
        $this->assertSame(self::OWNER_PHONE_TOKEN, DB::table('sellers')->where('id', self::SELLER)->value('cm_firebase_token'));
    }

    public function test_the_owner_still_registers_their_phone(): void
    {
        $response = $this->sellerApi('PUT', 'cm-firebase-token', ['cm_firebase_token' => 'OWNER-NEW-PHONE-TOKEN']);

        $response->assertOk();
        $this->assertSame('OWNER-NEW-PHONE-TOKEN', DB::table('sellers')->where('id', self::SELLER)->value('cm_firebase_token'));
    }
}
