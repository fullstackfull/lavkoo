<?php

namespace Tests\Feature\Security;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSellerApiWorld;
use Tests\TestCase;

/**
 * A list the seller app scopes to the caller (their emergency contacts, their delivery men) takes a search term. The conditions the term
 * adds must stay inside that scope: a term that matched another seller's row, or the in-house shop's, would list rows the seller has no
 * business reading, with their phone numbers and identity numbers. The coupon list's search is tested with the coupons.
 */
class SellerApiSearchScopeTest extends TestCase
{
    use BuildsSellerApiWorld;

    private const IN_HOUSE_OWNER_ID = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSellerApiWorld();
        $this->createEmergencyContactTable();
        $this->createDeliveryManTables();
        $this->seedEmergencyContacts();
        $this->seedDeliveryMen();
    }

    protected function tearDown(): void
    {
        $this->tearDownSellerApiWorld();

        parent::tearDown();
    }

    // ---- emergency contacts ----

    public function test_an_emergency_contact_search_cannot_list_the_contacts_of_other_sellers_or_the_in_house_shop(): void
    {
        $this->assertSame([], $this->listedContacts(search: '050222'), 'another seller\'s phone');
        $this->assertSame([], $this->listedContacts(search: 'B family'), 'another seller\'s name');
        $this->assertSame([], $this->listedContacts(search: '050333'), 'the in-house shop\'s phone');
    }

    public function test_the_seller_can_still_search_their_own_emergency_contacts_by_name_or_phone(): void
    {
        $this->assertSame(['A contact'], $this->listedContacts(search: '050111'));
        $this->assertSame(['A contact'], $this->listedContacts(search: 'A contact'));
        $this->assertSame([], $this->listedContacts(search: 'nobody-by-this-name'));
    }

    public function test_the_seller_still_lists_only_their_own_emergency_contacts_without_a_search(): void
    {
        $this->assertSame(['A contact'], $this->listedContacts());
    }

    // ---- top delivery men ----

    public function test_a_top_delivery_man_search_cannot_list_the_drivers_of_other_sellers_or_the_in_house_shop(): void
    {
        $this->assertSame([], $this->listedDrivers(search: 'Zahrani'), 'another seller\'s last name');
        $this->assertSame([], $this->listedDrivers(search: 'Omar'), 'another seller\'s first name');
        $this->assertSame([], $this->listedDrivers(search: 'Hassan'), 'the in-house shop\'s driver');
    }

    public function test_the_seller_can_still_search_their_own_top_delivery_men_by_first_or_last_name(): void
    {
        $this->assertSame(['Anders'], $this->listedDrivers(search: 'Ali'));
        $this->assertSame(['Anders'], $this->listedDrivers(search: 'Anders'));
        $this->assertSame(['Anders'], $this->listedDrivers(search: 'Ali nobody-by-this-name'));
        $this->assertSame([], $this->listedDrivers(search: 'nobody-by-this-name'));
    }

    public function test_the_seller_still_lists_only_their_own_top_delivery_men_without_a_search(): void
    {
        $this->assertSame(['Anders'], $this->listedDrivers());
    }

    // ---- helpers ----

    /** @return list<string> */
    private function listedContacts(?string $search = null): array
    {
        $response = $this->sellerApi('GET', 'delivery-man/emergency-contact/list' . ($search !== null ? '?search=' . urlencode($search) : ''));
        $this->assertSame(200, $response->getStatusCode());

        return array_column($response->json('contact_list'), 'name');
    }

    /** @return list<string> */
    private function listedDrivers(?string $search = null): array
    {
        $response = $this->sellerApi('GET', 'top-delivery-man?limit=10&offset=1' . ($search !== null ? '&search=' . urlencode($search) : ''));
        $this->assertSame(200, $response->getStatusCode());

        return array_column($response->json('delivery_man'), 'l_name');
    }

    private function createEmergencyContactTable(): void
    {
        $this->createSellerApiTable('emergency_contacts', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name')->nullable();
            $table->string('country_code')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    private function createDeliveryManTables(): void
    {
        $this->createSellerApiTable('delivery_men', function (Blueprint $table) {
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('identity_number')->nullable();
            $table->text('identity_image')->nullable();
            $table->string('image')->nullable();
            $table->string('password')->nullable();
            $table->string('auth_token')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is')->nullable();
            $table->string('order_status')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('reviews', function (Blueprint $table) {
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->integer('rating')->default(5);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        $this->createSellerApiTable('storages', function (Blueprint $table) {
            $table->string('data_type');
            $table->unsignedBigInteger('data_id');
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });
    }

    private function seedEmergencyContacts(): void
    {
        $contact = fn (int $userId, string $name, string $phone) => DB::table('emergency_contacts')->insert(['user_id' => $userId, 'name' => $name, 'phone' => $phone]);
        $contact(self::SELLER, 'A contact', '0501111111');
        $contact(self::OTHER_SELLER, 'B family', '0502222222');
        $contact(self::IN_HOUSE_OWNER_ID, 'Admin hotline', '0503333333');
    }

    private function seedDeliveryMen(): void
    {
        $driver = fn (int $id, int $sellerId, string $firstName, string $lastName, string $sellerIs, int $orderSellerId) => [
            DB::table('delivery_men')->insert([
                'id' => $id, 'seller_id' => $sellerId, 'f_name' => $firstName, 'l_name' => $lastName, 'phone' => '0555000' . $id,
                'email' => strtolower($lastName) . '@example.test', 'identity_number' => 'ID-' . $id,
            ]),
            DB::table('orders')->insert(['delivery_man_id' => $id, 'seller_id' => $orderSellerId, 'seller_is' => $sellerIs, 'order_status' => 'delivered']),
        ];
        $driver(8, self::SELLER, 'Ali', 'Anders', 'seller', self::SELLER);
        $driver(9, self::OTHER_SELLER, 'Omar', 'Zahrani', 'seller', self::OTHER_SELLER);
        $driver(10, self::IN_HOUSE_OWNER_ID, 'Samir', 'Hassan', 'admin', self::IN_HOUSE_ID);
    }
}
