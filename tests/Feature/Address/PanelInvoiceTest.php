<?php

namespace Tests\Feature\Address;

use App\Models\Order;
use App\Services\Address\AddressSchema;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CarriesAFrozenCompany;

/**
 * The printed invoice of the admin and the vendor panel: the same address rows whichever way the order's copy was made, and no crash on a
 * copy that lacks a key the template once took for granted (the invoice is a PDF, so a crash is a download that never arrives).
 */
class PanelInvoiceTest extends PanelAddressTestCase
{
    use CarriesAFrozenCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();
        app()->setLocale('en');
    }

    /** @param array<string, mixed> $invoiceSettings */
    private function invoice(string $view, Order $order, array $invoiceSettings = []): string
    {
        return View::make($view, [
            'order' => $order, 'vendor' => null, 'companyPhone' => '0110000000', 'companyEmail' => 'shop@example.test', 'companyName' => 'Pharmacy',
            'companyWebLogo' => null, 'invoiceSettings' => $invoiceSettings,
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

    #[DataProvider('invoices')]
    public function test_an_order_with_no_frozen_company_prints_the_business_identity_of_the_invoice_settings(string $view): void
    {
        $this->turnLegalEntitiesOnOrOff(on: true);

        $html = $this->invoice($view, $this->orderWithCopies($this->legacySnapshot()), $this->shopIdentity());

        $this->assertSame(1, preg_match('/<span class="fw-semibold">VAT<\/span>\s*:\s*300000000000003/', $html), 'the line the invoice always had');
        $this->assertStringNotContainsString('Lavkoo Kuwait Trading Co.', $html);
    }

    #[DataProvider('invoices')]
    public function test_an_order_with_a_frozen_company_prints_that_company_and_not_the_identity_of_the_shop(string $view): void
    {
        $this->turnLegalEntitiesOnOrOff(on: true);
        $order = $this->orderWithCopies($this->legacySnapshot(), null, ['entity_snapshot' => json_encode($this->frozenKuwaitCompany())]);

        $html = $this->invoice($view, $order, $this->shopIdentity());

        foreach (['Lavkoo Kuwait Trading Co.', '1 Example Street, Kuwait City', 'Commercial registration number', 'CR-KW-777'] as $printed) {
            $this->assertStringContainsString($printed, $html);
        }
        $this->assertStringNotContainsString('300000000000003', $html, 'the tax number of the shop is not printed for another company');
        $this->assertStringNotContainsString('Tax registration number', $html, 'a company with no tax number prints none');
    }

    #[DataProvider('invoices')]
    public function test_the_frozen_company_is_not_printed_while_legal_entities_are_off(string $view): void
    {
        $this->turnLegalEntitiesOnOrOff(on: false);
        $order = $this->orderWithCopies($this->legacySnapshot(), null, ['entity_snapshot' => json_encode($this->frozenKuwaitCompany())]);

        $html = $this->invoice($view, $order, $this->shopIdentity());

        $this->assertStringNotContainsString('Lavkoo Kuwait Trading Co.', $html);
        $this->assertStringContainsString('300000000000003', $html);
    }
}
