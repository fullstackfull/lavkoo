<?php

namespace Tests\Feature\Address;

use App\Events\OrderStatusEvent;
use App\Models\AddressCity;
use App\Models\AuditLog;
use App\Models\Order;
use App\Services\Address\AddressSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * An administrator correcting the address of an order from its page: the old form while the store has not switched to the structured
 * address, the region/city/National Address form once it has, and the ZIP code never invented or erased.
 */
class PanelOrderAddressEditTest extends PanelAddressTestCase
{
    private const ORDER_ID = 100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();
    }

    /** What the order page's edit form sends for an old-style address. */
    private function legacyForm(array $overrides = []): array
    {
        return $overrides + [
            'order_id' => self::ORDER_ID, 'address_type' => 'shipping', 'name' => 'Huda Alharbi', 'phone_number' => '0559876543',
            'country' => 'Saudi Arabia', 'city' => 'جدة', 'zip' => '21589', 'address' => 'Tahlia St 4', 'latitude' => '21.5', 'longitude' => '39.1',
        ];
    }

    /** What it sends for a Saudi address once the structured form is in use: the old city and ZIP boxes are switched off, so they are not sent. */
    private function structuredForm(array $overrides = []): array
    {
        return $overrides + [
            'order_id' => self::ORDER_ID, 'address_type' => 'shipping', 'name' => 'Huda Alharbi', 'phone_number' => '0559876543',
            'country' => 'Saudi Arabia', 'region_id' => $this->ids['region_MAK'], 'city_id' => $this->ids['jeddah'], 'district' => 'Al Rawdah',
            'national_address' => 'jedd 4321', 'address' => 'Tahlia St 4', 'latitude' => '21.5', 'longitude' => '39.1',
        ];
    }

    private function submit(array $form)
    {
        return $this->post(route('admin.orders.address-update'), $form);
    }

    private function orderWithLegacyCopies(): void
    {
        $this->insertPanelOrder(self::ORDER_ID, $this->legacySnapshot(), $this->legacySnapshot(['address' => 'Billing St 9']));
    }

    public function test_while_the_mode_is_off_the_edit_replaces_what_the_form_sends_and_keeps_everything_else(): void
    {
        $this->orderWithLegacyCopies();

        $this->submit($this->legacyForm())->assertRedirect();

        $copy = $this->storedCopy(self::ORDER_ID);
        $this->assertSame(
            ['Huda Alharbi', '0559876543', 'Saudi Arabia', 'جدة', '21589', 'Tahlia St 4', '21.5', '39.1'],
            [$copy['contact_person_name'], $copy['phone'], $copy['country'], $copy['city'], $copy['zip'], $copy['address'], $copy['latitude'], $copy['longitude']],
        );
        $this->assertSame(['sara@example.test', 'home', 7], [$copy['email'], $copy['address_type'], $copy['id']], 'keys the form does not carry are kept');
        $this->assertArrayHasKey('updated_at', $copy);
        $this->assertArrayNotHasKey('address_schema', $copy, 'the old copy keeps its old shape');
        $this->assertSame('Billing St 9', $this->storedCopy(self::ORDER_ID, 'billing')['address'], 'the other address of the order is untouched');
        $this->assertContains('success', $this->toastTypes());
    }

    public function test_while_the_mode_is_off_moving_a_structured_copy_leaves_no_ids_of_the_old_city_in_it(): void
    {
        $this->insertPanelOrder(self::ORDER_ID, $this->unifiedSnapshot($this->ids), $this->legacySnapshot(['address' => 'Billing St 9']));

        $this->submit($this->legacyForm())->assertRedirect();

        $copy = $this->storedCopy(self::ORDER_ID);
        $this->assertSame(['جدة', null, null, null, 'legacy', '21589'], [$copy['city'], $copy['city_id'], $copy['region_id'], $copy['national_address'], $copy['address_schema'], $copy['zip']], 'the typed city is the whole place now; the ZIP is what the form carried');
        $this->assertNull($copy['city_name_ar'] ?? null);
    }

    public function test_while_the_mode_is_off_structured_fields_in_the_request_are_ignored(): void
    {
        $this->orderWithLegacyCopies();

        $this->submit($this->legacyForm(['region_id' => $this->ids['region_MAK'], 'city_id' => $this->ids['jeddah'], 'national_address' => 'JEDD4321']))->assertRedirect();

        $copy = $this->storedCopy(self::ORDER_ID);
        $this->assertSame('جدة', $copy['city']);
        foreach (['region_id', 'city_id', 'national_address', 'district', 'address_schema'] as $key) {
            $this->assertArrayNotHasKey($key, $copy);
        }
    }

    public function test_the_copy_is_stored_as_one_json_object_that_the_pages_read_back(): void
    {
        $this->orderWithLegacyCopies();

        $this->submit($this->legacyForm())->assertRedirect();

        $stored = (string) DB::table('orders')->where('id', self::ORDER_ID)->value('shipping_address_data');
        $this->assertStringStartsWith('{', $stored, 'encoded once, not as a string holding JSON');
        $order = Order::find(self::ORDER_ID);
        $this->assertSame('Huda Alharbi', $order->shipping_address_data->contact_person_name);
        $this->assertSame('جدة', $order->shippingAddressSnapshot()['city']);
    }

    public function test_a_zip_the_form_does_not_carry_is_never_erased_and_one_it_carries_blank_is_cleared(): void
    {
        $this->orderWithLegacyCopies();
        $withoutZip = $this->legacyForm();
        unset($withoutZip['zip']);

        $this->submit($withoutZip)->assertRedirect();
        $this->assertSame('12211', $this->storedCopy(self::ORDER_ID)['zip'], 'omitted: the old code stored null here');

        $this->submit($this->legacyForm(['zip' => '']))->assertRedirect();
        $this->assertNull($this->storedCopy(self::ORDER_ID)['zip'], 'sent blank on purpose: cleared, and never replaced by a made-up code');

        $this->submit($this->legacyForm(['zip' => '  21589 ']))->assertRedirect();
        $this->assertSame('21589', $this->storedCopy(self::ORDER_ID)['zip'], 'a typed code is kept, trimmed');
    }

    public function test_in_soft_mode_a_saudi_order_is_given_the_structured_address_and_the_old_keys_follow_it(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->orderWithLegacyCopies();

        $this->submit($this->structuredForm())->assertRedirect();

        $copy = $this->storedCopy(self::ORDER_ID);
        $this->assertSame('unified', $copy['address_schema']);
        $this->assertSame([$this->ids['region_MAK'], $this->ids['jeddah'], 'Al Rawdah', 'JEDD4321'], [$copy['region_id'], $copy['city_id'], $copy['district'], $copy['national_address']]);
        $this->assertSame(['Jeddah', 'Makkah', 'جدة', 'منطقة مكة المكرمة'], [$copy['city_name_en'], $copy['region_name_en'], $copy['city'], $copy['state']], 'names are frozen into the copy, the old city and state follow');
        $this->assertSame('Saudi Arabia', $copy['country']);
        $this->assertSame('12211', $copy['zip'], 'the ZIP the order already had is kept, not derived from anything');
        $this->assertSame('legacy', $this->storedCopy(self::ORDER_ID, 'billing')['address_schema'] ?? 'legacy');
    }

    #[DataProvider('modesThatRequireTheStructuredAddress')]
    public function test_the_panel_always_enters_a_saudi_address_in_the_structured_form(string $mode): void
    {
        $this->setAddressMode($mode);
        $this->orderWithLegacyCopies();
        $before = $this->storedCopy(self::ORDER_ID);

        $this->submit($this->legacyForm())->assertRedirect();

        $this->assertSame($before, $this->storedCopy(self::ORDER_ID), 'a free-text city for a Saudi address is refused and nothing changes');
        $this->assertContains('error', $this->toastTypes());
        $this->assertSame(0, AuditLog::count());
    }

    /** @return array<string, array{string}> */
    public static function modesThatRequireTheStructuredAddress(): array
    {
        return ['soft' => [AddressSchema::MODE_SOFT], 'strict' => [AddressSchema::MODE_STRICT]];
    }

    public function test_an_address_in_another_country_keeps_the_old_form_in_every_mode(): void
    {
        $this->setAddressMode(AddressSchema::MODE_STRICT);
        $this->insertPanelOrder(self::ORDER_ID, $this->legacySnapshot(['country' => 'Egypt', 'city' => 'Cairo']));

        $this->submit($this->legacyForm(['country' => 'Egypt', 'city' => 'Alexandria', 'zip' => '21500']))->assertRedirect();

        $copy = $this->storedCopy(self::ORDER_ID);
        $this->assertSame(['Egypt', 'Alexandria', '21500'], [$copy['country'], $copy['city'], $copy['zip']]);
        $this->assertArrayNotHasKey('city_id', $copy);
    }

    public function test_moving_a_structured_order_to_another_country_drops_the_ids_and_the_frozen_names(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->insertPanelOrder(self::ORDER_ID, $this->unifiedSnapshot($this->ids));

        $this->submit($this->legacyForm(['country' => 'Egypt', 'city' => 'Cairo', 'zip' => '11511']))->assertRedirect();

        $copy = $this->storedCopy(self::ORDER_ID);
        $this->assertSame(['legacy', null, null, null, null, 'Cairo', 'Egypt'], [$copy['address_schema'], $copy['region_id'], $copy['city_id'], $copy['national_address'], $copy['city_name_en'], $copy['city'], $copy['country']]);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function tamperedLocations(): array
    {
        return [
            'a city of another region' => [['region_id' => 'EAS', 'city_id' => 'jeddah']],
            'a provider id for the city' => [['region_id' => 'MAK', 'city_id' => 36189]],
            'a region that does not exist' => [['region_id' => 999999, 'city_id' => 'jeddah']],
            'ids that are not whole numbers' => [['region_id' => '1e3', 'city_id' => '-5']],
            'ids sent as lists' => [['region_id' => ['1'], 'city_id' => ['2']]],
            'a missing city' => [['region_id' => 'MAK', 'city_id' => null]],
            'a National Address of the wrong shape' => [['national_address' => 'RAG29']],
            'a National Address sent as a list' => [['national_address' => ['RAGI2929']]],
            'a district that is far too long' => [['district' => str_repeat('x', 400)]],
            'a phone that is not a Saudi mobile' => [['phone_number' => '12345']],
        ];
    }

    #[DataProvider('tamperedLocations')]
    public function test_tampered_ids_and_malformed_fields_are_refused_and_the_order_is_untouched(array $tampered): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->insertPanelOrder(self::ORDER_ID, $this->unifiedSnapshot($this->ids));
        $before = $this->storedCopy(self::ORDER_ID);
        $handles = ['EAS' => $this->ids['region_EAS'], 'MAK' => $this->ids['region_MAK'], 'jeddah' => $this->ids['jeddah']];
        $tampered = array_map(static fn (mixed $value): mixed => is_string($value) ? ($handles[$value] ?? $value) : $value, $tampered);

        $this->submit($this->structuredForm($tampered))->assertRedirect();

        $this->assertSame($before, $this->storedCopy(self::ORDER_ID));
        $this->assertContains('error', $this->toastTypes());
        $this->assertSame(0, AuditLog::count(), 'a refused edit leaves no trace of having happened');
    }

    public function test_a_city_that_was_switched_off_cannot_be_chosen(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->orderWithLegacyCopies();
        AddressCity::whereKey($this->ids['jeddah'])->update(['is_active' => false]);

        $this->submit($this->structuredForm())->assertRedirect();

        $this->assertArrayNotHasKey('city_id', $this->storedCopy(self::ORDER_ID));
        $this->assertContains('error', $this->toastTypes());
    }

    public function test_a_phone_can_be_corrected_on_an_order_whose_city_was_switched_off_since_but_the_delivery_cannot_move_to_another_switched_off_city(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->insertPanelOrder(self::ORDER_ID, $this->unifiedSnapshot($this->ids));
        AddressCity::whereKey([$this->ids['riyadh'], $this->ids['jeddah']])->update(['is_active' => false]);

        $this->submit($this->structuredForm(['region_id' => $this->ids['region_RUH'], 'city_id' => $this->ids['riyadh'], 'phone_number' => '0558888888']))->assertRedirect();

        $copy = $this->storedCopy(self::ORDER_ID);
        $this->assertSame(['0558888888', $this->ids['riyadh']], [$copy['phone'], $copy['city_id']], 'the delivery stays where the order was placed');
        $this->assertNotContains('error', $this->toastTypes());

        $this->submit($this->structuredForm(['phone_number' => '0557777777']))->assertRedirect();

        $this->assertSame(['0558888888', $this->ids['riyadh']], [$this->storedCopy(self::ORDER_ID)['phone'], $this->storedCopy(self::ORDER_ID)['city_id']], 'choosing another switched-off city is a new choice and is refused');
        $this->assertContains('error', $this->toastTypes());
    }

    public function test_entering_the_location_again_reads_the_names_again(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->insertPanelOrder(self::ORDER_ID, $this->unifiedSnapshot($this->ids));
        AddressCity::whereKey($this->ids['riyadh'])->update(['name_ar' => 'اسم جديد', 'name_en' => 'Renamed']);

        $this->submit($this->structuredForm([
            'region_id' => $this->ids['region_RUH'], 'city_id' => $this->ids['riyadh'], 'phone_number' => '0558888888',
        ]))->assertRedirect();

        $copy = $this->storedCopy(self::ORDER_ID);
        $this->assertSame('0558888888', $copy['phone']);
        $this->assertSame('Renamed', $copy['city_name_en'], 'the form always sends the location, so what staff save is built from the lists as they are now');
    }

    public function test_only_the_address_that_was_named_changes(): void
    {
        $this->orderWithLegacyCopies();

        $this->submit($this->legacyForm(['address_type' => 'billing', 'address' => 'Billing St 10']))->assertRedirect();

        $this->assertSame('Billing St 10', $this->storedCopy(self::ORDER_ID, 'billing')['address']);
        $this->assertSame('King Fahd Rd 12', $this->storedCopy(self::ORDER_ID)['address']);
    }

    public function test_an_unknown_address_type_changes_nothing(): void
    {
        $this->orderWithLegacyCopies();
        $before = [$this->storedCopy(self::ORDER_ID), $this->storedCopy(self::ORDER_ID, 'billing')];

        $this->submit($this->legacyForm(['address_type' => 'both']))->assertRedirect();

        $this->assertSame($before, [$this->storedCopy(self::ORDER_ID), $this->storedCopy(self::ORDER_ID, 'billing')]);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_an_order_that_does_not_exist_is_answered_with_an_error_not_a_crash(): void
    {
        $this->submit($this->legacyForm(['order_id' => 424242]))->assertRedirect();

        $this->assertContains('error', $this->toastTypes());
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_an_order_that_kept_no_copy_can_be_given_one(): void
    {
        $this->insertPanelOrder(self::ORDER_ID, null);

        $this->submit($this->legacyForm())->assertRedirect();

        $this->assertSame('جدة', $this->storedCopy(self::ORDER_ID)['city']);
    }

    public function test_fields_sent_as_lists_or_far_too_long_are_refused(): void
    {
        $this->orderWithLegacyCopies();
        $before = $this->storedCopy(self::ORDER_ID);

        $this->submit($this->legacyForm(['name' => ['Huda']]))->assertRedirect();
        $this->submit($this->legacyForm(['address' => str_repeat('a', 5000)]))->assertRedirect();

        $this->assertSame($before, $this->storedCopy(self::ORDER_ID));
    }

    public function test_the_change_is_audited_with_who_which_order_and_which_fields_but_no_address_text(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->orderWithLegacyCopies();

        $this->submit($this->structuredForm())->assertRedirect();

        $entry = AuditLog::sole();
        $this->assertSame(['order.address_updated', 'order', (string) self::ORDER_ID, 'admin', 1], [$entry->action, $entry->subject_type, $entry->subject_id, $entry->actor_type, $entry->actor_id]);
        $this->assertSame('shipping', $entry->context['address_type']);
        $this->assertContains('national_address', $entry->context['changed']);
        $this->assertContains('phone', $entry->context['changed']);
        $this->assertEquals(['schema' => 'legacy', 'region_id' => null, 'city_id' => null], $entry->before);
        $this->assertEquals(['schema' => 'unified', 'region_id' => $this->ids['region_MAK'], 'city_id' => $this->ids['jeddah']], $entry->after);

        $logged = json_encode($entry->toArray(), JSON_UNESCAPED_UNICODE);
        foreach (['JEDD4321', '0559876543', 'Tahlia', 'Huda', 'Al Rawdah', 'جدة', 'sara@example.test', '21.5'] as $addressText) {
            $this->assertStringNotContainsString($addressText, (string) $logged, "the audit line must not hold '{$addressText}'");
        }
    }

    public function test_the_seller_and_the_courier_of_the_order_are_told_as_before(): void
    {
        $this->insertPanelOrder(self::ORDER_ID, $this->legacySnapshot(), null, ['seller_is' => 'seller', 'seller_id' => 5, 'delivery_type' => 'self_delivery', 'delivery_man_id' => 3]);

        $this->submit($this->legacyForm())->assertRedirect();

        Event::assertDispatchedTimes(OrderStatusEvent::class, 2);
    }
}
