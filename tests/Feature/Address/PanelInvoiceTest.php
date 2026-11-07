<?php

namespace Tests\Feature\Address;

use App\Models\Order;
use App\Services\Address\AddressSchema;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The printed invoice of the admin and the vendor panel: the same address rows whichever way the order's copy was made, and no crash on a
 * copy that lacks a key the template once took for granted (the invoice is a PDF, so a crash is a download that never arrives).
 */
class PanelInvoiceTest extends PanelAddressTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();
        app()->setLocale('en');
    }

    private function invoice(string $view, Order $order): string
    {
        return View::make($view, [
            'order' => $order, 'vendor' => null, 'companyPhone' => '0110000000', 'companyEmail' => 'shop@example.test', 'companyName' => 'Pharmacy',
            'companyWebLogo' => null, 'invoiceSettings' => [],
        ])->render();
    }

    /** The rows of one address block of the invoice, as "label : value" lines in order. */
    private function block(string $html, string $heading): array
    {
        $this->assertSame(1, preg_match('/<h5>\s*' . preg_quote($heading, '/') . '.*?<\/h5>(.*?)<\/tbody>/s', $html, $match), "the invoice has a '{$heading}' block");
        preg_match_all('/<tr>\s*<td[^>]*>(.*?)<\/td>\s*<td[^>]*>:<\/td>\s*<td[^>]*>(.*?)<\/td>\s*<\/tr>/s', $match[1], $rows, PREG_SET_ORDER);

        return array_map(static fn (array $row): string => trim((string) preg_replace('/\s+/', ' ', strip_tags($row[1]))) . ' : ' . trim((string) preg_replace('/\s+/', ' ', strip_tags($row[2]))), $rows);
    }

    private function heading(string $html, string $title): string
    {
        $this->assertSame(1, preg_match('/<h5>\s*' . preg_quote($title, '/') . '(.*?)<\/h5>/s', $html, $match));

        return trim((string) preg_replace('/\s+/', ' ', strip_tags($match[1])));
    }

    /** @return array<string, array{string}> */
    public static function invoices(): array
    {
        return ['admin invoice' => ['admin-views.order.invoice'], 'vendor invoice' => ['vendor-views.order.invoice']];
    }

    #[DataProvider('invoices')]
    public function test_an_old_copy_prints_the_single_city_and_zip_row_it_always_did(string $view): void
    {
        $html = $this->invoice($view, $this->orderWithCopies($this->legacySnapshot(), $this->legacySnapshot(['zip' => '', 'address' => 'Billing St 9'])));

        $this->assertSame(['Name : Sara Alharbi', 'Phone : 0551234567', 'City / Zip : الرياض 12211', 'Address : King Fahd Rd 12'], $this->block($html, 'Shipping address'));
        $this->assertSame(['Name : Sara Alharbi', 'Phone : 0551234567', 'City / Zip : الرياض', 'Address : Billing St 9'], $this->block($html, 'Billing address'), 'a blank ZIP leaves nothing dangling');
        $this->assertSame('(Home)', $this->heading($html, 'Shipping address'));
    }

    #[DataProvider('invoices')]
    public function test_a_structured_copy_prints_region_city_district_and_national_address(string $view): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);

        $html = $this->invoice($view, $this->orderWithCopies($this->unifiedSnapshot($this->ids), $this->unifiedSnapshot($this->ids)));

        foreach (['Shipping address', 'Billing address'] as $heading) {
            $this->assertSame(
                ['Name : Sara Alharbi', 'Phone : 0551234567', 'Region : Riyadh', 'City : Riyadh', 'District : Al Olaya', 'National Address : RAGI2929', 'Address : King Fahd Rd 12'],
                $this->block($html, $heading),
            );
        }
    }

    #[DataProvider('invoices')]
    public function test_a_structured_copy_that_kept_a_legacy_zip_prints_it(string $view): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);

        $html = $this->invoice($view, $this->orderWithCopies($this->unifiedSnapshot($this->ids, ['zip' => '12211'])));

        $this->assertContains('Zip code : 12211', $this->block($html, 'Shipping address'));
    }

    #[DataProvider('invoices')]
    public function test_a_copy_without_an_address_type_or_zip_still_prints(string $view): void
    {
        $bare = ['contact_person_name' => 'Sara Alharbi', 'phone' => '0551234567', 'address' => 'King Fahd Rd 12', 'city' => 'الرياض'];

        $html = $this->invoice($view, $this->orderWithCopies($bare, $bare));

        $this->assertSame(['Name : Sara Alharbi', 'Phone : 0551234567', 'City / Zip : الرياض', 'Address : King Fahd Rd 12'], $this->block($html, 'Shipping address'));
        $this->assertSame('', $this->heading($html, 'Shipping address'), 'no empty brackets after the heading');
        $this->assertSame('', $this->heading($html, 'Billing address'));
    }

    #[DataProvider('invoices')]
    public function test_a_copy_in_arabic_uses_the_arabic_names_of_the_copy(string $view): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        session(['local' => 'sa']);

        $html = $this->invoice($view, $this->orderWithCopies($this->unifiedSnapshot($this->ids)));

        $this->assertStringContainsString('منطقة الرياض', $html);
    }

    #[DataProvider('invoices')]
    public function test_an_order_without_a_delivery_copy_prints_the_customer_block_as_before(string $view): void
    {
        $html = $this->invoice($view, $this->orderWithCopies(null, $this->legacySnapshot(), ['is_guest' => 1]));

        $this->assertSame(1, preg_match('/<h5>\s*Customer Info\s*<\/h5>/', $html));
        $this->assertContains('Name : Sara Alharbi', $this->block($html, 'Billing address'));
    }
}
