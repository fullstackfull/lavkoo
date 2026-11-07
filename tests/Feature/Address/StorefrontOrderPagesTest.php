<?php

namespace Tests\Feature\Address;

use App\Models\Order;
use App\Models\ShippingAddress;
use App\Services\Address\AddressSnapshot;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsStorefrontOrderPages;

/**
 * What the customer's order details page and the invoice print of an order's address: the copy the order keeps, in the language of
 * the page, whether it was made with the structured address, with the old free-text one, or before either of them knew certain keys.
 */
class StorefrontOrderPagesTest extends StorefrontTestCase
{
    use BuildsStorefrontOrderPages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPageTables();
        $this->signIn();
    }

    /** @return array<string, array{string}> */
    public static function pages(): array
    {
        return ['order details' => ['renderOrderDetails'], 'invoice' => ['renderInvoice']];
    }

    /** @return array<string, mixed> the copy an order keeps when it was placed with a structured address */
    private function structuredCopy(array $overrides = []): array
    {
        $saved = $this->makeStructuredAddress($this->ids, 1, overrides: ['district' => 'Al Olaya', 'address' => 'King Fahd Rd 12']);

        return $overrides + AddressSnapshot::build($saved);
    }

    /** @return array<string, mixed> the copy an order kept before the structured address: old keys only */
    private function legacyCopy(array $overrides = []): array
    {
        return $overrides + [
            'contact_person_name' => 'Sara Alharbi', 'phone' => '+966551234567', 'email' => null, 'address_type' => 'home', 'address' => 'King Fahd Rd 12',
            'city' => 'الرياض', 'zip' => '12211', 'country' => 'Saudi Arabia', 'latitude' => '24.7', 'longitude' => '46.6', 'is_billing' => false,
        ];
    }

    #[DataProvider('pages')]
    public function test_a_structured_copy_names_the_region_city_district_and_national_address(string $render): void
    {
        session(['local' => 'en']);
        $order = $this->makeOrderKeeping($this->structuredCopy());

        $html = $this->{$render}($order);

        foreach (['Riyadh', 'Al Olaya', 'RAGI2929', 'King Fahd Rd 12'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        $this->assertStringContainsString(translate('address_region'), $html);
        $this->assertStringContainsString(translate('address_national_address'), $html);
        $this->assertStringNotContainsString(translate('zip_code'), $html, 'there is no ZIP code to print');
        $this->assertStringNotContainsString('City / Zip', $html);
    }

    #[DataProvider('pages')]
    public function test_the_names_follow_the_language_of_the_page(string $render): void
    {
        $order = $this->makeOrderKeeping($this->structuredCopy());

        session(['local' => 'sa']);
        $arabic = $this->{$render}($order);
        $this->assertStringContainsString('منطقة الرياض', $arabic);
        $this->assertStringContainsString('الرياض', $arabic);
        $this->assertStringNotContainsString('Riyadh', $arabic);

        session(['local' => 'en']);
        $english = $this->{$render}($order);
        $this->assertStringContainsString('Riyadh', $english);
        $this->assertStringNotContainsString('منطقة الرياض', $english);
    }

    #[DataProvider('pages')]
    public function test_an_old_copy_prints_its_city_and_prints_the_zip_only_when_there_is_one(string $render): void
    {
        session(['local' => 'en']);

        $withZip = $this->{$render}($this->makeOrderKeeping($this->legacyCopy(), id: 101));
        $this->assertStringContainsString('الرياض', $withZip);
        $this->assertStringContainsString('12211', $withZip);
        $this->assertStringNotContainsString('الرياض, 12211', $withZip);
        $render === 'renderInvoice'
            ? $this->assertStringContainsString('الرياض 12211', $withZip, 'the invoice keeps the single City / Zip row an old copy always had')
            : $this->assertStringContainsString(translate('zip_code'), $withZip, 'the page prints the ZIP on a line of its own');

        foreach ([null, '', '  '] as $position => $blank) {
            $withoutZip = $this->{$render}($this->makeOrderKeeping($this->legacyCopy(['zip' => $blank]), id: 110 + $position));
            $this->assertStringContainsString('الرياض', $withoutZip);
            $this->assertStringNotContainsString(translate('zip_code'), $withoutZip);
            $this->assertStringNotContainsString('الرياض,', $withoutZip, 'no dangling comma');
        }
    }

    #[DataProvider('pages')]
    public function test_an_old_copy_that_lacks_most_keys_still_renders(string $render): void
    {
        $order = $this->makeOrderKeeping(['contact_person_name' => 'Old Customer', 'phone' => '+966551234567', 'address' => 'Old street 7']);

        $html = $this->{$render}($order);

        $this->assertStringContainsString('Old Customer', $html);
        $this->assertStringContainsString('Old street 7', $html);
    }

    #[DataProvider('pages')]
    public function test_a_copy_that_was_encoded_twice_is_read_as_the_address_it_holds(string $render): void
    {
        $order = $this->makeOrderKeeping(json_encode(json_encode($this->legacyCopy(['contact_person_name' => 'Twice Encoded']), JSON_UNESCAPED_UNICODE)));

        $this->assertStringContainsString('Twice Encoded', $this->{$render}($order));
    }

    #[DataProvider('pages')]
    public function test_the_order_keeps_what_it_was_placed_with(string $render): void
    {
        session(['local' => 'en']);
        $saved = $this->makeStructuredAddress($this->ids, 1, overrides: ['district' => 'Al Olaya', 'address' => 'King Fahd Rd 12']);
        $order = $this->makeOrderKeeping(AddressSnapshot::build($saved));
        $before = $this->{$render}($order);

        DB::table('address_cities')->where('id', $this->ids['riyadh'])->update(['name_en' => 'Renamed City', 'name_ar' => 'اسم جديد', 'is_active' => false]);
        ShippingAddress::whereKey($saved->id)->update(['address' => 'Edited later', 'national_address' => 'ZZZZ0000', 'district' => 'Elsewhere']);
        $this->assertSame($before, $this->{$render}(Order::findOrFail($order->id)), 'editing the city list or the saved address does not rewrite the order');

        ShippingAddress::whereKey($saved->id)->delete();
        $this->assertSame($before, $this->{$render}(Order::findOrFail($order->id)), 'and neither does deleting the saved address');
    }

    public function test_the_billing_address_is_said_to_be_the_same_only_when_it_is_the_same_place(): void
    {
        session(['local' => 'en']);
        $shipping = $this->structuredCopy();
        $sameLabel = translate('Same as shipping address');

        $same = $this->renderOrderDetails($this->makeOrderKeeping($shipping, $shipping, id: 120));
        $this->assertStringContainsString($sameLabel, $same);

        $otherCity = ['city_id' => $this->ids['jeddah'], 'region_id' => $this->ids['region_MAK'], 'city_name_en' => 'Jeddah', 'city_name_ar' => 'جدة', 'region_name_en' => 'Makkah', 'region_name_ar' => 'منطقة مكة المكرمة'];
        $different = $this->renderOrderDetails($this->makeOrderKeeping($shipping, $otherCity + $shipping, id: 121));
        $this->assertStringNotContainsString($sameLabel, $different, 'the same street text in another city is another address');
        $this->assertStringContainsString('Jeddah', $different);
        $this->assertStringContainsString('Riyadh', $different);

        $otherNationalAddress = $this->renderOrderDetails($this->makeOrderKeeping($shipping, ['national_address' => 'JEDA1234'] + $shipping, id: 122));
        $this->assertStringNotContainsString($sameLabel, $otherNationalAddress);
        $this->assertStringContainsString('JEDA1234', $otherNationalAddress);
    }

    public function test_two_old_copies_with_the_same_text_are_the_same_address(): void
    {
        session(['local' => 'en']);

        $html = $this->renderOrderDetails($this->makeOrderKeeping($this->legacyCopy(), $this->legacyCopy(['zip' => '12211 ']), id: 130));

        $this->assertStringContainsString(translate('Same as shipping address'), $html);
    }

    public function test_an_order_that_kept_no_address_prints_no_address_block(): void
    {
        $order = $this->makeOrderKeeping(null, null);

        $details = $this->renderOrderDetails($order);
        $invoice = $this->renderInvoice($order);

        $this->assertStringNotContainsString(translate('shipping_address') . ':', $details);
        $this->assertStringNotContainsString(translate('Shipping_address'), $invoice);
        $this->assertStringContainsString(translate('Customer_Info'), $invoice);
    }
}
