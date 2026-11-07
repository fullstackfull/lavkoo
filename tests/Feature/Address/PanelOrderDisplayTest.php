<?php

namespace Tests\Feature\Address;

use App\Models\AddressCity;
use App\Models\Order;
use App\Services\Address\AddressSchema;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The order pages of the admin and vendor panels: what they print of an address and what their edit forms offer, whichever way the order's
 * copy was made (old, structured, or an old one missing keys the pages once took for granted) and whichever mode the store is in now.
 *
 * Each page costs about a second to render (every label is a translate() call), so a case renders it once and checks everything it shows.
 * The admin and the vendor page share the readers and differ in markup, which is why the main cases run on both.
 */
class PanelOrderDisplayTest extends PanelAddressTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();
        app()->setLocale('en');
    }

    /** @return array<string, mixed> everything the order pages are given besides the order */
    private function pageData(): array
    {
        return [
            'linkedOrders' => collect(), 'deliveryMen' => collect(), 'totalDelivered' => 0, 'companyName' => 'Pharmacy', 'companyWebLogo' => null,
            'physicalProduct' => true, 'countryRestrictStatus' => 0, 'zipRestrictStatus' => 0,
            'countries' => [['code' => 'EG', 'name' => 'Egypt'], ['code' => 'SA', 'name' => 'Saudi Arabia']], 'zipCodes' => 0,
            'orderCount' => 1, 'isOrderOnlyDigital' => false, 'previousOrder' => null, 'nextOrder' => null, 'allProductsList' => collect(),
            'isOrderEditable' => ['status' => false, 'message' => ''], 'orderProductsSession' => ['product_list' => []],
            'editOrderSummary' => ['order_amount' => 0], 'orderEditPaymentHistory' => collect(),
        ];
    }

    private function adminPage(Order $order): string
    {
        return View::make('admin-views.order.order-details', $this->pageData() + ['order' => $order])->render();
    }

    private function vendorPage(Order $order): string
    {
        return View::make('vendor-views.order.order-details', $this->pageData() + ['order' => $order, 'sellerEarning' => null, 'sellerTimeline' => ['events' => [], 'sla' => null]])->render();
    }

    /** The text of one address card of an order page, from its heading to the end of its table, on one line. */
    private function card(string $html, string $heading): string
    {
        $this->assertSame(1, preg_match('/<h4[^>]*>\s*(?:<img[^>]*>\s*)?' . preg_quote($heading, '/') . '\s*<\/h4>.*?<\/table>/s', $html, $match), "the page has a '{$heading}' card");

        return (string) preg_replace('/\s+/', ' ', strip_tags($match[0]));
    }

    /** The opening tag of the first input with this name, whatever order its attributes are in. */
    private function input(string $html, string $name): string
    {
        $this->assertSame(1, preg_match('/<input[^>]*\sname="' . preg_quote($name, '/') . '"[^>]*>/', $html, $match), "the page has an input named {$name}");

        return $match[0];
    }

    private function blocks(string $html): int
    {
        return substr_count($html, '<div class="unified-address" data-unified-address');
    }

    /** @return array<string, array{string}> */
    public static function pages(): array
    {
        return ['admin page' => ['adminPage'], 'vendor page' => ['vendorPage']];
    }

    #[DataProvider('pages')]
    public function test_old_copies_print_as_they_always_did_and_the_edit_form_is_the_old_one_while_the_mode_is_off(string $page): void
    {
        $html = $this->{$page}($this->orderWithCopies($this->legacySnapshot(), $this->legacySnapshot(['zip' => '', 'address' => 'Billing St 9'])));

        $shipping = $this->card($html, 'Shipping address');
        $this->assertStringContainsString('Country : Saudi Arabia', $shipping);
        $this->assertStringContainsString('City : الرياض', $shipping);
        $this->assertStringContainsString('Zip code : 12211', $shipping);
        $this->assertStringContainsString('Address : King Fahd Rd 12', $shipping);
        foreach (['Region', 'District', 'National Address'] as $absent) {
            $this->assertStringNotContainsString($absent, $shipping);
        }
        $this->assertStringNotContainsString('Zip code', $this->card($html, 'Billing address'), 'a blank ZIP leaves no empty row');

        $this->assertSame(0, $this->blocks($html));
        $this->assertSame(0, preg_match_all('/class="col-md-3"\s+data-ua-other/', $html));
        $this->assertSame(2, preg_match_all('/name="zip"[^>]*required/', $html), 'the old ZIP box of both forms is required exactly as before');
    }

    #[DataProvider('pages')]
    public function test_a_structured_copy_prints_region_city_district_and_national_address_and_its_form_asks_for_them(string $page): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $html = $this->{$page}($this->orderWithCopies($this->unifiedSnapshot($this->ids), $this->legacySnapshot(['address' => 'Billing St 9'])));

        $shipping = $this->card($html, 'Shipping address');
        foreach (['Region : Riyadh', 'City : Riyadh', 'District : Al Olaya', 'National Address : RAGI2929'] as $line) {
            $this->assertStringContainsString($line, $shipping);
        }
        $this->assertStringNotContainsString('Zip code', $shipping, 'a structured address that never had a ZIP prints no ZIP line');
        $billing = $this->card($html, 'Billing address');
        $this->assertStringContainsString('Address : Billing St 9', $billing, 'the billing card shows the billing copy, not the delivery one');
        $this->assertStringNotContainsString('National Address', $billing);

        $this->assertSame(2, $this->blocks($html), 'one block in the delivery form and one in the billing form');
        $this->assertSame(1, substr_count($html, 'window.unifiedAddress = {'), 'the picker script is on the page once');
        $this->assertSame(4, preg_match_all('/class="col-md-3"\s+data-ua-other/', $html), 'the old city and ZIP boxes of both forms are for other countries only');
        $this->assertSame(0, preg_match('/name="zip"[^>]*required/', $html), 'the ZIP box is no longer required');
        $this->assertStringContainsString('Street, building and landmark', strip_tags($html));

        $this->assertMatchesRegularExpression('/<option value="' . $this->ids['region_RUH'] . '"[^>]*selected/', $html, 'the saved region');
        $this->assertStringContainsString('value="' . $this->ids['riyadh'] . '"', $this->input($html, 'city_id'), 'the saved city');
        $this->assertStringContainsString('value="RAGI2929"', $this->input($html, 'national_address'));
        $this->assertStringContainsString('value="Al Olaya"', $this->input($html, 'district'));
    }

    #[DataProvider('pages')]
    public function test_an_old_copys_form_says_why_it_asks_for_the_structured_address_and_a_structured_copys_does_not(string $page): void
    {
        $notice = 'class="alert alert-warning ua-notice';

        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $html = $this->{$page}($this->orderWithCopies($this->legacySnapshot(), $this->legacySnapshot(['city' => 'جدة'])));
        $text = (string) preg_replace('/\s+/', ' ', strip_tags($html));

        $this->assertSame(2, substr_count($html, $notice), 'the delivery form and the billing form each say it');
        $this->assertStringContainsString('saved before regions, cities and National Addresses were introduced', $text);
        $this->assertStringContainsString('Previous city: الرياض', $text, 'the delivery copy reminds of its city');
        $this->assertStringContainsString('Previous city: جدة', $text, 'the billing copy reminds of its own');

        $html = $this->{$page}($this->orderWithCopies($this->unifiedSnapshot($this->ids), $this->legacySnapshot()));
        $this->assertSame(1, substr_count($html, $notice), 'only the billing copy, which is still old, says it');

        $this->setAddressMode(AddressSchema::MODE_OFF);
        $this->assertSame(0, substr_count($this->{$page}($this->orderWithCopies($this->legacySnapshot())), $notice), 'while the mode is off the forms are the old ones');
    }

    #[DataProvider('pages')]
    public function test_an_old_copy_missing_keys_the_pages_once_took_for_granted_still_renders(string $page): void
    {
        $bare = ['contact_person_name' => 'Sara Alharbi', 'phone' => '0551234567', 'address' => 'King Fahd Rd 12', 'city' => 'الرياض'];

        foreach ([AddressSchema::MODE_OFF, AddressSchema::MODE_SOFT] as $mode) {
            $this->setAddressMode($mode);

            $card = $this->card($this->{$page}($this->orderWithCopies($bare, $bare, ['is_guest' => 1])), 'Shipping address');

            $this->assertStringContainsString('City : الرياض', $card);
            $this->assertStringNotContainsString('Zip code', $card);
        }
    }

    public function test_a_structured_copy_prints_in_arabic_for_an_arabic_panel(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        session(['local' => 'sa']);

        $card = $this->card($this->adminPage($this->orderWithCopies($this->unifiedSnapshot($this->ids))), 'عنوان الشحن');

        $this->assertStringContainsString('منطقة الرياض', $card);
        $this->assertStringContainsString('الرياض', $card);
    }

    public function test_an_order_placed_with_the_structured_address_keeps_showing_it_when_the_store_goes_back_to_the_old_form(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $copy = $this->unifiedSnapshot($this->ids, ['zip' => '12211']);
        $this->setAddressMode(AddressSchema::MODE_OFF);

        $html = $this->adminPage($this->orderWithCopies($copy));

        $card = $this->card($html, 'Shipping address');
        $this->assertStringContainsString('National Address : RAGI2929', $card);
        $this->assertStringContainsString('Zip code : 12211', $card, 'a ZIP the copy carries is printed');
        $this->assertSame(0, $this->blocks($html), 'while the form is the old one again');
    }

    public function test_the_names_in_a_structured_copy_are_the_ones_of_the_day_it_was_made(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $copy = $this->unifiedSnapshot($this->ids);
        AddressCity::whereKey($this->ids['riyadh'])->update(['name_ar' => 'اسم جديد', 'name_en' => 'Renamed']);

        $card = $this->card($this->adminPage($this->orderWithCopies($copy)), 'Shipping address');

        $this->assertStringContainsString('City : Riyadh', $card);
        $this->assertStringNotContainsString('Renamed', $card);
    }

    public function test_an_old_saudi_copy_keeps_saudi_arabia_selected_whatever_the_stored_spelling_and_is_offered_the_structured_form_empty(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);

        $html = $this->adminPage($this->orderWithCopies($this->legacySnapshot(['country' => 'SA'])));

        $this->assertMatchesRegularExpression('/<option\s+value="Saudi Arabia"[^>]*selected/', $html, 'the order is not silently moved to the first country of the list');
        $this->assertDoesNotMatchRegularExpression('/<option\s+value="Egypt"[^>]*selected/', $html);
        $this->assertStringContainsString('value=""', $this->input($html, 'national_address'));
    }

    public function test_an_old_copy_in_another_country_keeps_that_country_selected_and_its_old_boxes(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);

        $html = $this->adminPage($this->orderWithCopies($this->legacySnapshot(['country' => 'Egypt', 'city' => 'Cairo', 'zip' => '11511'])));

        $this->assertMatchesRegularExpression('/<option\s+value="Egypt"[^>]*selected/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option\s+value="Saudi Arabia"[^>]*selected/', $html);
        $this->assertStringContainsString('value="Cairo"', $this->input($html, 'city'));
        $this->assertStringContainsString('value="11511"', $this->input($html, 'zip'));
    }

    public function test_a_blank_zip_is_not_written_into_the_form_as_text(): void
    {
        $html = $this->adminPage($this->orderWithCopies($this->legacySnapshot(['zip' => null])));

        $this->assertDoesNotMatchRegularExpression('/value="[^"]/', $this->input($html, 'zip'));
    }

    public function test_an_order_without_a_copy_renders_the_no_address_notice_and_no_form(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);

        $html = $this->adminPage($this->orderWithCopies(null));

        $this->assertStringContainsString('No shipping address found', strip_tags($html));
        $this->assertSame(0, $this->blocks($html));
    }
}
