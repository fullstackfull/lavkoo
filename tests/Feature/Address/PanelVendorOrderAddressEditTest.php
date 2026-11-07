<?php

namespace Tests\Feature\Address;

use App\Events\OrderStatusEvent;
use App\Models\AuditLog;
use App\Services\Address\AddressSchema;
use Illuminate\Support\Facades\Event;

/**
 * A vendor correcting the address of one of their own orders: the same rules as the administrator's form, behind the vendor's own scope.
 */
class PanelVendorOrderAddressEditTest extends PanelAddressTestCase
{
    private const ORDER_ID = 200;

    private const SELLER_ID = 5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsVendor(self::SELLER_ID);
    }

    /** @return array<string, mixed> */
    private function legacyForm(array $overrides = []): array
    {
        return $overrides + [
            'order_id' => self::ORDER_ID, 'address_type' => 'shipping', 'name' => 'Huda Alharbi', 'phone_number' => '0559876543',
            'country' => 'Saudi Arabia', 'city' => 'جدة', 'zip' => '21589', 'address' => 'Tahlia St 4', 'latitude' => '21.5', 'longitude' => '39.1',
        ];
    }

    /** @return array<string, mixed> */
    private function structuredForm(array $overrides = []): array
    {
        return $overrides + [
            'order_id' => self::ORDER_ID, 'address_type' => 'shipping', 'name' => 'Huda Alharbi', 'phone_number' => '0559876543',
            'country' => 'Saudi Arabia', 'region_id' => $this->ids['region_MAK'], 'city_id' => $this->ids['jeddah'], 'district' => 'Al Rawdah',
            'national_address' => 'JEDD4321', 'address' => 'Tahlia St 4', 'latitude' => '21.5', 'longitude' => '39.1',
        ];
    }

    private function submit(array $form)
    {
        return $this->post(route('vendor.orders.address-update'), $form);
    }

    private function ownOrder(array $shipping, array $overrides = []): void
    {
        $this->insertPanelOrder(self::ORDER_ID, $shipping, $shipping, $overrides + ['seller_id' => self::SELLER_ID, 'seller_is' => 'seller']);
    }

    public function test_while_the_mode_is_off_the_vendor_edit_works_as_it_always_did(): void
    {
        $this->ownOrder($this->legacySnapshot());

        $this->submit($this->legacyForm())->assertRedirect();

        $copy = $this->storedCopy(self::ORDER_ID);
        $this->assertSame(['Huda Alharbi', '0559876543', 'جدة', '21589', 'Tahlia St 4'], [$copy['contact_person_name'], $copy['phone'], $copy['city'], $copy['zip'], $copy['address']]);
        $this->assertArrayNotHasKey('address_schema', $copy);
        $this->assertSame('King Fahd Rd 12', $this->storedCopy(self::ORDER_ID, 'billing')['address']);
        $this->assertContains('success', $this->toastTypes());
    }

    public function test_in_strict_mode_a_saudi_order_moves_to_the_structured_address_and_keeps_its_zip(): void
    {
        $this->setAddressMode(AddressSchema::MODE_STRICT);
        $this->ownOrder($this->legacySnapshot());

        $this->submit($this->structuredForm())->assertRedirect();

        $copy = $this->storedCopy(self::ORDER_ID);
        $this->assertSame(['unified', $this->ids['jeddah'], 'JEDD4321', 'Jeddah', '12211'], [$copy['address_schema'], $copy['city_id'], $copy['national_address'], $copy['city_name_en'], $copy['zip']]);
    }

    public function test_a_free_text_city_for_a_saudi_address_is_refused_while_the_mode_is_on(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->ownOrder($this->legacySnapshot());
        $before = $this->storedCopy(self::ORDER_ID);

        $this->submit($this->legacyForm())->assertRedirect();

        $this->assertSame($before, $this->storedCopy(self::ORDER_ID));
        $this->assertContains('error', $this->toastTypes());
    }

    public function test_a_city_of_another_region_is_refused(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->ownOrder($this->legacySnapshot());
        $before = $this->storedCopy(self::ORDER_ID);

        $this->submit($this->structuredForm(['region_id' => $this->ids['region_EAS']]))->assertRedirect();

        $this->assertSame($before, $this->storedCopy(self::ORDER_ID));
        $this->assertContains('error', $this->toastTypes());
    }

    public function test_the_zip_the_form_does_not_carry_is_kept(): void
    {
        $this->ownOrder($this->legacySnapshot());
        $form = $this->legacyForm();
        unset($form['zip']);

        $this->submit($form)->assertRedirect();

        $this->assertSame('12211', $this->storedCopy(self::ORDER_ID)['zip']);
    }

    public function test_a_vendor_cannot_edit_the_address_of_another_vendors_order(): void
    {
        $this->ownOrder($this->legacySnapshot(), ['seller_id' => 6]);
        $before = $this->storedCopy(self::ORDER_ID);

        $this->submit($this->legacyForm(['address' => 'Hijacked']))->assertRedirect();

        $this->assertSame($before, $this->storedCopy(self::ORDER_ID));
        $this->assertContains('error', $this->toastTypes());
        $this->assertSame(0, AuditLog::count());
    }

    public function test_a_vendor_cannot_edit_the_address_of_an_order_the_shop_itself_sold(): void
    {
        $this->ownOrder($this->legacySnapshot(), ['seller_is' => 'admin']);
        $before = $this->storedCopy(self::ORDER_ID);

        $this->submit($this->legacyForm(['address' => 'Hijacked']))->assertRedirect();

        $this->assertSame($before, $this->storedCopy(self::ORDER_ID));
        $this->assertContains('error', $this->toastTypes());
    }

    public function test_an_order_number_that_does_not_exist_is_answered_with_an_error(): void
    {
        $this->submit($this->legacyForm(['order_id' => 999999]))->assertRedirect();

        $this->assertContains('error', $this->toastTypes());
    }

    public function test_the_edit_is_audited_against_the_vendor_without_any_address_text(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->ownOrder($this->legacySnapshot());

        $this->submit($this->structuredForm())->assertRedirect();

        $entry = AuditLog::sole();
        $this->assertSame(['order.address_updated', (string) self::ORDER_ID, 'seller', self::SELLER_ID], [$entry->action, $entry->subject_id, $entry->actor_type, $entry->actor_id]);
        foreach (['JEDD4321', '0559876543', 'Tahlia', 'Huda'] as $addressText) {
            $this->assertStringNotContainsString($addressText, (string) json_encode($entry->toArray(), JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_only_the_courier_is_told_when_the_vendor_delivers_the_order_themselves(): void
    {
        $this->ownOrder($this->legacySnapshot(), ['delivery_type' => 'self_delivery', 'delivery_man_id' => 3]);

        $this->submit($this->legacyForm())->assertRedirect();

        Event::assertDispatchedTimes(OrderStatusEvent::class, 1);
    }
}
