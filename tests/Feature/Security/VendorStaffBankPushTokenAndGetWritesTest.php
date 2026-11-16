<?php

namespace Tests\Feature\Security;

use App\Events\OrderStatusEvent;
use App\Services\Marketplace\SellerPermissionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A staff member signs in to the vendor panel as the owner, so what the owner's row holds is reachable through every page that reads or writes it:
 *
 * - the profile index is the bank information card (holder, bank, branch, account number), and any staff member, with no role at all, read it, while the
 *   bank page next to it (profile/update-bank-info) already asks for payouts.request. It asks for the same now;
 * - the web push token is one column of the owner's row, so the browser of any staff member that opened the panel replaced the browser that receives the
 *   shop's order and chat alerts. Saving it is the owner's alone;
 * - the map decides read or write from the HTTP method, but four GET routes change something. Assigning a delivery man (orders) and deleting a product image
 *   (products) were open to the `.view` permission of their domain; the coupon and review status switches have no weaker permission to be open to
 *   (promotions.manage covers the whole coupon area, reviews.view the whole review area), so listing them as writes changes no answer today.
 */
class VendorStaffBankPushTokenAndGetWritesTest extends VendorPanelTestCase
{
    private const ACCOUNT_NUMBER = 'SA0380000000608010167519';
    private const OWNER_TOKEN = 'OWNER-BROWSER-TOKEN';
    private const STAFF_TOKEN = 'STAFF-BROWSER-TOKEN';

    private const ORDER = 100001;
    private const DRIVER = 5;

    /** @var array<string, int> */
    private array $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([OrderStatusEvent::class]);
        $this->buildStaffTables();
        Schema::table('sellers', static function (Blueprint $table): void {
            $table->string('bank_name')->nullable();
            $table->string('branch')->nullable();
            $table->string('holder_name')->nullable();
            $table->string('account_no')->nullable();
            $table->string('cm_firebase_token_web')->nullable();
        });
        DB::table('sellers')->where('id', self::SELLER)->update([
            'bank_name' => 'Al Rajhi', 'branch' => 'Jeddah', 'holder_name' => 'Alpha Pharmacy LLC', 'account_no' => self::ACCOUNT_NUMBER, 'cm_firebase_token_web' => self::OWNER_TOKEN,
        ]);
        $this->app->instance('firebase.messaging', new class {
        });
        $this->buildAnOrderAndADriver();

        $this->staff = [
            'no role' => $this->insertStaffMember(name: 'norole', permissions: null),
            'an empty role' => $this->insertStaffMember(name: 'empty', permissions: []),
            'products.view' => $this->insertStaffMember(name: 'productsview', permissions: ['products.view']),
            'products.manage' => $this->insertStaffMember(name: 'productsmanage', permissions: ['products.manage']),
            'orders.view' => $this->insertStaffMember(name: 'ordersview', permissions: ['orders.view']),
            'orders.manage' => $this->insertStaffMember(name: 'ordersmanage', permissions: ['orders.manage']),
            'finance.view' => $this->insertStaffMember(name: 'financeview', permissions: ['finance.view']),
            'payouts.request' => $this->insertStaffMember(name: 'payouts', permissions: ['payouts.request']),
            'every permission but payouts.request' => $this->insertStaffMember(name: 'almost', permissions: array_values(array_diff(app(SellerPermissionService::class)->allKeys(), ['payouts.request']))),
            'every permission' => $this->insertStaffMember(name: 'everything', permissions: app(SellerPermissionService::class)->allKeys()),
        ];
    }

    /** @return array<string, array{string}> the staff whose role does not grant payouts.request, whatever else it grants */
    public static function staffWithoutPayouts(): array
    {
        return [
            'staff with no role' => ['no role'],
            'staff with an empty role' => ['an empty role'],
            'staff who view products' => ['products.view'],
            'staff who view finance' => ['finance.view'],
            'staff with every permission but payouts.request' => ['every permission but payouts.request'],
        ];
    }

    // ---- the bank information card ----

    #[DataProvider('staffWithoutPayouts')]
    public function test_a_staff_member_without_payouts_cannot_read_the_owners_bank_information_on_the_profile_index(string $staff): void
    {
        $response = $this->signInAsStaff($this->staff[$staff])->get('/vendor/profile/index');

        $response->assertForbidden();
        $this->assertStringNotContainsString(self::ACCOUNT_NUMBER, (string)$response->getContent());
    }

    public function test_a_staff_member_who_requests_payouts_still_reads_the_bank_information(): void
    {
        $this->signInAsStaff($this->staff['payouts.request'])->get('/vendor/profile/index')->assertOk()->assertSee(self::ACCOUNT_NUMBER);
    }

    public function test_the_owner_still_reads_the_bank_information(): void
    {
        $this->signInAs(self::SELLER)->get('/vendor/profile/index')->assertOk()->assertSee(self::ACCOUNT_NUMBER);
    }

    #[DataProvider('staffWithoutPayouts')]
    public function test_the_profile_form_stays_open_to_a_staff_member_without_payouts(string $staff): void
    {
        $response = $this->signInAsStaff($this->staff[$staff])->get('/vendor/profile/update/' . self::SELLER);

        $response->assertOk();
        $this->assertStringNotContainsString(self::ACCOUNT_NUMBER, (string)$response->getContent());
    }

    // ---- the web push token ----

    /** @return array<string, array{string}> */
    public static function everyKindOfStaff(): array
    {
        return [
            'staff with no role' => ['no role'],
            'staff who view products' => ['products.view'],
            'staff who request payouts' => ['payouts.request'],
            'staff with every permission' => ['every permission'],
        ];
    }

    #[DataProvider('everyKindOfStaff')]
    public function test_a_staff_member_cannot_replace_the_owners_web_push_token(string $staff): void
    {
        $this->signInAsStaff($this->staff[$staff])->postJson('/vendor/system/save-fcm-web-token', ['token' => self::STAFF_TOKEN])->assertForbidden();

        $this->assertSame(self::OWNER_TOKEN, DB::table('sellers')->where('id', self::SELLER)->value('cm_firebase_token_web'));
    }

    public function test_the_owner_still_saves_the_web_push_token(): void
    {
        $this->signInAs(self::SELLER)->postJson('/vendor/system/save-fcm-web-token', ['token' => 'OWNER-NEW-BROWSER-TOKEN'])->assertOk();

        $this->assertSame('OWNER-NEW-BROWSER-TOKEN', DB::table('sellers')->where('id', self::SELLER)->value('cm_firebase_token_web'));
    }

    // ---- GET routes that change something ----

    public function test_a_staff_member_who_only_views_orders_cannot_hand_an_order_to_a_driver(): void
    {
        $this->signInAsStaff($this->staff['orders.view'])->getJson('/vendor/orders/add-delivery-man/' . self::ORDER . '/' . self::DRIVER)->assertForbidden();

        $this->assertNull(DB::table('orders')->where('id', self::ORDER)->value('delivery_man_id'));
    }

    public function test_a_staff_member_who_manages_orders_still_hands_an_order_to_a_driver(): void
    {
        $this->signInAsStaff($this->staff['orders.manage'])->getJson('/vendor/orders/add-delivery-man/' . self::ORDER . '/' . self::DRIVER)->assertOk()->assertJson(['status' => true]);

        $this->assertSame(self::DRIVER, (int)DB::table('orders')->where('id', self::ORDER)->value('delivery_man_id'));
    }

    public function test_the_owner_still_hands_an_order_to_a_driver(): void
    {
        $this->signInAs(self::SELLER)->getJson('/vendor/orders/add-delivery-man/' . self::ORDER . '/' . self::DRIVER)->assertOk()->assertJson(['status' => true]);

        $this->assertSame(self::DRIVER, (int)DB::table('orders')->where('id', self::ORDER)->value('delivery_man_id'));
    }

    public function test_a_staff_member_who_only_views_products_cannot_delete_a_product_image(): void
    {
        $this->signInAsStaff($this->staff['products.view'])->get('/vendor/products/delete-image?id=' . self::OWN_PRODUCT . '&image=a.png&name=a.png')->assertForbidden();
    }

    public function test_a_staff_member_who_manages_products_still_reaches_the_delete_image_handler(): void
    {
        $this->signInAsStaff($this->staff['products.manage'])->get('/vendor/products/delete-image?id=' . self::OWN_PRODUCT . '&image=a.png&name=a.png')->assertRedirect();

        $this->assertSame(['warning'], $this->toastTypes(), 'the handler answered: the product has no image to spare');
    }

    private function buildAnOrderAndADriver(): void
    {
        Schema::table('orders', static function (Blueprint $table): void {
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->string('delivery_type')->nullable();
            $table->string('delivery_service_name')->nullable();
            $table->string('third_party_delivery_tracking_id')->nullable();
            $table->dateTime('deliveryman_assigned_at')->nullable();
            $table->string('shipping_responsibility')->nullable();
        });
        Schema::create('delivery_men', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id')->default(0);
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('fcm_token')->nullable();
            $table->boolean('is_active')->default(1);
            $table->timestamps();
        });
        DB::table('delivery_men')->insert(['id' => self::DRIVER, 'seller_id' => self::SELLER, 'f_name' => 'Own', 'l_name' => 'Driver', 'phone' => '0500000005']);
        DB::table('orders')->insert([
            'id' => self::ORDER, 'customer_id' => 7, 'seller_is' => 'seller', 'seller_id' => self::SELLER, 'order_status' => 'confirmed', 'shipping_responsibility' => 'sellerwise_shipping',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
