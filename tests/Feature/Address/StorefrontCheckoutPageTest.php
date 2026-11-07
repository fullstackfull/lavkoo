<?php

namespace Tests\Feature\Address;

use App\Models\ShippingAddress;
use App\Services\Address\AddressSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;

/**
 * The address step of the website's checkout as the page shows it (checkout-details): the old form while the structured address is
 * off, the Saudi fields once it is on, the saved addresses of the customer and of nobody else, and the markers the shared address
 * script works by.
 */
class StorefrontCheckoutPageTest extends StorefrontTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createCheckoutPageTables();
        View::share('web_config', ['guest_checkout_status' => 1]);
        $this->setBusinessSetting('billing_input_by_customer', 1);
        $this->setBusinessSetting('guest_checkout', 1);
    }

    private function openCheckout(): TestResponse
    {
        $customer = $this->signIn();
        $this->putOneProductInTheCart($customer->id);

        return $this->get(route('checkout-details'))->assertOk();
    }

    /** How many elements of the page carry the marker (as an attribute of a tag, not as text of a script). */
    private function marked(TestResponse $page, string $marker): int
    {
        return preg_match_all('/<[a-z][^>]*\s' . preg_quote($marker, '/') . '[\s>=]/', $page->getContent());
    }

    public function test_off_the_page_is_the_old_form(): void
    {
        $page = $this->openCheckout();

        $this->assertSame(0, $this->marked($page, 'data-unified-address'));
        $this->assertSame(0, $this->marked($page, 'data-ua-other'));
        $page->assertDontSee('window.unifiedAddress', false)->assertDontSee('storefrontAddress', false)->assertDontSee('data-ua-complete-notice', false);
        $page->assertSee('name="city"', false)->assertSee('name="billing_city"', false)->assertSee('name="zip"', false)->assertSee('name="billing_zip"', false);
        $page->assertDontSee('name="region_id"', false)->assertDontSee('name="national_address"', false);
        $page->assertSee('<option value="" readonly>', false);
        $this->assertStringNotContainsString('<option value="Saudi Arabia" selected', $page->getContent(), 'no country is chosen for the customer');
        $page->assertDontSee('Street, building and landmark');
    }

    public function test_off_the_coordinates_are_posted_as_before(): void
    {
        $this->setBusinessSetting('default_location', ['lat' => '24.5', 'lng' => '46.5']);

        $page = $this->openCheckout();

        $page->assertSee('name="latitude" class="form-control d-inline"', false);
        $this->assertSame(2, preg_match_all('/value="24\.5"\s+required/', $page->getContent()), 'shipping and billing both post the default location, as before');
    }

    public function test_soft_the_page_has_the_saudi_fields_in_both_forms_and_marks_the_old_rows(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);

        $page = $this->openCheckout();

        $this->assertSame(2, $this->marked($page, 'data-unified-address'), 'one block for the shipping form and one for the billing form');
        $this->assertSame(4, $this->marked($page, 'data-ua-other'), 'the old city row and the ZIP row of each form');
        foreach (['region_id', 'city_id', 'district', 'national_address', 'billing_region_id', 'billing_city_id', 'billing_district', 'billing_national_address'] as $field) {
            $page->assertSee('name="' . $field . '"', false);
        }
        $this->assertSame(1, substr_count($page->getContent(), 'window.unifiedAddress = '), 'the shared script is included once');
        $page->assertSee('data-ua-country-selector="#country"', false)->assertSee('data-ua-country-selector="#billing_country"', false);
        $page->assertSee('Street, building and landmark')->assertDontSee('<label>Address<span', false);
    }

    public function test_soft_saudi_arabia_is_the_country_the_page_starts_with(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);

        $page = $this->openCheckout();

        $this->assertSame(2, substr_count($page->getContent(), '<option value="Saudi Arabia" selected>'));
    }

    public function test_soft_no_country_is_chosen_when_deliveries_exclude_saudi_arabia(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->setBusinessSetting('delivery_country_restriction', 1);
        DB::table('delivery_country_codes')->insert(['country_code' => 'AE']);

        $page = $this->openCheckout();

        $this->assertSame(0, preg_match_all('/<option value="[^"]*" selected>/', $page->getContent()), 'no country is preselected');
        $this->assertStringNotContainsString('<option value="Saudi Arabia"', $page->getContent());
        $page->assertSee('<option value="United Arab Emirates">', false);
    }

    public function test_soft_the_coordinates_are_empty_until_the_customer_picks_a_point(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->setBusinessSetting('default_location', ['lat' => '24.5', 'lng' => '46.5']);

        $page = $this->openCheckout();

        $this->assertSame(0, preg_match_all('/\svalue="24\.5"/', $page->getContent()), 'the default location is not posted as if it were the customer\'s point');
        $this->assertSame(4, preg_match_all('/<input type="hidden"[^>]*name="(billing_)?(latitude|longitude)"[^>]*value=""/s', $page->getContent()));
        $page->assertSee('data-value="24.5"', false);
    }

    public function test_soft_the_page_ties_the_saudi_form_to_the_scripts_that_keep_it_in_charge(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);

        $page = $this->openCheckout();

        $page->assertSee('window.storefrontAddress = ', false);
        $page->assertSee('"form":"#address-form"', false)->assertSee('"street":"#address"', false)->assertSee('"phone":"#billing_phone"', false);
        $page->assertSee('"select":"shipping_method_select"', false)->assertSee('"select":"billing_method_select"', false);
        $content = $page->getContent();
        $this->assertLessThan(strpos($content, 'window.storefrontAddress = '), strpos($content, 'js/shipping.js'), 'the wrappers go after the script whose functions they wrap');
    }

    public function test_the_saved_addresses_are_the_customers_own_and_nobody_elses(): void
    {
        $customer = $this->signIn();
        $this->putOneProductInTheCart($customer->id);
        $this->makeLegacyAddress($customer->id, overrides: ['address' => 'My own street']);
        $this->makeLegacyAddress($this->makeCustomer()->id, overrides: ['address' => 'Someone else street']);
        $this->makeLegacyAddress($customer->id, isGuest: true, overrides: ['address' => 'A guest with my number street']);
        ShippingAddress::create(['customer_id' => null, 'is_guest' => false, 'address' => 'Nobody street', 'address_type' => 'home', 'city' => 'X', 'country' => 'Saudi Arabia', 'phone' => '0551234567']);

        $page = $this->get(route('checkout-details'))->assertOk();

        $page->assertSee('My own street');
        $page->assertDontSee('Someone else street')->assertDontSee('A guest with my number street')->assertDontSee('Nobody street');
    }

    public function test_a_guest_has_no_saved_addresses_and_sees_nobody_elses(): void
    {
        $this->withSession(['guest_id' => 77]);
        $this->putOneProductInTheCart(77, isGuest: true);
        ShippingAddress::create(['customer_id' => null, 'is_guest' => false, 'address' => 'Nobody street', 'address_type' => 'home', 'city' => 'X', 'country' => 'Saudi Arabia', 'phone' => '0551234567']);
        $this->makeLegacyAddress(77, isGuest: true, overrides: ['address' => 'An earlier unsaved copy street']);
        $this->makeLegacyAddress($this->makeCustomer()->id, overrides: ['address' => 'A customer street']);

        $page = $this->get(route('checkout-details'))->assertOk();

        $this->assertSame(0, substr_count($page->getContent(), 'select_shipping_address'));
        $page->assertDontSee('Nobody street')->assertDontSee('A customer street')->assertDontSee('An earlier unsaved copy street');
    }

    public function test_soft_a_saved_address_carries_what_the_form_needs_to_be_filled_and_the_old_one_is_flagged(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->signIn();
        $this->putOneProductInTheCart($customer->id);
        $old = $this->makeLegacyAddress($customer->id, overrides: ['address' => 'Old street']);
        $complete = $this->makeStructuredAddress($this->ids, $customer->id, overrides: ['district' => 'Al Olaya', 'address' => 'Complete street']);

        $page = $this->get(route('checkout-details'))->assertOk();

        $this->assertSame(2, substr_count($page->getContent(), 'badge badge-warning'), 'the old address is flagged in the shipping list and in the billing list');
        preg_match_all('/class="selected_shippingAddress\d+" value="([^"]+)"/', $page->getContent(), $matches);
        [$oldData, $completeData] = array_map(static fn (string $json): array => json_decode(html_entity_decode($json), true), $matches[1]);
        $this->assertSame($old->id, $oldData['id']);
        $this->assertTrue($oldData['needs_completion']);
        $this->assertNull($oldData['city_id']);
        $this->assertSame(
            [$complete->id, false, $this->ids['riyadh'], $this->ids['region_RUH'], 'الرياض', 'Riyadh', 'منطقة الرياض', 'Riyadh', 'Al Olaya', 'RAGI2929'],
            [$completeData['id'], $completeData['needs_completion'], $completeData['city_id'], $completeData['region_id'], $completeData['city_name_ar'], $completeData['city_name_en'],
                $completeData['region_name_ar'], $completeData['region_name_en'], $completeData['district'], $completeData['national_address']],
        );
        $this->assertSame('Old street', $oldData['address'], 'the old fields are still there for the page script that fills them');
    }

    public function test_off_a_saved_address_is_embedded_as_the_row_it_is(): void
    {
        $customer = $this->signIn();
        $this->putOneProductInTheCart($customer->id);
        $saved = $this->makeLegacyAddress($customer->id);

        $page = $this->get(route('checkout-details'))->assertOk();

        $page->assertSee('class="selected_shippingAddress0" value="' . e((string) $saved->fresh()) . '"', false);
        $page->assertDontSee('needs_completion')->assertDontSee('badge badge-warning', false);
    }

    public function test_a_guest_checkout_page_has_the_same_fields(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->withSession(['guest_id' => 78]);
        $this->putOneProductInTheCart(78, isGuest: true);

        $page = $this->get(route('checkout-details'))->assertOk();

        $this->assertSame(2, $this->marked($page, 'data-unified-address'));
        $page->assertSee('name="email"', false)->assertSee('name="region_id"', false)->assertSee('name="national_address"', false);
    }
}
