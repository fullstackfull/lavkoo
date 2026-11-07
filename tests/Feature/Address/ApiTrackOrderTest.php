<?php

namespace Tests\Feature\Address;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Looking a guest order up from the app by its number and phone (POST /api/v1/order/track-order). The route has no sign-in, so the phone is
 * the only proof: it has to be the text the order was placed with, and nothing a JSON body can spell as equal to it.
 */
class ApiTrackOrderTest extends ApiAddressTestCase
{
    private const TRACK = '/api/v1/order/track-order';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('order_edit_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->decimal('order_due_amount', 16, 2)->default(0);
            $table->string('order_due_payment_status')->nullable();
            $table->decimal('order_return_amount', 16, 2)->default(0);
            $table->string('order_return_payment_status')->nullable();
            $table->timestamps();
        });
    }

    private function placeGuestOrder(int $id, array $shipping): void
    {
        $this->insertOrder($id, ['is_guest' => 1, 'order_type' => 'default_type', 'shipping_address_data' => json_encode($shipping)]);
    }

    public function test_the_phone_the_order_was_placed_with_opens_it_in_any_saudi_spelling(): void
    {
        $this->placeGuestOrder(500, ['phone' => '0551234567', 'address' => 'Street']);

        foreach (['0551234567', '+966551234567'] as $phone) {
            $this->postJson(self::TRACK, ['order_id' => 500, 'phone_number' => $phone])->assertOk();
        }
    }

    public function test_another_phone_does_not(): void
    {
        $this->placeGuestOrder(501, ['phone' => '0551234567']);

        $this->postJson(self::TRACK, ['order_id' => 501, 'phone_number' => '0551234568'])->assertStatus(403);
        $this->postJson(self::TRACK, ['order_id' => 501, 'phone_number' => ''])->assertStatus(403);
        $this->postJson(self::TRACK, ['order_id' => 501])->assertStatus(403);
    }

    public function test_a_phone_that_is_not_text_opens_nothing(): void
    {
        $this->placeGuestOrder(502, ['phone' => '0551234567']);

        foreach ([true, 1, 551234567, ['0551234567']] as $notAPhone) {
            $this->postJson(self::TRACK, ['order_id' => 502, 'phone_number' => $notAPhone])->assertStatus(403);
        }
    }

    public function test_an_order_whose_copy_has_no_phone_opens_for_nobody(): void
    {
        $this->placeGuestOrder(503, ['address' => 'Street only']);

        foreach (['0551234567', '', null] as $phone) {
            $this->postJson(self::TRACK, ['order_id' => 503, 'phone_number' => $phone])->assertStatus(403);
        }
        $this->assertSame(1, DB::table('orders')->where('id', 503)->count());
    }
}
