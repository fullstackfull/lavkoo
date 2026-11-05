<?php

namespace Tests\Unit\Fulfillment;

use App\Services\Fulfillment\Support\PiiRedactor;
use PHPUnit\Framework\TestCase;

/** What identifies a person is removed from provider data before it is kept or leaves the application; what identifies a parcel stays. */
class PiiRedactorTest extends TestCase
{
    private const MASK = PiiRedactor::MASK;

    public function test_the_documented_add_order_body_keeps_the_parcel_and_loses_the_person(): void
    {
        $body = [
            'receiverName' => 'Sara Alharbi', 'receiverPhone' => '00966551234567', 'receiverPhone2' => '',
            'receiverAddress' => ['cityId' => '41125', 'regionId' => '1335', 'addressLine1' => 'King Fahd Rd 12'],
            'notes' => 'Leave with the guard', 'weight' => 1, 'receiverBusinessName' => 'Alharbi Pharmacy',
            'shipmentType' => 'COD', 'codCollectionMethod' => 'CASH', 'cod' => '72.50',
            'items' => [['productId' => 1421521, 'sku' => '13222', 'price' => 30.0, 'quantity' => 2]], 'invoiceNumber' => '100-TP1',
        ];

        $redacted = PiiRedactor::redact($body);

        $this->assertSame(self::MASK, $redacted['receiverName']);
        $this->assertSame(self::MASK, $redacted['receiverPhone']);
        $this->assertSame(self::MASK, $redacted['receiverAddress']['addressLine1']);
        $this->assertSame(self::MASK, $redacted['notes']);
        $this->assertSame(self::MASK, $redacted['receiverBusinessName']);
        $this->assertSame(['cityId' => '41125', 'regionId' => '1335', 'addressLine1' => self::MASK], $redacted['receiverAddress'], 'the city is what a debugger needs, and it is not a person');
        $this->assertSame('100-TP1', $redacted['invoiceNumber']);
        $this->assertSame('COD', $redacted['shipmentType']);
        $this->assertSame('72.50', $redacted['cod']);
        $this->assertSame($body['items'], $redacted['items']);
    }

    public function test_a_phone_number_or_an_email_under_a_key_nobody_expected_is_still_found(): void
    {
        $redacted = PiiRedactor::redact(['extra' => '0551234567', 'whoKnows' => 'sara@example.test', 'free' => 'call +966 55 123 4567 please', 'fine' => 'Riyadh']);

        $this->assertSame(self::MASK, $redacted['extra']);
        $this->assertSame(self::MASK, $redacted['whoKnows']);
        $this->assertStringNotContainsString('966 55', $redacted['free']);
        $this->assertSame('Riyadh', $redacted['fine']);
    }

    public function test_codes_barcodes_and_tracking_numbers_survive_even_though_they_are_long_digit_strings(): void
    {
        $redacted = PiiRedactor::redact(['barcode' => '6281000000011', 'sku' => '13222', 'trackingNumber' => 'KSA100422577363', 'awb' => '123456789012', 'orderId' => 4455, 'customerId' => 99, 'status' => 'OUT_FOR_DELIVERY']);

        $this->assertSame(['barcode' => '6281000000011', 'sku' => '13222', 'trackingNumber' => 'KSA100422577363', 'awb' => '123456789012', 'orderId' => 4455, 'customerId' => 99, 'status' => 'OUT_FOR_DELIVERY'], $redacted, 'an identifier is not a person');
    }

    public function test_a_phone_or_address_key_is_personal_even_when_it_ends_like_a_code(): void
    {
        $redacted = PiiRedactor::redact(['phoneCode' => '0551234567', 'emailId' => 'a@b.test', 'addressId' => 'King Fahd Rd']);

        $this->assertSame(self::MASK, $redacted['phoneCode']);
        $this->assertSame(self::MASK, $redacted['emailId']);
        $this->assertSame(self::MASK, $redacted['addressId']);
    }

    public function test_a_list_of_personal_values_is_masked_value_by_value(): void
    {
        $this->assertSame(['phones' => [self::MASK, self::MASK]], PiiRedactor::redact(['phones' => ['0551111111', '0552222222']]));
    }

    public function test_a_product_name_is_kept_in_a_catalogue_but_hidden_in_strict_mode(): void
    {
        $item = ['id' => 7, 'name' => 'Vitamin C 1000mg', 'sku' => 'VC1000', 'stock' => 40, 'price' => 19.0, 'description' => 'Tablets, 30 per box'];

        $this->assertSame($item, PiiRedactor::redact($item));

        $strict = PiiRedactor::redact($item, strict: true);
        $this->assertSame(self::MASK, $strict['name']);
        $this->assertSame(self::MASK, $strict['description']);
        $this->assertSame(['id' => 7, 'sku' => 'VC1000', 'stock' => 40, 'price' => 19.0], array_intersect_key($strict, array_flip(['id', 'sku', 'stock', 'price'])));
    }

    public function test_strict_mode_hides_any_string_that_is_not_a_short_code_word(): void
    {
        $redacted = PiiRedactor::redact(['status' => 'PICKED', 'deliveryInstructions' => 'Ring twice, the bell is broken', 'zone' => 'RUH-12', 'count' => 3, 'flag' => true, 'nothing' => null], strict: true);

        $this->assertSame(['status' => 'PICKED', 'deliveryInstructions' => self::MASK, 'zone' => 'RUH-12', 'count' => 3, 'flag' => true, 'nothing' => null], $redacted);
    }

    public function test_arabic_text_is_hidden_in_strict_mode_and_kept_in_a_catalogue(): void
    {
        $this->assertSame(self::MASK, PiiRedactor::redact(['label' => 'سارة الحربي'], strict: true)['label']);
        $this->assertSame('الرياض', PiiRedactor::redact(['cityName' => 'الرياض'])['cityName']);
    }

    public function test_text_is_masked_for_emails_and_phone_numbers_only(): void
    {
        $text = PiiRedactor::redactText('Invalid phone 0551234567 for sara@example.test on order KSA100422577363');

        $this->assertStringNotContainsString('0551234567', $text);
        $this->assertStringNotContainsString('sara@example.test', $text);
        $this->assertStringContainsString('KSA100422577363', $text);
        $this->assertStringContainsString('Invalid phone', $text);
    }

    public function test_redacting_leaves_the_shape_intact_and_changes_nothing_it_does_not_need_to(): void
    {
        $data = ['data' => [['id' => 1, 'sku' => 'A', 'stock' => 5, 'nested' => ['deep' => ['receiverEmail' => 'x@y.test', 'ok' => 'fine']]]], 'total' => 1];

        $redacted = PiiRedactor::redact($data);

        $this->assertSame([['id' => 1, 'sku' => 'A', 'stock' => 5, 'nested' => ['deep' => ['receiverEmail' => self::MASK, 'ok' => 'fine']]]], $redacted['data']);
        $this->assertSame(1, $redacted['total']);
    }
}
