<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\LegalEntityRegistry;
use App\Services\Storefront\OrderInvoiceIssuer;
use App\Services\Storefront\OrderLegalEntitySnapshot;
use ArrayObject;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\Support\BuildsLegalEntityWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * GCC-13, step 2: an invoice names the company that was frozen on the order, not the shop's settings as they are today. An order with no usable record
 * (every order placed before the registry) prints the shop's settings exactly as before, and so does every order while legal entities are off.
 */
class OrderInvoiceIssuerTest extends TestCase
{
    use BuildsLegalEntityWorld;
    use RestoresLanguageFiles;

    private const SHOP_ADDRESS = '12 Olaya Example Road, Riyadh';

    private const SHOP_VAT = '300000000000003';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildLegalEntityWorld();
        DB::table('business_settings')->insert(['type' => 'shop_address', 'value' => self::SHOP_ADDRESS]);
        clearWebConfigCacheKeys();
    }

    protected function tearDown(): void
    {
        $this->tearDownLegalEntityWorld();
        clearWebConfigCacheKeys();
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function countries(): array
    {
        return array_combine(['SA', 'AE', 'KW', 'QA', 'BH', 'OM'], array_map(static fn (string $code): array => [$code], ['SA', 'AE', 'KW', 'QA', 'BH', 'OM']));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed> the order as the invoice reads it: its record is the JSON text the database hands back
     */
    private function orderCarrying(string $country, array $overrides = []): array
    {
        $entity = $this->registerEntity($country, $overrides);

        return ['id' => 1, 'entity_snapshot' => json_encode(app(LegalEntityRegistry::class)->identityOf($entity), JSON_UNESCAPED_UNICODE)];
    }

    /** @return list<array{label: ?string, value: string}> */
    private function printedFor(string $country): array
    {
        return [
            ['label' => null, 'value' => "Lavkoo {$country} Trading Co."],
            ['label' => null, 'value' => "1 Example Street, {$country}"],
            ['label' => 'tax_registration_number', 'value' => "TAX-{$country}-001"],
            ['label' => 'commercial_registration_number', 'value' => "CR-{$country}-777"],
        ];
    }

    /** @return array<string, array{mixed}> */
    public static function ordersWithNoUsableRecord(): array
    {
        return [
            'no such attribute' => [['id' => 1]],
            'null' => [['entity_snapshot' => null]],
            'empty text' => [['entity_snapshot' => '']],
            'text that is not JSON' => [['entity_snapshot' => 'not json']],
            'JSON that is a string' => [['entity_snapshot' => '"a company"']],
            'JSON that is a number' => [['entity_snapshot' => '123']],
            'an empty object' => [['entity_snapshot' => '{}']],
            'a record with nothing to print' => [['entity_snapshot' => '{"legal_entity_id":3,"country":"KW","legal_name":"","address":"  ","tax_registration_number":null}']],
            'facts that are not text' => [['entity_snapshot' => '{"legal_name":["a"],"address":{"b":1},"tax_registration_number":1.5,"commercial_registration_number":true}']],
            'no order at all' => [null],
        ];
    }

    #[DataProvider('ordersWithNoUsableRecord')]
    public function test_an_order_with_no_usable_record_keeps_printing_the_shops_settings(mixed $order): void
    {
        $this->assertNull(OrderInvoiceIssuer::lines($order));
    }

    #[DataProvider('countries')]
    public function test_each_of_the_six_companies_is_printed_from_the_orders_own_copy(string $country): void
    {
        $this->assertSame($this->printedFor($country), OrderInvoiceIssuer::lines($this->orderCarrying($country)));
    }

    public function test_only_the_facts_a_company_has_are_printed(): void
    {
        $kuwait = $this->orderCarrying('KW', ['tax_registration_number' => null, 'address' => '   ']);

        $this->assertSame(
            [['label' => null, 'value' => 'Lavkoo KW Trading Co.'], ['label' => 'commercial_registration_number', 'value' => 'CR-KW-777']],
            OrderInvoiceIssuer::lines($kuwait),
        );
    }

    public function test_the_values_are_trimmed_and_a_whole_number_is_printed_as_text(): void
    {
        $order = ['entity_snapshot' => ['legal_name' => "  Lavkoo Bahrain W.L.L.\n", 'tax_registration_number' => 220012345600002]];

        $this->assertSame(
            [['label' => null, 'value' => 'Lavkoo Bahrain W.L.L.'], ['label' => 'tax_registration_number', 'value' => '220012345600002']],
            OrderInvoiceIssuer::lines($order),
        );
    }

    public function test_an_order_may_be_an_array_an_object_a_model_like_object_or_carry_a_record_already_decoded(): void
    {
        $text = $this->orderCarrying('AE')['entity_snapshot'];
        $expected = $this->printedFor('AE');

        $object = new stdClass();
        $object->entity_snapshot = $text;

        $this->assertSame($expected, OrderInvoiceIssuer::lines(['entity_snapshot' => $text]));
        $this->assertSame($expected, OrderInvoiceIssuer::lines($object));
        $this->assertSame($expected, OrderInvoiceIssuer::lines(new ArrayObject(['entity_snapshot' => $text])));
        $this->assertSame($expected, OrderInvoiceIssuer::lines(['entity_snapshot' => json_decode($text, associative: true)]));
    }

    public function test_the_company_is_the_one_the_order_was_placed_with_whatever_the_registry_says_later(): void
    {
        $entity = $this->registerEntity('KW');
        $this->orderOf(41, 'KW');
        app(OrderLegalEntitySnapshot::class)->record(41, 'KW');

        $entity->update(['legal_name' => 'Renamed Kuwait Co.', 'tax_registration_number' => 'TAX-NEW', 'address' => 'A new address']);
        app(LegalEntityRegistry::class)->flush();
        $stamped = DB::table('orders')->where('id', 41)->first();

        $this->assertSame($this->printedFor('KW'), OrderInvoiceIssuer::lines($stamped));
    }

    public function test_nothing_is_printed_from_a_record_while_legal_entities_are_off(): void
    {
        $order = $this->orderCarrying('KW');

        config(['gcc.storefronts.legal_entities.enabled' => false]);
        $this->assertNull(OrderInvoiceIssuer::lines($order), 'switching legal entities off is the way back to the old invoice');

        config(['gcc.storefronts.legal_entities.enabled' => true, 'gcc.storefronts.enabled' => false]);
        $this->assertNull(OrderInvoiceIssuer::lines($order), 'and so are storefronts');

        config(['gcc.storefronts.enabled' => true]);
        $this->assertSame($this->printedFor('KW'), OrderInvoiceIssuer::lines($order));
    }

    /** @param array<string, mixed> $invoiceSettings */
    private function issuerBlock(mixed $order, array $invoiceSettings): string
    {
        return view('partials.invoice._issuer', ['order' => $order, 'invoiceSettings' => $invoiceSettings])->render();
    }

    private function collapsed(string $html): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/>\s+</', '> <', $html)));
    }

    /** @return array<string, mixed> */
    private function businessIdentity(bool $shown): array
    {
        return ['business_identity_status' => $shown ? 1 : 0, 'business_identity' => 'VAT', 'business_identity_value' => self::SHOP_VAT];
    }

    public function test_an_order_with_no_record_renders_the_block_the_invoices_always_had(): void
    {
        $withIdentity = $this->collapsed($this->issuerBlock(['id' => 1, 'entity_snapshot' => null], $this->businessIdentity(shown: true)));
        $withoutIdentity = $this->collapsed($this->issuerBlock(['id' => 1], $this->businessIdentity(shown: false)));
        $noSettings = $this->collapsed($this->issuerBlock(['id' => 1], []));

        $this->assertSame('<p class="text-dark p-0">' . self::SHOP_ADDRESS . '</p> <p class="text-dark p-0"> <span class="fw-semibold">VAT</span> : ' . self::SHOP_VAT . ' </p>', $withIdentity);
        $this->assertSame('<p class="text-dark p-0">' . self::SHOP_ADDRESS . '</p>', $withoutIdentity);
        $this->assertSame($withoutIdentity, $noSettings);
    }

    public function test_an_order_with_a_company_renders_the_company_and_nothing_of_the_shops_settings(): void
    {
        $html = $this->issuerBlock($this->orderCarrying('KW', ['tax_registration_number' => null]), $this->businessIdentity(shown: true));
        $block = $this->collapsed($html);

        $this->assertSame(
            '<p class="text-dark p-0"> Lavkoo KW Trading Co. </p> <p class="text-dark p-0"> 1 Example Street, KW </p>'
            . ' <p class="text-dark p-0"> <span class="fw-semibold">Commercial registration number</span> : CR-KW-777 </p>',
            $block,
        );
        $this->assertStringNotContainsString(self::SHOP_ADDRESS, $html, 'the Saudi address of the shop is not printed beside another company');
        $this->assertStringNotContainsString(self::SHOP_VAT, $html, 'and neither is its tax number');
        $this->assertStringNotContainsString('Tax registration number', $html, 'a company with no tax number prints none');
    }

    public function test_the_tax_number_of_the_company_is_labelled_and_replaces_the_one_of_the_invoice_settings(): void
    {
        $html = $this->issuerBlock($this->orderCarrying('AE'), $this->businessIdentity(shown: true));

        $this->assertStringContainsString('<span class="fw-semibold">Tax registration number</span>', $html);
        $this->assertStringContainsString(': TAX-AE-001', $this->collapsed($html));
        $this->assertStringNotContainsString(self::SHOP_VAT, $html);
    }

    public function test_the_name_of_a_company_is_escaped(): void
    {
        $html = $this->issuerBlock($this->orderCarrying('QA', ['legal_name' => '<script>alert(1)</script> & Sons']), []);

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; Sons', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    /**
     * F-14: the visible brand and the legal company are two fields, so renaming the brand cannot rename the company on an invoice already
     * issued.
     *
     * The control in the same test is what makes the comparison mean something: the very same change DOES move the block of an order that
     * carries no company, which is the block every invoice printed before the registry existed.
     */
    public function test_renaming_the_shops_brand_does_not_change_the_legal_name_on_an_invoice_already_issued(): void
    {
        $order = $this->orderCarrying('KW');
        $withACompany = $this->collapsed($this->issuerBlock($order, $this->businessIdentity(shown: true)));
        $withNoCompany = $this->collapsed($this->issuerBlock(['id' => 2, 'entity_snapshot' => null], $this->businessIdentity(shown: true)));

        foreach (['company_name' => 'A Brand New Name', 'company_web_logo' => 'new-logo.png', 'shop_address' => 'Another address entirely'] as $setting => $value) {
            DB::table('business_settings')->updateOrInsert(['type' => $setting], ['value' => $value]);
        }
        clearWebConfigCacheKeys();

        $this->assertSame($withACompany, $this->collapsed($this->issuerBlock($order, $this->businessIdentity(shown: true))), 'the company on the invoice is the one the order was placed with');
        $this->assertStringNotContainsString('A Brand New Name', $withACompany, 'and the brand never stands in for it');

        $this->assertNotSame(
            $withNoCompany,
            $this->collapsed($this->issuerBlock(['id' => 2, 'entity_snapshot' => null], $this->businessIdentity(shown: true))),
            'the same change moves the block of an order with no company, so the sameness above is the separation and not an inert test',
        );
    }

    public function test_the_block_is_the_shops_again_when_legal_entities_are_switched_off(): void
    {
        $order = $this->orderCarrying('KW');
        config(['gcc.storefronts.legal_entities.enabled' => false]);

        $this->assertSame(
            '<p class="text-dark p-0">' . self::SHOP_ADDRESS . '</p>',
            $this->collapsed($this->issuerBlock($order, $this->businessIdentity(shown: false))),
        );
    }
}
