<?php

namespace Tests\Feature\Address;

use App\Models\ShippingAddress;
use App\Services\Address\AddressSchema;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Tests\Support\BuildsStorefrontOrderPages;

/**
 * The code is deployed before its migrations run. Until they have, even a store whose setting says "strict" behaves exactly as it
 * did before: the old form, the old validation, the old rows, the old copy of an address on an order's page.
 */
class StorefrontUnmigratedStoreTest extends StorefrontTestCase
{
    use BuildsStorefrontOrderPages;

    protected function buildAddressSchema(): void
    {
        $this->resetAddressTestDatabase();
        $this->createLegacyAddressTables();
    }

    protected function seedAddressCatalog(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createCheckoutPageTables();
        View::share('web_config', ['guest_checkout_status' => 1]);
        $this->setBusinessSetting('billing_input_by_customer', 1);
        $this->setAddressMode(AddressSchema::MODE_STRICT);
    }

    /** @return array<string, mixed> */
    private function oldForm(array $overrides = []): array
    {
        return $overrides + [
            'contact_person_name' => 'Sara Alharbi', 'phone' => '+966551234567', 'address_type' => 'home', 'country' => 'Saudi Arabia',
            'city' => 'الرياض', 'zip' => '12211', 'address' => 'King Fahd Rd 12', 'latitude' => '24.7', 'longitude' => '46.6', 'shipping_method_id' => 0,
        ];
    }

    public function test_the_store_has_no_structured_columns_and_says_the_mode_is_off(): void
    {
        $this->assertFalse(Schema::hasColumn('shipping_addresses', 'city_id'));
        $this->assertFalse(AddressSchema::enabled());
        $this->assertSame(AddressSchema::MODE_OFF, AddressSchema::mode());
    }

    public function test_the_checkout_step_saves_the_old_address_in_every_way(): void
    {
        $customer = $this->signIn();
        $post = fn (array $shipping) => $this->postJson(route('customer.choose-shipping-address-other'), [
            'physical_product' => 'yes', 'shipping' => http_build_query($shipping), 'billing' => '', 'billing_addresss_same_shipping' => 'true',
        ]);

        $post($this->oldForm())->assertOk();
        $post($this->oldForm())->assertOk();
        $this->assertSame(1, ShippingAddress::count(), 'an identical unsaved address is still reused');

        $post($this->oldForm(['save_address' => 'on', 'address' => 'Saved street']))->assertOk();
        $saved = ShippingAddress::where('address', 'Saved street')->sole();
        $this->assertSame((string) $customer->id, (string) $saved->customer_id);

        $post($this->oldForm(['shipping_method_id' => $saved->id, 'update_address' => 'on', 'address' => 'Updated street']))->assertOk();
        $this->assertSame('Updated street', $saved->fresh()->address);

        $post($this->oldForm(['city' => '']))->assertStatus(403);
    }

    public function test_an_address_that_is_not_the_callers_is_still_refused(): void
    {
        $this->signIn();
        $victim = $this->makeLegacyAddress($this->makeCustomer()->id, overrides: ['contact_person_name' => 'Victim Name']);
        $post = fn (array $shipping) => $this->postJson(route('customer.choose-shipping-address-other'), [
            'physical_product' => 'yes', 'shipping' => http_build_query($shipping), 'billing' => '', 'billing_addresss_same_shipping' => 'true',
        ]);

        $post($this->oldForm(['shipping_method_id' => $victim->id, 'update_address' => 'on']))->assertStatus(403);
        $post($this->oldForm(['shipping_method_id' => $victim->id, 'save_address' => 'off']))->assertOk();

        $this->assertNotSame($victim->id, (int) session('address_id'));
        $this->assertSame('Victim Name', $victim->fresh()->contact_person_name);
    }

    public function test_the_checkout_page_is_the_old_form(): void
    {
        $customer = $this->signIn();
        $this->putOneProductInTheCart($customer->id);
        $this->makeLegacyAddress($customer->id, overrides: ['address' => 'Old street']);

        $page = $this->get(route('checkout-details'))->assertOk();

        $page->assertSee('Old street')->assertSee('name="city"', false)->assertSee('name="zip"', false);
        $page->assertDontSee('data-unified-address', false)->assertDontSee('data-ua-other', false)->assertDontSee('badge badge-warning', false);
    }

    public function test_the_address_book_works_on_the_old_table(): void
    {
        $customer = $this->signIn();
        $form = ['address_type' => 'home', 'is_billing' => '0', 'name' => 'Sara Alharbi', 'phone' => '+966551234567', 'city' => 'الرياض', 'zip' => '12211',
            'country' => 'Saudi Arabia', 'address' => 'King Fahd Rd 12', 'latitude' => '24.7', 'longitude' => '46.6'];

        $this->post(route('address-store'), $form)->assertRedirect();
        $address = ShippingAddress::sole();
        $this->assertSame([(string) $customer->id, 'الرياض', '12211'], [(string) $address->customer_id, $address->city, $address->zip]);

        $this->post(route('address-update'), ['id' => $address->id, 'address' => 'Olaya St 3', 'city' => 'جدة'] + $form)->assertRedirect(route('account-address'));
        $this->assertSame(['Olaya St 3', 'جدة'], [$address->fresh()->address, $address->fresh()->city]);

        $this->get(route('account-address'))->assertOk()->assertSee('Olaya St 3')->assertDontSee('data-unified-address', false);
        $this->get(route('address-edit', ['id' => $address->id]))->assertOk()->assertSee('value="جدة"', false)->assertDontSee('data-unified-address', false);

        $this->get(route('address-delete', ['id' => $address->id]))->assertRedirect();
        $this->assertSame(0, ShippingAddress::count());
    }

    public function test_an_order_page_prints_the_old_copy_of_an_address(): void
    {
        $this->createOrderPageTables();
        $this->signIn();
        $order = $this->makeOrderKeeping(['contact_person_name' => 'Sara Alharbi', 'phone' => '+966551234567', 'address_type' => 'home', 'address' => 'King Fahd Rd 12', 'city' => 'الرياض', 'zip' => '12211']);

        foreach ([$this->renderOrderDetails($order), $this->renderInvoice($order)] as $html) {
            $this->assertStringContainsString('الرياض', $html);
            $this->assertStringContainsString('12211', $html);
            $this->assertStringContainsString('King Fahd Rd 12', $html);
        }
    }
}
