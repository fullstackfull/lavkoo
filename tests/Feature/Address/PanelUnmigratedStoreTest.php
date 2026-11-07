<?php

namespace Tests\Feature\Address;

use App\Models\ShippingAddress;
use App\Models\User;
use App\Services\Address\AddressSchema;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;

/**
 * The code is deployed before its migrations run. Until they have, even a store whose setting says "strict" behaves exactly as it did
 * before in the panels: the old forms, the old copy of an address, and pages that never ask for a table that is not there yet.
 */
class PanelUnmigratedStoreTest extends PanelAddressTestCase
{
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

        $this->actingAsAdmin();
        app()->setLocale('en');
        $this->setAddressMode(AddressSchema::MODE_STRICT);
    }

    public function test_the_store_really_has_none_of_the_new_tables_or_columns(): void
    {
        $this->assertFalse(Schema::hasColumn('shipping_addresses', 'city_id'));
        $this->assertFalse(Schema::hasTable('address_cities'));
        $this->assertSame(AddressSchema::MODE_OFF, AddressSchema::mode());
    }

    public function test_the_order_address_edit_is_the_old_one_and_ignores_structured_fields(): void
    {
        $this->insertPanelOrder(100, $this->legacySnapshot());

        $this->post(route('admin.orders.address-update'), [
            'order_id' => 100, 'address_type' => 'shipping', 'name' => 'Huda Alharbi', 'phone_number' => '0559876543', 'country' => 'Saudi Arabia',
            'city' => 'جدة', 'zip' => '21589', 'address' => 'Tahlia St 4', 'latitude' => '21.5', 'longitude' => '39.1',
            'region_id' => 1, 'city_id' => 2, 'national_address' => 'JEDD4321',
        ])->assertRedirect();

        $copy = $this->storedCopy(100);
        $this->assertSame(['جدة', '21589', 'Tahlia St 4'], [$copy['city'], $copy['zip'], $copy['address']]);
        $this->assertArrayNotHasKey('region_id', $copy);
        $this->assertContains('success', $this->toastTypes());
    }

    public function test_the_order_pages_and_the_invoice_render_without_asking_for_the_new_tables(): void
    {
        $order = $this->orderWithCopies($this->legacySnapshot(), $this->legacySnapshot());
        $pageData = [
            'linkedOrders' => collect(), 'deliveryMen' => collect(), 'totalDelivered' => 0, 'companyName' => 'Pharmacy', 'companyWebLogo' => null,
            'physicalProduct' => true, 'countryRestrictStatus' => 0, 'zipRestrictStatus' => 0, 'countries' => [['code' => 'SA', 'name' => 'Saudi Arabia']], 'zipCodes' => 0,
            'orderCount' => 1, 'isOrderOnlyDigital' => false, 'previousOrder' => null, 'nextOrder' => null, 'allProductsList' => collect(),
            'isOrderEditable' => ['status' => false, 'message' => ''], 'orderProductsSession' => ['product_list' => []],
            'editOrderSummary' => ['order_amount' => 0], 'orderEditPaymentHistory' => collect(), 'order' => $order,
        ];

        $page = View::make('admin-views.order.order-details', $pageData)->render();
        $invoice = View::make('admin-views.order.invoice', ['order' => $order, 'vendor' => null, 'companyPhone' => '', 'companyEmail' => '', 'companyName' => 'Pharmacy', 'companyWebLogo' => null, 'invoiceSettings' => []])->render();

        $this->assertStringNotContainsString('data-unified-address', $page);
        $this->assertStringContainsString('الرياض', $invoice);
    }

    public function test_a_customer_added_at_the_till_gets_the_old_address_row(): void
    {
        $this->postJson(route('admin.customer.add'), [
            'f_name' => 'Huda', 'l_name' => 'Alharbi', 'email' => 'huda@example.test', 'phone' => '0559876543', 'country' => 'Saudi Arabia',
            'city' => 'جدة', 'zip_code' => '21589', 'address' => 'Tahlia St 4', 'region_id' => 2, 'city_id' => 3, 'national_address' => 'JEDD4321',
        ])->assertOk()->assertJson(['success' => true]);

        $address = ShippingAddress::sole();
        $this->assertSame(['جدة', '21589'], [$address->city, $address->zip]);
        $this->assertFalse(array_key_exists('city_id', $address->getAttributes()));
    }

    public function test_the_customer_page_lists_the_old_addresses(): void
    {
        $customerId = User::unguarded(static fn () => User::create(['f_name' => 'Sara', 'l_name' => 'Alharbi', 'email' => 'sara@example.test', 'phone' => '0551234567']))->id;
        $this->makeLegacyAddress($customerId);

        $html = (string) $this->get(route('admin.customer.view', ['user_id' => $customerId]))->assertOk()->getContent();

        $this->assertStringContainsString('الرياض', $html);
        $this->assertStringNotContainsString('badge text-bg-warning', $html);
    }
}
