<?php

namespace Tests\Feature\Address;

use App\Models\User;
use App\Services\Address\AddressSchema;

/**
 * The saved addresses on a customer's page in the admin panel: the structured fields of the new addresses, a flag on the old Saudi ones
 * that must be completed before they can ship again, and nobody else's addresses.
 */
class PanelCustomerViewTest extends PanelAddressTestCase
{
    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();
        app()->setLocale('en');
        $this->customerId = User::unguarded(static fn () => User::create(['f_name' => 'Sara', 'l_name' => 'Alharbi', 'email' => 'sara@example.test', 'phone' => '0551234567']))->id;
    }

    /**
     * The addresses listed on the page, oldest first: the label and value of each row, and whether it carries the "needs completion" flag.
     *
     * @return list<array{rows: array<string, string>, flagged: bool}>
     */
    private function savedAddresses(): array
    {
        $html = (string) $this->get(route('admin.customer.view', ['user_id' => $this->customerId]))->assertOk()->getContent();
        $start = strpos($html, 'id="savedAddressOffcanvas"');
        $this->assertNotFalse($start);
        $blocks = array_slice(explode('<div class="p-2 bg-section rounded mb-3 mb-sm-20">', substr($html, $start)), 1);

        return array_map(static function (string $block): array {
            preg_match_all('/<span class="min-w-80">(.*?)<\/span>\s*<span>:<\/span>\s*<span>(.*?)<\/span>/s', $block, $rows, PREG_SET_ORDER);

            return [
                'rows' => array_column(array_map(static fn (array $row): array => [trim($row[1]), trim(strip_tags($row[2]))], $rows), 1, 0),
                'flagged' => str_contains(explode('bg-white rounded', $block)[0], 'badge text-bg-warning'),
            ];
        }, $blocks);
    }

    public function test_an_old_address_prints_as_it_did_and_a_blank_zip_is_not_listed(): void
    {
        $this->makeLegacyAddress($this->customerId);
        $this->makeLegacyAddress($this->customerId, ['zip' => '', 'address' => 'Olaya St 3']);

        [$withZip, $withoutZip] = $this->savedAddresses();

        $this->assertSame(['City' => 'الرياض', 'Zip code' => '12211', 'Address' => 'King Fahd Rd 12'], array_intersect_key($withZip['rows'], array_flip(['City', 'Zip code', 'Address'])));
        $this->assertArrayNotHasKey('Zip code', $withoutZip['rows']);
        foreach ([$withZip, $withoutZip] as $listed) {
            $this->assertSame([], array_intersect_key($listed['rows'], array_flip(['Region', 'District', 'National Address'])));
            $this->assertFalse($listed['flagged']);
        }
    }

    public function test_a_structured_address_lists_region_city_district_and_national_address(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->makeStructuredAddress($this->ids, $this->customerId);

        [$listed] = $this->savedAddresses();

        $this->assertSame(
            ['Region' => 'Riyadh', 'City' => 'Riyadh', 'District' => 'Al Olaya', 'National Address' => 'RAGI2929'],
            array_intersect_key($listed['rows'], array_flip(['Region', 'City', 'District', 'National Address'])),
        );
        $this->assertArrayNotHasKey('Zip code', $listed['rows'], 'a structured address that never had a ZIP lists none');
        $this->assertFalse($listed['flagged']);
    }

    public function test_an_old_saudi_address_is_flagged_once_it_has_to_be_completed_and_only_then(): void
    {
        $this->makeLegacyAddress($this->customerId);
        $this->makeLegacyAddress($this->customerId, ['country' => 'Egypt', 'city' => 'Cairo']);

        $this->assertSame([false, false], array_column($this->savedAddresses(), 'flagged'), 'while the mode is off nothing has to be completed');

        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->assertSame([true, false], array_column($this->savedAddresses(), 'flagged'), 'the Saudi address is flagged, the one in Egypt is not');
    }

    public function test_the_page_hands_its_template_the_copies_and_the_ids_of_the_addresses_to_complete(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $old = $this->makeLegacyAddress($this->customerId);
        $structured = $this->makeStructuredAddress($this->ids, $this->customerId);

        $response = $this->get(route('admin.customer.view', ['user_id' => $this->customerId]))->assertOk();

        $this->assertSame([$old->id], $response->viewData('incompleteAddressIds'));
        $copies = $response->viewData('addressCopies');
        $this->assertSame('unified', $copies[$structured->id]['address_schema']);
        $this->assertSame('legacy', $copies[$old->id]['address_schema']);
    }

    public function test_a_guests_address_that_shares_the_number_is_not_listed_on_a_registered_customers_page(): void
    {
        $this->makeLegacyAddress($this->customerId, ['address' => 'Mine 1']);
        $this->makeLegacyAddress($this->customerId, ['is_guest' => true, 'address' => 'A guest with the same number']);

        $listed = $this->savedAddresses();

        $this->assertCount(1, $listed);
        $this->assertSame('Mine 1', $listed[0]['rows']['Address']);
    }
}
