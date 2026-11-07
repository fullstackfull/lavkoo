<?php

namespace Tests\Feature\Address;

use App\Models\ShippingAddress;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsStorefrontOrderPages;

/**
 * Looking a guest order up by its number and phone (track-order/result): the phone the order was placed with opens it, whatever
 * became of the saved address it was made from.
 */
class StorefrontTrackOrderTest extends StorefrontTestCase
{
    use BuildsStorefrontOrderPages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPageTables();
        Schema::create('offline_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('method_name')->nullable();
            $table->longText('method_informations')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        Schema::create('addon_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key_name')->nullable();
            $table->longText('live_values')->nullable();
            $table->longText('test_values')->nullable();
            $table->string('settings_type')->nullable();
            $table->string('mode')->default('live');
            $table->boolean('is_active')->default(true);
            $table->longText('additional_data')->nullable();
            $table->timestamps();
        });
    }

    private function placeGuestOrder(int $id, array|null $shipping, ?int $shippingAddressId = null): void
    {
        DB::table('orders')->insert([
            'id' => $id, 'customer_id' => 9, 'is_guest' => 1, 'order_type' => 'default_type', 'order_status' => 'pending', 'payment_status' => 'paid',
            'shipping_address' => $shippingAddressId, 'shipping_address_data' => $shipping === null ? null : json_encode($shipping), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function track(int $orderId, ?string $phone): \Illuminate\Testing\TestResponse
    {
        return $this->get(route('track-order.result', ['order_id' => $orderId, 'phone_number' => $phone]));
    }

    public function test_the_phone_the_order_was_placed_with_opens_it(): void
    {
        $this->placeGuestOrder(300, ['phone' => '+966551234567', 'address' => 'Street']);

        $this->track(300, '+966551234567')->assertOk()->assertSee('TRACKING PAGE OF ORDER 300');
    }

    public function test_another_phone_or_none_does_not(): void
    {
        $this->placeGuestOrder(301, ['phone' => '+966551234567']);

        $this->track(301, '+966550000000')->assertRedirect(route('track-order.index', ['order_id' => 301, 'phone_number' => '+966550000000']));
        $this->track(301, null)->assertRedirect();
        $this->track(301, '')->assertRedirect();
    }

    public function test_an_order_whose_copy_has_no_phone_opens_for_nobody_instead_of_failing(): void
    {
        $this->placeGuestOrder(302, ['address' => 'Street only']);

        $this->track(302, '+966551234567')->assertRedirect();
        $this->track(302, null)->assertRedirect();
    }

    public function test_editing_the_saved_address_afterwards_does_not_change_who_may_open_the_order(): void
    {
        $saved = $this->makeLegacyAddress(9, true, ['phone' => '+966551234567']);
        $this->placeGuestOrder(303, ['phone' => '+966551234567'], $saved->id);
        $saved->update(['phone' => '+966559999999']);

        $this->track(303, '+966559999999')->assertRedirect();
        $this->track(303, '+966551234567')->assertOk()->assertSee('TRACKING PAGE OF ORDER 303');

        ShippingAddress::whereKey($saved->id)->delete();
        $this->track(303, '+966551234567')->assertOk();
    }

    public function test_an_order_that_kept_no_copy_is_opened_by_the_saved_address_it_points_to(): void
    {
        $saved = $this->makeLegacyAddress(9, true, ['phone' => '+966551234567']);
        $this->placeGuestOrder(304, null, $saved->id);

        $this->track(304, '+966551234567')->assertOk()->assertSee('TRACKING PAGE OF ORDER 304');
        $this->track(304, '+966550000000')->assertRedirect();
    }
}
