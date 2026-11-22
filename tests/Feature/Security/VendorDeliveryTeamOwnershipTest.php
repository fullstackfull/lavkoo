<?php

namespace Tests\Feature\Security;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The delivery team pages of the vendor panel reach an emergency contact, and an order's status history, by the id in the URL. The sibling
 * handlers of the same controllers (the contact's status switch and delete, the delivery man's pages) already scope on the signed-in seller;
 * the contact's edit form and update, and the status history, did not: any seller could open and rewrite another seller's emergency contact,
 * and read the status timeline of any order of any seller, or of the in-house shop, by guessing its number.
 */
class VendorDeliveryTeamOwnershipTest extends VendorPanelTestCase
{
    private const OWN_CONTACT = 1;
    private const OTHER_SELLERS_CONTACT = 2;
    private const IN_HOUSE_CONTACT = 3;

    private const OWN_ORDER = 100001;
    private const OTHER_SELLERS_ORDER = 100002;
    private const IN_HOUSE_ORDER = 100003;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('emergency_contacts', static function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('user_id');
            $table->string('name');
            $table->string('country_code', 20)->nullable();
            $table->string('phone', 25);
            $table->boolean('status');
            $table->timestamps();
        });
        Schema::create('order_status_histories', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_type')->nullable();
            $table->string('status');
            $table->string('cause')->nullable();
            $table->timestamps();
        });

        // The in-house contact and order carry the admin's id, which is also seller 1's id.
        $this->insertContact(id: self::OWN_CONTACT, userId: self::SELLER, name: 'Own contact');
        $this->insertContact(id: self::OTHER_SELLERS_CONTACT, userId: self::OTHER_SELLER, name: 'Other sellers contact');
        $this->insertContact(id: self::IN_HOUSE_CONTACT, userId: 0, name: 'In-house contact');

        $this->insertOrder(id: self::OWN_ORDER, sellerIs: 'seller', sellerId: self::SELLER, status: 'delivered');
        $this->insertOrder(id: self::OTHER_SELLERS_ORDER, sellerIs: 'seller', sellerId: self::OTHER_SELLER, status: 'returned');
        $this->insertOrder(id: self::IN_HOUSE_ORDER, sellerIs: 'admin', sellerId: self::SELLER, status: 'failed');
    }

    /** @return array<string, array{int}> the two contacts a seller reaches by id that are not theirs: another seller's, and the in-house one */
    public static function contactsThatAreNotTheSellers(): array
    {
        return [
            'another seller contact' => [self::OTHER_SELLERS_CONTACT],
            'in-house contact' => [self::IN_HOUSE_CONTACT],
        ];
    }

    /** @return array<string, array{int, string}> the two orders a seller reaches by number that are not theirs, with the status each one's timeline ends on */
    public static function ordersThatAreNotTheSellers(): array
    {
        return [
            'another seller order' => [self::OTHER_SELLERS_ORDER, 'returned'],
            'in-house order sharing the seller id' => [self::IN_HOUSE_ORDER, 'failed to deliver'],
        ];
    }

    // ---- emergency contacts ----

    #[DataProvider('contactsThatAreNotTheSellers')]
    public function test_a_seller_cannot_open_the_edit_form_of_an_emergency_contact_that_is_not_theirs(int $contactId): void
    {
        [$name, , $phone] = $this->contactRow($contactId);

        $response = $this->signInAs(self::SELLER)->getJson('/vendor/delivery-man/emergency-contact/update/' . $contactId);

        $response->assertNotFound();
        $this->assertStringNotContainsString(strtolower($name), strtolower((string) $response->getContent()));
        $this->assertStringNotContainsString($phone, (string) $response->getContent());
    }

    #[DataProvider('contactsThatAreNotTheSellers')]
    public function test_a_seller_cannot_update_an_emergency_contact_that_is_not_theirs(int $contactId): void
    {
        $before = $this->contactRow($contactId);

        $this->signInAs(self::SELLER)->post('/vendor/delivery-man/emergency-contact/update/' . $contactId, $this->contactForm())->assertRedirect();

        $this->assertSame($before, $this->contactRow($contactId));
        $this->assertContains('error', $this->toastTypes());
    }

    public function test_a_seller_can_still_open_the_edit_form_of_their_own_emergency_contact(): void
    {
        $view = $this->signInAs(self::SELLER)->getJson('/vendor/delivery-man/emergency-contact/update/' . self::OWN_CONTACT)->assertOk()->json('view');

        $this->assertStringContainsString('Own contact', $view);
    }

    public function test_a_seller_can_still_update_their_own_emergency_contact(): void
    {
        $this->signInAs(self::SELLER)->post('/vendor/delivery-man/emergency-contact/update/' . self::OWN_CONTACT, $this->contactForm())->assertRedirect();

        $this->assertSame(['Renamed contact', '+966', '0551234567'], array_slice($this->contactRow(self::OWN_CONTACT), 0, 3));
        $this->assertContains('success', $this->toastTypes());
    }

    // ---- the status history of an order ----

    #[DataProvider('ordersThatAreNotTheSellers')]
    public function test_a_seller_cannot_read_the_status_history_of_an_order_that_is_not_theirs(int $orderId, string $status): void
    {
        $response = $this->signInAs(self::SELLER)->get('/vendor/delivery-man/wallet/order-history-status/' . $orderId);

        $response->assertNotFound();
        $this->assertStringNotContainsString($status, strtolower((string) $response->getContent()));
    }

    public function test_a_seller_can_still_read_the_status_history_of_their_own_order(): void
    {
        $response = $this->signInAs(self::SELLER)->get('/vendor/delivery-man/wallet/order-history-status/' . self::OWN_ORDER);

        $response->assertOk()->assertSee((string) self::OWN_ORDER)->assertSee('Delivered');
    }

    private function insertContact(int $id, int $userId, string $name): void
    {
        DB::table('emergency_contacts')->insert([
            'id' => $id, 'user_id' => $userId, 'name' => $name, 'country_code' => '+966', 'phone' => '055000000' . $id, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertOrder(int $id, string $sellerIs, int $sellerId, string $status): void
    {
        DB::table('orders')->insert(['id' => $id, 'customer_id' => 7, 'seller_is' => $sellerIs, 'seller_id' => $sellerId, 'order_status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('order_status_histories')->insert(['order_id' => $id, 'user_id' => $sellerId, 'user_type' => 'seller', 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return list<string> the name, country code, phone and switch of a contact */
    private function contactRow(int $contactId): array
    {
        $contact = DB::table('emergency_contacts')->where('id', $contactId)->first();

        return [$contact->name, $contact->country_code, $contact->phone, (string) $contact->status];
    }

    /** @return array<string, string> a valid emergency contact form */
    private function contactForm(): array
    {
        return ['name' => 'Renamed contact', 'country_code' => '+966', 'phone' => '0551234567'];
    }
}
