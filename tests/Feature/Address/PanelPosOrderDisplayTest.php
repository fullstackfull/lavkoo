<?php

namespace Tests\Feature\Address;

use App\Models\Order;
use App\Services\Address\AddressSchema;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The detail page of a point-of-sale order in the admin and the vendor panel: the delivery address with the structured lines when the
 * order's copy has them, and the same few lines as ever for an old copy, including the one the point of sale builds from the customer's record.
 */
class PanelPosOrderDisplayTest extends PanelAddressTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();
        app()->setLocale('en');
    }

    /** @return array<string, array{string}> */
    public static function pages(): array
    {
        return ['admin page' => ['admin-views.pos.order.order-details'], 'vendor page' => ['vendor-views.pos.order.order-details']];
    }

    /** The lines of the delivery address card, in order. */
    private function addressLines(string $view, Order $order): array
    {
        $html = View::make($view, [
            'order' => $order, 'orderCount' => 1, 'totalDelivered' => 0, 'previousOrder' => null, 'nextOrder' => null,
            'isOrderEditable' => ['status' => false, 'message' => ''], 'editOrderSummary' => ['order_amount' => 0], 'deliveryMen' => collect(), 'physicalProduct' => true,
        ])->render();
        $this->assertSame(1, preg_match('/Shipping address\s*<\/h4>\s*<div class="d-flex flex-column gap-2 fs-12">(.*?<\/div>)\s*<\/div>\s*<\/div>/s', $html, $match));
        preg_match_all('/<(?:div|a)[^>]*>(.*?)<\/(?:div|a)>/s', $match[1], $lines);

        return array_values(array_filter(array_map(static fn (string $line): string => trim((string) preg_replace('/\s+/', ' ', strip_tags($line))), $lines[1]), static fn (string $line): bool => $line !== ''));
    }

    #[DataProvider('pages')]
    public function test_an_old_copy_keeps_its_lines_without_a_dangling_blank(string $view): void
    {
        $order = $this->orderWithCopies($this->legacySnapshot(['zip' => '']), null, ['order_type' => 'POS']);

        $this->assertSame(['Sara Alharbi', '0551234567', 'King Fahd Rd 12', 'City: الرياض'], $this->addressLines($view, $order));
    }

    #[DataProvider('pages')]
    public function test_the_copy_the_till_builds_from_the_customer_record_renders(string $view): void
    {
        $fromTheCustomerRecord = ['contact_person_name' => 'Sara Alharbi', 'phone' => '0551234567', 'email' => 'sara@example.test', 'address' => 'King Fahd Rd 12', 'city' => 'الرياض', 'country' => 'Saudi Arabia', 'house_no' => '3'];

        $this->assertSame(['Sara Alharbi', '0551234567', 'King Fahd Rd 12', 'City: الرياض'], $this->addressLines($view, $this->orderWithCopies($fromTheCustomerRecord, null, ['order_type' => 'POS'])));
    }

    #[DataProvider('pages')]
    public function test_a_structured_copy_lists_region_city_district_and_national_address(string $view): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $order = $this->orderWithCopies($this->unifiedSnapshot($this->ids, ['zip' => '12211']), null, ['order_type' => 'POS']);

        $this->assertSame(['Sara Alharbi', '0551234567', 'King Fahd Rd 12', 'Region: Riyadh', 'City: Riyadh', 'District: Al Olaya', 'National Address: RAGI2929', 'Zip code: 12211'], $this->addressLines($view, $order));
    }
}
